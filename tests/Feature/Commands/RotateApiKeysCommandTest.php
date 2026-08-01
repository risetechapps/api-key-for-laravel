<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->csv = sys_get_temp_dir().'/rotate-'.uniqid().'.csv';
});

afterEach(function () {
    if (is_file($this->csv)) {
        unlink($this->csv);
    }
});

/** Chave ativa de um dono novo. */
function activeKey(array $attributes = []): ApiKey
{
    return ApiKey::factory()->create(array_merge([
        'authentication_id' => Authentication::factory()->create()->getKey(),
        'active' => true,
    ], $attributes));
}

describe('Choosing what to rotate', function () {
    it('refuses to run without a selector', function () {
        // O padrão não pode ser "tudo": uma flag digitada errado revogaria a
        // instalação inteira.
        activeKey();

        $this->artisan('api-key:rotate-keys')->assertFailed();
    });

    it('refuses more than one selector', function () {
        activeKey();

        $this->artisan('api-key:rotate-keys --all --legacy')->assertFailed();
    });

    it('fails when the named user does not exist', function () {
        $this->artisan('api-key:rotate-keys --user=ninguem@example.com')->assertFailed();
    });

    it('finds the user by e-mail, ignoring case', function () {
        $user = Authentication::factory()->create(['email' => 'dono@example.com']);
        activeKey(['authentication_id' => $user->getKey()]);

        $this->artisan('api-key:rotate-keys --user=DONO@EXAMPLE.COM --dry-run')
            ->expectsOutputToContain('1 API key(s) would be rotated.')
            ->assertSuccessful();
    });

    it('finds the user by id', function () {
        $user = Authentication::factory()->create();
        activeKey(['authentication_id' => $user->getKey()]);

        $this->artisan("api-key:rotate-keys --user={$user->getKey()} --dry-run")
            ->expectsOutputToContain('1 API key(s) would be rotated.')
            ->assertSuccessful();
    });

    it('selects only the v1 backlog under --legacy', function () {
        // O backlog v1 é exatamente o conjunto sem lookup_hash — o que faz o
        // fallback bcrypt custar tempo na autenticação.
        activeKey()->forceFill(['lookup_hash' => null])->saveQuietly();
        activeKey();

        $this->artisan('api-key:rotate-keys --legacy --dry-run')
            ->expectsOutputToContain('1 API key(s) would be rotated.')
            ->assertSuccessful();
    });

    it('ignores inactive keys', function () {
        activeKey(['active' => false]);

        $this->artisan('api-key:rotate-keys --all --dry-run')
            ->expectsOutputToContain('No API keys matched')
            ->assertSuccessful();
    });

    it('changes nothing on a dry run', function () {
        $apiKey = activeKey();
        $before = $apiKey->fresh()->key;

        $this->artisan('api-key:rotate-keys --all --dry-run')->assertSuccessful();

        expect($apiKey->fresh()->key)->toBe($before);
    });
});

describe('Not losing the new keys', function () {
    it('refuses to rotate several keys with nowhere to write them', function () {
        // A chave nova existe em texto puro só durante a execução. Rotacionar em
        // lote sem --output trancaria todos os clientes para fora sem meio de
        // devolver o acesso.
        activeKey();
        activeKey();

        $this->artisan('api-key:rotate-keys --all --force')
            ->expectsOutputToContain('Refusing to rotate 2 keys with nowhere to write them.')
            ->assertFailed();
    });

    it('allows a single key without a file, printing it', function () {
        $user = Authentication::factory()->create(['email' => 'unico@example.com']);
        activeKey(['authentication_id' => $user->getKey()]);

        $this->artisan('api-key:rotate-keys --user=unico@example.com --force')
            ->expectsOutputToContain('unico@example.com:')
            ->assertSuccessful();
    });

    it('leaves the keys untouched when the refusal fires', function () {
        $first = activeKey();
        $second = activeKey();
        $before = [$first->fresh()->key, $second->fresh()->key];

        $this->artisan('api-key:rotate-keys --all --force')->assertFailed();

        expect([$first->fresh()->key, $second->fresh()->key])->toBe($before);
    });

    it('fails when the CSV path cannot be opened', function () {
        activeKey();
        activeKey();

        $this->artisan('api-key:rotate-keys', [
            '--all' => true,
            '--force' => true,
            '--output' => '/caminho/que/nao/existe/keys.csv',
        ])->assertFailed();
    });
});

describe('Rotating', function () {
    it('writes one CSV row per key, with the plain key', function () {
        $user = Authentication::factory()->create(['email' => 'dono@example.com']);
        activeKey(['authentication_id' => $user->getKey()]);
        activeKey();

        $this->artisan('api-key:rotate-keys', ['--all' => true, '--force' => true, '--output' => $this->csv])->assertSuccessful();

        $rows = array_map('str_getcsv', file($this->csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        expect($rows[0])->toBe(['authentication_id', 'email', 'api_key'])
            ->and($rows)->toHaveCount(3)
            // 64 bytes em hexadecimal, o mesmo formato emitido no registro.
            ->and($rows[1][2])->toHaveLength(128);
    });

    it('replaces the stored key', function () {
        $apiKey = activeKey();
        $before = $apiKey->fresh()->key;

        $this->artisan('api-key:rotate-keys', ['--all' => true, '--force' => true, '--output' => $this->csv])->assertSuccessful();

        expect($apiKey->fresh()->key)->not->toBe($before);
    });

    it('never writes the stored hash to the CSV', function () {
        $apiKey = activeKey();

        $this->artisan('api-key:rotate-keys', ['--all' => true, '--force' => true, '--output' => $this->csv])->assertSuccessful();

        expect(file_get_contents($this->csv))->not->toContain($apiKey->fresh()->key);
    });

    it('issues a distinct key per owner', function () {
        activeKey();
        activeKey();

        $this->artisan('api-key:rotate-keys', ['--all' => true, '--force' => true, '--output' => $this->csv])->assertSuccessful();

        $rows = array_map('str_getcsv', file($this->csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        expect($rows[1][2])->not->toBe($rows[2][2]);
    });

    it('clears the legacy backlog it rotates', function () {
        // O hook de saving recalcula o lookup_hash, então a chave rotacionada sai
        // sozinha do conjunto legado — é o que faz o scan bcrypt se esgotar.
        activeKey()->forceFill(['lookup_hash' => null])->saveQuietly();

        $this->artisan('api-key:rotate-keys', ['--legacy' => true, '--force' => true, '--output' => $this->csv])->assertSuccessful();

        expect(ApiKey::whereNull('lookup_hash')->count())->toBe(0);
    });

    it('aborts without rotating when the confirmation is declined', function () {
        $apiKey = activeKey();
        $before = $apiKey->fresh()->key;

        $this->artisan('api-key:rotate-keys', ['--all' => true, '--output' => $this->csv])
            ->expectsConfirmation('Continue?', 'no')
            ->assertSuccessful();

        expect($apiKey->fresh()->key)->toBe($before);
    });
});
