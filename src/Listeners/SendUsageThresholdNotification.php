<?php

namespace RiseTechApps\ApiKey\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RiseTechApps\ApiKey\Events\PlanUsageThresholdReached;
use RiseTechApps\ApiKey\Notifications\UsageThresholdNotification;

// Enfileirado: o evento dispara no hot path (toda requisição na faixa de aviso),
// e o envio do e-mail (SMTP) não pode bloquear a resposta HTTP.
class SendUsageThresholdNotification implements ShouldQueue
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
