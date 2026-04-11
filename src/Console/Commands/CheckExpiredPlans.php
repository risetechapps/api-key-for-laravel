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
            // Only notify about plans entering grace period today
            $graceStartPlans = UserPlan::where('active', true)
                ->whereDate('end_date', '=', now()->toDateString())
                ->with(['authentication', 'plan'])
                ->get();

            foreach ($graceStartPlans as $userPlan) {
                if ($userPlan->authentication && $userPlan->plan) {
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
            }

            return self::SUCCESS;
        }

        // Get plans that are completely expired (past grace period)
        $gracePeriodEndDate = now()->subDays($graceDays);

        $expiredPlans = UserPlan::where('active', true)
            ->where('end_date', '<', $gracePeriodEndDate)
            ->with(['authentication', 'plan'])
            ->get();

        $count = 0;
        foreach ($expiredPlans as $userPlan) {
            // Check if completely expired (past grace period)
            if ($userPlan->isCompletelyExpired()) {
                // Deactivate the plan
                $userPlan->update(['active' => false]);

                // Disable the API key
                if ($userPlan->authentication?->apiKey) {
                    $userPlan->authentication->apiKey->update(['active' => false]);
                }

                // Fire the PlanExpired event
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
                    "Deactivated expired plan: {$userPlan->plan->name} for user {$userPlan->authentication?->email}"
                );
            }
        }

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
