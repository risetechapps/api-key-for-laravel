<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Support\Carbon;
use RiseTechApps\ApiKey\Enums\BillingCycle;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

/**
 * Números do negócio para o painel admin.
 *
 * Calculados em PHP sobre conjuntos pequenos (assinaturas ativas, períodos
 * recentes), para funcionar igual em PostgreSQL, MySQL e no SQLite dos testes
 * sem SQL de data específico de cada banco.
 */
class MetricsService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $now = now();
        $since = $now->copy()->subDays(30);

        $active = UserPlan::with('plan')
            ->where('active', true)
            ->where('end_date', '>=', $now)
            ->whereNull('refunded_at')
            ->get();

        $paid = $active->filter(fn (UserPlan $p) => ! $p->is_trial && (float) $p->plan?->price > 0);

        $mrr = round($paid->sum(fn (UserPlan $p) => $this->monthly($p)), 2);

        $byPlan = $paid->groupBy('plan_id')->map(fn ($group) => [
            'plan' => $group->first()->plan?->name,
            'subscribers' => $group->count(),
            'mrr' => round($group->sum(fn (UserPlan $p) => $this->monthly($p)), 2),
        ])->sortByDesc('mrr')->values()->all();

        $activeUserIds = $active->pluck('authentication_id')->unique();

        // Saíram nos últimos 30 dias: período encerrado e nenhuma assinatura
        // ativa hoje. Renovação conta como continuidade, não como saída.
        $churned = UserPlan::where('end_date', '>=', $since)
            ->where('end_date', '<', $now)
            ->where('is_trial', false)
            ->whereNotNull('payment_id')
            ->whereNotIn('authentication_id', $activeUserIds)
            ->distinct()
            ->count('authentication_id');

        $base = $paid->pluck('authentication_id')->unique()->count() + $churned;

        $revenue30 = (float) UserPlan::whereNotNull('payment_id')
            ->whereNull('refunded_at')
            ->where('start_date', '>=', $since)
            ->sum('payment_amount')
            + (float) UserPlan::whereNotNull('overage_paid_at')
                ->where('overage_paid_at', '>=', $since)
                ->sum('overage_amount');

        $refunds30 = (float) UserPlan::whereNotNull('refunded_at')
            ->where('refunded_at', '>=', $since)
            ->sum('payment_amount');

        return [
            'mrr' => $mrr,
            'arr' => round($mrr * 12, 2),
            'active_subscribers' => $paid->pluck('authentication_id')->unique()->count(),
            'active_trials' => $active->where('is_trial', true)->count(),
            'new_subscribers_30d' => $this->newSubscribers($since),
            'churned_30d' => $churned,
            'churn_rate_30d' => $base > 0 ? round($churned / $base * 100, 1) : 0.0,
            'revenue_30d' => round($revenue30, 2),
            'refunds_30d' => round($refunds30, 2),
            'trial_conversion_90d' => $this->trialConversion($now->copy()->subDays(90)),
            'renewals_in_retry' => UserPlan::where('active', true)
                ->whereNotNull('next_renewal_attempt_at')
                ->whereNull('renewal_failed_at')
                ->count(),
            'by_plan' => $byPlan,
            'revenue_by_month' => $this->revenueByMonth(12),
        ];
    }

    /** Valor mensal equivalente de uma assinatura. */
    private function monthly(UserPlan $userPlan): float
    {
        $plan = $userPlan->plan;

        if (! $plan || ! $plan->billing_cycle) {
            return 0.0;
        }

        $days = BillingCycle::convertInDays($plan->billing_cycle);

        return (float) $plan->price * 30 / $days;
    }

    /** Contas cuja primeira assinatura paga começou no período. */
    private function newSubscribers(Carbon $since): int
    {
        $recent = UserPlan::whereNotNull('payment_id')
            ->where('start_date', '>=', $since)
            ->pluck('authentication_id')
            ->unique();

        if ($recent->isEmpty()) {
            return 0;
        }

        $returning = UserPlan::whereNotNull('payment_id')
            ->where('start_date', '<', $since)
            ->whereIn('authentication_id', $recent)
            ->distinct()
            ->pluck('authentication_id');

        return $recent->diff($returning)->count();
    }

    /**
     * Testes encerrados no período que viraram assinatura paga.
     *
     * @return array{trials: int, converted: int, rate: float}
     */
    private function trialConversion(Carbon $since): array
    {
        $trials = UserPlan::where('is_trial', true)
            ->where('end_date', '>=', $since)
            ->where('end_date', '<', now())
            ->get(['authentication_id', 'end_date']);

        $converted = $trials->filter(fn (UserPlan $t) => UserPlan::where('authentication_id', $t->authentication_id)
            ->whereNotNull('payment_id')
            ->where('start_date', '>=', $t->end_date->copy()->subDay())
            ->exists()
        )->count();

        return [
            'trials' => $trials->count(),
            'converted' => $converted,
            'rate' => $trials->count() > 0 ? round($converted / $trials->count() * 100, 1) : 0.0,
        ];
    }

    /**
     * @return list<array{month: string, revenue: float}>
     */
    private function revenueByMonth(int $months): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);

        $rows = UserPlan::whereNotNull('payment_id')
            ->whereNull('refunded_at')
            ->where('start_date', '>=', $start)
            ->get(['start_date', 'payment_amount']);

        $series = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $series[$month->format('Y-m')] = 0.0;
        }

        foreach ($rows as $row) {
            $key = $row->start_date?->format('Y-m');

            if ($key !== null && isset($series[$key])) {
                $series[$key] += (float) $row->payment_amount;
            }
        }

        return array_map(
            fn ($month, $revenue) => ['month' => $month, 'revenue' => round($revenue, 2)],
            array_keys($series),
            array_values($series),
        );
    }
}
