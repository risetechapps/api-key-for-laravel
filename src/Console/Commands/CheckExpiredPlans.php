<?php

namespace RiseTechApps\ApiKey\Console\Commands;

use Illuminate\Console\Command;
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
            // Only notify about plans entering grace period today.
            // Chunked, and with a sargable range instead of whereDate(), which
            // wraps the column in a function and defeats the index.
            UserPlan::where('active', true)
                ->whereBetween('end_date', [now()->startOfDay(), now()->endOfDay()])
                ->with(['authentication', 'plan'])
                ->chunkById(200, function ($plans) use ($graceDays) {
                    foreach ($plans as $userPlan) {
                        if (! $userPlan->authentication || ! $userPlan->plan) {
                            continue;
                        }

                        PlanGracePeriodStarted::dispatch(
                            $userPlan->authentication,
                            $userPlan,
                            $userPlan->plan,
                            $graceDays,
                            now()->addDays($graceDays)
                        );

                        $this->info(
                            "Plan grace period started: {$userPlan->plan->name} for user {$userPlan->authentication->email}"
                        );
                    }
                });

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
            ->with(['authentication.apiKey', 'plan'])
            ->chunkById(200, function ($plans) use (&$count) {
                foreach ($plans as $userPlan) {
                    $userPlan->update(['active' => false]);

                    if ($userPlan->authentication?->apiKey) {
                        $userPlan->authentication->apiKey->update(['active' => false]);
                    }

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
