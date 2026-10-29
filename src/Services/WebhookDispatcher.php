<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;
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
    /** Eventos do pacote. A lista completa, com os do app, é events(). */
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
        'api_key.expiring',
        'webhook.test',
    ];

    /** Descrição de cada evento do pacote, mostrada no painel ao lado do nome. */
    public const DESCRIPTIONS = [
        'subscription.activated' => 'Assinatura ativada',
        'subscription.changed' => 'Troca de plano',
        'subscription.cancelled' => 'Assinatura cancelada',
        'subscription.expired' => 'Assinatura expirada',
        'subscription.grace_period_started' => 'Início do período de carência',
        'subscription.refunded' => 'Assinatura estornada',
        'subscription.renewal_failed' => 'Falha na renovação automática',
        'subscription.payment_required' => 'Renovação aguardando pagamento',
        'payment.rejected' => 'Pagamento recusado',
        'billing.overage_charged' => 'Cobrança do excedente',
        'usage.threshold_reached' => 'Uso chegou ao limite de aviso',
        'usage.limit_reached' => 'Limite do plano atingido',
        'usage.overage_started' => 'Início do excedente',
        'api_key.expiring' => 'Chave de API perto de vencer',
        'webhook.test' => 'Evento de teste',
    ];

    /** Formato dos nomes: `recurso.acao`, minúsculo (ex.: `pedido.criado`). */
    private const NAME_PATTERN = '/^[a-z0-9_]+(\.[a-z0-9_]+)+$/';

    /** @var array<string, string|null> Eventos do app (nome => descrição), registrados por registerEvents(). */
    private static array $registered = [];

    /**
     * Eventos do app que instala o pacote, para o cliente assinar no painel.
     * Chame no boot() de um service provider; também dá para listar em
     * `api-key.webhooks.events`. Só evento registrado sai no dispatch().
     *
     *   WebhookDispatcher::registerEvents('pedido.criado', 'nota.emitida');
     *   WebhookDispatcher::registerEvents(['pedido.criado' => 'Pedido criado', 'nota.emitida' => 'Nota fiscal emitida']);
     *
     * @param  string|array<int|string, string>  ...$events
     */
    public static function registerEvents(string|array ...$events): void
    {
        foreach (self::normalize($events, strict: true) as $event => $description) {
            // Registrar de novo sem descrição não apaga a que já existe.
            self::$registered[$event] = $description ?? self::$registered[$event] ?? null;
        }
    }

    /**
     * Todos os eventos que um endpoint pode assinar: os do pacote, os da config
     * e os registrados pelo app.
     *
     * @return array<int, string>
     */
    public static function events(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * Nome => descrição de todos os eventos. Sem descrição, null (o painel
     * mostra só o nome).
     *
     * @return array<string, string|null>
     */
    public static function catalog(): array
    {
        $catalog = self::DESCRIPTIONS;

        // Config inválida não derruba a requisição: o nome fora do formato só
        // fica de fora (registerEvents(), em código, recusa com exceção).
        foreach ([...self::normalize([(array) config('api-key.webhooks.events', [])], strict: false), ...self::$registered] as $event => $description) {
            $catalog[$event] = $description ?? $catalog[$event] ?? null;
        }

        return $catalog;
    }

    /** O evento existe (do pacote, da config ou registrado)? */
    public static function isRegistered(string $event): bool
    {
        return array_key_exists($event, self::catalog());
    }

    /** Esquece os eventos registrados (testes). */
    public static function flushRegisteredEvents(): void
    {
        self::$registered = [];
    }

    /**
     * Aceita nomes soltos e mapas nome => descrição.
     *
     * @param  array<int, string|array<int|string, mixed>>  $groups
     * @return array<string, string|null>
     */
    private static function normalize(array $groups, bool $strict): array
    {
        $events = [];

        foreach ($groups as $group) {
            foreach ((array) $group as $key => $value) {
                [$event, $description] = is_string($key) ? [$key, $value] : [$value, null];

                if (! is_string($event) || ! preg_match(self::NAME_PATTERN, $event)) {
                    if ($strict) {
                        $name = is_scalar($event) ? (string) $event : get_debug_type($event);
                        throw new InvalidArgumentException("Nome de evento de webhook inválido: [{$name}]. Use recurso.acao em minúsculas, ex.: pedido.criado.");
                    }

                    continue;
                }

                $events[$event] = is_string($description) && $description !== '' ? $description : null;
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return int Quantas entregas foram enfileiradas.
     */
    public static function dispatch(Authentication $user, string $event, array $data): int
    {
        if (! config('api-key.webhooks.enabled', true)) {
            return 0;
        }

        // Só sai evento que o cliente pôde escolher no painel. Um nome errado
        // ou esquecido no registro iria para quem marcou "Todos os eventos"
        // sem ele ter escolhido; aqui vira erro no log e nada é enviado.
        if (! self::isRegistered($event)) {
            report(new InvalidArgumentException("Evento de webhook não registrado: [{$event}]. Registre com WebhookDispatcher::registerEvents() ou em api-key.webhooks.events."));

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
