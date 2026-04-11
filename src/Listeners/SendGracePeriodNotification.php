<?php

namespace RiseTechApps\ApiKey\Listeners;

use RiseTechApps\ApiKey\Events\PlanGracePeriodStarted;

class SendGracePeriodNotification
{
    /**
     * Handle the event.
     */
    public function handle(PlanGracePeriodStarted $event): void
    {
        // Example: Send email notification to user
        // $event->user->notify(new GracePeriodStartedNotification(
        //     $event->plan,
        //     $event->gracePeriodDays,
        //     $event->gracePeriodEndDate
        // ));

        // Log the grace period start
        \Illuminate\Support\Facades\Log::info('Plan grace period started', [
            'user_id' => $event->user->id,
            'plan_id' => $event->plan->id,
            'plan_name' => $event->plan->name,
            'grace_period_days' => $event->gracePeriodDays,
            'grace_period_end' => $event->gracePeriodEndDate->toDateTimeString(),
        ]);
    }
}
