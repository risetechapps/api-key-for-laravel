<?php

namespace RiseTechApps\ApiKey\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use RiseTechApps\ApiKey\Contracts\CardGateway;
use RiseTechApps\ApiKey\Gateways\GatewayRegistry;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Notifications\RenewalFailedNotification;
use RiseTechApps\ApiKey\Notifications\RenewalPaymentRequiredNotification;
use RiseTechApps\ApiKey\Services\AutomaticPaymentService;
use RiseTechApps\ApiKey\Services\MpCustomerService;
use RiseTechApps\ApiKey\Services\WebhookDispatcher;
use RiseTechApps\ApiKey\Traits\SendsIdempotentPayments;

/**
 * Charges the renewal of a single subscription.
 *
 * One plan per job so the daily billing run fans out across queue workers.
 * Running them inline meant two sequential Mercado Pago round-trips per
 * subscriber inside one command — at a few thousand subscribers the run no
 * longer fit in a day, and withoutOverlapping() would then skip the next one.
 */
class ProcessPlanRenewalJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SendsIdempotentPayments, SerializesModels;

    /**
     * Payments are never retried automatically: a timeout after the charge was
     * accepted upstream would double-bill. Failures are logged for manual review.
     */
    public int $tries = 1;

    public function __construct(public readonly string $userPlanId) {}

    /**
     * Guards against the same subscription being charged twice if the command is
     * run more than once in a day.
     */
    public function uniqueId(): string
    {
        return $this->userPlanId;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(MpCustomerService $mpService, ?AutomaticPaymentService $automatic = null): void
    {
        $automatic ??= app(AutomaticPaymentService::class);

        $userPlan = UserPlan::with(['authentication', 'plan'])->find($this->userPlanId);

        if (! $userPlan || ! $userPlan->active) {
            return;
        }

        // Re-checked here and not only in the command that dispatched it: the job
        // can sit in the queue while the subscriber cancels, and charging a card
        // after someone asked you to stop is the worst failure mode this has.
        if ($userPlan->isCancelled()) {
            logglyInfo()->withContext([
                'user_plan_id' => $this->userPlanId,
                'cancelled_at' => $userPlan->cancelled_at?->toIso8601String(),
            ])->log('billing renewal skipped: subscription cancelled');

            return;
        }

        $user = $userPlan->authentication;
        $plan = $userPlan->plan;

        if (! $user || ! $plan) {
            logglyWarning()->withContext([
                'user_plan_id' => $this->userPlanId,
            ])->log('billing renewal skipped: missing user or plan');

            return;
        }

        // As novas tentativas acabaram: a assinatura segue até o fim da carência
        // e expira. Só uma compra nova reabre a cobrança.
        if ($userPlan->renewal_failed_at !== null) {
            return;
        }

        $amount = (float) $plan->price;

        // Plano gratuito não passa pelo gateway, que recusaria cobrança de zero.
        if ($amount <= 0) {
            $user->subscribeToPlan($plan);

            return;
        }

        $card = UserCard::forCharging($user->getKey());

        // Sem cartão, ou assinante que paga com PIX: não há o que cobrar
        // sozinho. O assinante renova pelo painel. Quem tem cartão, mas só de
        // um gateway que não é mais o ativo, é avisado para cadastrar outro.
        if (! $card || $userPlan->payment_method === 'pix') {
            $reason = match (true) {
                $userPlan->payment_method === 'pix' => 'pix',
                UserCard::where('authentication_id', $user->getKey())->exists() => 'card_out_of_use',
                default => 'no_card',
            };

            $this->requirePayment($userPlan, $amount, $reason);

            return;
        }

        // Cartão de outro gateway (Pagar.me, Stripe, Efí, PagBank): cobrado
        // pelo cartão salvo lá, sem CVV, como recorrência.
        if ($driver = GatewayRegistry::get((string) $card->gateway)) {
            $this->chargeWithDriver($driver, $card, $userPlan, $amount);

            return;
        }

        // Pagamentos Automáticos: cobra pelo perfil do cartão, sem CVV. Null
        // quando o cartão não tem perfil ou a conta não foi autorizada pelo
        // Mercado Pago, e aí a renovação segue pelo caminho antigo abaixo.
        $order = $automatic->charge(
            $card,
            $amount,
            "rn-{$userPlan->getKey()}",
            $this->idempotencyKey(null, ['automatic', ...$this->renewalKeyParts($userPlan)]),
        );

        if ($order !== null) {
            $this->handlePayment($order, $userPlan, $amount);

            return;
        }

        MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));

        try {
            $token = $mpService->tokenizeRecurring($card->mp_customer_id, $card->mp_card_id);
        } catch (MPApiException|\RuntimeException $e) {
            // Cartão removido ou vencido no cofre do gateway: tentar de novo com
            // ele não muda nada, o assinante precisa trocar de cartão.
            $this->registerFailure($userPlan, $amount, __('api-key::messages.renewal_card_unusable'));

            return;
        }

        $renewalPayload = [
            'transaction_amount' => $amount,
            'token' => $token,
            'installments' => 1,
            'payment_method_id' => $card->brand,
            'payer' => [
                'id' => $card->mp_customer_id,
                'email' => strtolower((string) $user->email),
            ],
            'description' => __('api-key::messages.plan_renewal_description', ['plan' => $plan->name]),
            'external_reference' => "renewal|{$user->getKey()}|{$plan->getKey()}|{$userPlan->getKey()}",
            'statement_descriptor' => mb_substr((string) config('app.name') ?: 'Assinatura', 0, 22),
            'additional_info' => [
                'items' => [
                    [
                        'id' => (string) $plan->getKey(),
                        'title' => __('api-key::messages.plan_renewal_description', ['plan' => $plan->name]),
                        'description' => $plan->description ?? __('api-key::messages.plan_renewal_description', ['plan' => $plan->name]),
                        'category_id' => 'services',
                        'quantity' => 1,
                        'unit_price' => $amount,
                    ],
                ],
            ],
        ];

        if ($notificationUrl = $this->notificationUrl()) {
            $renewalPayload['notification_url'] = $notificationUrl;
        }

        // Sem device id: a renovacao roda em fila, sem navegador do assinante
        // para coletar a impressao do dispositivo.
        try {
            $payment = $this->createPayment($renewalPayload, $userPlan);
        } catch (MPApiException $e) {
            // Conta do Mercado Pago que exige o CVV em toda cobrança: sem o
            // titular presente não há como cobrar, e tentar de novo dá o mesmo
            // erro. Vai para o pagamento pelo painel, sem novas tentativas.
            if (self::isCvvRequired($e)) {
                $this->requirePayment($userPlan, $amount, 'cvv_required');

                return;
            }

            logglyWarning()->withContext([
                'user_plan_id' => $this->userPlanId,
                'body' => $e->getApiResponse()->getContent(),
            ])->log('billing renewal refused by gateway');

            $this->registerFailure($userPlan, $amount, null);

            return;
        }

        $this->handlePayment($payment, $userPlan, $amount);
    }

    /**
     * Renovação no cartão salvo em outro gateway. Sem credencial dele (o
     * admin trocou de gateway e apagou a chave), o cartão não tem como ser
     * cobrado e o assinante renova pelo painel.
     *
     * Falha sem resposta do gateway sobe: não dá para saber se cobrou, e o job
     * (tries = 1) registra para conferência em vez de tentar de novo.
     */
    private function chargeWithDriver(CardGateway $driver, UserCard $card, UserPlan $userPlan, float $amount): void
    {
        if (! $driver->configured()) {
            $this->requirePayment($userPlan, $amount, 'cvv_required');

            return;
        }

        $user = $userPlan->authentication;
        $plan = $userPlan->plan;

        $payment = $driver->chargeCard(
            $card,
            $amount,
            "renewal|{$user->getKey()}|{$plan->getKey()}|{$userPlan->getKey()}",
            __('api-key::messages.plan_renewal_description', ['plan' => $plan->name]),
            $this->idempotencyKey(null, [$driver->name(), ...$this->renewalKeyParts($userPlan)]),
        );

        $this->handlePayment($payment, $userPlan, $amount);
    }

    private function createPayment(array $renewalPayload, UserPlan $userPlan): object
    {
        return new PaymentClient()->create($renewalPayload, $this->idempotentRequest(null, $this->renewalKeyParts($userPlan)));
    }

    /**
     * O que identifica esta cobrança para a chave de idempotência.
     *
     * @return list<string|null>
     */
    private function renewalKeyParts(UserPlan $userPlan): array
    {
        return [
            // ShouldBeUnique stops the same job running twice at once, and
            // tries = 1 stops a retry after a timeout. Neither covers the gap
            // where the charge is accepted, the write that records it fails, and
            // uniqueFor (1h) then lets a later run dispatch the same renewal
            // again. The gateway settles that one.
            //
            // Keyed on the period being renewed, never on the subscription alone:
            // next month's renewal is a different charge and must go through.
            //
            // A tentativa entra na chave a partir da segunda: com a mesma chave o
            // gateway devolveria a recusa da primeira, sem cobrar de novo.
            'renewal',
            $this->userPlanId,
            $userPlan->end_date?->toDateString(),
            ...($userPlan->renewal_attempts > 0 ? ['attempt-'.$userPlan->renewal_attempts] : []),
        ];
    }

    private function handlePayment(object $payment, UserPlan $userPlan, float $amount): void
    {
        $user = $userPlan->authentication;
        $plan = $userPlan->plan;

        if ($payment->status === 'approved') {
            $user->subscribeToPlan($plan, [
                'payment_id' => (string) $payment->id,
                'payment_amount' => $amount,
                'payment_method' => 'card',
            ]);

            logglyInfo()->withContext([
                'user_id' => $user->getKey(),
                'plan' => $plan->name,
                'payment_id' => $payment->id,
            ])->log('billing renewal approved');

            return;
        }

        // Em análise: o gateway decide depois e avisa por webhook. Registrar a
        // espera é o que permite ao `api-key:reconcile-payments` alcançá-la se
        // esse webhook não chegar — caso em que o assinante teria pago sem a
        // renovação acontecer, e ninguém perceberia: este job já terminou e o
        // log abaixo diria "not approved", que naquele instante era verdade.
        //
        // Sem cupom aqui: renovação cobra o preço cheio, então não há reserva a
        // devolver como no checkout.
        if (in_array($payment->status ?? '', ['pending', 'in_process'], true)) {
            PendingPayment::create([
                'authentication_id' => $user->getKey(),
                'plan_id' => $plan->getKey(),
                'payment_id' => (string) $payment->id,
                'amount' => $amount,
                'status' => (string) $payment->status,
            ]);

            logglyInfo()->withContext([
                'user_id' => $user->getKey(),
                'user_plan_id' => $this->userPlanId,
                'status' => $payment->status,
            ])->log('billing renewal pending');

            return;
        }

        logglyWarning()->withContext([
            'user_id' => $user->getKey(),
            'user_plan_id' => $this->userPlanId,
            'status' => $payment->status,
            'status_detail' => $payment->status_detail ?? null,
        ])->log('billing renewal not approved');

        $this->registerFailure($userPlan, $amount, $this->rejectionReason($payment->status_detail ?? null));
    }

    /**
     * Se a recusa é a da conta que exige CVV em toda cobrança com cartão
     * salvo (`security_code_id can't be null`, código 3031). Só a solução de
     * Pagamentos Automáticos do Mercado Pago cobra sem CVV.
     */
    public static function isCvvRequired(MPApiException $e): bool
    {
        $content = $e->getApiResponse()->getContent();
        $text = strtolower(json_encode($content) ?: '');

        return str_contains($text, 'security_code_id') || str_contains($text, '"code":3031');
    }

    /**
     * Renovação que só o assinante pode concluir, pelo painel.
     *
     * Encerra a cobrança automática deste período (sem novas tentativas: o
     * motivo não muda sozinho) e avisa uma vez. A assinatura segue até o fim
     * da carência; se ele renovar antes, nada se perde.
     */
    private function requirePayment(UserPlan $userPlan, float $amount, string $reason): void
    {
        $alreadyAsked = $userPlan->renewal_requires_payment !== null;

        $userPlan->update([
            'renewal_requires_payment' => $reason,
            'renewal_failed_at' => now(),
            'next_renewal_attempt_at' => null,
        ]);

        logglyInfo()->withContext([
            'user_plan_id' => $userPlan->getKey(),
            'reason' => $reason,
        ])->log('billing renewal requires payment in the dashboard');

        $user = $userPlan->authentication;
        $plan = $userPlan->plan;

        if ($alreadyAsked || ! $user || ! $plan) {
            return;
        }

        $graceDays = (int) config('api-key.grace_period_days', 3);
        $accessUntil = $userPlan->end_date?->copy()->addDays($graceDays);

        $notification = config('api-key.notifications.renewal_payment_required', RenewalPaymentRequiredNotification::class);
        $user->notify(new $notification($plan, $amount, $accessUntil, $reason));

        WebhookDispatcher::dispatch($user, 'subscription.payment_required', [
            'subscription_id' => $userPlan->getKey(),
            'plan' => $plan->name,
            'amount' => $amount,
            'reason' => $reason,
            'access_until' => $accessUntil?->toIso8601String(),
        ]);
    }

    /** Motivo da recusa em texto, quando o pacote sabe traduzir o código. */
    private function rejectionReason(?string $detail): ?string
    {
        if (! $detail) {
            return null;
        }

        $key = "api-key::messages.payment_rejected.{$detail}";
        $message = __($key);

        return $message === $key ? null : $message;
    }

    /**
     * Agenda a próxima tentativa (ou desiste) e avisa o assinante.
     *
     * As datas contam a partir do vencimento, e não da tentativa anterior: o
     * assinante recebe datas previsíveis, e uma tentativa disparada fora de
     * hora (troca de cartão) não empurra o calendário para frente.
     */
    private function registerFailure(UserPlan $userPlan, float $amount, ?string $reason): void
    {
        $attempts = $userPlan->renewal_attempts + 1;
        $retryDays = array_values((array) config('api-key.billing.retry_days', []));

        $next = null;

        if ($attempts <= count($retryDays) && $userPlan->end_date) {
            $next = $userPlan->end_date->copy()->startOfDay()->addDays((int) $retryDays[$attempts - 1]);

            if ($next->isPast()) {
                $next = now()->addDay()->startOfDay();
            }
        }

        $userPlan->update([
            'renewal_attempts' => $attempts,
            'next_renewal_attempt_at' => $next,
            'renewal_failed_at' => $next ? null : now(),
        ]);

        logglyWarning()->withContext([
            'user_plan_id' => $userPlan->getKey(),
            'attempt' => $attempts,
            'next_attempt_at' => $next?->toIso8601String(),
        ])->log($next ? 'billing renewal retry scheduled' : 'billing renewal retries exhausted');

        $user = $userPlan->authentication;
        $plan = $userPlan->plan;

        if ($user && $plan) {
            $notification = config('api-key.notifications.renewal_failed', RenewalFailedNotification::class);
            $user->notify(new $notification($plan, $amount, $attempts, $next, $reason));

            WebhookDispatcher::dispatch($user, 'subscription.renewal_failed', [
                'plan' => $plan->name,
                'amount' => $amount,
                'attempt' => $attempts,
                'next_attempt_at' => $next?->toIso8601String(),
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        logglyError()->withContext([
            'user_plan_id' => $this->userPlanId,
            'error' => $e->getMessage(),
        ])->log('billing renewal failed');
    }
}
