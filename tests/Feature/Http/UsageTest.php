<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\RequestLog\RequestLog;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create(['request_limit' => 1000, 'rate_limit_per_minute' => 120]);

    $this->apiKey = ApiKey::factory()->create([
        'authentication_id' => $this->user->id,
        'active' => true,
        'name' => 'Produção',
    ]);

    $this->userPlan = UserPlan::factory()->create([
        'authentication_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'active' => true,
        'end_date' => now()->addDays(10),
        'requests_used' => 500,
    ]);
});

function withKey(string $path)
{
    return test()->withHeaders(['X-API-KEY' => test()->apiKey->plainKey])->getJson($path);
}

describe('Quota headers', function () {
    it('reports the quota after this request was charged', function () {
        withKey('/api/v1/test-endpoint')
            ->assertOk()
            ->assertHeader('X-Quota-Limit', '1000')
            ->assertHeader('X-Quota-Used', '501')
            ->assertHeader('X-Quota-Remaining', '499')
            ->assertHeader('X-Quota-Reset', (string) $this->userPlan->fresh()->end_date->getTimestamp());
    });

    it('does not count a request whose slot was given back', function () {
        withKey('/api/v1/test-endpoint-server-error')
            ->assertStatus(500)
            ->assertHeader('X-Quota-Used', '500')
            ->assertHeader('X-Quota-Remaining', '500');
    });

    it('is sent with the 429 of an exhausted quota', function () {
        $this->userPlan->update(['requests_used' => 1000]);

        withKey('/api/v1/test-endpoint')
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'REQUEST_LIMIT_REACHED')
            ->assertHeader('X-Quota-Limit', '1000')
            ->assertHeader('X-Quota-Remaining', '0');
    });

    it('leaves out limit and remaining on an unlimited plan', function () {
        $this->plan->update(['request_limit' => 0]);

        withKey('/api/v1/test-endpoint')
            ->assertOk()
            ->assertHeader('X-Quota-Used', '501')
            ->assertHeaderMissing('X-Quota-Limit')
            ->assertHeaderMissing('X-Quota-Remaining');
    });

    it('can be turned off', function () {
        config(['api-key.request_limit.headers' => false]);

        withKey('/api/v1/test-endpoint')
            ->assertOk()
            ->assertHeaderMissing('X-Quota-Used');
    });
});

describe('GET /usage', function () {
    it('reports the usage without spending the quota', function () {
        withKey('/api/v1/usage')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.plan.id', $this->plan->id)
            ->assertJsonPath('data.period.status', 'active')
            ->assertJsonPath('data.requests.limit', 1000)
            ->assertJsonPath('data.requests.used', 500)
            ->assertJsonPath('data.requests.remaining', 500)
            ->assertJsonPath('data.rate_limit.per_minute', 120)
            ->assertJsonPath('data.api_key.id', $this->apiKey->id)
            ->assertJsonPath('data.api_key.name', 'Produção')
            ->assertJsonMissingPath('data.api_key.key')
            ->assertHeader('X-Quota-Used', '500');

        expect($this->userPlan->fresh()->requests_used)->toBe(500);
    });

    it('keeps answering after the quota is exhausted', function () {
        // É justamente quando o cliente mais precisa saber o uso.
        $this->userPlan->update(['requests_used' => 1000]);

        withKey('/api/v1/usage')
            ->assertOk()
            ->assertJsonPath('data.requests.remaining', 0);
    });

    it('reports null limit and remaining on an unlimited plan', function () {
        $this->plan->update(['request_limit' => 0]);

        withKey('/api/v1/usage')
            ->assertOk()
            ->assertJsonPath('data.requests.limit', null)
            ->assertJsonPath('data.requests.remaining', null)
            ->assertJsonPath('data.overage.available', false);
    });

    it('reports the grace period', function () {
        $this->userPlan->update(['end_date' => now()->subDay()]);

        withKey('/api/v1/usage')
            ->assertOk()
            ->assertJsonPath('data.period.status', 'grace_period')
            ->assertJsonPath('data.period.grace_period_ends_at', fn ($date) => $date !== null);
    });

    it('reports requests and amount beyond the limit', function () {
        $this->plan->update(['overage_price' => 0.10, 'overage_max_requests' => 50]);
        $this->user->forceFill(['overage_enabled' => true])->save();
        $this->userPlan->update(['requests_used' => 1020]);

        withKey('/api/v1/usage')
            ->assertOk()
            ->assertJsonPath('data.requests.remaining', 0)
            ->assertJsonPath('data.overage.available', true)
            ->assertJsonPath('data.overage.enabled', true)
            ->assertJsonPath('data.overage.requests', 20)
            ->assertJsonPath('data.overage.max_requests', 50)
            ->assertJsonPath('data.overage.amount', 2);
    });

    it('refuses without a key, in English, with a stable code', function () {
        $this->getJson('/api/v1/usage')
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'MISSING_API_KEY');
    });

    it('refuses an account without an active plan', function () {
        $this->userPlan->update(['active' => false]);

        withKey('/api/v1/usage')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PLAN_INACTIVE');
    });
});

function logRequest(Authentication $user, string $code, DateTimeInterface $at): void
{
    RequestLog::create([
        'authentication_id' => $user->id,
        'endpoint' => 'api/v1/reports',
        'method' => 'GET',
        'response_code' => $code,
        'requested_at' => $at,
    ]);
}

describe('GET /usage/daily', function () {
    it('counts requests per day, split by status class, without gaps', function () {
        logRequest($this->user, '200', now());
        logRequest($this->user, '201', now());
        logRequest($this->user, '422', now());
        logRequest($this->user, '429', now());
        logRequest($this->user, '500', now()->subDays(2));
        logRequest($this->user, '200', now()->subDays(40));
        logRequest(Authentication::factory()->create(), '200', now());

        $data = withKey('/api/v1/usage/daily?days=7')
            ->assertOk()
            ->assertHeader('X-Quota-Used', '500')
            ->json('data');

        expect($data['days'])->toHaveCount(7)
            ->and($data['from'])->toBe(now()->subDays(6)->toDateString())
            ->and($data['to'])->toBe(now()->toDateString())
            ->and($data['days'][6])->toBe([
                'date' => now()->toDateString(),
                'total' => 4,
                'success' => 2,
                'client_error' => 2,
                'server_error' => 0,
            ])
            ->and($data['days'][4]['server_error'])->toBe(1)
            ->and($data['days'][0]['total'])->toBe(0);

        // Consultar o histórico não consome a cota.
        expect($this->userPlan->fresh()->requests_used)->toBe(500);
    });

    it('defaults to 30 days and keeps the window between 1 and 90', function (string $query, int $expected) {
        expect(withKey('/api/v1/usage/daily'.$query)->assertOk()->json('data.days'))->toHaveCount($expected);
    })->with([
        'default' => ['', 30],
        'above' => ['?days=365', 90],
        'below' => ['?days=0', 1],
    ]);

    it('refuses without a key', function () {
        $this->getJson('/api/v1/usage/daily')->assertStatus(401)->assertJsonPath('error_code', 'MISSING_API_KEY');
    });

    it('feeds the dashboard chart from the same source', function () {
        logRequest($this->user, '200', now());
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/dashboard/stats?days=3')
            ->assertOk()
            ->assertJsonCount(3, 'data.series')
            ->assertJsonPath('data.series.2.total', 1)
            ->assertJsonPath('data.series.2.date', now()->toDateString());
    });
});
