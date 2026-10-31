<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RiseTechApps\ApiKey\Http\Controllers\Authentication\TwoFactorController;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\AuditLog\AuditLog;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Models\Webhook\WebhookDelivery;
use RiseTechApps\ApiKey\Models\Webhook\WebhookEndpoint;
use RiseTechApps\ApiKey\Notifications\AccountSecurityNotification;
use RiseTechApps\ApiKey\Services\WebhookDispatcher;
use RiseTechApps\ApiKey\Support\Totp;
use RiseTechApps\ApiKey\Support\WebhookUrlGuard;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    $this->user = Authentication::factory()->create(['email' => 'ana@example.com']);
    $this->plan = Plan::factory()->create(['request_limit' => 1000, 'price' => 30, 'billing_cycle' => 'monthly']);
    $this->userPlan = UserPlan::factory()->create([
        'authentication_id' => $this->user->getKey(),
        'plan_id' => $this->plan->getKey(),
        'active' => true,
        'end_date' => now()->addDays(10),
    ]);
    ApiKey::factory()->create(['authentication_id' => $this->user->getKey(), 'active' => true, 'name' => 'Principal']);
});

describe('Multiple API keys', function () {
    it('creates a named key, showing the secret only once', function () {
        Sanctum::actingAs($this->user);

        $created = $this->postJson('/api/v1/dashboard/api-keys', ['name' => 'Produção'])
            ->assertOk()
            ->json('data');

        expect($created['key'])->toHaveLength(128)
            ->and($created['name'])->toBe('Produção')
            ->and($created['active'])->toBeTrue();

        $list = $this->getJson('/api/v1/dashboard/api-keys')->assertOk()->json('data');

        expect($list)->toHaveCount(2)
            ->and($list[1])->not->toHaveKey('key');

        $this->withHeaders(['X-API-KEY' => $created['key']])->getJson('/api/v1/test-endpoint')->assertOk();
    });

    it('revokes a key, but never the last one', function () {
        Sanctum::actingAs($this->user);
        $first = $this->user->apiKeys()->first();

        $this->deleteJson("/api/v1/dashboard/api-keys/{$first->getKey()}")->assertStatus(422);

        $second = $this->postJson('/api/v1/dashboard/api-keys', ['name' => 'Teste'])->json('data');
        $this->deleteJson("/api/v1/dashboard/api-keys/{$second['id']}")->assertOk();

        $this->withHeaders(['X-API-KEY' => $second['key']])->getJson('/api/v1/test-endpoint')->assertUnauthorized();
    });

    it('kills the old secret the moment the key is regenerated', function () {
        // A regeneração mantém o id; o cache de validação não pode seguir
        // aceitando o segredo antigo até expirar.
        Sanctum::actingAs($this->user);
        $created = $this->postJson('/api/v1/dashboard/api-keys', ['name' => 'Produção'])->json('data');

        $this->withHeaders(['X-API-KEY' => $created['key']])->getJson('/api/v1/test-endpoint')->assertOk();

        $new = $this->postJson("/api/v1/dashboard/api-keys/{$created['id']}/regenerate")->assertOk()->json('data.key');

        $this->withHeaders(['X-API-KEY' => $created['key']])->getJson('/api/v1/test-endpoint')->assertUnauthorized();
        $this->withHeaders(['X-API-KEY' => $new])->getJson('/api/v1/test-endpoint')->assertOk();
    });

    it('caps keys per account', function () {
        config(['api-key.api_keys.max_per_user' => 2]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/dashboard/api-keys', ['name' => 'B'])->assertOk();
        $this->postJson('/api/v1/dashboard/api-keys', ['name' => 'C'])->assertStatus(422);
    });

    it('records last use', function () {
        Sanctum::actingAs($this->user);
        $created = $this->postJson('/api/v1/dashboard/api-keys', ['name' => 'Uso'])->json('data');

        $this->withHeaders(['X-API-KEY' => $created['key']])->getJson('/api/v1/test-endpoint')->assertOk();

        expect(ApiKey::find($created['id'])->last_used_at)->not->toBeNull();
    });

    it('enforces scopes on feature routes without charging the quota', function () {
        $key = ApiKey::factory()->create([
            'authentication_id' => $this->user->getKey(),
            'active' => true,
            'scopes' => ['some-other-feature'],
        ]);
        $before = $this->userPlan->fresh()->requests_used;

        $this->withHeaders(['X-API-KEY' => $key->plainKey])
            ->getJson('/api/v1/test-endpoint-feature')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'API_KEY_SCOPE_DENIED');

        expect($this->userPlan->fresh()->requests_used)->toBe($before);
    });

    it('reactivates every key when a new subscription starts', function () {
        $this->user->apiKeys()->update(['active' => false]);
        ApiKey::factory()->create(['authentication_id' => $this->user->getKey(), 'active' => false]);

        $this->user->subscribeToPlan($this->plan);

        expect($this->user->apiKeys()->where('active', false)->count())->toBe(0);
    });
});

describe('Per-minute rate limit', function () {
    it('throttles a key over its per-minute limit, without spending quota', function () {
        $key = ApiKey::factory()->create([
            'authentication_id' => $this->user->getKey(),
            'active' => true,
            'rate_limit_per_minute' => 2,
        ]);

        $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint')
            ->assertOk()->assertHeader('X-RateLimit-Limit', '2');
        $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint')->assertOk();

        $used = $this->userPlan->fresh()->requests_used;

        $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint')
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        expect($this->userPlan->fresh()->requests_used)->toBe($used);
    });

    it('falls back to the plan limit', function () {
        $this->plan->update(['rate_limit_per_minute' => 1]);
        $key = ApiKey::factory()->create(['authentication_id' => $this->user->getKey(), 'active' => true]);

        $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint')->assertOk();
        $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint')->assertStatus(429);
    });

    it('never lets a key go above the plan limit', function () {
        $this->plan->update(['rate_limit_per_minute' => 1]);
        $key = ApiKey::factory()->create([
            'authentication_id' => $this->user->getKey(),
            'active' => true,
            'rate_limit_per_minute' => 1_000_000,
        ]);

        $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint')
            ->assertOk()->assertHeader('X-RateLimit-Limit', '1');
        $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint')->assertStatus(429);
    });

    it('counts the plan limit for the whole account, not per key', function () {
        // Com um contador por chave, cinco chaves davam cinco vezes o plano.
        $this->plan->update(['rate_limit_per_minute' => 2]);
        $first = ApiKey::factory()->create(['authentication_id' => $this->user->getKey(), 'active' => true]);
        $second = ApiKey::factory()->create(['authentication_id' => $this->user->getKey(), 'active' => true]);

        $this->withHeaders(['X-API-KEY' => $first->plainKey])->getJson('/api/v1/test-endpoint')->assertOk();
        $this->withHeaders(['X-API-KEY' => $second->plainKey])->getJson('/api/v1/test-endpoint')->assertOk();
        $this->withHeaders(['X-API-KEY' => $second->plainKey])->getJson('/api/v1/test-endpoint')->assertStatus(429);
        $this->withHeaders(['X-API-KEY' => $first->plainKey])->getJson('/api/v1/test-endpoint')->assertStatus(429);
    });

    it('refuses a key limit above the plan in the panel', function () {
        $this->plan->update(['rate_limit_per_minute' => 60]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/dashboard/api-keys', ['name' => 'B', 'rate_limit_per_minute' => 61])
            ->assertUnprocessable()->assertJsonValidationErrors('rate_limit_per_minute');
        $this->postJson('/api/v1/dashboard/api-keys', ['name' => 'B', 'rate_limit_per_minute' => 60])->assertOk();
    });

    it('does not limit when nothing is configured', function () {
        $key = ApiKey::factory()->create(['authentication_id' => $this->user->getKey(), 'active' => true]);

        foreach (range(1, 5) as $_) {
            $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint')->assertOk();
        }
    });
});

describe('Outbound webhooks', function () {
    beforeEach(fn () => config(['api-key.webhooks.block_private_networks' => false]));

    it('registers an endpoint and returns the signing secret once', function () {
        Sanctum::actingAs($this->user);

        $data = $this->postJson('/api/v1/dashboard/webhooks', [
            'url' => 'https://hooks.example.com/api',
            'events' => ['subscription.activated'],
        ])->assertOk()->json('data');

        expect($data['secret'])->toStartWith('whsec_');

        $this->getJson('/api/v1/dashboard/webhooks')
            ->assertOk()
            ->assertJsonMissingPath('data.endpoints.0.secret');
    });

    it('refuses plain http and private addresses', function () {
        config(['api-key.webhooks.block_private_networks' => true]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/dashboard/webhooks', ['url' => 'http://hooks.example.com'])->assertStatus(422);
        $this->postJson('/api/v1/dashboard/webhooks', ['url' => 'https://127.0.0.1/hook'])->assertStatus(422);
        $this->postJson('/api/v1/dashboard/webhooks', ['url' => 'https://169.254.169.254/latest'])->assertStatus(422);

        expect(WebhookUrlGuard::isAllowed('https://10.0.0.8/x'))->toBeFalse()
            ->and(WebhookUrlGuard::isAllowed('https://8.8.8.8/x'))->toBeTrue();
    });

    it('refuses ranges the PHP filter lets through', function () {
        config(['api-key.webhooks.block_private_networks' => true]);

        // CGNAT, NAT64 (embute 127.0.0.1), 6to4, faixa de teste, multicast,
        // SIIT (embute 10.0.0.1) e site-local.
        foreach (['100.64.0.1', '[64:ff9b::7f00:1]', '[2002:7f00:1::1]', '198.18.0.1', '224.0.0.1', '[2001:db8::1]', '[::ffff:0:a00:1]', '[fec0::1]'] as $host) {
            expect(WebhookUrlGuard::isAllowed("https://{$host}/x"))->toBeFalse($host);
        }

        expect(WebhookUrlGuard::isAllowed('https://[2606:4700:4700::1111]/x'))->toBeTrue();
    });

    it('throttles test and redelivery requests', function () {
        // As rotas leem a config no boot: vale o padrão, 10 por minuto.
        Queue::fake();
        Sanctum::actingAs($this->user);

        $id = $this->postJson('/api/v1/dashboard/webhooks', ['url' => 'https://hooks.example.com/api'])->json('data.id');

        foreach (range(1, 10) as $_) {
            $this->postJson("/api/v1/dashboard/webhooks/{$id}/test")->assertOk();
        }

        $this->postJson("/api/v1/dashboard/webhooks/{$id}/test")->assertStatus(429);
    });

    it('pins delivery to the addresses the guard checked', function () {
        config(['api-key.webhooks.block_private_networks' => true]);

        // IP literal não tem o que fixar; recusada não chega a conectar.
        expect(WebhookUrlGuard::allowedAddresses('https://8.8.8.8/x'))->toBe([])
            ->and(WebhookUrlGuard::allowedAddresses('https://10.0.0.8/x'))->toBeNull();

        expect(WebhookUrlGuard::curlResolve('https://hooks.example.com/api', ['93.184.216.34', '2606:2800:220:1::1']))
            ->toBe(['hooks.example.com:443:93.184.216.34,[2606:2800:220:1::1]'])
            ->and(WebhookUrlGuard::curlResolve('http://hooks.example.com:8080/api', ['93.184.216.34']))
            ->toBe(['hooks.example.com:8080:93.184.216.34'])
            ->and(WebhookUrlGuard::curlResolve('https://hooks.example.com/api', []))->toBe([]);
    });

    it('delivers a signed event when the subscription changes', function () {
        Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);

        $endpoint = WebhookEndpoint::create([
            'authentication_id' => $this->user->getKey(),
            'url' => 'https://hooks.example.com/api',
            'secret' => 'whsec_test',
            'events' => ['subscription.activated', 'subscription.changed'],
            'active' => true,
        ]);

        $this->user->subscribeToPlan(Plan::factory()->create());

        $delivery = WebhookDelivery::where('webhook_endpoint_id', $endpoint->getKey())->sole();
        expect($delivery->status)->toBe(WebhookDelivery::DELIVERED)
            // O usuário já tinha plano: é troca, não ativação.
            ->and($delivery->event)->toBe('subscription.changed');

        Http::assertSent(function (HttpRequest $request) {
            $timestamp = $request->header('X-Webhook-Timestamp')[0];
            $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'whsec_test');

            return $request->header('X-Webhook-Signature')[0] === $expected
                && $request['event'] === 'subscription.changed';
        });
    });

    it('skips endpoints not subscribed to the event', function () {
        WebhookEndpoint::create([
            'authentication_id' => $this->user->getKey(),
            'url' => 'https://hooks.example.com/api',
            'secret' => 's',
            'events' => ['payment.rejected'],
            'active' => true,
        ]);

        expect(WebhookDispatcher::dispatch($this->user, 'subscription.activated', []))->toBe(0);
    });

    it('records a failed delivery', function () {
        Http::fake(['hooks.example.com/*' => Http::response('nope', 500)]);

        $endpoint = WebhookEndpoint::create([
            'authentication_id' => $this->user->getKey(),
            'url' => 'https://hooks.example.com/api',
            'secret' => 's',
            'active' => true,
        ]);

        try {
            WebhookDispatcher::dispatch($this->user, 'webhook.test', ['x' => 1]);
        } catch (Throwable) {
            // O driver sync da fila propaga a falha; em produção ela volta para a fila.
        }

        $delivery = WebhookDelivery::where('webhook_endpoint_id', $endpoint->getKey())->sole();
        expect($delivery->response_code)->toBe(500)
            ->and($delivery->status)->not->toBe(WebhookDelivery::DELIVERED);
    });
});

describe('Two-factor authentication', function () {
    function enableTwoFactor(Authentication $user): string
    {
        $secret = Totp::generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [hash('sha256', 'AAAAA-BBBBB')],
        ])->save();

        return $secret;
    }

    it('enables after confirming a code and returns recovery codes', function () {
        Sanctum::actingAs($this->user);

        $secret = $this->postJson('/api/v1/dashboard/two-factor', ['password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.otpauth_uri', fn ($uri) => str_starts_with($uri, 'otpauth://totp/'))
            ->json('data.secret');

        $this->postJson('/api/v1/dashboard/two-factor/confirm', ['code' => '000000'])->assertStatus(422);

        $codes = $this->postJson('/api/v1/dashboard/two-factor/confirm', ['code' => Totp::code($secret)])
            ->assertOk()
            ->json('data.recovery_codes');

        expect($codes)->toHaveCount(8)
            ->and($this->user->fresh()->two_factor_confirmed_at)->not->toBeNull()
            ->and(AuditLog::where('action', 'account.two_factor_enabled')->exists())->toBeTrue();
    });

    it('asks for the code on login and issues the token only after it', function () {
        $secret = enableTwoFactor($this->user);

        $challenge = $this->postJson('/api/v1/login', ['email' => 'ana@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.two_factor', true)
            ->assertJsonMissingPath('data.token')
            ->json('data.challenge');

        $this->postJson('/api/v1/login/two-factor', ['challenge' => $challenge, 'code' => '123456'])->assertStatus(422);

        $this->postJson('/api/v1/login/two-factor', ['challenge' => $challenge, 'code' => Totp::code($secret)])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token']]);
    });

    it('does not accept the same code twice', function () {
        $secret = enableTwoFactor($this->user);
        $code = Totp::code($secret);

        $challenge = $this->postJson('/api/v1/login', ['email' => 'ana@example.com', 'password' => 'password'])->json('data.challenge');
        $this->postJson('/api/v1/login/two-factor', ['challenge' => $challenge, 'code' => $code])->assertOk();
        $this->postJson('/api/v1/login/two-factor', ['challenge' => $challenge, 'code' => $code])->assertStatus(422);
    });

    it('accepts a recovery code once', function () {
        enableTwoFactor($this->user);
        $challenge = $this->postJson('/api/v1/login', ['email' => 'ana@example.com', 'password' => 'password'])->json('data.challenge');

        $this->postJson('/api/v1/login/two-factor', ['challenge' => $challenge, 'recovery_code' => 'aaaaa-bbbbb'])->assertOk();
        $this->postJson('/api/v1/login/two-factor', ['challenge' => $challenge, 'recovery_code' => 'AAAAA-BBBBB'])->assertStatus(422);
    });

    it('locks the code step per account, whatever the IP', function () {
        // O throttle da rota é por IP: com muitos IPs, quem tem a senha
        // adivinharia o código. O contador da conta soma todos.
        Notification::fake();
        config(['api-key.two_factor.max_attempts' => 2]);
        $secret = enableTwoFactor($this->user);
        $challenge = $this->postJson('/api/v1/login', ['email' => 'ana@example.com', 'password' => 'password'])->json('data.challenge');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
            ->postJson('/api/v1/login/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])
            ->postJson('/api/v1/login/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);

        // Bloqueada: nem o código certo passa, e não gasta o código.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.3'])
            ->postJson('/api/v1/login/two-factor', ['challenge' => $challenge, 'code' => Totp::code($secret)])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        Notification::assertSentTo($this->user, AccountSecurityNotification::class,
            fn ($n) => $n->event === AccountSecurityNotification::TWO_FACTOR_LOCKED);
        Notification::assertSentToTimes($this->user, AccountSecurityNotification::class, 1);
        expect(AuditLog::where('action', 'account.two_factor_locked')->count())->toBe(1);

        $this->travel(16)->minutes();

        $this->postJson('/api/v1/login/two-factor', ['challenge' => TwoFactorController::challengeFor($this->user), 'code' => Totp::code($secret)])->assertOk();
    });

    it('starts the count over after a successful code', function () {
        config(['api-key.two_factor.max_attempts' => 2]);
        $secret = enableTwoFactor($this->user);
        $challenge = $this->postJson('/api/v1/login', ['email' => 'ana@example.com', 'password' => 'password'])->json('data.challenge');

        $this->postJson('/api/v1/login/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        $this->postJson('/api/v1/login/two-factor', ['challenge' => $challenge, 'code' => Totp::code($secret)])->assertOk();
        $this->postJson('/api/v1/login/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
    });

    it('rejects a forged or expired challenge', function () {
        enableTwoFactor($this->user);

        $this->postJson('/api/v1/login/two-factor', ['challenge' => 'forged', 'code' => '123456'])->assertStatus(410);

        $old = Crypt::encryptString(json_encode(['id' => $this->user->getKey(), 'exp' => now()->subMinute()->getTimestamp()]));
        $this->postJson('/api/v1/login/two-factor', ['challenge' => $old, 'code' => '123456'])->assertStatus(410);
    });

    it('blocks admin routes for an admin without 2FA when required', function () {
        config(['api-key.two_factor.required_for_admins' => true]);
        $admin = Authentication::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/dashboard/admin/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_required');

        enableTwoFactor($admin);
        Sanctum::actingAs($admin->fresh());

        $this->getJson('/api/v1/dashboard/admin/users')->assertOk();
    });

    it('disables with the password and a code from the app', function () {
        $secret = enableTwoFactor($this->user);
        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/v1/dashboard/two-factor', ['password' => 'password', 'code' => Totp::code($secret)])->assertOk();

        expect($this->user->fresh()->two_factor_confirmed_at)->toBeNull();
    });

    it('disables with a recovery code', function () {
        enableTwoFactor($this->user);
        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/v1/dashboard/two-factor', ['password' => 'password', 'recovery_code' => 'aaaaa-bbbbb'])->assertOk();

        expect($this->user->fresh()->two_factor_confirmed_at)->toBeNull();
    });

    it('does not disable with the password alone', function () {
        // Senha vazada é justamente o caso que a 2FA cobre.
        enableTwoFactor($this->user);
        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/v1/dashboard/two-factor', ['password' => 'password'])->assertUnprocessable();
        $this->deleteJson('/api/v1/dashboard/two-factor', ['password' => 'password', 'code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        expect($this->user->fresh()->two_factor_confirmed_at)->not->toBeNull();
    });

    it('regenerates recovery codes only with a second factor', function () {
        // Códigos novos entram no login sem o aplicativo: só a senha não basta.
        $secret = enableTwoFactor($this->user);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/dashboard/two-factor/recovery-codes', ['password' => 'password'])->assertUnprocessable();
        $this->postJson('/api/v1/dashboard/two-factor/recovery-codes', ['password' => 'password', 'code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        expect($this->user->fresh()->two_factor_recovery_codes)->toBe([hash('sha256', 'AAAAA-BBBBB')]);

        $codes = $this->postJson('/api/v1/dashboard/two-factor/recovery-codes', ['password' => 'password', 'code' => Totp::code($secret)])
            ->assertOk()
            ->json('data.recovery_codes');

        expect($codes)->toHaveCount(8)
            ->and($this->user->fresh()->two_factor_recovery_codes)->not->toContain(hash('sha256', 'AAAAA-BBBBB'));
    });

    it('regenerates recovery codes with a recovery code, which is spent', function () {
        enableTwoFactor($this->user);
        Sanctum::actingAs($this->user);

        $codes = $this->postJson('/api/v1/dashboard/two-factor/recovery-codes', ['password' => 'password', 'recovery_code' => 'AAAAA-BBBBB'])
            ->assertOk()
            ->json('data.recovery_codes');

        expect($this->user->fresh()->two_factor_recovery_codes)->toBe(array_map(fn ($c) => hash('sha256', $c), $codes));
    });

    it('counts wrong codes in the panel toward the account lockout', function () {
        // Com a sessão e a senha, o painel não pode servir para adivinhar o
        // código fora do contador do login.
        Notification::fake();
        config(['api-key.two_factor.max_attempts' => 2]);
        $secret = enableTwoFactor($this->user);
        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/v1/dashboard/two-factor', ['password' => 'password', 'code' => '000000'])->assertUnprocessable();
        $this->postJson('/api/v1/dashboard/two-factor/recovery-codes', ['password' => 'password', 'code' => '000000'])->assertUnprocessable();

        $this->deleteJson('/api/v1/dashboard/two-factor', ['password' => 'password', 'code' => Totp::code($secret)])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $challenge = TwoFactorController::challengeFor($this->user);
        $this->postJson('/api/v1/login/two-factor', ['challenge' => $challenge, 'code' => Totp::code($secret)])->assertStatus(429);

        Notification::assertSentTo($this->user, AccountSecurityNotification::class,
            fn ($n) => $n->event === AccountSecurityNotification::TWO_FACTOR_LOCKED);
        expect($this->user->fresh()->two_factor_confirmed_at)->not->toBeNull();
    });

    it('does not count a wrong password toward the code lockout', function () {
        config(['api-key.two_factor.max_attempts' => 1]);
        $secret = enableTwoFactor($this->user);
        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/v1/dashboard/two-factor', ['password' => 'wrong', 'code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->deleteJson('/api/v1/dashboard/two-factor', ['password' => 'password', 'code' => Totp::code($secret)])->assertOk();
    });

    it('cancels an unconfirmed setup with the password alone', function () {
        $this->user->forceFill(['two_factor_secret' => Totp::generateSecret()])->save();
        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/v1/dashboard/two-factor', ['password' => 'password'])->assertOk();

        expect($this->user->fresh()->two_factor_secret)->toBeNull();
    });
});

describe('Admin audit and metrics', function () {
    beforeEach(function () {
        $this->admin = Authentication::factory()->create(['role' => 'admin']);
    });

    it('records admin actions and lists them', function () {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/dashboard/admin/users/{$this->user->getKey()}/status", ['status' => 'disabled'])->assertOk();

        $log = AuditLog::where('action', 'admin.user_status_changed')->sole();
        expect($log->actor_id)->toBe($this->admin->getKey())
            ->and($log->subject_id)->toBe($this->user->getKey())
            ->and($log->context)->toBe(['from' => 'enabled', 'to' => 'disabled']);

        $this->getJson('/api/v1/dashboard/admin/audit?action=admin.')
            ->assertOk()
            ->assertJsonPath('data.data.0.action', 'admin.user_status_changed')
            ->assertJsonPath('data.data.0.subject.email', 'ana@example.com');
    });

    it('computes MRR, subscribers and revenue', function () {
        $this->userPlan->update(['payment_id' => 'p1', 'payment_amount' => 30, 'start_date' => now()->subDays(5)]);

        $annual = Plan::factory()->create(['price' => 1200, 'billing_cycle' => 'annually']);
        UserPlan::factory()->create([
            'authentication_id' => Authentication::factory()->create()->getKey(),
            'plan_id' => $annual->getKey(),
            'active' => true,
            'start_date' => now()->subDays(3),
            'end_date' => now()->addYear(),
            'payment_id' => 'p2',
            'payment_amount' => 1200,
        ]);

        Sanctum::actingAs($this->admin);

        $data = $this->getJson('/api/v1/dashboard/admin/metrics')->assertOk()->json('data');

        // Mensal (30) + anual normalizado (1200 * 30 / 365).
        expect($data['mrr'])->toBe(round(30 + 1200 * 30 / 365, 2))
            ->and($data['active_subscribers'])->toBe(2)
            ->and($data['new_subscribers_30d'])->toBe(2)
            ->and((float) $data['revenue_30d'])->toBe(1230.0)
            ->and($data['revenue_by_month'])->toHaveCount(12)
            ->and($data['by_plan'])->toHaveCount(2);
    });

    it('is admin only', function () {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/dashboard/admin/metrics')->assertForbidden();
        $this->getJson('/api/v1/dashboard/admin/audit')->assertForbidden();
    });
});
