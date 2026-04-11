<?php

namespace RiseTechApps\ApiKey\Listeners;

use RiseTechApps\ApiKey\Events\PlanExpired;

class SendPlanExpiredNotification
{
    /**
     * Handle the event.
     */
    public function handle(PlanExpired $event): void
    {
        // Example: Send email notification to user about plan expiration
        // $event->user->notify(new PlanExpiredNotification($event->plan));

        // Log the plan expiration
        \Illuminate\Support\Facades\Log::warning('Plan completely expired', [
            'user_id' => $event->user->id,
            'plan_id' => $event->plan->id,
            'plan_name' => $event->plan->name,
            'expired_at' => $event->expiredAt->toDateTimeString(),
        ]);
    }
}
