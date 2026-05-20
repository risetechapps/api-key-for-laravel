<?php

namespace RiseTechApps\ApiKey\Services;

use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

class PlanService
{
    /**
     * Subscribe a user to a plan.
     *
     * @param Authentication $user
     * @param Plan $plan
     * @return UserPlan
     */
    public function subscribe(Authentication $user, Plan $plan): UserPlan
    {
        return $user->subscribeToPlan($plan);
    }

    /**
     * Check if user has reached request limit.
     */
    public function hasReachedLimit(Authentication $user): bool
    {
        $activePlan = $user->activePlan()->with('plan')->first();

        if (!$activePlan || !$activePlan->plan) {
            return false;
        }

        $requestsLimit = (int) $activePlan->plan->request_limit;

        if ($requestsLimit <= 0) {
            return false; // Unlimited
        }

        return $activePlan->requests_used >= $requestsLimit;
    }

    /**
     * Increment request usage for the user.
     */
    public function incrementUsage(Authentication $user, int $statusCode = 0): void
    {
        $user->requestUsed($statusCode);
    }

    /**
     * Get remaining requests for the user.
     */
    public function getRemainingRequests(Authentication $user): ?int
    {
        $activePlan = $user->activePlan()->with('plan')->first();

        if (!$activePlan || !$activePlan->plan) {
            return null;
        }

        $limit = (int) $activePlan->plan->request_limit;

        if ($limit <= 0) {
            return null; // Unlimited
        }

        return max(0, $limit - $activePlan->requests_used);
    }
}
