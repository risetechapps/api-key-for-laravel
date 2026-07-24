<?php

namespace RiseTechApps\ApiKey\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RiseTechApps\ApiKey\Events\PlanGracePeriodStarted;
use RiseTechApps\ApiKey\Notifications\GracePeriodStartedNotification;

class SendGracePeriodNotification implements ShouldQueue
{
    use InteractsWithQueue;

    // Roteia para a conexão observada pelo Horizon (por padrão redis); sem isto
    // o job iria para o QUEUE_CONNECTION default (database) e não seria processado.
    public function viaConnection(): ?string
    {
        return config('api-key.queue.connection');
    }

    public function viaQueue(): ?string
    {
        return config('api-key.queue.name');
    }

    public function handle(PlanGracePeriodStarted $event): void
    {
        $notification = config('api-key.notifications.grace_period', GracePeriodStartedNotification::class);

        $event->user->notify(new $notification(
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
