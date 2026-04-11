<?php

use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create([
        'request_limit' => 1000,
    ]);
});

describe('ApiKey Authentication Middleware', function () {
    it('allows request with valid api key', function () {
        $apiKey = ApiKey::factory()->create([
            'authentication_id' => $this->user->id,
            'active' => true,
        ]);

        $userPlan = UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'active' => true,
            'end_date' => now()->addDay(),
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $apiKey->plainKey,
        ])->getJson('/api/v1/test-endpoint');

        $response->assertStatus(200);
    });

    it('rejects request without api key', function () {
        $response = $this->getJson('/api/v1/test-endpoint');

        $response->assertStatus(401);
    });

    it('rejects request with invalid api key', function () {
        $response = $this->withHeaders([
            'X-API-KEY' => 'invalid-key',
        ])->getJson('/api/v1/test-endpoint');

        $response->assertStatus(401);
    });
});

describe('Check Active Plan Middleware', function () {
    it('allows request when plan is active', function () {
        $apiKey = ApiKey::factory()->create([
            'authentication_id' => $this->user->id,
            'active' => true,
        ]);

        UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'active' => true,
            'end_date' => now()->addDay(),
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $apiKey->plainKey,
        ])->getJson('/api/v1/test-endpoint');

        $response->assertStatus(200);
    });

    it('rejects request when plan is expired', function () {
        $apiKey = ApiKey::factory()->create([
            'authentication_id' => $this->user->id,
            'active' => true,
        ]);

        UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'active' => true,
            'end_date' => now()->subDay(),
            'start_date' => now()->subDays(30),
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $apiKey->plainKey,
        ])->getJson('/api/v1/test-endpoint');

        $response->assertStatus(403);
    });

    it('allows request during grace period', function () {
        $apiKey = ApiKey::factory()->create([
            'authentication_id' => $this->user->id,
            'active' => true,
        ]);

        UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'active' => true,
            'end_date' => now()->subHours(12),
            'start_date' => now()->subDays(30),
        ]);

        config(['api-key.grace_period_days' => 3]);

        $response = $this->withHeaders([
            'X-API-KEY' => $apiKey->plainKey,
        ])->getJson('/api/v1/test-endpoint');

        $response->assertStatus(200)
            ->assertHeader('X-Plan-Status', 'grace-period');
    });
});

describe('Check Request Limit Middleware', function () {
    it('allows request when under limit', function () {
        $apiKey = ApiKey::factory()->create([
            'authentication_id' => $this->user->id,
            'active' => true,
        ]);

        UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'active' => true,
            'end_date' => now()->addDay(),
            'requests_used' => 500,
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $apiKey->plainKey,
        ])->getJson('/api/v1/test-endpoint');

        $response->assertStatus(200);
    });

    it('rejects request when limit reached', function () {
        $apiKey = ApiKey::factory()->create([
            'authentication_id' => $this->user->id,
            'active' => true,
        ]);

        UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'active' => true,
            'end_date' => now()->addDay(),
            'requests_used' => 1000,
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $apiKey->plainKey,
        ])->getJson('/api/v1/test-endpoint');

        $response->assertStatus(429);
    });
});

describe('Api Key Origin Validator Middleware', function () {
    it('allows request from allowed origin', function () {
        $apiKey = ApiKey::factory()->create([
            'authentication_id' => $this->user->id,
            'active' => true,
            'allowed_origins' => ['example.com'],
        ]);

        UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'active' => true,
            'end_date' => now()->addDay(),
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $apiKey->plainKey,
            'Origin' => 'https://example.com',
        ])->getJson('/api/v1/test-endpoint');

        $response->assertStatus(200);
    });

    it('rejects request from unauthorized origin', function () {
        $apiKey = ApiKey::factory()->create([
            'authentication_id' => $this->user->id,
            'active' => true,
            'allowed_origins' => ['example.com'],
        ]);

        UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'active' => true,
            'end_date' => now()->addDay(),
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $apiKey->plainKey,
            'Origin' => 'https://malicious.com',
        ])->getJson('/api/v1/test-endpoint');

        $response->assertStatus(403);
    });
});
