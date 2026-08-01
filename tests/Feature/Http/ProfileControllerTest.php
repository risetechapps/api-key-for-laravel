<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = Authentication::factory()->create();
    $this->actingAs($this->user, 'sanctum');
});

/**
 * Payload mínimo aceito pelo ProfileRequest: só name, cpf e birth_date são
 * obrigatórios nas regras 'profile'. O CPF precisa passar na validação de
 * dígito verificador — 529.982.247-25 é o número de teste canônico.
 */
function profilePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Nome Alterado',
        'cpf' => '52998224725',
        'birth_date' => '1990-05-20',
    ], $overrides);
}

describe('Profile', function () {
    it('requires authentication', function () {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/dashboard/profile')->assertStatus(401);
    });

    it('returns the signed-in user', function () {
        $this->getJson('/api/v1/dashboard/profile')
            ->assertStatus(200)
            ->assertJsonPath('data.contact.email', $this->user->email)
            ->assertJsonPath('data.account.id', $this->user->getKey());
    });

    it('masks the API key instead of returning it', function () {
        // A chave só existe em texto puro no registro e na regeneração; o perfil
        // é consultado a toda hora e não pode ser um segundo canal para ela.
        ApiKey::factory()->create(['authentication_id' => $this->user->getKey()]);

        $stored = $this->user->fresh()->apiKey->key;

        $response = $this->getJson('/api/v1/dashboard/profile')->assertStatus(200);

        expect($response->json('data.api_key'))->toBe(str_repeat('•', 32))
            ->and($response->getContent())->not->toContain($stored);
    });

    it('updates the profile', function () {
        $this->putJson('/api/v1/dashboard/profile', profilePayload())->assertStatus(200);

        // O pacote to-upper normaliza o nome para maiúsculas na gravação.
        expect($this->user->fresh()->name)->toBe('NOME ALTERADO');
    });

    it('rejects an invalid CPF', function () {
        $this->putJson('/api/v1/dashboard/profile', profilePayload(['cpf' => '11111111111']))
            ->assertStatus(422);
    });

    it('creates the address on the first update and edits it afterwards', function () {
        // O controller cria o endereço quando não existe e atualiza o existente
        // depois; o segundo caminho só aparece a partir da segunda chamada.
        $this->putJson('/api/v1/dashboard/profile', profilePayload([
            'address' => ['city' => 'Santos', 'state' => 'SP'],
        ]))->assertStatus(200);

        expect($this->user->fresh()->address?->city)->toBe('SANTOS');

        // Reautentica com uma instância nova de propósito. O actingAs() guarda o
        // objeto que recebeu e o devolve em toda requisição seguinte, então o
        // `null` que o Eloquent cacheou na relação `address` durante a primeira
        // chamada sobreviveria à segunda — e o controller criaria outro endereço
        // em vez de editar. Em produção cada requisição materializa o usuário do
        // token, sem esse cache; repetir a instância testaria um cenário que não
        // existe.
        $this->actingAs($this->user->fresh(), 'sanctum');

        $this->putJson('/api/v1/dashboard/profile', profilePayload([
            'address' => ['city' => 'Recife', 'state' => 'PE'],
        ]))->assertStatus(200);

        expect($this->user->fresh()->address?->city)->toBe('RECIFE')
            ->and($this->user->fresh()->address()->count())->toBe(1);
    });
});

describe('Allowed origins', function () {
    it('reads back what was written', function () {
        ApiKey::factory()->create(['authentication_id' => $this->user->getKey()]);

        $this->postJson('/api/v1/dashboard/profile/allowed', [
            'allowed' => ['https://app.example.com', 'https://admin.example.com'],
        ])->assertStatus(200);

        $this->getJson('/api/v1/dashboard/profile/allowed')
            ->assertStatus(200)
            ->assertJsonPath('data', ['https://app.example.com', 'https://admin.example.com']);
    });

    it('accepts the allowed_origins spelling too', function () {
        // O controller lê 'allowed' ou 'allowed_origins'; as duas grafias circulam
        // entre a SPA e integrações externas.
        ApiKey::factory()->create(['authentication_id' => $this->user->getKey()]);

        $this->postJson('/api/v1/dashboard/profile/allowed', [
            'allowed_origins' => ['https://outro.example.com'],
        ])->assertStatus(200);

        $this->getJson('/api/v1/dashboard/profile/allowed')
            ->assertJsonPath('data', ['https://outro.example.com']);
    });

    it('does not expose another user origins', function () {
        $mine = ApiKey::factory()->create(['authentication_id' => $this->user->getKey()]);
        $mine->update(['allowed_origins' => ['https://meu.example.com']]);

        $other = Authentication::factory()->create();
        ApiKey::factory()->create(['authentication_id' => $other->getKey()])
            ->update(['allowed_origins' => ['https://alheio.example.com']]);

        $this->getJson('/api/v1/dashboard/profile/allowed')
            ->assertStatus(200)
            ->assertJsonPath('data', ['https://meu.example.com']);
    });
});

describe('Regenerating the API key', function () {
    it('returns a key that is only ever shown once', function () {
        ApiKey::factory()->create(['authentication_id' => $this->user->getKey()]);

        $key = $this->postJson('/api/v1/dashboard/profile/regenerate-key')
            ->assertStatus(200)
            ->json('data.key');

        // 64 bytes em hexadecimal. A resposta é a única vez em que a chave existe
        // em texto puro — o banco guarda o hash.
        expect($key)->toHaveLength(128)
            ->and($key)->toMatch('/^[0-9a-f]+$/');
    });

    it('invalidates the previous key', function () {
        $apiKey = ApiKey::factory()->create(['authentication_id' => $this->user->getKey()]);
        $storedBefore = $apiKey->fresh()->key;

        $this->postJson('/api/v1/dashboard/profile/regenerate-key')->assertStatus(200);

        expect($apiKey->fresh()->key)->not->toBe($storedBefore);
    });

    it('never stores the key in plain text', function () {
        ApiKey::factory()->create(['authentication_id' => $this->user->getKey()]);

        $key = $this->postJson('/api/v1/dashboard/profile/regenerate-key')->json('data.key');

        expect($this->user->fresh()->apiKey->key)->not->toBe($key);
    });

    it('reports when the user has no key to regenerate', function () {
        $this->postJson('/api/v1/dashboard/profile/regenerate-key')->assertStatus(410);
    });
});
