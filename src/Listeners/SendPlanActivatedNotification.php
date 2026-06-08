<?php

namespace RiseTechApps\ApiKey\Listeners;

use Illuminate\Support\Facades\Log;
use RiseTechApps\ApiKey\Events\PlanChanged;
use RiseTechApps\ApiKey\Notifications\PlanActivatedNotification;

class SendPlanActivatedNotification
{
    public function handle(PlanChanged $event): void
    {
        $event->user->notify(new PlanActivatedNotification(
            $event->plan,
            $event->userPlan,
            $event->previousPlan
        ));

        Log::info('Plan activated notification sent', [
            'user_id'          => $event->user->id,
            'plan_id'          => $event->plan->id,
            'previous_plan_id' => $event->previousPlan?->id,
        ]);
    }
}
