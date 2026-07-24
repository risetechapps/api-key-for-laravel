<?php

namespace RiseTechApps\ApiKey\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RiseTechApps\ApiKey\Events\PlanExpired;
use RiseTechApps\ApiKey\Notifications\PlanExpiredNotification;

class SendPlanExpiredNotification implements ShouldQueue
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

    public function handle(PlanExpired $event): void
    {
        $notification = config('api-key.notifications.plan_expired', PlanExpiredNotification::class);

        $event->user->notify(new $notification(
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
