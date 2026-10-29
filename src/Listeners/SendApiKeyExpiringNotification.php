<?php

namespace RiseTechApps\ApiKey\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RiseTechApps\ApiKey\Events\ApiKeyExpiring;
use RiseTechApps\ApiKey\Notifications\ApiKeyExpiringNotification;

class SendApiKeyExpiringNotification implements ShouldQueue
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

    public function handle(ApiKeyExpiring $event): void
    {
        $notification = config('api-key.notifications.api_key_expiring', ApiKeyExpiringNotification::class);

        $event->user->notify(new $notification($event->apiKey, $event->daysLeft));

        logglyInfo()->withContext([
            'user_id' => $event->user->id,
            'api_key_id' => $event->apiKey->id,
            'days_left' => $event->daysLeft,
        ])->log('API key expiring notification sent');
    }
}
