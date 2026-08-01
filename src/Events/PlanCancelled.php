<?php

namespace RiseTechApps\ApiKey\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

/**
 * The subscriber asked not to be charged again.
 *
 * Distinct from PlanExpired: nothing has been taken away yet. The paid period
 * keeps running to `accessUntil`, and only the renewal was called off.
 */
class PlanCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Authentication $user,
        public readonly UserPlan $userPlan,
        public readonly Plan $plan,
        public readonly \DateTimeInterface $accessUntil
    ) {}
}
