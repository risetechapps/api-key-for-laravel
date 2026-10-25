<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use RiseTechApps\ApiKey\Jobs\LogApiRequestJob;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\RequestLog\RequestLog;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create(['request_limit' => 1000]);
    $this->userPlan = UserPlan::factory()->create([
        'authentication_id' => $this->user->getKey(),
        'plan_id' => $this->plan->getKey(),
        'active' => true,
        'end_date' => now()->addDays(10),
    ]);
});

function keyWithOrigins(array $origins): ApiKey
{
    return ApiKey::factory()->create([
        'authentication_id' => test()->user->getKey(),
        'active' => true,
        'allowed_origins' => $origins,
    ]);
}

describe('Origin validation over HTTP', function () {
    it('accepts an allowed Origin header', function () {
        $key = keyWithOrigins(['app.example.com']);

        $this->withHeaders(['X-API-KEY' => $key->plainKey, 'Origin' => 'https://app.example.com'])
            ->getJson('/api/v1/test-endpoint')
            ->assertOk();
    });

    it('rejects an Origin outside the list', function () {
        $key = keyWithOrigins(['app.example.com']);

        $this->withHeaders(['X-API-KEY' => $key->plainKey, 'Origin' => 'https://evil.com'])
            ->getJson('/api/v1/test-endpoint')
            ->assertForbidden();
    });

    it('rejects a trailing wildcard used to smuggle a third-party domain', function () {
        $key = keyWithOrigins(['app.example.*']);

        $this->withHeaders(['X-API-KEY' => $key->plainKey, 'Origin' => 'https://app.example.evil.com'])
            ->getJson('/api/v1/test-endpoint')
            ->assertForbidden();
    });

    it('falls back to the client IP when there is no Origin', function () {
        $key = keyWithOrigins(['10.0.0.5']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->withHeaders(['X-API-KEY' => $key->plainKey])
            ->getJson('/api/v1/test-endpoint')
            ->assertOk();
    });

    it('ignores a forged X-Forwarded-For from an untrusted client', function () {
        $key = keyWithOrigins(['10.0.0.5']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['X-API-KEY' => $key->plainKey, 'X-Forwarded-For' => '10.0.0.5'])
            ->getJson('/api/v1/test-endpoint')
            ->assertForbidden();
    });

    it('does not charge the quota for a refused origin', function () {
        $key = keyWithOrigins(['app.example.com']);
        $before = (int) $this->userPlan->fresh()->requests_used;

        $this->withHeaders(['X-API-KEY' => $key->plainKey, 'Origin' => 'https://evil.com'])
            ->getJson('/api/v1/test-endpoint')
            ->assertForbidden();

        expect((int) $this->userPlan->fresh()->requests_used)->toBe($before);
    });
});

describe('Language middleware', function () {
    beforeEach(function () {
        Route::middleware('language')->get('/_locale', fn () => response()->json(['locale' => app()->getLocale()]));
        Route::middleware('language:en')->get('/_locale-en', fn () => response()->json(['locale' => app()->getLocale()]));
    });

    it('answers the dashboard in portuguese whatever the client asks', function (string $header) {
        $this->getJson('/_locale', ['Accept-Language' => $header])->assertJson(['locale' => 'pt']);
    })->with(['', 'en-US', 'fr-FR', 'en;q=1, pt;q=0.5']);

    it('answers in the language passed as parameter', function () {
        $this->getJson('/_locale-en', ['Accept-Language' => 'pt-BR'])->assertJson(['locale' => 'en']);
    });
});

describe('LogApiRequestJob', function () {
    it('writes one log row with the request data', function () {
        new LogApiRequestJob($this->user->getKey(), $this->userPlan->getKey(), '/api/v1/x', 'POST', 201)->handle();

        $log = RequestLog::where('authentication_id', $this->user->getKey())->sole();

        expect($log->endpoint)->toBe('/api/v1/x')
            ->and($log->method)->toBe('POST')
            ->and((int) $log->response_code)->toBe(201);
    });

    it('records the client IP', function () {
        new LogApiRequestJob($this->user->getKey(), $this->userPlan->getKey(), '/api/v1/x', 'GET', 200, '203.0.113.7')->handle();

        expect(RequestLog::where('authentication_id', $this->user->getKey())->sole()->ip)->toBe('203.0.113.7');
    });

    it('records the IP of a request through the plan group, with or without a queue', function (?string $queue) {
        config(['api-key.request_log.queue' => $queue]);
        $key = keyWithOrigins([]);

        $this->withHeaders(['X-API-KEY' => $key->plainKey])
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->getJson('/api/v1/test-endpoint')
            ->assertOk();

        expect(RequestLog::where('authentication_id', $this->user->getKey())->sole()->ip)->toBe('198.51.100.20');
    })->with(['queue' => 'logs', 'after response' => null]);

    it('shows the IP in the dashboard request list', function () {
        new LogApiRequestJob($this->user->getKey(), $this->userPlan->getKey(), '/api/v1/x', 'GET', 200, '2001:db8::1')->handle();
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/dashboard/log')
            ->assertOk()
            ->assertJsonPath('data.data.0.request.ip', '2001:db8::1');
    });

    it('resolves the active plan when none is passed', function () {
        new LogApiRequestJob($this->user->getKey(), null, '/api/v1/x', 'GET', 200)->handle();

        expect(RequestLog::where('authentication_id', $this->user->getKey())->count())->toBe(1);
    });

    it('writes nothing without an active plan', function () {
        $this->userPlan->update(['active' => false]);

        new LogApiRequestJob($this->user->getKey(), null, '/api/v1/x', 'GET', 200)->handle();

        expect(RequestLog::where('authentication_id', $this->user->getKey())->count())->toBe(0);
    });

    it('is dispatched to the configured queue by the quota middleware', function () {
        Queue::fake();
        config(['api-key.request_log.queue' => 'logs']);
        $key = keyWithOrigins([]);

        $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint')->assertOk();

        Queue::assertPushedOn('logs', LogApiRequestJob::class);
    });
});

describe('Payment rate limit', function () {
    beforeEach(function () {
        // O controller exige o token já no construtor; a chamada nunca sai.
        config(['api-key.mercadopago.access_token' => 'TEST-not-a-real-token']);
    });

    it('limits coupon checks per user', function () {
        Sanctum::actingAs($this->user);

        // Padrão: 10 a cada 10 minutos.
        for ($i = 0; $i < 10; $i++) {
            expect($this->postJson('/api/v1/dashboard/checkout/coupon', ['code' => "X{$i}", 'plan_id' => $this->plan->getKey()])->status())
                ->not->toBe(429);
        }

        $this->postJson('/api/v1/dashboard/checkout/coupon', ['code' => 'LAST', 'plan_id' => $this->plan->getKey()])
            ->assertStatus(429);
    });

    it('does not share the budget with other throttled routes', function () {
        Sanctum::actingAs($this->user);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/dashboard/checkout/coupon', ['code' => "X{$i}", 'plan_id' => $this->plan->getKey()]);
        }

        $this->postJson('/api/v1/dashboard/checkout/coupon', ['code' => 'LAST', 'plan_id' => $this->plan->getKey()])
            ->assertStatus(429);

        // Pagamento esgotado; a exclusão de conta tem contador próprio.
        $this->deleteJson('/api/v1/dashboard/profile', ['password' => 'wrong-password'])
            ->assertStatus(422);
    });
});
