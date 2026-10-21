<?php

namespace RiseTechApps\ApiKey\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RiseTechApps\ApiKey\Events\PlanRefunded;
use RiseTechApps\ApiKey\Notifications\PlanRefundedNotification;

class SendPlanRefundedNotification implements ShouldQueue
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

    public function handle(PlanRefunded $event): void
    {
        $notification = config('api-key.notifications.plan_refunded', PlanRefundedNotification::class);

        $event->user->notify(new $notification(
            $event->plan,
            $event->userPlan,
            $event->amount
        ));

        logglyInfo()->withContext([
            'user_id' => $event->user->id,
            'plan_id' => $event->plan->id,
            'amount' => $event->amount,
            'refund_id' => $event->refundId,
        ])->log('Plan refunded notification sent');
    }
}
