<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RiseTechApps\ApiKey\Jobs\DeliverWebhookJob;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Webhook\WebhookDelivery;
use RiseTechApps\ApiKey\Models\Webhook\WebhookEndpoint;
use RiseTechApps\ApiKey\Services\WebhookDispatcher;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['api-key.webhooks.block_private_networks' => false]);
    $this->user = Authentication::factory()->create();
});

afterEach(fn () => WebhookDispatcher::flushRegisteredEvents());

function signatureFor(HttpRequest $request, string $secret): bool
{
    $timestamp = $request->header('X-Webhook-Timestamp')[0] ?? '';

    return ($request->header('X-Webhook-Signature')[0] ?? '') === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), $secret);
}

describe('segredo da conta', function () {
    it('é gerado no primeiro uso, cifrado e no formato exato', function () {
        $secret = $this->user->webhookSecret();

        $stored = DB::table('authentications')->where('id', $this->user->getKey())->value('webhook_secret');

        expect($secret)->toStartWith('whsec_')
            ->and(strlen($secret))->toBe(46)
            // O HasToUpper não pode mexer: o HMAC é sensível a maiúsculas.
            ->and($secret)->not->toBe(strtoupper($secret))
            ->and($stored)->not->toContain($secret)
            ->and($this->user->fresh()->webhookSecret())->toBe($secret)
            ->and($this->user->fresh()->toArray())->not->toHaveKey('webhook_secret');
    });

    it('não troca o segredo de quem já tem um', function () {
        $first = $this->user->webhookSecret();

        // Outra instância do mesmo usuário, sem o atributo carregado.
        expect(Authentication::query()->select('id')->find($this->user->getKey())->webhookSecret())->toBe($first);
    });

    it('é mostrado e trocado pelo painel', function () {
        Sanctum::actingAs($this->user);

        $old = $this->getJson('/api/v1/dashboard/webhooks/secret')->assertOk()->json('data.secret');
        $new = $this->postJson('/api/v1/dashboard/webhooks/secret/rotate')->assertOk()->json('data.secret');

        expect($old)->toStartWith('whsec_')
            ->and($new)->toStartWith('whsec_')->not->toBe($old)
            ->and($this->user->fresh()->webhookSecret())->toBe($new);
    });

    it('a rota antiga do endpoint troca o segredo da conta', function () {
        Sanctum::actingAs($this->user);
        $old = $this->user->webhookSecret();
        $endpoint = WebhookEndpoint::create(['authentication_id' => $this->user->getKey(), 'url' => 'https://hooks.example.com/a', 'active' => true]);

        $new = $this->postJson("/api/v1/dashboard/webhooks/{$endpoint->getKey()}/rotate-secret")->assertOk()->json('data.secret');

        expect($new)->not->toBe($old)->and($this->user->fresh()->webhookSecret())->toBe($new);
    });

    it('assina com o mesmo segredo todos os endpoints e as URLs avulsas', function () {
        Http::fake(['*' => Http::response('ok', 200)]);
        $secret = $this->user->webhookSecret();

        WebhookEndpoint::create(['authentication_id' => $this->user->getKey(), 'url' => 'https://a.example.com/hook', 'active' => true]);
        WebhookEndpoint::create(['authentication_id' => $this->user->getKey(), 'url' => 'https://b.example.com/hook', 'active' => true]);

        WebhookDispatcher::dispatch($this->user, 'webhook.test', ['x' => 1]);
        WebhookDispatcher::dispatchTo($this->user, 'https://c.example.com/hook?tenant=1', 'webhook.test', ['x' => 2]);

        Http::assertSentCount(3);
        Http::assertSent(fn (HttpRequest $r) => signatureFor($r, $secret));
        Http::assertNotSent(fn (HttpRequest $r) => ! signatureFor($r, $secret));
    });
});

describe('dispatchTo', function () {
    it('entrega o envelope na URL com o id pedido e grava no histórico', function () {
        Http::fake(['*' => Http::response('ok', 200)]);
        $delivery = WebhookDispatcher::dispatchTo($this->user, 'https://hooks.example.com/x?tenant=7', 'pedido.pronto', ['id' => 'p1'], 'id-estavel-1');

        expect($delivery)->not->toBeNull()
            ->and($delivery->fresh()->status)->toBe(WebhookDelivery::DELIVERED)
            ->and($delivery->webhook_endpoint_id)->toBeNull()
            ->and($delivery->authentication_id)->toBe($this->user->getKey());

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://hooks.example.com/x?tenant=7'
            && $r->header('X-Webhook-Id')[0] === 'id-estavel-1'
            && $r->header('X-Webhook-Event')[0] === 'pedido.pronto'
            && $r['id'] === 'id-estavel-1'
            && $r['event'] === 'pedido.pronto'
            && $r['data'] === ['id' => 'p1']);

        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/dashboard/webhooks/deliveries')
            ->assertOk()
            ->assertJsonPath('data.0.url', 'https://hooks.example.com/x?tenant=7')
            ->assertJsonPath('data.0.status', WebhookDelivery::DELIVERED);
    });

    it('não exige registro: o evento não vira opção dos endpoints', function () {
        Http::fake(['*' => Http::response('ok', 200)]);

        expect(WebhookDispatcher::dispatchTo($this->user, 'https://hooks.example.com/x', 'relatorio.pronto', []))->not->toBeNull()
            ->and(WebhookDispatcher::events())->not->toContain('relatorio.pronto');
    });

    it('recusa nome de evento fora do formato', function () {
        Http::fake();

        expect(WebhookDispatcher::dispatchTo($this->user, 'https://hooks.example.com/x', 'Relatorio Pronto', []))->toBeNull();

        Http::assertNothingSent();
        expect(WebhookDelivery::count())->toBe(0);
    });

    it('não envia com os webhooks desligados', function () {
        config(['api-key.webhooks.enabled' => false]);

        expect(WebhookDispatcher::dispatchTo($this->user, 'https://hooks.example.com/x', 'webhook.test', []))->toBeNull();
    });

    it('recusa rede privada na entrega e deixa o motivo no histórico', function () {
        config(['api-key.webhooks.block_private_networks' => true]);
        Http::fake();

        $delivery = WebhookDispatcher::dispatchTo($this->user, 'https://10.0.0.8/hook', 'webhook.test', []);

        Http::assertNothingSent();
        expect($delivery->fresh()->error)->toContain('not allowed');
    });

    it('usa a fila e as tentativas da config', function () {
        Queue::fake();
        config(['api-key.webhooks.queue' => 'webhooks', 'api-key.webhooks.tries' => 3, 'api-key.webhooks.backoff' => [10, 60]]);

        WebhookDispatcher::dispatchTo($this->user, 'https://hooks.example.com/x', 'webhook.test', []);

        Queue::assertPushedOn('webhooks', DeliverWebhookJob::class, fn (DeliverWebhookJob $job) => $job->tries === 3 && $job->backoff() === [10, 60]);
    });

    it('reenvia uma entrega avulsa pelo painel', function () {
        Http::fake(['*' => Http::sequence()->push('erro', 500)->push('ok', 200)]);

        try {
            WebhookDispatcher::dispatchTo($this->user, 'https://hooks.example.com/x', 'webhook.test', []);
        } catch (Throwable) {
            // O driver sync propaga a falha.
        }

        $delivery = WebhookDelivery::sole();
        expect($delivery->status)->not->toBe(WebhookDelivery::DELIVERED);

        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/dashboard/webhooks/deliveries/{$delivery->getKey()}/redeliver")->assertOk();

        expect($delivery->fresh()->status)->toBe(WebhookDelivery::DELIVERED);
    });

    it('não deixa reenviar entrega de outra conta', function () {
        $other = Authentication::factory()->create();
        Queue::fake();
        $delivery = WebhookDispatcher::dispatchTo($other, 'https://hooks.example.com/x', 'webhook.test', []);

        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/dashboard/webhooks/deliveries/{$delivery->getKey()}/redeliver")->assertNotFound();
        expect($this->getJson('/api/v1/dashboard/webhooks/deliveries')->json('data'))->toBe([]);
    });
});

describe('teste de URL pelo painel', function () {
    beforeEach(fn () => Sanctum::actingAs($this->user));

    it('envia um webhook.test assinado e devolve o resultado', function () {
        Http::fake(['*' => Http::response('ok', 200)]);
        $secret = $this->user->webhookSecret();

        $this->postJson('/api/v1/dashboard/webhooks/test-url', ['url' => 'https://hooks.example.com/x?tenant=1'])
            ->assertOk()
            ->assertJsonPath('data.delivered', true)
            ->assertJsonPath('data.http_status', 200);

        Http::assertSent(fn (HttpRequest $r) => $r['event'] === 'webhook.test' && signatureFor($r, $secret));
    });

    it('mostra a recusa do cliente sem novas tentativas', function () {
        Http::fake(['*' => Http::response('{"reason":"invalid_signature"}', 401)]);

        $this->postJson('/api/v1/dashboard/webhooks/test-url', ['url' => 'https://hooks.example.com/x'])
            ->assertOk()
            ->assertJsonPath('data.delivered', false)
            ->assertJsonPath('data.http_status', 401);

        $delivery = WebhookDelivery::sole();
        expect($delivery->status)->toBe(WebhookDelivery::FAILED)
            ->and($delivery->next_attempt_at)->toBeNull()
            ->and($delivery->error)->toContain('invalid_signature');
    });

    it('recusa endereço privado', function () {
        config(['api-key.webhooks.block_private_networks' => true]);

        $this->postJson('/api/v1/dashboard/webhooks/test-url', ['url' => 'https://127.0.0.1/x'])->assertStatus(422);
    });
});

describe('migração 1.3.0', function () {
    it('a conta herda o segredo do endpoint mais antigo e as entregas ganham a conta', function () {
        $migration = require __DIR__.'/../../../database/migrations/2026_11_16_000001_add_account_webhook_secret.php';
        $migration->down();

        $old = WebhookEndpoint::create(['authentication_id' => $this->user->getKey(), 'url' => 'https://a.example.com', 'secret' => 'whsec_antigo', 'active' => true]);
        DB::table('webhook_endpoints')->where('id', $old->getKey())->update(['created_at' => now()->subDay()]);
        $newer = WebhookEndpoint::create(['authentication_id' => $this->user->getKey(), 'url' => 'https://b.example.com', 'secret' => 'whsec_novo', 'active' => true]);
        DB::table('webhook_deliveries')->insert([
            'id' => (string) Str::uuid(), 'webhook_endpoint_id' => $newer->getKey(), 'event' => 'webhook.test',
            'payload' => '{}', 'status' => 'delivered', 'attempts' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $semEndpoint = Authentication::factory()->create();

        $migration->up();

        expect($this->user->fresh()->webhookSecret())->toBe('whsec_antigo')
            ->and(DB::table('authentications')->where('id', $semEndpoint->getKey())->value('webhook_secret'))->toBeNull()
            ->and(WebhookDelivery::sole()->authentication_id)->toBe($this->user->getKey());
    });
});
