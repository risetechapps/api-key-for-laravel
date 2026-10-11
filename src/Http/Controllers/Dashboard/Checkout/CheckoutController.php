<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Checkout;

use App\Http\Controllers\Controller;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use RiseTechApps\ApiKey\Contracts\CardGateway;
use RiseTechApps\ApiKey\Exceptions\PaymentMessageException;
use RiseTechApps\ApiKey\Gateways\GatewayRegistry;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Coupon\Coupon;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Repositories\Coupon\CouponRepository;
use RiseTechApps\ApiKey\Repositories\Plan\PlanRepository;
use RiseTechApps\ApiKey\Services\AuditLogger;
use RiseTechApps\ApiKey\Services\AutomaticPaymentService;
use RiseTechApps\ApiKey\Services\MpCustomerService;
use RiseTechApps\ApiKey\Services\PaymentOutcomeService;
use RiseTechApps\ApiKey\Services\PixService;
use RiseTechApps\ApiKey\Services\SystemHealthService;
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
        protected readonly PixService $pixService,
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
        $credit = min($this->prorationCredit(), max(0, round($originalPrice - $discount, 2)));
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
            'payment_method' => ['nullable', 'in:card,pix'],
            // Segundo token do mesmo cartão, gerado com o CVV junto do token do
            // pagamento. Cada token serve uma vez só, e este vai para o perfil
            // de Pagamentos Automáticos, que cobra a renovação sem CVV.
            'profile_token' => ['nullable', 'string', 'max:64'],
            // Outros gateways: o cartão novo como o navegador gerou (token com
            // o CVV, cartão criptografado do PagBank, SetupIntent do Stripe).
            'card_token' => ['nullable', 'string', 'max:4096'],
            // O que o gateway pede além do token (bandeira na Efí, CVV da
            // primeira cobrança no PagBank). Nunca é gravado nem registrado.
            'extra' => ['nullable', 'array'],
            'holder_name' => ['nullable', 'string', 'max:255'],
            'cpf' => ['nullable', 'string', 'max:20'],
            'payment_type_id' => ['nullable', 'in:credit_card,debit_card,prepaid_card'],
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
        // Só o que cabe na compra: o resto do crédito não vira saldo, e gravar o
        // crédito inteiro em credit_applied faria o período valer mais do que
        // custou na próxima troca.
        $credit = min($this->prorationCredit(), $transactionAmount);

        if ($credit > 0) {
            $transactionAmount = max(0, round($transactionAmount - $credit, 2));
        }

        // Período de teste: só na primeira assinatura paga da conta, e só em
        // plano que ofereça. Não passa pelo gateway; quem não cancelar paga na
        // renovação, no fim do teste, como qualquer assinante.
        if ($trial = $this->trialFor($plan, $validated)) {
            $this->releaseCoupon($appliedCoupon);

            return $trial;
        }

        if ($transactionAmount <= 0) {
            auth()->user()->subscribeToPlan($plan, ['payment_amount' => 0, 'credit_applied' => $credit ?: null]);

            return response()->jsonSuccess([
                'status' => 'approved',
                'message' => $credit > 0
                    ? __('api-key::messages.subscription_activated_with_credit')
                    : __('api-key::messages.subscription_activated_full_discount'),
            ]);
        }

        if (($validated['payment_method'] ?? 'card') === 'pix') {
            return $this->processPix($plan, $transactionAmount, $credit, $appliedCoupon, $validated);
        }

        // Só o gateway ativo cobra. Cartão salvo em outro gateway não é aceito
        // aqui: o assinante cadastra um cartão no gateway atual.
        if ($driver = GatewayRegistry::active()) {
            return $this->processDriverCard($driver, $plan, $transactionAmount, $credit, $appliedCoupon, $validated);
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
                ->where('gateway', 'mercadopago')
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
                // O webhook deste mesmo pagamento chega em segundos e pode
                // vencer esta linha; subscribeToPlan() é idempotente por
                // payment_id, então quem chegar depois só completa os campos.
                auth()->user()->subscribeToPlan($plan, [
                    'payment_id' => (string) $payment->id,
                    'payment_amount' => $transactionAmount,
                    'credit_applied' => $credit ?: null,
                    'payment_method' => 'card',
                ]);
                $couponSettled = true;

                if ($savedCard) {
                    $savedCard->makeDefault();
                } else {
                    try {
                        $savedCard = $this->syncCardAfterPayment(auth()->user(), $payment, $validated);
                    } catch (\Exception $e) {
                        logglyWarning()->withContext(['error' => $e->getMessage()])->log('Card sync after payment failed');
                    }
                }

                // Melhor esforço, como o próprio registro do cartão: a compra já
                // foi aprovada, e sem perfil a renovação só pede o pagamento pelo
                // painel.
                $automaticBilling = $savedCard && app(AutomaticPaymentService::class)->registerProfile(
                    $savedCard,
                    $validated['profile_token'] ?? null,
                    $validated['payment_type_id'] ?? null,
                );

                return response()->jsonSuccess([
                    'status' => 'approved',
                    'message' => __('api-key::messages.payment_approved'),
                    // Cartão salvo nesta compra sem perfil (o Card Payment Brick
                    // gera um token só): o painel oferece ativar a renovação
                    // automática pedindo só o CVV. Se o painel já mandou o token
                    // do perfil e ele não vingou, pedir de novo não muda nada.
                    'automatic_billing_offer' => ! $automaticBilling && empty($validated['profile_token'])
                        && $savedCard?->mp_card_id && AutomaticPaymentService::enabled()
                        ? ['card_id' => $savedCard->getKey(), 'mp_card_id' => $savedCard->mp_card_id, 'last_four' => $savedCard->last_four]
                        : null,
                ]);
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

        } catch (PaymentMessageException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            logglyError()->withContext(['error' => $e->getMessage()])->log('MP card checkout failed');

            return response()->json(['success' => false, 'message' => __('api-key::messages.error_processing_payment')], 422);
        } catch (MPApiException $e) {
            $body = $e->getApiResponse()?->getContent();
            $detail = $body['status_detail'] ?? $body['message'] ?? '';
            logglyError()->withContext(['body' => $body, 'status' => $e->getApiResponse()?->getStatusCode()])->log('MP API exception');

            return response()->json(['success' => false, 'message' => $this->translateStatusDetail($detail) ?: ($detail ?: __('api-key::messages.payment_declined'))], 422);
        } catch (\Exception $e) {
            // ->exception() em vez de getTraceAsString() no contexto: ele guarda
            // o trace estruturado, apenas file, line, class e function por frame,
            // limitado a 20 frames. O trace em texto do PHP inclui os argumentos
            // de cada chamada em forma abreviada, e este é o caminho por onde
            // passam token de cartão e dados do pagador — que agora ficariam
            // legíveis na tela de logs do painel.
            logglyError()->exception($e)->log('Checkout process error');

            return response()->json(['success' => false, 'message' => __('api-key::messages.error_processing_payment')], 500);
        } finally {
            // Covers the declined-payment return and all three catches above.
            if (! $couponSettled) {
                $this->releaseCoupon($appliedCoupon);
            }
        }
    }

    /**
     * Compra com cartão nos outros gateways: cartão salvo (cobrado sem CVV) ou
     * cartão novo (salvo e validado no gateway antes da cobrança).
     *
     * Cupom e crédito seguem a mesma regra do Mercado Pago: a reserva do cupom
     * só fica quando a compra pode ainda virar assinatura.
     */
    private function processDriverCard(CardGateway $gateway, Plan $plan, float $amount, float $credit, ?Coupon $coupon, array $validated): JsonResponse
    {
        if (! $gateway->configured()) {
            $this->releaseCoupon($coupon);

            return response()->json(['success' => false, 'message' => __('api-key::messages.gateway_not_configured')], 422);
        }

        $user = $this->payerUser();

        try {
            $card = empty($validated['saved_card_id']) ? null : UserCard::where('authentication_id', $user->getKey())
                ->where('gateway', $gateway->name())
                ->whereNotNull('gateway_card_id')
                ->find($validated['saved_card_id']);

            if (! $card) {
                if (empty($validated['card_token'])) {
                    $this->releaseCoupon($coupon);

                    return response()->json(['success' => false, 'message' => __('api-key::messages.invalid_payment_data')], 422);
                }

                $card = $gateway->saveCard($user, [
                    'card_token' => $validated['card_token'],
                    'cpf' => $validated['cpf'] ?? ($validated['payer']['identification']['number'] ?? null),
                    'holder_name' => $validated['holder_name'] ?? null,
                    'extra' => $validated['extra'] ?? [],
                ]);
            }

            $payment = $gateway->chargeCard(
                $card,
                $amount,
                $user->getKey().'|'.$plan->getKey(),
                __('api-key::messages.plan_subscription_description', ['plan' => $plan->name]),
                $this->idempotencyKey($validated['idempotency_key'] ?? null, ['checkout-'.$gateway->name(), $user->getKey(), $plan->getKey(), $amount, $card->gateway_card_id]),
                customerInitiated: true,
                input: $validated['extra'] ?? [],
            );
        } catch (PaymentMessageException $e) {
            $this->releaseCoupon($coupon);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            // Falha técnica do gateway (HTTP 500, resposta inesperada): o
            // detalhe fica no log, a tela recebe a mensagem genérica.
            $this->releaseCoupon($coupon);
            logglyError()->withContext(['gateway' => $gateway->name(), 'error' => $e->getMessage()])->log('Card checkout failed');

            return response()->json(['success' => false, 'message' => __('api-key::messages.error_processing_payment')], 422);
        }

        logglyInfo()->withContext(['gateway' => $gateway->name(), 'status' => $payment->status, 'id' => $payment->id])->log('Card payment response');

        if ($payment->status === 'approved') {
            $user->subscribeToPlan($plan, [
                'payment_id' => (string) $payment->id,
                'payment_amount' => $amount,
                'credit_applied' => $credit ?: null,
                'payment_method' => 'card',
            ]);

            $card->makeDefault();

            return response()->jsonSuccess(['status' => 'approved', 'message' => __('api-key::messages.payment_approved')]);
        }

        if (in_array($payment->status, ['pending', 'in_process'], true)) {
            PendingPayment::create([
                'authentication_id' => $user->getKey(),
                'plan_id' => $plan->getKey(),
                'payment_id' => (string) $payment->id,
                'amount' => $amount,
                'coupon_id' => $coupon?->getKey(),
                'credit_applied' => $credit ?: null,
                'status' => (string) $payment->status,
            ]);

            return response()->jsonSuccess([
                'status' => 'pending',
                'message' => __('api-key::messages.payment_pending'),
                'payment_id' => (string) $payment->id,
                // O banco pediu autenticação (3DS no Stripe): o painel conclui
                // no navegador e acompanha pelo status do pagamento.
                ...(isset($payment->client_action) ? ['client_action' => $payment->client_action] : []),
            ]);
        }

        $this->releaseCoupon($coupon);

        return response()->json(['success' => false, 'message' => __('api-key::messages.payment_declined')], 422);
    }

    /**
     * Compra com PIX: gera o QR e devolve para o painel exibir.
     *
     * A assinatura não sai aqui: PIX nasce pendente, e quem assina é o webhook
     * da confirmação (ou a reconciliação). Cupom e crédito ficam guardados no
     * registro de espera, como num cartão em análise.
     */
    private function processPix(Plan $plan, float $amount, float $credit, ?Coupon $coupon, array $validated): JsonResponse
    {
        if (! $this->pixService->enabled()) {
            $this->releaseCoupon($coupon);

            return response()->json(['success' => false, 'message' => __('api-key::messages.pix_disabled')], 422);
        }

        $user = $this->payerUser();
        $expiresAt = PixService::expiresAt();

        try {
            $payment = $this->pixService->createCharge(
                $user,
                $plan,
                $amount,
                $user->getKey().'|'.$plan->getKey(),
                $expiresAt,
                // A hora entra na chave: um clique duplo devolve o mesmo QR, mas
                // quem volta depois de o QR expirar recebe um novo.
                ['checkout-pix', $user->getKey(), $plan->getKey(), $amount, now()->format('YmdH')],
                $validated['idempotency_key'] ?? null,
                $validated['payer']['identification'] ?? null,
            );
        } catch (MPApiException $e) {
            $this->releaseCoupon($coupon);
            logglyError()->withContext(['body' => $e->getApiResponse()->getContent()])->log('MP PIX creation failed');

            return response()->json(['success' => false, 'message' => __('api-key::messages.pix_failed')], 422);
        } catch (PaymentMessageException $e) {
            // Falta CPF ou telefone no perfil, valor fora do limite do PIX:
            // mensagem para o cliente.
            $this->releaseCoupon($coupon);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            logglyError()->withContext(['error' => $e->getMessage()])->log('PIX checkout failed');
            $this->releaseCoupon($coupon);

            return response()->json(['success' => false, 'message' => __('api-key::messages.pix_failed')], 422);
        } catch (\Throwable $e) {
            $this->releaseCoupon($coupon);
            logglyError()->exception($e)->log('PIX checkout error');

            return response()->json(['success' => false, 'message' => __('api-key::messages.error_processing_payment')], 500);
        }

        PendingPayment::firstOrCreate(
            ['payment_id' => (string) $payment->id],
            [
                'authentication_id' => $user->getKey(),
                'plan_id' => $plan->getKey(),
                'amount' => $amount,
                'coupon_id' => $coupon?->getKey(),
                'credit_applied' => $credit ?: null,
                'status' => (string) ($payment->status ?? 'pending'),
            ],
        );

        return response()->jsonSuccess([
            'status' => 'pix',
            'payment_id' => (string) $payment->id,
            'amount' => $amount,
            'expires_at' => $expiresAt->toIso8601String(),
            ...PixService::qrData($payment),
        ]);
    }

    /**
     * Situação de um pagamento pendente do próprio usuário. O painel consulta
     * enquanto mostra o QR do PIX, para avançar sozinho quando o pagamento cair.
     */
    public function paymentStatus(string $paymentId): JsonResponse
    {
        $pending = PendingPayment::where('authentication_id', auth()->id())
            ->where('payment_id', $paymentId)
            ->firstOrFail();

        // Gateway sem webhook de PIX (Efí, que exige mTLS na entrega) ou com
        // a notificação atrasada: enquanto o painel mostra o QR, cada consulta
        // pergunta ao gateway. Melhor esforço; a reconciliação cobre o resto.
        if ($pending->outcome === null && ($driver = GatewayRegistry::forPaymentId($paymentId)) && $driver->configured()) {
            try {
                if ($payment = $driver->findPayment($paymentId)) {
                    $this->paymentOutcome->apply($payment);
                    $pending->refresh();
                }
            } catch (\RuntimeException) {
                // Fica pendente; a próxima consulta tenta de novo.
            }
        }

        $status = match (true) {
            $pending->outcome === PendingPayment::OUTCOME_APPROVED => 'approved',
            $pending->outcome === PendingPayment::OUTCOME_REJECTED => 'rejected',
            default => 'pending',
        };

        return response()->jsonSuccess(['status' => $status]);
    }

    /**
     * Ativa o período de teste quando ele se aplica, ou devolve null.
     */
    private function trialFor(Plan $plan, array $validated): ?JsonResponse
    {
        $user = $this->payerUser();

        if ($plan->trial_days <= 0 || $user->trial_used_at !== null || (float) $plan->price <= 0) {
            return null;
        }

        // Quem já pagou alguma assinatura não é cliente novo, mesmo que a conta
        // seja anterior ao controle de teste (trial_used_at vazio).
        if (UserPlan::where('authentication_id', $user->getKey())->whereNotNull('payment_id')->exists()) {
            return null;
        }

        // Teste com cartão: sem cartão salvo a renovação no fim do teste não
        // teria como cobrar. Com o PIX ligado, quem não tem cartão recebe o PIX
        // da renovação por e-mail, então o cartão deixa de ser exigido.
        $hasCard = UserCard::where('authentication_id', $user->getKey())->chargeable()->exists();

        if (! $hasCard && ! $this->pixService->enabled()) {
            return null;
        }

        $userPlan = $user->subscribeToPlan($plan, [
            'is_trial' => true,
            'end_date' => now()->addDays($plan->trial_days),
        ]);

        $user->forceFill(['trial_used_at' => now()])->save();

        AuditLogger::record('billing.trial_started', $user, ['plan' => $plan->name, 'days' => $plan->trial_days]);

        return response()->jsonSuccess([
            'status' => 'trial',
            'message' => __('api-key::messages.trial_started', ['days' => $plan->trial_days]),
            'trial_ends_at' => $userPlan->end_date?->toIso8601String(),
        ]);
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

    private function syncCardAfterPayment(Authentication $user, object $payment, array $formData): ?UserCard
    {
        $mpCustomerId = (string) ($payment->payer?->id ?? '');
        if (! $mpCustomerId) {
            return null;
        }

        $mpCardId = $this->mpCustomerService->attachCard($mpCustomerId, $formData['token']);
        if (! $mpCardId) {
            return null;
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

        return $card;
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

        SystemHealthService::recordWebhook('mercadopago');

        $type = $request->input('type') ?? $request->input('topic');

        // Pagamentos Automáticos: a cobrança é uma order, e o perfil do cartão
        // muda de status por notificação própria.
        if ($type === 'order') {
            return $this->orderNotification($request);
        }

        if (is_string($type) && str_starts_with($type, 'payment_profile')) {
            $profileId = $request->input('data.id');

            if (is_string($profileId) && $profileId !== '') {
                try {
                    app(AutomaticPaymentService::class)->refreshProfile($profileId);
                } catch (\Exception $e) {
                    logglyWarning()->withContext(['error' => $e->getMessage()])->log('MP payment profile notification failed');
                }
            }

            return response()->json(['message' => 'ok']);
        }

        if ($type !== 'payment') {
            return response()->json(['message' => 'ok']);
        }

        // `resource` e a query `id` são os campos do IPN; `data.id` é o do
        // webhook assinado.
        $paymentId = $request->input('data.id')
            ?? $request->input('id')
            ?? $request->input('resource')
            ?? $request->query('id');

        // Notificação sem id utilizável nunca vai virar pagamento, por mais que
        // seja reentregue. Antes ia para `get(0)`, a exceção do SDK virava 500 e
        // o gateway reentregava para sempre.
        if (! is_scalar($paymentId) || ! ctype_digit((string) $paymentId)) {
            logglyWarning()->withContext([
                'payment_id' => is_scalar($paymentId) ? $paymentId : gettype($paymentId),
            ])->log('MP notification ignored: missing or malformed payment id');

            return response()->json(['message' => 'ok']);
        }

        MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));
        $client = new PaymentClient;

        try {
            $payment = $client->get((int) $paymentId);
        } catch (MPApiException $e) {
            $statusCode = $e->getApiResponse()->getStatusCode();

            // 404: o pagamento não existe nesta conta. Reentregar não muda isso.
            // Qualquer outra falha (gateway fora, timeout, token inválido) pode
            // passar, então devolve erro e deixa o Mercado Pago tentar de novo.
            if ($statusCode === 404) {
                logglyWarning()->withContext(['payment_id' => $paymentId])
                    ->log('MP notification ignored: payment not found on this account');

                return response()->json(['message' => 'ok']);
            }

            logglyError()->withContext([
                'payment_id' => $paymentId,
                'status' => $statusCode,
            ])->log('MP notification: payment lookup failed, asking for redelivery');

            return response()->json(['message' => 'retry'], 503);
        }

        // O que fazer com cada desfecho vive no serviço, e não aqui: o comando
        // de reconciliação precisa decidir exatamente a mesma coisa quando o
        // webhook não chega. A resposta é sempre 'ok' — o Mercado Pago reentrega
        // enquanto não receber 200, e um pagamento que não nos diz respeito não
        // é motivo para pedir reentrega.
        $this->paymentOutcome->apply($payment);

        return response()->json(['message' => 'ok']);
    }

    /**
     * Webhook dos outros gateways (`/checkout/webhook/{gateway}`).
     *
     * O driver confere a assinatura (ou a credencial da URL) e diz quais
     * pagamentos a notificação aponta. Cada um é consultado na API do gateway
     * e o pacote aplica o que a API diz: o corpo da notificação nunca aprova
     * nada sozinho.
     */
    public function gatewayWebhook(Request $request, string $gateway): JsonResponse
    {
        $driver = GatewayRegistry::get($gateway);

        if (! $driver) {
            return response()->json(['message' => 'not found'], 404);
        }

        try {
            $ids = $driver->webhookPaymentIds($request);
        } catch (AuthenticationException) {
            logglyWarning()->withContext(['gateway' => $gateway])->log('Gateway notification refused: invalid signature or credentials');

            return response()->json(['message' => 'unauthorized'], 401);
        } catch (\RuntimeException $e) {
            // Gateway que precisa consultar a notificação (Efí) e não respondeu.
            logglyError()->withContext(['gateway' => $gateway, 'error' => $e->getMessage()])
                ->log('Gateway notification: lookup failed, asking for redelivery');

            return response()->json(['message' => 'retry'], 503);
        }

        SystemHealthService::recordWebhook($gateway);

        if (! $driver->configured()) {
            if ($ids !== []) {
                logglyWarning()->withContext(['gateway' => $gateway, 'ids' => $ids])->log('Gateway notification ignored: gateway not configured');
            }

            return response()->json(['message' => 'ok']);
        }

        foreach (array_unique($ids) as $id) {
            if (! $driver->ownsPaymentId($id)) {
                continue;
            }

            try {
                $payment = $driver->findPayment($id);
            } catch (\RuntimeException $e) {
                logglyError()->withContext(['gateway' => $gateway, 'payment_id' => $id, 'error' => $e->getMessage()])
                    ->log('Gateway notification: lookup failed, asking for redelivery');

                return response()->json(['message' => 'retry'], 503);
            }

            if ($payment === null) {
                logglyWarning()->withContext(['gateway' => $gateway, 'payment_id' => $id])->log('Gateway notification ignored: payment not found on this account');

                continue;
            }

            $this->paymentOutcome->apply($payment);
        }

        return response()->json(['message' => 'ok']);
    }

    /**
     * Notificação de order (Pagamentos Automáticos): consulta a order e aplica
     * o desfecho pelo mesmo serviço dos pagamentos comuns.
     */
    private function orderNotification(Request $request): JsonResponse
    {
        $orderId = $request->input('data.id') ?? $request->query('data_id');

        if (! is_string($orderId) || ! preg_match('/^[A-Za-z0-9]+$/', $orderId)) {
            logglyWarning()->log('MP order notification ignored: missing or malformed order id');

            return response()->json(['message' => 'ok']);
        }

        try {
            $order = app(AutomaticPaymentService::class)->findOrder($orderId);
        } catch (\RuntimeException $e) {
            logglyError()->withContext(['order_id' => $orderId, 'error' => $e->getMessage()])
                ->log('MP order notification: lookup failed, asking for redelivery');

            return response()->json(['message' => 'retry'], 503);
        }

        if ($order === null) {
            logglyWarning()->withContext(['order_id' => $orderId])
                ->log('MP order notification ignored: order not found on this account');

            return response()->json(['message' => 'ok']);
        }

        $this->paymentOutcome->apply($order);

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
        // O Mercado Pago assina o id em minúsculas quando ele é alfanumérico.
        // Ids de pagamento são numéricos, mas outros tópicos não são.
        $dataId = strtolower((string) $request->query('data_id', $request->input('data.id', '')));

        $ts = $this->extractSignaturePart($signature, 'ts');
        $v1 = $this->extractSignaturePart($signature, 'v1');
        $hash = hash_hmac('sha256', "id:{$dataId};request-id:{$xRequestId};ts:{$ts};", (string) $secret);

        if (! hash_equals($hash, $v1)) {
            return false;
        }

        return $this->timestampIsFresh($ts);
    }

    /**
     * Se o `ts` assinado está dentro da tolerância configurada.
     *
     * O HMAC prova que a notificação veio do Mercado Pago, não que é recente:
     * sem isto, uma notificação capturada poderia ser reenviada a qualquer
     * momento. Aceita `ts` em segundos ou em milissegundos.
     */
    private function timestampIsFresh(string $ts): bool
    {
        $tolerance = (int) config('api-key.mercadopago.webhook_tolerance_seconds', 0);

        if ($tolerance <= 0) {
            return true;
        }

        if (! ctype_digit($ts)) {
            return false;
        }

        $seconds = (int) $ts;

        if ($seconds > 99_999_999_999) {
            $seconds = intdiv($seconds, 1000);
        }

        if (abs(time() - $seconds) > $tolerance) {
            logglyWarning()->withContext(['ts' => $ts, 'tolerance' => $tolerance])
                ->log('MP webhook rejected: signature timestamp outside tolerance');

            return false;
        }

        return true;
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
