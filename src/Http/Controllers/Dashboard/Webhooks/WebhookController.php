<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RiseTechApps\ApiKey\Jobs\DeliverWebhookJob;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Webhook\WebhookDelivery;
use RiseTechApps\ApiKey\Models\Webhook\WebhookEndpoint;
use RiseTechApps\ApiKey\Services\AuditLogger;
use RiseTechApps\ApiKey\Services\WebhookDispatcher;
use RiseTechApps\ApiKey\Support\WebhookUrlGuard;

/**
 * Webhooks de saída da conta. O segredo de assinatura só aparece na criação e
 * na rotação.
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
            'available_events' => array_values(array_diff(WebhookDispatcher::EVENTS, ['webhook.test'])),
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

        $secret = 'whsec_'.Str::random(40);

        $endpoint = WebhookEndpoint::create([
            ...$data,
            'authentication_id' => $user->getKey(),
            'secret' => $secret,
            'active' => true,
        ]);

        AuditLogger::record('webhook.created', $user, ['webhook_id' => $endpoint->getKey(), 'url' => $endpoint->url]);

        return response()->jsonSuccess([...$this->present($endpoint), 'secret' => $secret]);
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

    public function rotateSecret(Request $request, string $id): JsonResponse
    {
        $endpoint = $this->find($request, $id);
        $secret = 'whsec_'.Str::random(40);

        $endpoint->update(['secret' => $secret]);

        AuditLogger::record('webhook.secret_rotated', $this->owner($request), ['webhook_id' => $id]);

        return response()->jsonSuccess([...$this->present($endpoint), 'secret' => $secret]);
    }

    /** Envia um evento de teste só para este endpoint. */
    public function test(Request $request, string $id): JsonResponse
    {
        $endpoint = $this->find($request, $id);

        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->getKey(),
            'event' => 'webhook.test',
            'payload' => [
                'id' => 'evt_'.Str::lower((string) Str::ulid()),
                'event' => 'webhook.test',
                'created_at' => now()->toIso8601String(),
                'data' => ['message' => 'Teste de webhook'],
            ],
            'status' => WebhookDelivery::PENDING,
        ]);

        dispatch(new DeliverWebhookJob($delivery->getKey()));

        return response()->jsonSuccess(['delivery_id' => $delivery->getKey()]);
    }

    public function deliveries(Request $request, string $id): JsonResponse
    {
        $endpoint = $this->find($request, $id);

        $deliveries = $endpoint->deliveries()->latest()->limit(50)->get()->map(fn (WebhookDelivery $d) => [
            'id' => $d->getKey(),
            'event' => $d->event,
            'status' => $d->status,
            'attempts' => $d->attempts,
            'response_code' => $d->response_code,
            'error' => $d->error,
            'created_at' => $d->created_at?->toIso8601String(),
            'delivered_at' => $d->delivered_at?->toIso8601String(),
            'next_attempt_at' => $d->next_attempt_at?->toIso8601String(),
        ]);

        return response()->jsonSuccess($deliveries->values()->all());
    }

    public function redeliver(Request $request, string $id, string $deliveryId): JsonResponse
    {
        $endpoint = $this->find($request, $id);
        $delivery = $endpoint->deliveries()->findOrFail($deliveryId);

        $delivery->update(['status' => WebhookDelivery::PENDING, 'attempts' => 0, 'error' => null]);
        dispatch(new DeliverWebhookJob($delivery->getKey()));

        return response()->jsonSuccess(['delivery_id' => $delivery->getKey()]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'url' => [$creating ? 'required' : 'sometimes', 'url', 'max:2048'],
            'events' => ['sometimes', 'nullable', 'array'],
            'events.*' => ['string', Rule::in(WebhookDispatcher::EVENTS)],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['url']) && ! WebhookUrlGuard::isAllowed($data['url'])) {
            abort(response()->json([
                'success' => false,
                'message' => __('api-key::messages.webhook_url_not_allowed'),
                'errors' => ['url' => [__('api-key::messages.webhook_url_not_allowed')]],
            ], 422));
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
