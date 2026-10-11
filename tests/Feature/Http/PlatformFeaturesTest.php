<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\AuditLog\AuditLog;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Models\Webhook\WebhookDelivery;
use RiseTechApps\ApiKey\Models\Webhook\WebhookEndpoint;
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
            ->assertJsonPath('status', 'forbidden_scope');

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

    it('disables with the password', function () {
        enableTwoFactor($this->user);
        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/v1/dashboard/two-factor', ['password' => 'password'])->assertOk();

        expect($this->user->fresh()->two_factor_confirmed_at)->toBeNull();
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
