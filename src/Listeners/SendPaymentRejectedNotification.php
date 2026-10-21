<?php

namespace RiseTechApps\ApiKey\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RiseTechApps\ApiKey\Events\PaymentRejected;
use RiseTechApps\ApiKey\Notifications\PaymentRejectedNotification;

class SendPaymentRejectedNotification implements ShouldQueue
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

    public function handle(PaymentRejected $event): void
    {
        $notification = config('api-key.notifications.payment_rejected', PaymentRejectedNotification::class);

        $event->user->notify(new $notification(
            $event->plan,
            $event->amount,
            $event->reason,
            $event->isRenewal
        ));

        logglyInfo()->withContext([
            'user_id' => $event->user->id,
            'plan_id' => $event->plan->id,
            'payment_id' => $event->paymentId,
        ])->log('Payment rejected notification sent');
    }
}
