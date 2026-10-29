<?php

namespace RiseTechApps\ApiKey\Support;

use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Services\OverageBillingService;

/**
 * A cota do período como o cliente da API a enxerga.
 *
 * Fonte única dos headers `X-Quota-*` (CheckRequestLimitMiddleware) e do
 * `GET /usage` (UsageController): os dois nunca podem dar números diferentes
 * para a mesma assinatura.
 */
class Quota
{
    /**
     * Headers de cota para uma resposta da API do cliente.
     *
     * Plano sem limite (`request_limit` 0) não tem `X-Quota-Limit` nem
     * `X-Quota-Remaining`: não há número honesto para eles. `X-Quota-Reset` é
     * o fim do período em epoch (segundos), quando o contador volta a zero na
     * renovação. No excedente, `X-Quota-Used` passa do limite e o restante
     * fica em 0.
     *
     * @return array<string, string>
     */
    public static function headers(UserPlan $userPlan): array
    {
        $limit = self::limit($userPlan);
        $used = (int) $userPlan->requests_used;

        $headers = [];

        if ($limit !== null) {
            $headers['X-Quota-Limit'] = (string) $limit;
            $headers['X-Quota-Remaining'] = (string) max(0, $limit - $used);
        }

        $headers['X-Quota-Used'] = (string) $used;

        if ($userPlan->end_date) {
            $headers['X-Quota-Reset'] = (string) $userPlan->end_date->getTimestamp();
        }

        return $headers;
    }

    /**
     * Retrato do uso para o `GET /usage`.
     *
     * @return array<string, mixed>
     */
    public static function report(UserPlan $userPlan): array
    {
        $plan = $userPlan->plan;
        $limit = self::limit($userPlan);
        $used = (int) $userPlan->requests_used;
        $overagePrice = $plan?->overage_price;
        $graceEnds = $userPlan->getGracePeriodEndDate();

        return [
            'plan' => [
                'id' => $plan?->getKey(),
                'name' => $plan?->name,
                'billing_cycle' => $plan?->billing_cycle?->value,
            ],
            'period' => [
                'start' => $userPlan->start_date?->toIso8601String(),
                'end' => $userPlan->end_date?->toIso8601String(),
                'status' => $userPlan->isExpired() ? 'grace_period' : 'active',
                'grace_period_ends_at' => $userPlan->isExpired() ? $graceEnds?->toIso8601String() : null,
                'trial' => (bool) $userPlan->is_trial,
                'cancelled' => $userPlan->isCancelled(),
            ],
            'requests' => [
                'limit' => $limit,
                'used' => $used,
                'remaining' => $limit === null ? null : max(0, $limit - $used),
                'resets_at' => $userPlan->end_date?->toIso8601String(),
            ],
            'overage' => [
                'available' => $overagePrice !== null && $limit !== null,
                'enabled' => (bool) $userPlan->authentication?->overage_enabled,
                'requests' => OverageBillingService::overageRequests($userPlan),
                'max_requests' => $overagePrice !== null && $limit !== null ? OverageBillingService::extraAllowed($userPlan) : 0,
                'price_per_request' => $overagePrice === null ? null : (float) $overagePrice,
                'amount' => OverageBillingService::overageAmount($userPlan),
            ],
        ];
    }

    /** Limite do período, ou null quando o plano não tem limite. */
    private static function limit(UserPlan $userPlan): ?int
    {
        $limit = (int) ($userPlan->plan->request_limit ?? 0);

        return $limit > 0 ? $limit : null;
    }
}
