<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Support\Collection;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use RiseTechApps\ApiKey\Gateways\GatewayRegistry;
use RiseTechApps\ApiKey\Jobs\ProcessPlanRenewalJob;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Notifications\OverageChargeNotification;
use RiseTechApps\ApiKey\Traits\SendsIdempotentPayments;

/**
 * Cobra as requisições além do limite de um período encerrado.
 *
 * Roda depois do fim do período, e não na hora do uso: uma cobrança por
 * período, com o total fechado. Cartão salvo primeiro; sem cartão (ou com
 * conta que exige CVV), o assinante é avisado e paga pelo painel. Nada de
 * cobrança por e-mail.
 */
class OverageBillingService
{
    use SendsIdempotentPayments;

    public function __construct(
        private readonly MpCustomerService $mpService,
        private readonly PixService $pixService,
        private readonly AutomaticPaymentService $automatic,
    ) {}

    /**
     * Quantas requisições além do limite esta assinatura pode fazer.
     *
     * 0 quando não há excedente: plano sem preço de excedente, plano sem
     * limite, ou assinante que não ligou o excedente (é desligado por padrão).
     * Null quando não há teto nenhum. Senão, o menor entre o teto do plano e o
     * teto de gasto do assinante convertido em requisições.
     */
    public static function extraAllowed(UserPlan $userPlan): ?int
    {
        $plan = $userPlan->plan;
        $user = $userPlan->authentication;

        if (! $plan || $plan->overage_price === null || (int) $plan->request_limit <= 0) {
            return 0;
        }

        if (! $user || ! $user->overage_enabled) {
            return 0;
        }

        $price = (float) $plan->overage_price;
        $caps = array_filter([
            $plan->overage_max_requests !== null ? (int) $plan->overage_max_requests : null,
            $user->overage_spend_cap !== null && $price > 0 ? (int) floor((float) $user->overage_spend_cap / $price) : null,
        ], fn ($cap) => $cap !== null);

        return $caps === [] ? null : min($caps);
    }

    /** Requisições além do limite no período. */
    public static function overageRequests(UserPlan $userPlan): int
    {
        $limit = (int) ($userPlan->plan->request_limit ?? 0);

        return $limit > 0 ? max(0, (int) $userPlan->requests_used - $limit) : 0;
    }

    /** Valor do excedente, já arredondado. */
    public static function overageAmount(UserPlan $userPlan): float
    {
        $price = $userPlan->plan?->overage_price;

        return $price === null ? 0.0 : round(self::overageRequests($userPlan) * (float) $price, 2);
    }

    /**
     * @return string Desfecho: `none`, `waived`, `paid`, `pending`,
     *                `payment_required` ou `rejected`.
     */
    public function bill(UserPlan $userPlan): string
    {
        $userPlan->loadMissing(['plan', 'authentication']);

        $requests = self::overageRequests($userPlan);
        $amount = self::overageAmount($userPlan);
        $user = $userPlan->authentication;
        $plan = $userPlan->plan;

        $userPlan->update([
            'overage_requests' => $requests,
            'overage_amount' => $amount,
            'overage_billed_at' => now(),
        ]);

        if ($requests === 0 || ! $user || ! $plan) {
            return 'none';
        }

        // Abaixo do mínimo que o gateway aceita cobrar: perdoado, e registrado.
        if ($amount < (float) config('api-key.billing.overage_minimum_charge', 1.00)) {
            return 'waived';
        }

        $reference = "overage|{$user->getKey()}|{$plan->getKey()}|{$userPlan->getKey()}";

        $card = UserCard::forCharging($user->getKey());

        if ($card) {
            return $this->chargeCard($userPlan, $card, $amount, $requests, $reference);
        }

        // Sem cartão: o assinante paga pelo painel. O e-mail só avisa.
        $this->notify($userPlan, 'payment_required', $amount, $requests);

        return 'payment_required';
    }

    /**
     * Excedente em aberto: períodos já faturados, com valor, ainda não pagos.
     *
     * @return Collection<int, UserPlan>
     */
    public static function unpaid(string $authenticationId)
    {
        return UserPlan::with('plan')
            ->where('authentication_id', $authenticationId)
            ->whereNotNull('overage_billed_at')
            ->whereNull('overage_paid_at')
            ->where('overage_amount', '>', 0)
            ->orderBy('end_date')
            ->get();
    }

    /**
     * Gera o PIX de um excedente em aberto, a pedido do assinante no painel.
     *
     * @return array<string, mixed> QR e dados para o painel acompanhar o pagamento.
     */
    public function payWithPix(UserPlan $userPlan): array
    {
        $userPlan->loadMissing(['plan', 'authentication']);
        $amount = (float) $userPlan->overage_amount;
        $expiresAt = PixService::expiresAt();

        $payment = $this->pixService->createCharge(
            $userPlan->authentication,
            $userPlan->plan,
            $amount,
            "overage|{$userPlan->authentication_id}|{$userPlan->plan_id}|{$userPlan->getKey()}",
            $expiresAt,
            // Um QR por hora: clique duplo devolve o mesmo, e quem volta depois
            // de o QR expirar recebe outro.
            ['overage-pix', $userPlan->getKey(), now()->format('YmdH')],
        );

        $this->recordPending($userPlan, $payment, $amount);

        return [
            'status' => 'pix',
            'payment_id' => (string) $payment->id,
            'amount' => $amount,
            'expires_at' => $expiresAt->toIso8601String(),
            ...PixService::qrData($payment),
        ];
    }

    /**
     * Paga um excedente em aberto com um cartão salvo, a pedido do assinante no
     * painel. O resultado volta na tela, então não há e-mail.
     *
     * @param  string|null  $cvvToken  Mercado Pago: token gerado no navegador
     *                                 com o CVV, para cartão que não cobra sozinho.
     * @param  string|null  $clientKey  Uma por tentativa: depois de uma recusa, a
     *                                  próxima tentativa é outra cobrança.
     * @return string `paid`, `pending`, `rejected`, `cvv_required` ou `error`.
     */
    public function payWithCard(UserPlan $userPlan, UserCard $card, ?string $cvvToken = null, ?string $clientKey = null): string
    {
        $userPlan->loadMissing(['plan', 'authentication']);

        $requests = (int) $userPlan->overage_requests;
        $reference = "overage|{$userPlan->authentication_id}|{$userPlan->plan_id}|{$userPlan->getKey()}";
        $attempt = ['panel', $clientKey ?: now()->format('YmdHis')];

        $state = $this->chargeCard($userPlan, $card, (float) $userPlan->overage_amount, $requests, $reference, $cvvToken, $attempt, notify: false);

        // A cobrança automática chama de `payment_required` o cartão que pede
        // CVV; aqui o assinante está na tela e pode digitá-lo.
        return $state === 'payment_required' ? 'cvv_required' : $state;
    }

    /**
     * @param  list<string>  $attempt  Entra nas chaves de idempotência: vazio na
     *                                 cobrança agendada (uma por período).
     */
    private function chargeCard(UserPlan $userPlan, UserCard $card, float $amount, int $requests, string $reference, ?string $cvvToken = null, array $attempt = [], bool $notify = true): string
    {
        $user = $userPlan->authentication;
        $plan = $userPlan->plan;
        $notify = $notify
            ? fn (string $state) => $this->notify($userPlan, $state, $amount, $requests)
            : fn (string $state) => null;

        // Cartão de outro gateway: cobrado pelo cartão salvo lá, sem CVV.
        if ($driver = GatewayRegistry::get((string) $card->gateway)) {
            if (! $driver->configured()) {
                $notify('payment_required');

                return 'payment_required';
            }

            try {
                $payment = $driver->chargeCard(
                    $card,
                    $amount,
                    $reference,
                    __('api-key::messages.overage_description', ['plan' => $userPlan->plan?->name, 'requests' => $requests]),
                    $this->idempotencyKey(null, [$driver->name(), 'overage', $userPlan->getKey(), ...$attempt]),
                );
            } catch (\RuntimeException $e) {
                logglyError()->withContext(['user_plan_id' => $userPlan->getKey(), 'gateway' => $driver->name(), 'error' => $e->getMessage()])
                    ->log('overage card charge failed without a gateway answer');

                return 'error';
            }

            return $this->settle($userPlan, $payment, $amount, $requests, $notify);
        }

        // Pagamentos Automáticos primeiro: pelo perfil do cartão, sem CVV. Null
        // quando não há perfil ou a conta não foi autorizada, e aí segue pelo
        // caminho antigo. Com o CVV digitado agora, vai direto pelo token dele.
        try {
            $payment = $cvvToken ? null : $this->automatic->charge(
                $card,
                $amount,
                "ov-{$userPlan->getKey()}",
                $this->idempotencyKey(null, ['automatic', 'overage', $userPlan->getKey(), ...$attempt]),
            );
        } catch (\RuntimeException $e) {
            // Sem resposta do gateway não dá para saber se cobrou. Fica em
            // aberto no painel em vez de virar recusa, e o log pede conferência.
            logglyError()->withContext(['user_plan_id' => $userPlan->getKey(), 'error' => $e->getMessage()])
                ->log('overage automatic charge failed without a gateway answer');

            return 'error';
        }

        if ($payment !== null) {
            return $this->settle($userPlan, $payment, $amount, $requests, $notify);
        }

        MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));

        try {
            $token = $cvvToken ?: $this->mpService->tokenizeRecurring($card->mp_customer_id, $card->mp_card_id);

            $description = __('api-key::messages.overage_description', ['plan' => $plan->name, 'requests' => $requests]);

            $payload = [
                'transaction_amount' => $amount,
                'token' => $token,
                'installments' => 1,
                'payment_method_id' => $card->brand,
                'payer' => ['id' => $card->mp_customer_id, 'email' => strtolower((string) $user->email)],
                'description' => $description,
                'external_reference' => $reference,
                'statement_descriptor' => mb_substr((string) config('app.name') ?: 'Assinatura', 0, 22),
                'additional_info' => ['items' => [[
                    'id' => 'overage-'.$plan->getKey(),
                    'title' => $description,
                    'category_id' => 'services',
                    'quantity' => 1,
                    'unit_price' => $amount,
                ]]],
            ];

            if ($notificationUrl = $this->notificationUrl()) {
                $payload['notification_url'] = $notificationUrl;
            }

            // Uma cobrança de excedente por período, nunca duas.
            $payment = (new PaymentClient)->create($payload, $this->idempotentRequest(null, ['overage', $userPlan->getKey(), ...$attempt]));
        } catch (MPApiException|\RuntimeException $e) {
            logglyWarning()->withContext([
                'user_plan_id' => $userPlan->getKey(),
                'error' => $e instanceof MPApiException ? $e->getApiResponse()->getContent() : $e->getMessage(),
            ])->log('overage charge refused');

            // Conta que exige CVV: não é recusa do cartão, é falta do titular.
            $state = $e instanceof MPApiException && ProcessPlanRenewalJob::isCvvRequired($e)
                ? 'payment_required'
                : 'rejected';

            $notify($state);

            return $state;
        }

        return $this->settle($userPlan, $payment, $amount, $requests, $notify);
    }

    /** Aplica o desfecho da cobrança no cartão, venha ela da API antiga ou da Orders API. */
    /** @param  (callable(string): mixed)|null  $notify */
    private function settle(UserPlan $userPlan, object $payment, float $amount, int $requests, ?callable $notify = null): string
    {
        $notify ??= fn (string $state) => $this->notify($userPlan, $state, $amount, $requests);

        if ($payment->status === 'approved') {
            $userPlan->update(['overage_payment_id' => (string) $payment->id, 'overage_paid_at' => now()]);
            $notify('paid');

            return 'paid';
        }

        if (in_array($payment->status ?? '', ['pending', 'in_process'], true)) {
            $this->recordPending($userPlan, $payment, $amount);

            return 'pending';
        }

        $userPlan->update(['overage_payment_id' => $payment->id !== null ? (string) $payment->id : null]);
        $notify('rejected');

        return 'rejected';
    }

    /**
     * Aprovação vinda do webhook ou da reconciliação.
     */
    public static function markPaid(string $userPlanId, string $paymentId): bool
    {
        return UserPlan::whereKey($userPlanId)
            ->whereNull('overage_paid_at')
            ->update(['overage_payment_id' => $paymentId, 'overage_paid_at' => now()]) > 0;
    }

    private function recordPending(UserPlan $userPlan, object $payment, float $amount): void
    {
        $userPlan->update(['overage_payment_id' => (string) $payment->id]);

        PendingPayment::firstOrCreate(
            ['payment_id' => (string) $payment->id],
            [
                'authentication_id' => $userPlan->authentication_id,
                'plan_id' => $userPlan->plan_id,
                'amount' => $amount,
                'status' => (string) ($payment->status ?? 'pending'),
            ],
        );
    }

    private function notify(UserPlan $userPlan, string $state, float $amount, int $requests): void
    {
        $notification = config('api-key.notifications.overage_charge', OverageChargeNotification::class);

        $userPlan->authentication?->notify(new $notification($userPlan->plan, $state, $amount, $requests));

        if ($userPlan->authentication) {
            WebhookDispatcher::dispatch($userPlan->authentication, 'billing.overage_charged', [
                'plan' => $userPlan->plan?->name,
                'state' => $state,
                'amount' => $amount,
                'requests' => $requests,
            ]);
        }
    }
}
