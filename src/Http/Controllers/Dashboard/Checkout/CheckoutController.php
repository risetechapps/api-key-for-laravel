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
use RiseTechApps\ApiKey\Repositories\Coupon\CouponRepository;
use RiseTechApps\ApiKey\Repositories\Plan\PlanRepository;
use RiseTechApps\ApiKey\Services\MpCustomerService;
use RiseTechApps\ApiKey\Traits\BuildsPaymentPayer;
use RiseTechApps\ApiKey\Traits\SendsIdempotentPayments;

class CheckoutController extends Controller
{
    use BuildsPaymentPayer, SendsIdempotentPayments;

    public function __construct(
        protected readonly PlanRepository $planRepository,
        protected readonly MpCustomerService $mpCustomerService,
        protected readonly CouponRepository $couponRepository,
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
            Log::info('MP payment response', [
                'status' => $payment->status,
                'status_detail' => $payment->status_detail,
                'id' => $payment->id,
            ]);

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
                        Log::warning('Card sync after payment failed', ['error' => $e->getMessage()]);
                    }
                }

                return response()->jsonSuccess(['status' => 'approved', 'message' => __('api-key::messages.payment_approved')]);
            }

            if (in_array($payment->status ?? '', ['pending', 'in_process'])) {
                // Still live: the webhook subscribes the user if it settles, so the
                // redemption stays claimed.
                $couponSettled = true;

                return response()->jsonSuccess(['status' => 'pending', 'message' => __('api-key::messages.payment_pending')]);
            }

            return response()->json(['success' => false, 'message' => $this->translateStatusDetail($payment->status_detail ?? '')], 422);

        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (MPApiException $e) {
            $body = $e->getApiResponse()?->getContent();
            $detail = $body['status_detail'] ?? $body['message'] ?? '';
            Log::error('MP API exception', ['body' => $body, 'status' => $e->getApiResponse()?->getStatusCode()]);

            return response()->json(['success' => false, 'message' => $this->translateStatusDetail($detail) ?: ($detail ?: __('api-key::messages.payment_declined'))], 422);
        } catch (\Exception $e) {
            Log::error('Checkout process error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

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

        Log::info('Card synced after payment', ['card_id' => $card->id, 'mp_card_id' => $mpCardId]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $secret = config('api-key.mercadopago.webhook_secret');

        // Fail closed. An unsigned webhook endpoint accepts any caller's claim
        // that a payment happened, so a missing secret is a misconfiguration to
        // reject loudly, not a check to skip.
        if (! $secret) {
            Log::error('MP webhook rejected: no webhook secret configured');

            return response()->json(['message' => __('api-key::messages.invalid_webhook_signature')], 400);
        }

        $xSignature = $request->header('x-signature', '');
        $xRequestId = $request->header('x-request-id', '');
        $dataId = $request->query('data_id', $request->input('data.id', ''));

        $ts = $this->extractSignaturePart($xSignature, 'ts');
        $v1 = $this->extractSignaturePart($xSignature, 'v1');
        $hash = hash_hmac('sha256', "id:{$dataId};request-id:{$xRequestId};ts:{$ts};", (string) $secret);

        if (! hash_equals($hash, $v1)) {
            return response()->json(['message' => __('api-key::messages.invalid_webhook_signature')], 400);
        }

        $type = $request->input('type') ?? $request->input('topic');

        if ($type !== 'payment') {
            return response()->json(['message' => 'ok']);
        }

        $paymentId = $request->input('data.id') ?? $request->input('id');

        MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));
        $client = new PaymentClient;
        $payment = $client->get((int) $paymentId);

        if ($payment->status !== 'approved' || ! $payment->external_reference) {
            return response()->json(['message' => 'ok']);
        }

        $reference = $this->parseExternalReference((string) $payment->external_reference);

        if (! $reference) {
            Log::warning('MP webhook: unrecognised external_reference', [
                'external_reference' => $payment->external_reference,
                'payment_id' => $payment->id,
            ]);

            return response()->json(['message' => 'ok']);
        }

        if ($reference['plan_id'] === 'card_validation') {
            return response()->json(['message' => 'ok']);
        }

        $user = Authentication::find($reference['user_id']);
        // findById, not findActiveById: the money has already changed hands. A plan
        // taken off sale between checkout and confirmation must still be delivered
        // to the buyer who paid for it — only *acquiring* a plan is gated on is_active.
        $plan = $this->planRepository->findById($reference['plan_id']);

        if (! $user || ! $plan) {
            return response()->json(['message' => 'ok']);
        }

        $alreadySubscribed = $user->userPlan()
            ->where('plan_id', $plan->getKey())
            ->where('active', true)
            ->where('payment_id', (string) $payment->id)
            ->exists();

        if ($alreadySubscribed) {
            return response()->json(['message' => 'ok']);
        }

        $userPlan = $user->subscribeToPlan($plan);

        // The direct checkout flow records these; the webhook flow used not to,
        // which left webhook-confirmed subscriptions with no payment trail and
        // invisible to the refund screen (it filters on payment_id).
        $userPlan->update([
            'payment_id' => (string) $payment->id,
            'payment_amount' => (float) $payment->transaction_amount,
        ]);

        return response()->json(['message' => 'ok']);
    }

    /**
     * Split a Mercado Pago external_reference into its parts.
     *
     * Two shapes are emitted by this package:
     *   "<userId>|<planId>"                        — checkout and card validation
     *   "renewal|<userId>|<planId>|<userPlanId>"   — automatic renewal
     *
     * The previous parser always split into two pieces, so a renewal reference
     * yielded userId = "renewal" and every renewal webhook was silently dropped.
     *
     * @return array{user_id: string, plan_id: string}|null
     */
    private function parseExternalReference(string $reference): ?array
    {
        $parts = explode('|', $reference);

        if (($parts[0] ?? null) === 'renewal') {
            return isset($parts[1], $parts[2])
                ? ['user_id' => $parts[1], 'plan_id' => $parts[2]]
                : null;
        }

        return isset($parts[0], $parts[1]) && $parts[0] !== ''
            ? ['user_id' => $parts[0], 'plan_id' => $parts[1]]
            : null;
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
