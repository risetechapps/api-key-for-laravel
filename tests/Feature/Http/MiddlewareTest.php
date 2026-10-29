<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Testing\TestResponse;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

uses(RefreshDatabase::class);

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
            // Além do grace period (3 dias): completamente expirado → 403.
            'end_date' => now()->subDays(5),
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

    it('does not let an Origin header carrying an allowed IP through the IP list', function () {
        // A brecha: a lista só tem o IP do servidor do cliente, e quem tem a
        // chave manda `Origin: http://<esse IP>` de qualquer lugar.
        $apiKey = ApiKey::factory()->create([
            'authentication_id' => $this->user->id,
            'active' => true,
            'allowed_origins' => ['203.0.113.5'],
        ]);
        UserPlan::factory()->create(['authentication_id' => $this->user->id, 'plan_id' => $this->plan->id, 'active' => true, 'end_date' => now()->addDay()]);

        foreach (['http://203.0.113.5', '203.0.113.5', 'https://203.0.113.5:8443'] as $origin) {
            $this->withHeaders(['X-API-KEY' => $apiKey->plainKey, 'Origin' => $origin])
                ->getJson('/api/v1/test-endpoint')
                ->assertStatus(403)
                ->assertJsonPath('error_code', 'ORIGIN_NOT_ALLOWED');
        }
    });

    it('checks the connection IP against IP entries, whatever the Origin header says', function () {
        $apiKey = ApiKey::factory()->create([
            'authentication_id' => $this->user->id,
            'active' => true,
            'allowed_origins' => ['203.0.113.0/24'],
        ]);
        UserPlan::factory()->create(['authentication_id' => $this->user->id, 'plan_id' => $this->plan->id, 'active' => true, 'end_date' => now()->addDay()]);

        // Servidor do cliente que manda um Origin qualquer: antes era recusado,
        // porque o Origin tomava o lugar do IP na conferência.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['X-API-KEY' => $apiKey->plainKey, 'Origin' => 'https://dashboard.example.com'])
            ->getJson('/api/v1/test-endpoint')
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->withHeaders(['X-API-KEY' => $apiKey->plainKey])
            ->getJson('/api/v1/test-endpoint')
            ->assertStatus(403);
    });
});

describe('Internal token bypass', function () {
    beforeEach(function () {
        config(['api-key.internal_token' => 'internal-secret']);
    });

    afterEach(function () {
        TrustProxies::flushState();
    });

    function internalCall(string $remoteAddr, array $headers = []): TestResponse
    {
        return test()->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
            ->withHeaders([
                'X-Internal-Token' => 'internal-secret',
                'X-User-Id' => test()->user->getKey(),
                ...$headers,
            ])
            ->getJson('/api/v1/test-endpoint');
    }

    it('accepts the token from loopback', function () {
        internalCall('127.0.0.1')->assertStatus(200);
    });

    it('rejects the token from a remote address', function () {
        internalCall('203.0.113.9')->assertStatus(401);
    });

    it('rejects the token for a client forwarded by a local proxy', function () {
        // nginx na mesma máquina: o socket é 127.0.0.1 para toda requisição da
        // internet. Com o TrustProxies configurado, $request->ip() revela o
        // cliente real e o atalho interno não vale para ele.
        TrustProxies::at('127.0.0.1');

        internalCall('127.0.0.1', ['X-Forwarded-For' => '203.0.113.9'])->assertStatus(401);
    });

    it('does not let a forged forwarded header open the bypass from outside', function () {
        // Confiança em qualquer proxy (`*`) faria $request->ip() aceitar o
        // X-Forwarded-For forjado; o socket remoto continua barrando.
        TrustProxies::at('*');

        internalCall('203.0.113.9', ['X-Forwarded-For' => '127.0.0.1'])->assertStatus(401);
    });

    it('rejects a wrong token even from loopback', function () {
        internalCall('127.0.0.1', ['X-Internal-Token' => 'wrong'])->assertStatus(401);
    });
});
