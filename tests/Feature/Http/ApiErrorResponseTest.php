<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Services\AuthService;

uses(RefreshDatabase::class);

// A API do cliente responde sempre em inglês, com `error_code` estável, mesmo
// quando o cliente pede português.
beforeEach(function () {
    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create(['request_limit' => 1000]);
    $this->userPlan = UserPlan::factory()->create([
        'authentication_id' => $this->user->getKey(),
        'plan_id' => $this->plan->getKey(),
        'active' => true,
        'end_date' => now()->addDays(10),
    ]);
    $this->key = ApiKey::factory()->create(['authentication_id' => $this->user->getKey(), 'active' => true]);
});

function apiCall(?string $key, string $uri = '/api/v1/test-endpoint', array $headers = [])
{
    return test()->getJson($uri, array_filter([
        'Accept-Language' => 'pt-BR',
        'X-API-KEY' => $key,
        ...$headers,
    ]));
}

it('rejects a missing key', function () {
    apiCall(null)
        ->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'API key is missing. Send it in the X-API-KEY header.',
            'error_code' => 'MISSING_API_KEY',
        ]);
});

it('rejects an invalid key', function () {
    apiCall('not-a-key')
        ->assertStatus(401)
        ->assertJsonPath('error_code', 'INVALID_API_KEY')
        ->assertJsonPath('message', 'Invalid API key.');
});

it('rejects a suspended account', function () {
    $this->user->forceFill(['status' => AuthService::$DISABLE])->save();

    apiCall($this->key->plainKey)
        ->assertForbidden()
        ->assertJsonPath('error_code', 'ACCOUNT_DISABLED')
        ->assertJsonPath('message', 'This account is suspended. Please contact support.');
});

it('rejects an account without an active plan', function () {
    $this->userPlan->update(['active' => false]);

    apiCall($this->key->plainKey)
        ->assertForbidden()
        ->assertJsonPath('error_code', 'PLAN_INACTIVE')
        ->assertJsonPath('message', 'Your plan has expired or is not active.');
});

it('rejects a request past the plan limit', function () {
    $this->plan->update(['request_limit' => 1, 'overage_price' => null]);
    $this->userPlan->update(['requests_used' => 1]);

    apiCall($this->key->plainKey)
        ->assertStatus(429)
        ->assertJsonPath('error_code', 'REQUEST_LIMIT_REACHED');
});

it('rejects a burst past the per-minute limit', function () {
    $this->key->update(['rate_limit_per_minute' => 1]);

    apiCall($this->key->plainKey)->assertOk();

    $response = apiCall($this->key->plainKey)
        ->assertStatus(429)
        ->assertJsonPath('error_code', 'RATE_LIMITED')
        ->assertJsonPath('message', 'Too many requests per minute. Please wait and try again.')
        ->assertHeader('Retry-After');

    expect($response->json('retry_after'))->toBeInt();
});

it('rejects an origin outside the allowed list', function () {
    $this->key->update(['allowed_origins' => ['app.example.com']]);

    apiCall($this->key->plainKey, headers: ['Origin' => 'https://evil.com'])
        ->assertForbidden()
        ->assertJsonPath('error_code', 'ORIGIN_NOT_ALLOWED')
        ->assertJsonPath('message', 'Request origin or IP is not allowed for this API key.');
});

it('rejects a feature the plan does not include', function () {
    apiCall($this->key->plainKey, '/api/v1/test-endpoint-feature')
        ->assertStatus(402)
        ->assertJsonPath('error_code', 'FEATURE_NOT_IN_PLAN')
        ->assertJsonPath('message', 'Your plan does not include any of these features: test-missing-feature.')
        ->assertJsonPath('features', ['test-missing-feature']);
});

it('answers a non-JSON client in JSON too', function () {
    $this->get('/api/v1/test-endpoint-feature', ['X-API-KEY' => $this->key->plainKey])
        ->assertStatus(402)
        ->assertJsonPath('error_code', 'FEATURE_NOT_IN_PLAN');
});

it('runs the app routes of the plan group in english', function () {
    Route::middleware('plan')->get('/api/v1/_locale', fn () => response()->json(['locale' => app()->getLocale()]));

    apiCall($this->key->plainKey, '/api/v1/_locale')->assertJsonPath('locale', 'en');
});
