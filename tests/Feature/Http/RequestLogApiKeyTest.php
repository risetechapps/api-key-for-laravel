<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
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
    $plan = Plan::factory()->create(['request_limit' => 1000]);
    $this->userPlan = UserPlan::factory()->create([
        'authentication_id' => $this->user->getKey(),
        'plan_id' => $plan->getKey(),
        'active' => true,
        'end_date' => now()->addDays(10),
    ]);
    $this->prod = ApiKey::factory()->create(['authentication_id' => $this->user->getKey(), 'active' => true, 'name' => 'Produção']);
    $this->staging = ApiKey::factory()->create(['authentication_id' => $this->user->getKey(), 'active' => true, 'name' => 'Homologação']);
});

function logFor(?ApiKey $key, string $endpoint = '/api/v1/x'): void
{
    new LogApiRequestJob(test()->user->getKey(), test()->userPlan->getKey(), $endpoint, 'GET', 200, '203.0.113.7', $key?->getKey())->handle();
}

describe('Gravação', function () {
    it('grava a chave que fez a requisição, com ou sem fila', function (?string $queue) {
        config(['api-key.request_log.queue' => $queue]);

        $this->withHeaders(['X-API-KEY' => $this->staging->plainKey])->getJson('/api/v1/test-endpoint')->assertOk();

        expect(RequestLog::where('authentication_id', $this->user->getKey())->sole()->api_key_id)->toBe($this->staging->getKey());
    })->with(['queue' => 'logs', 'after response' => null]);

    it('o job enfileirado antes da coluna existir grava sem chave', function () {
        logFor(null);

        expect(RequestLog::sole()->api_key_id)->toBeNull();
    });
});

describe('Histórico no painel', function () {
    beforeEach(fn () => Sanctum::actingAs($this->user));

    it('mostra a chave de cada requisição', function () {
        logFor($this->prod);

        $this->getJson('/api/v1/dashboard/log')
            ->assertOk()
            ->assertJsonPath('data.data.0.api_key.id', $this->prod->getKey())
            ->assertJsonPath('data.data.0.api_key.name', 'Produção')
            ->assertJsonPath('data.data.0.api_key.deleted', false);
    });

    it('linha antiga, sem chave, vem com api_key null', function () {
        logFor(null);

        $this->getJson('/api/v1/dashboard/log')->assertOk()->assertJsonPath('data.data.0.api_key', null);
    });

    it('filtra por chave', function () {
        logFor($this->prod, '/api/v1/prod');
        logFor($this->staging, '/api/v1/staging');
        logFor($this->staging, '/api/v1/staging');

        $response = $this->getJson('/api/v1/dashboard/log?api_key_id='.$this->staging->getKey())->assertOk();

        expect($response->json('data.total'))->toBe(2)
            ->and(collect($response->json('data.data'))->pluck('request.endpoint')->unique()->all())->toBe(['/api/v1/staging']);
    });

    it('o log da chave excluída continua dizendo que foi ela', function () {
        logFor($this->staging);
        $id = $this->staging->getKey();

        $this->deleteJson("/api/v1/dashboard/api-keys/{$id}")->assertOk();

        $this->getJson("/api/v1/dashboard/log?api_key_id={$id}")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.api_key.id', $id)
            ->assertJsonPath('data.data.0.api_key.name', null)
            ->assertJsonPath('data.data.0.api_key.deleted', true);
    });

    it('chave de outra conta não traz nada', function () {
        $other = Authentication::factory()->create();
        $foreign = ApiKey::factory()->create(['authentication_id' => $other->getKey()]);
        logFor($this->prod);

        $this->getJson('/api/v1/dashboard/log?api_key_id='.$foreign->getKey())->assertOk()->assertJsonPath('data.total', 0);
    });

    it('recusa id que não é UUID (no PostgreSQL quebraria a consulta)', function () {
        $this->getJson('/api/v1/dashboard/log?api_key_id=abc')->assertUnprocessable();
    });
});

it('a exportação de dados leva a chave de cada requisição', function () {
    logFor($this->prod);
    Sanctum::actingAs($this->user);

    $json = json_decode($this->get('/api/v1/dashboard/profile/export')->assertOk()->streamedContent(), true);

    expect($json['request_logs'][0]['api_key_id'])->toBe($this->prod->getKey());
});

it('lista as chaves excluídas, com o nome, para o filtro do histórico', function () {
    Sanctum::actingAs($this->user);
    $id = $this->staging->getKey();

    $this->deleteJson("/api/v1/dashboard/api-keys/{$id}")->assertOk();

    $this->getJson('/api/v1/dashboard/api-keys/revoked')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.name', 'Homologação');

    // Chave excluída de outra conta não aparece.
    $other = Authentication::factory()->create();
    ApiKey::factory()->count(2)->create(['authentication_id' => $other->getKey()]);
    Sanctum::actingAs($other);
    $this->getJson('/api/v1/dashboard/api-keys/revoked')->assertOk()->assertJsonCount(0, 'data');
});
