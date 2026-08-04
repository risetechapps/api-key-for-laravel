<?php

namespace RiseTechApps\ApiKey\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RiseTechApps\ApiKey\Events\PlanChanged;
use RiseTechApps\ApiKey\Notifications\PlanActivatedNotification;

class SendPlanActivatedNotification implements ShouldQueue
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

    public function handle(PlanChanged $event): void
    {
        $notification = config('api-key.notifications.plan_activated', PlanActivatedNotification::class);

        $event->user->notify(new $notification(
            $event->plan,
            $event->userPlan,
            $event->previousPlan
        ));

        logglyInfo()->withContext([
            'user_id' => $event->user->id,
            'plan_id' => $event->plan->id,
            'previous_plan_id' => $event->previousPlan?->id,
        ])->log('Plan activated notification sent');
    }
}
