<?php

namespace RiseTechApps\ApiKey\Listeners;

use Illuminate\Support\Facades\Log;
use RiseTechApps\ApiKey\Events\PlanExpired;
use RiseTechApps\ApiKey\Notifications\PlanExpiredNotification;

class SendPlanExpiredNotification
{
    public function handle(PlanExpired $event): void
    {
        $event->user->notify(new PlanExpiredNotification(
            $event->plan,
            $event->userPlan
        ));

        Log::warning('Plan expired notification sent', [
            'user_id'    => $event->user->id,
            'plan_id'    => $event->plan->id,
            'expired_at' => $event->expiredAt->format('Y-m-d H:i:s'),
        ]);
    }
}
