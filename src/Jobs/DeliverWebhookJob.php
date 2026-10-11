<?php

namespace RiseTechApps\ApiKey\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use RiseTechApps\ApiKey\Models\Webhook\WebhookDelivery;
use RiseTechApps\ApiKey\Support\WebhookUrlGuard;
use RuntimeException;
use Throwable;

/**
 * Entrega um evento a um webhook do cliente.
 *
 * Assinatura: `X-Webhook-Signature: sha256=<hmac>` sobre "<timestamp>.<corpo>",
 * com o segredo do endpoint; `X-Webhook-Timestamp` permite ao cliente recusar
 * reenvios antigos. Qualquer 2xx conta como entregue. Falha volta para a fila
 * com espera crescente; depois da última, a entrega fica como falha e o
 * endpoint é desligado se acumular falhas seguidas demais.
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public function __construct(public readonly string $deliveryId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 21600];
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::with('endpoint')->find($this->deliveryId);
        $endpoint = $delivery?->endpoint;

        if (! $delivery || ! $endpoint || ! $endpoint->active || $delivery->status === WebhookDelivery::DELIVERED) {
            return;
        }

        $delivery->increment('attempts');

        // Tentar de novo não muda o destino: falha na hora, sem novas tentativas.
        if (! WebhookUrlGuard::isAllowed($endpoint->url)) {
            $delivery->update(['error' => 'URL not allowed (private network or invalid scheme)']);
            $this->fail(new RuntimeException('Webhook URL not allowed'));

            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $endpoint->secret);

        try {
            $response = Http::withoutRedirecting()
                ->timeout((int) config('api-key.webhooks.timeout', 10))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => config('app.name', 'Laravel').'-Webhooks/1.0',
                    'X-Webhook-Id' => $delivery->payload['id'] ?? $delivery->getKey(),
                    'X-Webhook-Event' => $delivery->event,
                    'X-Webhook-Timestamp' => $timestamp,
                    'X-Webhook-Signature' => 'sha256='.$signature,
                ])
                ->withBody((string) $body, 'application/json')
                ->post($endpoint->url);
        } catch (Throwable $e) {
            $this->recordAttempt($delivery, null, $e->getMessage());

            throw $e;
        }

        if ($response->successful()) {
            $delivery->update([
                'status' => WebhookDelivery::DELIVERED,
                'response_code' => $response->status(),
                'error' => null,
                'delivered_at' => now(),
                'next_attempt_at' => null,
            ]);

            $endpoint->update(['consecutive_failures' => 0]);

            return;
        }

        $this->recordAttempt($delivery, $response->status(), mb_substr($response->body(), 0, 500));

        throw new RuntimeException("Webhook endpoint answered {$response->status()}");
    }

    public function failed(?Throwable $e): void
    {
        $delivery = WebhookDelivery::with('endpoint')->find($this->deliveryId);

        if (! $delivery || $delivery->status === WebhookDelivery::DELIVERED) {
            return;
        }

        $this->markFailed($delivery, $delivery->response_code, $delivery->error ?? $e?->getMessage());
    }

    private function recordAttempt(WebhookDelivery $delivery, ?int $status, ?string $error): void
    {
        $backoff = $this->backoff();
        $wait = $backoff[min($delivery->attempts - 1, count($backoff) - 1)] ?? null;

        $delivery->update([
            'response_code' => $status,
            'error' => $error,
            'next_attempt_at' => $delivery->attempts < $this->tries && $wait ? now()->addSeconds($wait) : null,
        ]);
    }

    private function markFailed(WebhookDelivery $delivery, ?int $status, ?string $error): void
    {
        $delivery->update([
            'status' => WebhookDelivery::FAILED,
            'response_code' => $status,
            'error' => $error,
            'next_attempt_at' => null,
        ]);

        $endpoint = $delivery->endpoint;

        if (! $endpoint) {
            return;
        }

        $endpoint->increment('consecutive_failures');

        // Endpoint que só falha vira ruído na fila para sempre. Desligado,
        // continua listado no painel com o motivo, e o cliente reativa.
        if ($endpoint->consecutive_failures >= (int) config('api-key.webhooks.disable_after_failures', 20)) {
            $endpoint->update(['active' => false, 'disabled_at' => now()]);
        }
    }
}
