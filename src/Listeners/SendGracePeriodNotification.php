<?php

namespace RiseTechApps\ApiKey\Listeners;

use Illuminate\Support\Facades\Log;
use RiseTechApps\ApiKey\Events\PlanGracePeriodStarted;
use RiseTechApps\ApiKey\Notifications\GracePeriodStartedNotification;

class SendGracePeriodNotification
{
    public function handle(PlanGracePeriodStarted $event): void
    {
        $event->user->notify(new GracePeriodStartedNotification(
            $event->plan,
            $event->userPlan,
            $event->gracePeriodDays,
            $event->gracePeriodEndDate
        ));

        Log::info('Grace period notification sent', [
            'user_id'            => $event->user->id,
            'plan_id'            => $event->plan->id,
            'grace_period_days'  => $event->gracePeriodDays,
            'grace_period_end'   => $event->gracePeriodEndDate->format('Y-m-d'),
        ]);
    }
}
