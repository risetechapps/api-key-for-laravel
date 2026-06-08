<?php

namespace RiseTechApps\ApiKey\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RiseTechApps\ApiKey\Events\PlanUsageThresholdReached;
use RiseTechApps\ApiKey\Notifications\UsageThresholdNotification;

class SendUsageThresholdNotification
{
    public function handle(PlanUsageThresholdReached $event): void
    {
        // O evento dispara em toda requisição dentro da faixa de aviso (ex.: 80-99%).
        // Envia no máximo uma vez por período do plano: a chave expira junto com o
        // ciclo atual (end_date), então uma nova assinatura volta a avisar.
        $key = 'api-key:usage-threshold-notified:' . $event->userPlan->getKey();

        $ttl = $event->userPlan->end_date && $event->userPlan->end_date->isFuture()
            ? $event->userPlan->end_date
            : now()->addDay();

        if (! Cache::add($key, true, $ttl)) {
            return;
        }

        $notification = config('api-key.notifications.usage_threshold', UsageThresholdNotification::class);

        $event->user->notify(new $notification(
            $event->plan,
            $event->userPlan,
            $event->requestsUsed,
            $event->requestsLimit,
            $event->threshold
        ));

        Log::info('Usage threshold notification sent', [
            'user_id'        => $event->user->id,
            'plan_id'        => $event->plan->id,
            'requests_used'  => $event->requestsUsed,
            'requests_limit' => $event->requestsLimit,
            'threshold'      => $event->threshold,
        ]);
    }
}
