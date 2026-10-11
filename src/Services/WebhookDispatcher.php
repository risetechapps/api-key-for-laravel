<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Support\Str;
use RiseTechApps\ApiKey\Jobs\DeliverWebhookJob;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Webhook\WebhookDelivery;
use RiseTechApps\ApiKey\Models\Webhook\WebhookEndpoint;
use Throwable;

/**
 * Enfileira um evento para os webhooks da conta.
 *
 * Grava a entrega antes de enfileirar: o histórico mostra o evento mesmo que
 * a fila esteja fora, e a tentativa seguinte sabe o que reenviar.
 */
class WebhookDispatcher
{
    /** Eventos que um endpoint pode assinar. */
    public const EVENTS = [
        'subscription.activated',
        'subscription.changed',
        'subscription.cancelled',
        'subscription.expired',
        'subscription.grace_period_started',
        'subscription.refunded',
        'subscription.renewal_failed',
        'subscription.payment_required',
        'payment.rejected',
        'billing.overage_charged',
        'usage.threshold_reached',
        'usage.limit_reached',
        'usage.overage_started',
        'webhook.test',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return int Quantas entregas foram enfileiradas.
     */
    public static function dispatch(Authentication $user, string $event, array $data): int
    {
        if (! config('api-key.webhooks.enabled', true)) {
            return 0;
        }

        try {
            $endpoints = WebhookEndpoint::where('authentication_id', $user->getKey())
                ->where('active', true)
                ->get()
                ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->listensTo($event));

            foreach ($endpoints as $endpoint) {
                $delivery = WebhookDelivery::create([
                    'webhook_endpoint_id' => $endpoint->getKey(),
                    'event' => $event,
                    'payload' => [
                        'id' => 'evt_'.Str::lower((string) Str::ulid()),
                        'event' => $event,
                        'created_at' => now()->toIso8601String(),
                        'data' => $data,
                    ],
                    'status' => WebhookDelivery::PENDING,
                ]);

                $job = new DeliverWebhookJob($delivery->getKey());

                if ($queue = config('api-key.webhooks.queue')) {
                    $job->onQueue($queue);
                }

                dispatch($job);
            }

            return $endpoints->count();
        } catch (Throwable $e) {
            // Webhook do cliente é acessório: nunca derruba a operação que o
            // gerou (um pagamento aprovado, uma assinatura ativada).
            report($e);

            return 0;
        }
    }
}
