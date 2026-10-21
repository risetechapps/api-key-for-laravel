<?php

namespace RiseTechApps\ApiKey\Listeners;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use RiseTechApps\ApiKey\Events\PaymentRejected;
use RiseTechApps\ApiKey\Events\PlanCancelled;
use RiseTechApps\ApiKey\Events\PlanChanged;
use RiseTechApps\ApiKey\Events\PlanExpired;
use RiseTechApps\ApiKey\Events\PlanGracePeriodStarted;
use RiseTechApps\ApiKey\Events\PlanRefunded;
use RiseTechApps\ApiKey\Events\PlanUsageThresholdReached;
use RiseTechApps\ApiKey\Events\RequestLimitReached;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Services\WebhookDispatcher;

/**
 * Traduz os eventos do pacote em webhooks para a conta afetada.
 */
class DispatchAccountWebhooks
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            PlanChanged::class => 'onPlanChanged',
            PlanCancelled::class => 'onPlanCancelled',
            PlanExpired::class => 'onPlanExpired',
            PlanGracePeriodStarted::class => 'onGracePeriod',
            PlanRefunded::class => 'onRefunded',
            PaymentRejected::class => 'onPaymentRejected',
            PlanUsageThresholdReached::class => 'onThreshold',
            RequestLimitReached::class => 'onLimit',
        ];
    }

    public function onPlanChanged(PlanChanged $e): void
    {
        WebhookDispatcher::dispatch($e->user, $e->previousPlan ? 'subscription.changed' : 'subscription.activated', [
            ...$this->subscription($e->userPlan, $e->plan->name),
            'previous_plan' => $e->previousPlan?->name,
        ]);
    }

    public function onPlanCancelled(PlanCancelled $e): void
    {
        WebhookDispatcher::dispatch($e->user, 'subscription.cancelled', [
            ...$this->subscription($e->userPlan, $e->plan->name),
            'access_until' => $e->accessUntil->format(DATE_ATOM),
        ]);
    }

    public function onPlanExpired(PlanExpired $e): void
    {
        WebhookDispatcher::dispatch($e->user, 'subscription.expired', $this->subscription($e->userPlan, $e->plan->name));
    }

    public function onGracePeriod(PlanGracePeriodStarted $e): void
    {
        WebhookDispatcher::dispatch($e->user, 'subscription.grace_period_started', [
            ...$this->subscription($e->userPlan, $e->plan->name),
            'grace_period_ends_at' => $e->gracePeriodEndDate->format(DATE_ATOM),
        ]);
    }

    public function onRefunded(PlanRefunded $e): void
    {
        WebhookDispatcher::dispatch($e->user, 'subscription.refunded', [
            ...$this->subscription($e->userPlan, $e->plan->name),
            'amount' => $e->amount,
        ]);
    }

    public function onPaymentRejected(PaymentRejected $e): void
    {
        WebhookDispatcher::dispatch($e->user, 'payment.rejected', [
            'plan' => $e->plan->name,
            'payment_id' => $e->paymentId,
            'amount' => $e->amount,
            'renewal' => $e->isRenewal,
        ]);
    }

    /**
     * Os eventos de uso disparam a cada requisição dentro da faixa; o webhook
     * sai uma vez por período.
     */
    public function onThreshold(PlanUsageThresholdReached $e): void
    {
        if ($this->once('threshold', $e->userPlan)) {
            WebhookDispatcher::dispatch($e->user, 'usage.threshold_reached', [
                ...$this->subscription($e->userPlan, $e->plan->name),
                'requests_used' => $e->requestsUsed,
                'requests_limit' => $e->requestsLimit,
                'threshold_percent' => $e->threshold,
            ]);
        }
    }

    public function onLimit(RequestLimitReached $e): void
    {
        if ($this->once('limit', $e->userPlan)) {
            WebhookDispatcher::dispatch($e->user, 'usage.limit_reached', [
                ...$this->subscription($e->userPlan, $e->plan->name),
                'requests_used' => $e->requestsUsed,
                'requests_limit' => $e->requestsLimit,
            ]);
        }
    }

    private function once(string $kind, UserPlan $userPlan): bool
    {
        $ttl = $userPlan->end_date && $userPlan->end_date->isFuture() ? $userPlan->end_date : now()->addDay();

        return Cache::add("api-key:webhook-{$kind}:{$userPlan->getKey()}", true, $ttl);
    }

    /** @return array<string, mixed> */
    private function subscription(UserPlan $userPlan, string $planName): array
    {
        return [
            'subscription_id' => $userPlan->getKey(),
            'plan' => $planName,
            'start_date' => $userPlan->start_date?->toIso8601String(),
            'end_date' => $userPlan->end_date?->toIso8601String(),
            'trial' => (bool) $userPlan->is_trial,
        ];
    }
}
