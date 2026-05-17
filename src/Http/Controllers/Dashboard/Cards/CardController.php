<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Cards;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Payment\PaymentRefundClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Services\MpCustomerService;

class CardController extends Controller
{
    public function __construct(
        protected readonly MpCustomerService $mpCustomerService,
    ) {}

    public function index(): JsonResponse
    {
        $cards = UserCard::where('authentication_id', auth()->id())->latest()->get();
        return response()->jsonSuccess($cards);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mp_token'          => ['required', 'string'],
            'cpf'               => ['required', 'string'],
            'payment_method_id' => ['required', 'string'],
            'holder_name'       => ['required', 'string', 'max:255'],
            'brand'             => ['required', 'string', 'max:50'],
        ]);

        $user = auth()->user();
        $cpf  = preg_replace('/\D/', '', $validated['cpf']);

        MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));
        $paymentClient = new PaymentClient();

        try {
            $nameParts = explode(' ', $user->name ?? '', 2);

            // Cobrança de R$5,00 para validar o cartão
            $payment = $paymentClient->create([
                'transaction_amount'   => 5.00,
                'token'                => $validated['mp_token'],
                'description'          => 'Validação de cartão',
                'installments'         => 1,
                'payment_method_id'    => $validated['payment_method_id'],
                'external_reference'   => $user->getKey() . '|card_validation',
                'statement_descriptor' => mb_substr(config('app.name') ?: 'Validacao Cartao', 0, 22),
                'payer'                => [
                    'email'          => strtolower($user->email),
                    'identification' => ['type' => 'CPF', 'number' => $cpf],
                ],
                'additional_info' => [
                    'payer' => [
                        'first_name'        => $nameParts[0] ?? '',
                        'last_name'         => $nameParts[1] ?? '',
                        'registration_date' => $user->created_at?->toIso8601String(),
                    ],
                    'items' => [
                        [
                            'id'          => 'card_validation',
                            'title'       => 'Validação de cartão',
                            'description' => 'Cobrança de validação de cartão de crédito (estornada automaticamente)',
                            'category_id' => 'services',
                            'quantity'    => 1,
                            'unit_price'  => 5.00,
                        ],
                    ],
                ],
            ]);

            Log::info('Card validation payment', [
                'status'        => $payment->status,
                'status_detail' => $payment->status_detail,
                'payment_id'    => $payment->id,
            ]);

            if ($payment->status !== 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => $this->translateStatusDetail($payment->status_detail ?? ''),
                ], 422);
            }

            // Cria/obtém cliente MP e associa o cartão
            $mpCustomerId = $this->mpCustomerService->getOrCreateCustomer($user);
            $mpCardId     = $this->mpCustomerService->attachCard($mpCustomerId, $validated['mp_token']);

            $lastFour    = (string) ($payment->card?->last_four_digits ?? '');
            $expiryMonth = str_pad((string) ($payment->card?->expiration_month ?? 1), 2, '0', STR_PAD_LEFT);
            $expiryYear  = (string) ($payment->card?->expiration_year ?? date('Y'));

            $existing = UserCard::where('authentication_id', $user->getKey())
                ->where('last_four', $lastFour)
                ->first();

            if ($existing) {
                $existing->update([
                    'mp_customer_id' => $mpCustomerId,
                    'mp_card_id'     => $mpCardId ?? $existing->mp_card_id,
                    'is_default'     => true,
                ]);
                $card = $existing;
            } else {
                UserCard::where('authentication_id', $user->getKey())->update(['is_default' => false]);

                $card = UserCard::create([
                    'authentication_id' => $user->getKey(),
                    'holder_name'       => $validated['holder_name'],
                    'last_four'         => $lastFour,
                    'brand'             => $validated['brand'],
                    'expiry_month'      => $expiryMonth,
                    'expiry_year'       => $expiryYear,
                    'mp_customer_id'    => $mpCustomerId,
                    'mp_card_id'        => $mpCardId,
                    'is_default'        => true,
                ]);
            }

            // Estorno automático da cobrança de validação
            try {
                $refundClient = new PaymentRefundClient();
                $refundClient->refund($payment->id, 5.00);
                Log::info('Card validation refunded', ['payment_id' => $payment->id]);
            } catch (\Exception $e) {
                Log::warning('Card validation refund failed', [
                    'payment_id' => $payment->id,
                    'error'      => $e->getMessage(),
                ]);
            }

            return response()->jsonSuccess($card, 201);

        } catch (MPApiException $e) {
            $body   = $e->getApiResponse()?->getContent();
            $detail = $body['status_detail'] ?? $body['message'] ?? '';
            Log::error('MP card validation API error', ['body' => $body]);
            return response()->json([
                'success' => false,
                'message' => $this->translateStatusDetail($detail) ?: __('api-key::messages.error_processing_payment'),
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Card store error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => __('api-key::messages.error_processing_payment')], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $card = UserCard::where('authentication_id', auth()->id())->findOrFail($id);
        $card->delete();
        return response()->jsonSuccess();
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
