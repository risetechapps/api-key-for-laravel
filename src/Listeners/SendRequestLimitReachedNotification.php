<?php

namespace RiseTechApps\ApiKey\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RiseTechApps\ApiKey\Events\RequestLimitReached;
use RiseTechApps\ApiKey\Notifications\RequestLimitReachedNotification;

class SendRequestLimitReachedNotification
{
    public function handle(RequestLimitReached $event): void
    {
        // O evento dispara a CADA requisição bloqueada enquanto o limite estiver
        // estourado. Para não enviar um e-mail por requisição, notifica no máximo
        // uma vez a cada 24h por assinatura. Cache::add é atômico (só o primeiro
        // a inserir a chave segue adiante).
        $key = 'api-key:limit-notified:' . $event->userPlan->getKey();

        if (! Cache::add($key, true, now()->addDay())) {
            return;
        }

        $notification = config('api-key.notifications.limit_reached', RequestLimitReachedNotification::class);

        $event->user->notify(new $notification(
            $event->plan,
            $event->userPlan,
            $event->requestsUsed,
            $event->requestsLimit
        ));

        Log::info('Request limit reached notification sent', [
            'user_id'        => $event->user->id,
            'plan_id'        => $event->plan->id,
            'requests_used'  => $event->requestsUsed,
            'requests_limit' => $event->requestsLimit,
        ]);
    }
}
