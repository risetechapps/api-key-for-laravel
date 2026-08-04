<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Checkout;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Coupon\Coupon;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Repositories\Coupon\CouponRepository;
use RiseTechApps\ApiKey\Repositories\Plan\PlanRepository;
use RiseTechApps\ApiKey\Services\MpCustomerService;
use RiseTechApps\ApiKey\Services\PaymentOutcomeService;
use RiseTechApps\ApiKey\Traits\BuildsPaymentPayer;
use RiseTechApps\ApiKey\Traits\SendsIdempotentPayments;

class CheckoutController extends Controller
{
    use BuildsPaymentPayer, SendsIdempotentPayments;

    public function __construct(
        protected readonly PlanRepository $planRepository,
        protected readonly MpCustomerService $mpCustomerService,
        protected readonly CouponRepository $couponRepository,
        protected readonly PaymentOutcomeService $paymentOutcome,
    ) {}

    public function validateCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'plan_id' => ['required', 'string'],
        ]);

        $plan = $this->planRepository->findActiveById($validated['plan_id']);

        if (! $plan) {
            return response()->json(['success' => false, 'message' => __('api-key::messages.plan_not_found')], 404);
        }

        $coupon = Coupon::where('code', strtoupper($validated['code']))->first();

        if (! $coupon || ! $coupon->isValid()) {
            return response()->json(['success' => false, 'message' => __('api-key::messages.coupon_invalid_or_expired')], 422);
        }

        $originalPrice = (float) $plan->price;
        $discount = $coupon->type === 'percentage'
            ? $originalPrice * ($coupon->value / 100)
            : min((float) $coupon->value, $originalPrice);

        // The preview has to apply the proration credit for the same reason
        // process() does, and in the same order — otherwise the screen quotes one
        // amount and the card is charged another.
        $credit = $this->prorationCredit();
        $finalPrice = max(0, round($originalPrice - $discount - $credit, 2));

        return response()->jsonSuccess([
            'coupon' => $coupon->code,
            'type' => $coupon->type,
            'discount_value' => $coupon->value,
            'discount' => round($discount, 2),
            'credit' => $credit,
            'original_price' => $originalPrice,
            'final_price' => $finalPrice,
        ]);
    }

    public function process(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'string'],
            'token' => ['nullable', 'string'],
            'saved_card_id' => ['nullable', 'integer'],
            'payment_method_id' => ['nullable', 'string'],
            'issuer_id' => ['nullable', 'string'],
            'payer' => ['nullable', 'array'],
            'payer.email' => ['nullable', 'email'],
            'payer.identification' => ['nullable', 'array'],
            'payer.identification.type' => ['nullable', 'string'],
            'payer.identification.number' => ['nullable', 'string'],
            'coupon_code' => ['nullable', 'string'],
            // Optional, and the client should send it: a value generated once per
            // checkout attempt and reused across retries is the only thing that
            // makes a re-submitted form provably the same purchase. See
            // idempotencyKey() for what happens when it is absent.
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            // Coletado no navegador pelo security.js do Mercado Pago. Opcional:
            // um cliente que nao o envie continua conseguindo pagar, apenas com
            // analise de risco mais pobre.
            'device_id' => ['nullable', 'string', 'max:255'],
        ]);

        $plan = $this->planRepository->findActiveById($validated['plan_id']);
        if (! $plan) {
            return response()->json(['success' => false, 'message' => __('api-key::messages.plan_not_found')], 404);
        }

        $transactionAmount = (float) $plan->price;
        $appliedCoupon = null;

        // A claimed coupon use is kept only when the checkout reaches a state
        // that can still become a subscription (approved now, or pending /
        // in_process for the webhook to settle). Every other exit hands it back,
        // so a declined card does not burn a redemption.
        $couponSettled = false;

        if (! empty($validated['coupon_code'])) {
            $coupon = Coupon::where('code', strtoupper($validated['coupon_code']))->first();

            // Claimed before the gateway is called rather than after approval:
            // the claim is what enforces max_uses, so it has to happen while this
            // request can still be refused. See CouponRepository::claimUse().
            if ($coupon && $this->couponRepository->claimUse($coupon)) {
                $discount = $coupon->type === 'percentage'
                    ? $transactionAmount * ($coupon->value / 100)
                    : min((float) $coupon->value, $transactionAmount);
                $transactionAmount = max(0, round($transactionAmount - $discount, 2));
                $appliedCoupon = $coupon;
            }
        }

        // Proration. subscribeToPlan() replaces the running subscription with a
        // fresh one, so whatever was left of the current period is discarded —
        // upgrading on day 3 of 30 used to throw away 27 paid days. Applied after
        // the coupon so a percentage discount is computed on the plan's real
        // price, not on an already-credited amount.
        $credit = $this->prorationCredit();

        if ($credit > 0) {
            $transactionAmount = max(0, round($transactionAmount - $credit, 2));
        }

        if ($transactionAmount <= 0) {
            $userPlan = auth()->user()->subscribeToPlan($plan);
            $userPlan->update(['credit_applied' => $credit ?: null]);

            return response()->jsonSuccess([
                'status' => 'approved',
                'message' => $credit > 0
                    ? __('api-key::messages.subscription_activated_with_credit')
                    : __('api-key::messages.subscription_activated_full_discount'),
            ]);
        }

        if (empty($validated['token'])) {
            $this->releaseCoupon($appliedCoupon);

            return response()->json(['success' => false, 'message' => __('api-key::messages.invalid_payment_data')], 422);
        }

        if (empty($validated['payment_method_id']) || empty($validated['payer']['email'])) {
            $this->releaseCoupon($appliedCoupon);

            return response()->json(['success' => false, 'message' => __('api-key::messages.invalid_payment_data')], 422);
        }

        $payerEmail = strtolower($validated['payer']['email']);
        $token = $validated['token'] ?? null;
        $savedCard = null;

        if (! empty($validated['saved_card_id'])) {
            $savedCard = UserCard::where('authentication_id', auth()->user()->getKey())
                ->find($validated['saved_card_id']);
        }

        try {
            MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));
            $client = new PaymentClient;

            $authUser = $this->payerUser();

            $payerData = ['email' => $payerEmail, ...$this->payerNames($authUser->name)];
            $identification = $validated['payer']['identification'] ?? $request->input('payer.identification');
            if (! empty($identification['type']) && ! empty($identification['number'])) {
                $payerData['identification'] = $identification;
            }

            if ($savedCard?->mp_customer_id) {
                $payerData['id'] = $savedCard->mp_customer_id;
            }

            $description = __('api-key::messages.plan_subscription_description', ['plan' => $plan->name]);

            $paymentPayload = [
                'transaction_amount' => $transactionAmount,
                'token' => $token,
                'description' => $description,
                'installments' => 1,
                'payment_method_id' => $validated['payment_method_id'],
                'payer' => $payerData,
                'external_reference' => auth()->id().'|'.$plan->getKey(),
                'statement_descriptor' => mb_substr((string) config('app.name') ?: 'Assinatura', 0, 22),
                'additional_info' => [
                    'payer' => [
                        ...$this->payerAdditionalInfo($authUser),
                        'is_prime_user' => '0',
                        'is_first_purchase_online' => '1',
                    ],
                    'items' => [
                        [
                            'id' => (string) $plan->getKey(),
                            'title' => $description,
                            'description' => $plan->description ?? $description,
                            'category_id' => 'services',
                            'quantity' => 1,
                            'unit_price' => $transactionAmount,
                        ],
                    ],
                ],
            ];

            if (! empty($validated['issuer_id'])) {
                $paymentPayload['issuer_id'] = (int) $validated['issuer_id'];
            }

            if ($notificationUrl = $this->notificationUrl()) {
                $paymentPayload['notification_url'] = $notificationUrl;
            }

            $payment = $client->create($paymentPayload, $this->idempotentRequest(
                $validated['idempotency_key'] ?? null,
                ['checkout', auth()->id(), $plan->getKey(), $transactionAmount, $token],
                $validated['device_id'] ?? null
            ));

            // Only the fields needed to trace a payment. The full $payment object
            // carries cardholder name, last four digits and the payer document —
            // none of which belongs in an application log.
            logglyInfo()->withContext([
                'status' => $payment->status,
                'status_detail' => $payment->status_detail,
                'id' => $payment->id,
            ])->log('MP payment response');

            if ($payment->status === 'approved') {
                $userPlan = auth()->user()->subscribeToPlan($plan);
                $userPlan->update([
                    'payment_id' => $payment->id,
                    'payment_amount' => $transactionAmount,
                    'credit_applied' => $credit ?: null,
                ]);
                $couponSettled = true;

                if ($savedCard) {
                    UserCard::where('authentication_id', auth()->user()->getKey())->update(['is_default' => false]);
                    $savedCard->update(['is_default' => true]);
                } else {
                    try {
                        $this->syncCardAfterPayment(auth()->user(), $payment, $validated);
                    } catch (\Exception $e) {
                        logglyWarning()->withContext(['error' => $e->getMessage()])->log('Card sync after payment failed');
                    }
                }

                return response()->jsonSuccess(['status' => 'approved', 'message' => __('api-key::messages.payment_approved')]);
            }

            if (in_array($payment->status ?? '', ['pending', 'in_process'])) {
                // Still live: the webhook subscribes the user if it settles, so the
                // redemption stays claimed.
                $couponSettled = true;

                // A espera vira registro. Sem ela uma recusa posterior não teria
                // a quem se referir: o comprador não seria avisado, a reserva do
                // cupom ficaria queimada e um webhook que não chegasse deixaria o
                // pagamento órfão.
                PendingPayment::create([
                    'authentication_id' => auth()->id(),
                    'plan_id' => $plan->getKey(),
                    'payment_id' => (string) $payment->id,
                    'amount' => $transactionAmount,
                    'coupon_id' => $appliedCoupon?->getKey(),
                    'credit_applied' => $credit ?: null,
                    'status' => (string) $payment->status,
                ]);

                return response()->jsonSuccess(['status' => 'pending', 'message' => __('api-key::messages.payment_pending')]);
            }

            return response()->json(['success' => false, 'message' => $this->translateStatusDetail($payment->status_detail ?? '')], 422);

        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (MPApiException $e) {
            $body = $e->getApiResponse()?->getContent();
            $detail = $body['status_detail'] ?? $body['message'] ?? '';
            logglyError()->withContext(['body' => $body, 'status' => $e->getApiResponse()?->getStatusCode()])->log('MP API exception');

            return response()->json(['success' => false, 'message' => $this->translateStatusDetail($detail) ?: ($detail ?: __('api-key::messages.payment_declined'))], 422);
        } catch (\Exception $e) {
            logglyError()->withContext(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()])->log('Checkout process error');

            return response()->json(['success' => false, 'message' => __('api-key::messages.error_processing_payment')], 500);
        } finally {
            // Covers the declined-payment return and all three catches above.
            if (! $couponSettled) {
                $this->releaseCoupon($appliedCoupon);
            }
        }
    }

    /**
     * Credit owed for the unused remainder of the buyer's current subscription.
     *
     * Zero when there is no running subscription, when it is on a free plan, or
     * when its period has already lapsed — see UserPlan::unusedCredit().
     */
    private function prorationCredit(): float
    {
        return auth()->user()
            ?->activePlan()
            ->with('plan')
            ->first()
            ?->unusedCredit() ?? 0.0;
    }

    /**
     * Hand a claimed coupon use back when the checkout it was claimed for did
     * not result in a subscription. No-op when no coupon was applied.
     */
    private function releaseCoupon(?Coupon $coupon): void
    {
        if ($coupon) {
            $this->couponRepository->releaseUse($coupon);
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
        $brand = $formData['payment_method_id'] ?? 'outros';
        $expiryM = str_pad((string) ($payment->card?->expiration_month ?? 1), 2, '0', STR_PAD_LEFT);
        $expiryY = (string) ($payment->card?->expiration_year ?? date('Y'));
        $holder = $payment->card?->cardholder?->name ?? $user->name;

        UserCard::where('authentication_id', $user->getKey())->update(['is_default' => false]);

        $card = UserCard::updateOrCreate(
            ['authentication_id' => $user->getKey(), 'last_four' => $lastFour],
            [
                'holder_name' => $holder,
                'brand' => $brand,
                'expiry_month' => $expiryM,
                'expiry_year' => $expiryY,
                'mp_customer_id' => $mpCustomerId,
                'mp_card_id' => $mpCardId,
                'is_default' => true,
            ]
        );

        logglyInfo()->withContext(['card_id' => $card->id, 'mp_card_id' => $mpCardId])->log('Card synced after payment');
    }

    /**
     * Recebe as notificações do Mercado Pago.
     *
     * O gateway entrega em dois formatos, e o pacote precisa dos dois:
     *
     *   Webhook  — corpo `{type, data:{id}}` com os headers `x-signature` e
     *              `x-request-id`. Assinado, validado por HMAC.
     *   IPN      — query `?topic=payment&id=…` com corpo `{resource, topic}`.
     *              Sem assinatura nenhuma; é assim que o formato foi desenhado.
     *
     * O IPN chega porque o pagamento carrega `notification_url`, que a revisão
     * de qualidade do Mercado Pago exige. Rejeitá-lo não é opção: sem responder
     * 200 o gateway reentrega indefinidamente.
     *
     * A autenticidade do IPN vem de nós irmos buscar o pagamento na API com o
     * nosso access token — só agimos sobre pagamentos da nossa própria conta.
     * É verificação mais fraca que o HMAC, e por isso desligável.
     */
    public function webhook(Request $request): JsonResponse
    {
        $signature = (string) $request->header('x-signature', '');

        if ($signature !== '') {
            if (! $this->signatureIsValid($request, $signature)) {
                return response()->json(['message' => __('api-key::messages.invalid_webhook_signature')], 400);
            }
        } elseif (! $this->acceptsUnsignedNotification($request)) {
            logglyWarning()->withContext([
                'topic' => $request->input('topic'),
                'has_secret' => (bool) config('api-key.mercadopago.webhook_secret'),
            ])->log('MP notification rejected: unsigned and not a recognised IPN');

            return response()->json(['message' => __('api-key::messages.invalid_webhook_signature')], 400);
        }

        $type = $request->input('type') ?? $request->input('topic');

        if ($type !== 'payment') {
            return response()->json(['message' => 'ok']);
        }

        // `resource` e a query `id` são os campos do IPN; `data.id` é o do
        // webhook assinado.
        $paymentId = $request->input('data.id')
            ?? $request->input('id')
            ?? $request->input('resource')
            ?? $request->query('id');

        MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));
        $client = new PaymentClient;
        $payment = $client->get((int) $paymentId);

        // O que fazer com cada desfecho vive no serviço, e não aqui: o comando
        // de reconciliação precisa decidir exatamente a mesma coisa quando o
        // webhook não chega. A resposta é sempre 'ok' — o Mercado Pago reentrega
        // enquanto não receber 200, e um pagamento que não nos diz respeito não
        // é motivo para pedir reentrega.
        $this->paymentOutcome->apply($payment);

        return response()->json(['message' => 'ok']);
    }

    /**
     * Confere o HMAC do webhook assinado.
     *
     * Continua fechando por falta de segredo: um endpoint que aceita qualquer
     * assinatura é o mesmo que não ter assinatura, e segredo ausente é erro de
     * configuração para gritar, não checagem a pular.
     */
    private function signatureIsValid(Request $request, string $signature): bool
    {
        $secret = config('api-key.mercadopago.webhook_secret');

        if (! $secret) {
            logglyError()->log('MP webhook rejected: no webhook secret configured');

            return false;
        }

        $xRequestId = (string) $request->header('x-request-id', '');
        $dataId = $request->query('data_id', $request->input('data.id', ''));

        $ts = $this->extractSignaturePart($signature, 'ts');
        $v1 = $this->extractSignaturePart($signature, 'v1');
        $hash = hash_hmac('sha256', "id:{$dataId};request-id:{$xRequestId};ts:{$ts};", (string) $secret);

        return hash_equals($hash, $v1);
    }

    /**
     * Se esta requisição sem assinatura é um IPN legítimo do Mercado Pago.
     *
     * Exige a forma do IPN — `topic` mais `resource` ou o `id` na query — para
     * que uma requisição qualquer sem assinatura não passe. Não é autenticação:
     * quem autentica é a consulta do pagamento na API logo adiante, com o nosso
     * access token. Serve para não abrir o endpoint a qualquer corpo JSON.
     *
     * Desligável por `api-key.mercadopago.accept_ipn` para quem recebe só
     * webhooks assinados e prefere recusar o resto.
     */
    private function acceptsUnsignedNotification(Request $request): bool
    {
        if (! config('api-key.mercadopago.accept_ipn', true)) {
            return false;
        }

        $hasTopic = $request->input('topic') !== null || $request->query('topic') !== null;
        $hasResource = $request->input('resource') !== null || $request->query('id') !== null;

        return $hasTopic && $hasResource;
    }

    private function extractSignaturePart(string $signature, string $key): string
    {
        if (preg_match("/{$key}=([^,]+)/", $signature, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Turn a Mercado Pago status_detail into a message for the buyer.
     *
     * Looked up in the translation files rather than matched inline, so the text
     * follows the request locale like every other message in the package.
     */
    private function translateStatusDetail(string $detail): string
    {
        $key = "api-key::messages.payment_rejected.{$detail}";
        $message = __($key);

        // __() echoes the key back when there is no entry for it, which is how an
        // unmapped status_detail is detected.
        return $message === $key
            ? __('api-key::messages.payment_rejected.default')
            : $message;
    }
}
