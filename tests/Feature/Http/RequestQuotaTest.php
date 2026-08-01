<?php

use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create(['request_limit' => 1000]);

    $this->apiKey = ApiKey::factory()->create([
        'authentication_id' => $this->user->id,
        'active' => true,
    ]);

    $this->userPlan = UserPlan::factory()->create([
        'authentication_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'active' => true,
        'end_date' => now()->addDay(),
        'requests_used' => 500,
    ]);
});

function callQuotaEndpoint(string $path)
{
    return test()->withHeaders(['X-API-KEY' => test()->apiKey->plainKey])->getJson($path);
}

describe('Quota accounting', function () {
    it('charges a successful request', function () {
        callQuotaEndpoint('/api/v1/test-endpoint')->assertStatus(200);

        expect($this->userPlan->fresh()->requests_used)->toBe(501);
    });

    it('gives the slot back when the server fails', function () {
        // A cota é reservada antes do request rodar — é isso que garante o limite
        // sob concorrência. Mas uma falha do servidor não entregou nada, e cobrar
        // por ela é cobrar o cliente pelo defeito da aplicação.
        callQuotaEndpoint('/api/v1/test-endpoint-server-error')->assertStatus(500);

        expect($this->userPlan->fresh()->requests_used)->toBe(500);
    });

    it('still charges a client error', function () {
        // Deliberado: requisição malformada é do próprio cliente. Estornar tornaria
        // a cota burlável por quem se dispusesse a mandar lixo.
        callQuotaEndpoint('/api/v1/test-endpoint-client-error')->assertStatus(422);

        expect($this->userPlan->fresh()->requests_used)->toBe(501);
    });

    it('does not charge a request rejected for being over the limit', function () {
        // O 429 nunca chega a reservar: o UPDATE condicional não casa nenhuma linha.
        $this->userPlan->update(['requests_used' => 1000]);

        callQuotaEndpoint('/api/v1/test-endpoint')->assertStatus(429);

        expect($this->userPlan->fresh()->requests_used)->toBe(1000);
    });

    it('logs the failed request even after returning the slot', function () {
        // O estorno é da cota, não do registro: a chamada aconteceu e precisa
        // aparecer no log de requisições.
        callQuotaEndpoint('/api/v1/test-endpoint-server-error')->assertStatus(500);

        $this->assertDatabaseHas('request_logs', [
            'authentication_id' => $this->user->id,
            'response_code' => 500,
        ]);
    });

    it('stops exactly at the limit under repeated calls', function () {
        $this->userPlan->update(['requests_used' => 998]);

        callQuotaEndpoint('/api/v1/test-endpoint')->assertStatus(200);
        callQuotaEndpoint('/api/v1/test-endpoint')->assertStatus(200);
        callQuotaEndpoint('/api/v1/test-endpoint')->assertStatus(429);

        expect($this->userPlan->fresh()->requests_used)->toBe(1000);
    });
});
