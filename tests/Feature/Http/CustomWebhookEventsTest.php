<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Webhook\WebhookDelivery;
use RiseTechApps\ApiKey\Models\Webhook\WebhookEndpoint;
use RiseTechApps\ApiKey\Services\WebhookDispatcher;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['api-key.webhooks.block_private_networks' => false]);
    $this->user = Authentication::factory()->create();
    Sanctum::actingAs($this->user);
});

afterEach(fn () => WebhookDispatcher::flushRegisteredEvents());

it('evento do app fora do registro continua recusado no cadastro', function () {
    $this->postJson('/api/v1/dashboard/webhooks', ['url' => 'https://hooks.example.com/api', 'events' => ['pedido.criado']])
        ->assertUnprocessable();
});

it('evento registrado em código aparece no painel e pode ser assinado', function () {
    WebhookDispatcher::registerEvents('pedido.criado', 'nota.emitida');

    $events = $this->getJson('/api/v1/dashboard/webhooks')->assertOk()->json('data.available_events');
    expect($events)->toContain('pedido.criado', 'nota.emitida', 'subscription.activated')
        ->not->toContain('webhook.test');

    $this->postJson('/api/v1/dashboard/webhooks', ['url' => 'https://hooks.example.com/api', 'events' => ['pedido.criado']])
        ->assertOk()
        ->assertJsonPath('data.events', ['pedido.criado']);
});

it('evento listado na config também vale', function () {
    config(['api-key.webhooks.events' => ['pedido.criado', 'INVÁLIDO', 123]]);

    expect(WebhookDispatcher::events())->toContain('pedido.criado')->not->toContain('INVÁLIDO');

    $this->postJson('/api/v1/dashboard/webhooks', ['url' => 'https://hooks.example.com/api', 'events' => ['pedido.criado']])->assertOk();
});

it('registrar duas vezes não duplica', function () {
    WebhookDispatcher::registerEvents('pedido.criado');
    WebhookDispatcher::registerEvents('pedido.criado');

    expect(array_count_values(WebhookDispatcher::events())['pedido.criado'])->toBe(1);
});

it('recusa nome fora do formato recurso.acao', function (string $name) {
    WebhookDispatcher::registerEvents($name);
})->with(['sem ponto' => 'pedido', 'maiúscula' => 'Pedido.Criado', 'espaço' => 'pedido. criado'])
    ->throws(InvalidArgumentException::class);

it('o app envia o próprio evento só para quem assinou', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);
    WebhookDispatcher::registerEvents('pedido.criado');

    $subscribed = WebhookEndpoint::create(['authentication_id' => $this->user->getKey(), 'url' => 'https://hooks.example.com/a', 'secret' => 'whsec_a', 'events' => ['pedido.criado'], 'active' => true]);
    $other = WebhookEndpoint::create(['authentication_id' => $this->user->getKey(), 'url' => 'https://hooks.example.com/b', 'secret' => 'whsec_b', 'events' => ['subscription.activated'], 'active' => true]);

    expect(WebhookDispatcher::dispatch($this->user, 'pedido.criado', ['numero' => 42]))->toBe(1);

    expect(WebhookDelivery::where('webhook_endpoint_id', $subscribed->getKey())->sole()->status)->toBe(WebhookDelivery::DELIVERED)
        ->and(WebhookDelivery::where('webhook_endpoint_id', $other->getKey())->exists())->toBeFalse();

    Http::assertSent(fn (HttpRequest $r) => $r['event'] === 'pedido.criado' && $r['data']['numero'] === 42);
});

it('evento não registrado não sai: vira erro no log', function () {
    Http::fake();
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (InvalidArgumentException $e) use (&$reported) {
        $reported[] = $e->getMessage();

        return false;
    });

    // Endpoint de "Todos os eventos": antes recebia qualquer nome.
    WebhookEndpoint::create(['authentication_id' => $this->user->getKey(), 'url' => 'https://hooks.example.com/a', 'secret' => 'whsec_a', 'events' => null, 'active' => true]);

    expect(WebhookDispatcher::dispatch($this->user, 'pedido.criadoo', ['numero' => 1]))->toBe(0)
        ->and(WebhookDelivery::count())->toBe(0)
        ->and($reported)->toHaveCount(1)
        ->and($reported[0])->toContain('pedido.criadoo');
    Http::assertNothingSent();
});

it('evento da config também pode sair', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);
    config(['api-key.webhooks.events' => ['pedido.criado' => 'Pedido criado']]);
    WebhookEndpoint::create(['authentication_id' => $this->user->getKey(), 'url' => 'https://hooks.example.com/a', 'secret' => 'whsec_a', 'events' => null, 'active' => true]);

    expect(WebhookDispatcher::dispatch($this->user, 'pedido.criado', []))->toBe(1);
});

it('registra com descrição e o painel mostra a descrição de cada evento', function () {
    WebhookDispatcher::registerEvents(['pedido.criado' => 'Pedido criado'], 'nota.emitida');
    // Registrar de novo sem descrição não apaga a que existe.
    WebhookDispatcher::registerEvents('pedido.criado');

    $catalog = collect($this->getJson('/api/v1/dashboard/webhooks')->assertOk()->json('data.events_catalog'))->keyBy('name');

    expect($catalog['pedido.criado']['description'])->toBe('Pedido criado')
        ->and($catalog['nota.emitida']['description'])->toBeNull()
        ->and($catalog['subscription.activated']['description'])->toBe('Assinatura ativada')
        ->and($catalog->has('webhook.test'))->toBeFalse();
});

it('todo evento do pacote tem descrição', function () {
    expect(array_keys(WebhookDispatcher::DESCRIPTIONS))->toEqualCanonicalizing(WebhookDispatcher::EVENTS);
});
