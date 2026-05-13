<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Checkout;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Coupon\Coupon;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Repositories\Plan\PlanRepository;
use RiseTechApps\ApiKey\Services\MpCustomerService;

class CheckoutController extends Controller
{
    public function __construct(
        protected readonly PlanRepository $planRepository,
        protected readonly MpCustomerService $mpCustomerService,
    ) {
    }

    public function validateCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'    => ['required', 'string'],
            'plan_id' => ['required', 'string'],
        ]);

        $plan = $this->planRepository->findById($validated['plan_id']);

        if (! $plan) {
            return response()->json(['success' => false, 'message' => 'Plano não encontrado.'], 404);
        }

        $coupon = Coupon::where('code', strtoupper($validated['code']))->first();

        if (! $coupon || ! $coupon->isValid()) {
            return response()->json(['success' => false, 'message' => 'Cupom inválido ou expirado.'], 422);
        }

        $originalPrice = (float) $plan->price;
        $discount      = $coupon->type === 'percentage'
            ? $originalPrice * ($coupon->value / 100)
            : min((float) $coupon->value, $originalPrice);
        $finalPrice = max(0, round($originalPrice - $discount, 2));

        return response()->jsonSuccess([
            'coupon'         => $coupon->code,
            'type'           => $coupon->type,
            'discount_value' => $coupon->value,
            'discount'       => round($discount, 2),
            'original_price' => $originalPrice,
            'final_price'    => $finalPrice,
        ]);
    }

    public function process(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id'                     => ['required', 'string'],
            'token'                       => ['nullable', 'string'],
            'mp_card_id'                  => ['nullable', 'string'],
            'security_code'               => ['nullable', 'string'],
            'payment_method_id'           => ['nullable', 'string'],
            'issuer_id'                   => ['nullable', 'string'],
            'payer'                       => ['nullable', 'array'],
            'payer.email'                 => ['nullable', 'email'],
            'payer.identification'        => ['nullable', 'array'],
            'payer.identification.type'   => ['nullable', 'string'],
            'payer.identification.number' => ['nullable', 'string'],
            'coupon_code'                 => ['nullable', 'string'],
        ]);

        $plan = $this->planRepository->findById($validated['plan_id']);
        if (! $plan) {
            return response()->json(['success' => false, 'message' => 'Plano não encontrado.'], 404);
        }

        $transactionAmount = (float) $plan->price;
        $appliedCoupon     = null;

        if (! empty($validated['coupon_code'])) {
            $coupon = Coupon::where('code', strtoupper($validated['coupon_code']))->first();
            if ($coupon && $coupon->isValid()) {
                $discount          = $coupon->type === 'percentage'
                    ? $transactionAmount * ($coupon->value / 100)
                    : min((float) $coupon->value, $transactionAmount);
                $transactionAmount = max(0, round($transactionAmount - $discount, 2));
                $appliedCoupon     = $coupon;
            }
        }

        if ($transactionAmount <= 0) {
            auth()->user()->subscribeToPlan($plan);
            $appliedCoupon?->increment('uses');
            return response()->jsonSuccess(['status' => 'approved', 'message' => 'Assinatura ativada com cupom de desconto total.']);
        }

        if (empty($validated['token']) && empty($validated['mp_card_id'])) {
            return response()->json(['success' => false, 'message' => 'Dados de pagamento inválidos.'], 422);
        }

        if (empty($validated['payment_method_id']) || empty($validated['payer']['email'])) {
            return response()->json(['success' => false, 'message' => 'Dados de pagamento inválidos.'], 422);
        }

        $payerEmail   = strtolower($validated['payer']['email']);
        $token        = $validated['token'] ?? null;
        $savedCard    = null;
        $mpCustomerId = null;

        try {
            if (! empty($validated['mp_card_id'])) {
                $savedCard = UserCard::where('authentication_id', auth()->user()->getKey())
                    ->where('mp_card_id', $validated['mp_card_id'])
                    ->first();

                if (! $savedCard || ! $savedCard->mp_customer_id) {
                    return response()->json(['success' => false, 'message' => 'Cartão não encontrado.'], 404);
                }

                if (empty($validated['security_code'])) {
                    return response()->json(['success' => false, 'message' => 'CVV obrigatório.'], 422);
                }

                $mpCustomerId = $savedCard->mp_customer_id;
                $token        = $this->mpCustomerService->tokenizeCard(
                    $mpCustomerId,
                    $savedCard->mp_card_id,
                    $validated['security_code'],
                );
            }

            MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));
            $client = new PaymentClient();

            $payerData = ['email' => $payerEmail];
            if ($mpCustomerId) {
                $payerData['id'] = $mpCustomerId;
            }
            $identification = $validated['payer']['identification'] ?? $request->input('payer.identification');
            if (! empty($identification['type']) && ! empty($identification['number'])) {
                $payerData['identification'] = $identification;
            }

            Log::debug('MP checkout payer sent', [
                'payer'          => $payerData,
                'raw_payer_input'=> $request->input('payer'),
            ]);

            $authUser  = auth()->user();
            $nameParts = explode(' ', $authUser->name ?? '', 2);

            $paymentPayload = [
                'transaction_amount'   => $transactionAmount,
                'token'                => $token,
                'description'          => "Assinatura do plano {$plan->name}",
                'installments'         => 1,
                'payment_method_id'    => $validated['payment_method_id'],
                'payer'                => $payerData,
                'external_reference'   => auth()->id() . '|' . $plan->getKey(),
                'statement_descriptor' => mb_substr(config('app.name'), 0, 22),
                'additional_info'      => [
                    'payer' => [
                        'first_name'               => $nameParts[0] ?? '',
                        'last_name'                => $nameParts[1] ?? '',
                        'registration_date'        => $authUser->created_at?->toIso8601String(),
                        'is_prime_user'            => '0',
                        'is_first_purchase_online' => '1',
                    ],
                    'items' => [
                        [
                            'id'          => (string) $plan->getKey(),
                            'title'       => "Assinatura do plano {$plan->name}",
                            'description' => $plan->description ?? "Assinatura do plano {$plan->name}",
                            'category_id' => 'services',
                            'quantity'    => 1,
                            'unit_price'  => $transactionAmount,
                        ],
                    ],
                ],
            ];

            if (! empty($validated['issuer_id'])) {
                $paymentPayload['issuer_id'] = (int) $validated['issuer_id'];
            }

            $payment = $client->create($paymentPayload);

            Log::info('MP payment response', [
                'status'        => $payment->status,
                'status_detail' => $payment->status_detail,
                'id'            => $payment->id,
                'payment' => $payment
            ]);

            if ($payment->status === 'approved') {
                $userPlan = auth()->user()->subscribeToPlan($plan);
                $userPlan->update(['payment_id' => $payment->id]);
                $appliedCoupon?->increment('uses');

                if ($savedCard) {
                    UserCard::where('authentication_id', auth()->user()->getKey())->update(['is_default' => false]);
                    $savedCard->update(['is_default' => true]);
                } else {
                    try {
                        $this->syncCardAfterPayment(auth()->user(), $payment, $validated);
                    } catch (\Exception $e) {
                        Log::warning('Card sync after payment failed', ['error' => $e->getMessage()]);
                    }
                }

                return response()->jsonSuccess(['status' => 'approved', 'message' => 'Pagamento aprovado! Sua assinatura foi ativada.']);
            }

            if (in_array($payment->status ?? '', ['pending', 'in_process'])) {
                return response()->jsonSuccess(['status' => 'pending', 'message' => 'Pagamento em análise. Você será notificado em breve.']);
            }

            return response()->json(['success' => false, 'message' => $this->translateStatusDetail($payment->status_detail ?? '')], 422);

        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (MPApiException $e) {
            $body   =$e->getApiResponse()?->getContent();
            $detail = $body['status_detail'] ?? $body['message'] ?? '';
            Log::error('MP API exception', ['body' => $body, 'status' => $e->getApiResponse()?->getStatusCode()]);
            return response()->json(['success' => false, 'message' => $this->translateStatusDetail($detail) ?: ($detail ?: 'Pagamento recusado.')], 422);
        } catch (\Exception $e) {
            Log::error('Checkout process error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Erro interno ao processar o pagamento.'], 500);
        }
    }

    private function syncCardAfterPayment(Authentication $user, object $payment, array $formData): void
    {
        $mpCustomerId = (string) ($payment->payer?->id ?? '');
        if (! $mpCustomerId) {
            return;
        }

        $mpCardId = $this->mpCustomerService->attachCard($mpCustomerId, $formData['token']);
        if (! $mpCardId) {
            return;
        }

        $lastFour = (string) ($payment->card?->last_four_digits ?? '');
        $brand    = $formData['payment_method_id'] ?? 'outros';
        $expiryM  = str_pad((string) ($payment->card?->expiration_month ?? 1), 2, '0', STR_PAD_LEFT);
        $expiryY  = (string) ($payment->card?->expiration_year ?? date('Y'));
        $holder   = $payment->card?->cardholder?->name ?? $user->name;

        UserCard::where('authentication_id', $user->getKey())->update(['is_default' => false]);

        $card = UserCard::updateOrCreate(
            ['authentication_id' => $user->getKey(), 'last_four' => $lastFour],
            [
                'holder_name'    => $holder,
                'brand'          => $brand,
                'expiry_month'   => $expiryM,
                'expiry_year'    => $expiryY,
                'mp_customer_id' => $mpCustomerId,
                'mp_card_id'     => $mpCardId,
                'is_default'     => true,
            ]
        );

        Log::info('Card synced after payment', ['card_id' => $card->id, 'mp_card_id' => $mpCardId]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $secret = config('api-key.mercadopago.webhook_secret');

        if ($secret) {
            $xSignature = $request->header('x-signature', '');
            $xRequestId = $request->header('x-request-id', '');
            $dataId     = $request->query('data_id', $request->input('data.id', ''));

            $ts   = $this->extractSignaturePart($xSignature, 'ts');
            $v1   = $this->extractSignaturePart($xSignature, 'v1');
            $hash = hash_hmac('sha256', "id:{$dataId};request-id:{$xRequestId};ts:{$ts};", $secret);

            if (! hash_equals($hash, $v1)) {
                return response()->json(['message' => 'Invalid signature.'], 400);
            }
        }

        $type = $request->input('type') ?? $request->input('topic');

        if ($type === 'payment') {
            $paymentId = $request->input('data.id') ?? $request->input('id');

            MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));
            $client  = new PaymentClient();
            $payment = $client->get((int) $paymentId);

            if ($payment->status === 'approved' && $payment->external_reference) {
                [$userId, $planId] = explode('|', $payment->external_reference, 2);

                $user = Authentication::find($userId);
                $plan = $this->planRepository->findById($planId);

                if ($user && $plan) {
                    $alreadySubscribed = $user->userPlan()
                        ->where('plan_id', $plan->getKey())
                        ->where('active', true)
                        ->exists();

                    if (! $alreadySubscribed) {
                        $user->subscribeToPlan($plan);
                    }
                }
            }
        }

        return response()->json(['message' => 'ok']);
    }

    private function extractSignaturePart(string $signature, string $key): string
    {
        if (preg_match("/{$key}=([^,]+)/", $signature, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function translateStatusDetail(string $detail): string
    {
        return match ($detail) {
            'cc_rejected_bad_filled_card_number'   => 'Número do cartão inválido.',
            'cc_rejected_bad_filled_date'          => 'Data de validade inválida.',
            'cc_rejected_bad_filled_other'         => 'Dado inválido. Verifique as informações do cartão.',
            'cc_rejected_bad_filled_security_code' => 'Código de segurança inválido.',
            'cc_rejected_blacklist'                => 'Cartão não permitido.',
            'cc_rejected_call_for_authorize'       => 'Entre em contato com seu banco para autorizar o pagamento.',
            'cc_rejected_card_disabled'            => 'Cartão desativado. Entre em contato com seu banco.',
            'cc_rejected_duplicated_payment'       => 'Pagamento duplicado detectado.',
            'cc_rejected_high_risk'                => 'Pagamento recusado por motivos de segurança.',
            'cc_rejected_insufficient_amount'      => 'Saldo insuficiente.',
            'cc_rejected_invalid_installments'     => 'Número de parcelas inválido.',
            'cc_rejected_max_attempts'             => 'Número máximo de tentativas atingido. Tente outro cartão.',
            default                                => 'Pagamento recusado. Verifique os dados e tente novamente.',
        };
    }
}
