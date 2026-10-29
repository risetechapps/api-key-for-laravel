<?php

namespace RiseTechApps\ApiKey\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use RiseTechApps\ApiKey\Events\PlanExpired;
use RiseTechApps\ApiKey\Events\PlanGracePeriodStarted;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

class CheckExpiredPlans extends Command
{
    protected $signature = 'api-key:check-expired-plans {--grace-only : Only check for plans entering grace period}';

    protected $description = 'Check and process expired subscription plans with grace period support';

    public function handle(): int
    {
        $graceDays = Config::get('api-key.grace_period_days', 3);

        if ($this->option('grace-only')) {
            if ($graceDays <= 0) {
                $this->info('Grace period is disabled (grace_period_days = 0); nothing to notify.');

                return self::SUCCESS;
            }

            // Venceu nas últimas 24h e continua ativa: não foi renovada. A
            // renovação (08:00) substitui a assinatura por uma nova e desativa
            // esta, então rodar depois dela é o que separa "renovou" de
            // "entrou em carência". Antes a janela era o dia de hoje, inteiro:
            // avisava de manhã quem só vencia à tarde e ainda ia ser renovado.
            // Range sargable e em lotes, como antes.
            $notified = 0;

            UserPlan::where('active', true)
                ->where('end_date', '>=', now()->subDay())
                ->where('end_date', '<', now())
                ->with(['authentication', 'plan'])
                ->chunkById(200, function ($plans) use ($graceDays, &$notified) {
                    foreach ($plans as $userPlan) {
                        if (! $userPlan->authentication || ! $userPlan->plan) {
                            continue;
                        }

                        // Uma vez por assinatura: o agendamento é diário, mas
                        // uma execução manual a mais não repete o e-mail.
                        $graceEnds = $userPlan->end_date->copy()->addDays($graceDays);

                        if (! Cache::add('api-key:grace-notified:'.$userPlan->getKey(), true, $graceEnds->copy()->addDay())) {
                            continue;
                        }

                        PlanGracePeriodStarted::dispatch(
                            $userPlan->authentication,
                            $userPlan,
                            $userPlan->plan,
                            $graceDays,
                            $graceEnds
                        );

                        $notified++;

                        $this->info(
                            "Plan grace period started: {$userPlan->plan->name} for user {$userPlan->authentication->email}"
                        );
                    }
                });

            $this->info("Notified {$notified} plan(s) entering grace period.");

            return self::SUCCESS;
        }

        // Get plans that are completely expired (past grace period)
        $gracePeriodEndDate = now()->subDays($graceDays);

        $count = 0;

        // Chunked: a backlog (a few missed runs, or a first run on an existing
        // database) would otherwise load every expired plan, with its user and
        // plan eager loaded, into memory at once.
        //
        // The SQL predicate already guarantees the grace period has passed, so
        // the previous isCompletelyExpired() re-check in PHP was redundant.
        UserPlan::where('active', true)
            ->where('end_date', '<', $gracePeriodEndDate)
            // Renovação recusada com nova tentativa marcada: encerrar agora
            // faria o job da tentativa desistir (assinatura inativa) e o
            // assinante perderia a chance que o e-mail acabou de prometer.
            ->where(fn ($q) => $q->whereNull('next_renewal_attempt_at')->orWhereNotNull('renewal_failed_at'))
            ->with(['authentication.apiKeys', 'plan'])
            ->chunkById(200, function ($plans) use (&$count) {
                foreach ($plans as $userPlan) {
                    $userPlan->update(['active' => false]);

                    // Todas as chaves da conta, não só a principal.
                    $userPlan->authentication?->apiKeys->each->update(['active' => false]);

                    if ($userPlan->authentication && $userPlan->plan) {
                        PlanExpired::dispatch(
                            $userPlan->authentication,
                            $userPlan,
                            $userPlan->plan,
                            now()
                        );
                        $count++;
                    }

                    $this->info(
                        "Deactivated expired plan: {$userPlan->plan?->name} for user {$userPlan->authentication?->email}"
                    );
                }
            });

        $this->info("Processed {$count} completely expired plans.");

        // Show grace period warnings
        $gracePeriodPlans = UserPlan::where('active', true)
            ->where('end_date', '<', now())
            ->where('end_date', '>=', $gracePeriodEndDate)
            ->count();

        if ($gracePeriodPlans > 0) {
            $this->warn("{$gracePeriodPlans} plan(s) currently in grace period.");
        }

        return self::SUCCESS;
    }
}
