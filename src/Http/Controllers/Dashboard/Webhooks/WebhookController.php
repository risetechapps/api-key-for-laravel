<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RiseTechApps\ApiKey\Jobs\DeliverWebhookJob;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Webhook\WebhookDelivery;
use RiseTechApps\ApiKey\Models\Webhook\WebhookEndpoint;
use RiseTechApps\ApiKey\Services\AuditLogger;
use RiseTechApps\ApiKey\Services\WebhookDispatcher;
use RiseTechApps\ApiKey\Support\WebhookUrlGuard;

/**
 * Webhooks de saída da conta.
 *
 * Um segredo por conta assina todas as entregas: as dos endpoints cadastrados
 * e as enviadas para URLs avulsas (WebhookDispatcher::dispatchTo()).
 */
class WebhookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $endpoints = WebhookEndpoint::where('authentication_id', $this->owner($request)->getKey())
            ->latest()
            ->get()
            ->map(fn (WebhookEndpoint $e) => $this->present($e));

        return response()->jsonSuccess([
            'endpoints' => $endpoints->values()->all(),
            'available_events' => array_values(array_diff(WebhookDispatcher::events(), ['webhook.test'])),
            // Mesmos eventos, com a descrição para o painel (null: só o nome).
            'events_catalog' => collect(WebhookDispatcher::catalog())
                ->except('webhook.test')
                ->map(fn (?string $description, string $name) => ['name' => $name, 'description' => $description])
                ->values()
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->owner($request);
        $data = $this->validated($request, creating: true);

        $max = (int) config('api-key.webhooks.max_per_user', 5);

        if (WebhookEndpoint::where('authentication_id', $user->getKey())->count() >= $max) {
            return response()->jsonError(__('api-key::messages.webhook_limit_reached', ['max' => $max]));
        }

        $endpoint = WebhookEndpoint::create([
            ...$data,
            'authentication_id' => $user->getKey(),
            'active' => true,
        ]);

        AuditLogger::record('webhook.created', $user, ['webhook_id' => $endpoint->getKey(), 'url' => $endpoint->url]);

        // `secret` é o da conta, o mesmo de todos os endpoints. Fica na resposta
        // para quem consumia a rota quando cada endpoint tinha o seu.
        return response()->jsonSuccess([...$this->present($endpoint), 'secret' => $user->webhookSecret()]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $endpoint = $this->find($request, $id);
        $data = $this->validated($request, creating: false);

        // Reativar zera o contador de falhas: o cliente diz que consertou.
        if (($data['active'] ?? false) && ! $endpoint->active) {
            $data['consecutive_failures'] = 0;
            $data['disabled_at'] = null;
        }

        $endpoint->update($data);

        return response()->jsonSuccess($this->present($endpoint->fresh()));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $endpoint = $this->find($request, $id);
        $endpoint->delete();

        AuditLogger::record('webhook.deleted', $this->owner($request), ['webhook_id' => $id, 'url' => $endpoint->url]);

        return response()->jsonSuccess(['message' => __('api-key::messages.webhook_deleted')]);
    }

    /**
     * Obsoleta desde a 1.3.0: o segredo é da conta, e esta rota troca o da
     * conta inteira (todos os endpoints e URLs avulsas). Use
     * `POST dashboard/webhooks/secret/rotate`.
     */
    public function rotateSecret(Request $request, string $id): JsonResponse
    {
        $endpoint = $this->find($request, $id);
        $secret = $this->rotate($request);

        return response()->jsonSuccess([...$this->present($endpoint), 'secret' => $secret]);
    }

    /** O segredo que assina todos os webhooks da conta. */
    public function secret(Request $request): JsonResponse
    {
        return response()->jsonSuccess(['secret' => $this->owner($request)->webhookSecret()]);
    }

    /** Troca o segredo da conta. O anterior deixa de valer na hora. */
    public function rotateAccountSecret(Request $request): JsonResponse
    {
        return response()->jsonSuccess(['secret' => $this->rotate($request)]);
    }

    /**
     * Envia um `webhook.test` para uma URL qualquer da conta (não precisa estar
     * cadastrada) e espera a resposta, para o painel mostrar o resultado na hora.
     *
     * É o teste das URLs que o cliente informa por pedido, como o `webhook_url`
     * de uma geração: mesmo envelope, mesma assinatura das entregas reais.
     */
    public function testUrl(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'url', 'max:2048']]);

        if (! WebhookUrlGuard::isAllowed($data['url'])) {
            return $this->urlNotAllowed();
        }

        $user = $this->owner($request);

        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => null,
            'authentication_id' => $user->getKey(),
            'url' => $data['url'],
            'event' => 'webhook.test',
            'payload' => WebhookDispatcher::envelope('webhook.test', ['message' => 'Teste de webhook']),
            'status' => WebhookDelivery::PENDING,
        ]);

        // Uma tentativa só, aqui mesmo: o painel quer o resultado agora, e um
        // teste que falhou não deve ficar sendo reenviado.
        $job = new DeliverWebhookJob($delivery->getKey());
        $job->tries = 1;

        try {
            $job->handle();
        } catch (\Throwable $e) {
            $job->failed($e);
        }

        // Fora da fila, o fail() do guard de URL não chama failed(): a entrega
        // ficaria "na fila" para sempre no histórico.
        if ($delivery->refresh()->status === WebhookDelivery::PENDING) {
            $job->failed(null);
            $delivery->refresh();
        }

        return response()->jsonSuccess([
            'delivery_id' => $delivery->getKey(),
            'delivered' => $delivery->status === WebhookDelivery::DELIVERED,
            'http_status' => $delivery->response_code,
            'error' => $delivery->error,
        ]);
    }

    /** Últimas 50 entregas para URLs avulsas da conta. */
    public function directDeliveries(Request $request): JsonResponse
    {
        $deliveries = WebhookDelivery::where('authentication_id', $this->owner($request)->getKey())
            ->whereNull('webhook_endpoint_id')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (WebhookDelivery $d) => [...$this->presentDelivery($d), 'url' => $d->url]);

        return response()->jsonSuccess($deliveries->values()->all());
    }

    public function redeliverDirect(Request $request, string $deliveryId): JsonResponse
    {
        $delivery = WebhookDelivery::where('authentication_id', $this->owner($request)->getKey())
            ->whereNull('webhook_endpoint_id')
            ->findOrFail($deliveryId);

        return $this->requeue($delivery);
    }

    private function rotate(Request $request): string
    {
        $user = $this->owner($request);
        $secret = $user->rotateWebhookSecret();

        AuditLogger::record('webhook.secret_rotated', $user, []);

        return $secret;
    }

    /** Envia um evento de teste só para este endpoint. */
    public function test(Request $request, string $id): JsonResponse
    {
        $endpoint = $this->find($request, $id);

        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->getKey(),
            'authentication_id' => $endpoint->authentication_id,
            'event' => 'webhook.test',
            'payload' => WebhookDispatcher::envelope('webhook.test', ['message' => 'Teste de webhook']),
            'status' => WebhookDelivery::PENDING,
        ]);

        dispatch(new DeliverWebhookJob($delivery->getKey()));

        return response()->jsonSuccess(['delivery_id' => $delivery->getKey()]);
    }

    public function deliveries(Request $request, string $id): JsonResponse
    {
        $endpoint = $this->find($request, $id);

        $deliveries = $endpoint->deliveries()->latest()->limit(50)->get()->map(fn (WebhookDelivery $d) => $this->presentDelivery($d));

        return response()->jsonSuccess($deliveries->values()->all());
    }

    public function redeliver(Request $request, string $id, string $deliveryId): JsonResponse
    {
        $endpoint = $this->find($request, $id);
        $delivery = $endpoint->deliveries()->findOrFail($deliveryId);

        return $this->requeue($delivery);
    }

    private function requeue(WebhookDelivery $delivery): JsonResponse
    {
        $delivery->update(['status' => WebhookDelivery::PENDING, 'attempts' => 0, 'error' => null]);

        $job = new DeliverWebhookJob($delivery->getKey());

        if ($queue = config('api-key.webhooks.queue')) {
            $job->onQueue($queue);
        }

        dispatch($job);

        return response()->jsonSuccess(['delivery_id' => $delivery->getKey()]);
    }

    /** @return array<string, mixed> */
    private function presentDelivery(WebhookDelivery $d): array
    {
        return [
            'id' => $d->getKey(),
            'event' => $d->event,
            'status' => $d->status,
            'attempts' => $d->attempts,
            'response_code' => $d->response_code,
            'error' => $d->error,
            'created_at' => $d->created_at?->toIso8601String(),
            'delivered_at' => $d->delivered_at?->toIso8601String(),
            'next_attempt_at' => $d->next_attempt_at?->toIso8601String(),
        ];
    }

    private function urlNotAllowed(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __('api-key::messages.webhook_url_not_allowed'),
            'errors' => ['url' => [__('api-key::messages.webhook_url_not_allowed')]],
        ], 422);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'url' => [$creating ? 'required' : 'sometimes', 'url', 'max:2048'],
            'events' => ['sometimes', 'nullable', 'array'],
            'events.*' => ['string', Rule::in(WebhookDispatcher::events())],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['url']) && ! WebhookUrlGuard::isAllowed($data['url'])) {
            abort($this->urlNotAllowed());
        }

        return $data;
    }

    private function find(Request $request, string $id): WebhookEndpoint
    {
        return WebhookEndpoint::where('authentication_id', $this->owner($request)->getKey())->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function present(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->getKey(),
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'active' => (bool) $endpoint->active,
            'consecutive_failures' => $endpoint->consecutive_failures,
            'disabled_at' => $endpoint->disabled_at?->toIso8601String(),
            'created_at' => $endpoint->created_at?->toIso8601String(),
        ];
    }

    private function owner(Request $request): Authentication
    {
        $user = $request->user();

        abort_unless($user instanceof Authentication, 401);

        return $user;
    }
}
