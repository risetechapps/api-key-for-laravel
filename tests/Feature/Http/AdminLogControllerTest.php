<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Authentication::factory()->create(['role' => 'admin']);

    // A tabela pertence ao pacote de monitoramento. Recriada aqui com as colunas
    // que o controller lê, para o teste nao depender das migrations dele nem da
    // versao instalada.
    Schema::dropIfExists('monitoring');
    Schema::create('monitoring', function ($table) {
        $table->id();
        $table->uuid('uuid');
        $table->string('batch_id')->nullable();
        $table->string('type');
        $table->text('content')->nullable();
        $table->text('tags')->nullable();
        $table->text('user')->nullable();
        $table->timestamp('resolved_at')->nullable();
        $table->timestamps();
    });
});

/** Entrada como o Loggly a grava: content em JSON com level, message e context. */
function monitoringEntry(array $overrides = []): string
{
    $uuid = $overrides['uuid'] ?? (string) Str::uuid();

    DB::table('monitoring')->insert([
        'uuid' => $uuid,
        'type' => $overrides['type'] ?? 'log',
        'content' => json_encode([
            'level' => $overrides['level'] ?? 'error',
            'message' => $overrides['message'] ?? 'Refund failed',
            'context' => $overrides['context'] ?? ['payment_id' => '4400111222'],
            'properties' => ['class' => 'RiseTechApps\ApiKey\Http\Controllers\Dashboard\Admin\AdminController'],
        ]),
        'tags' => json_encode([]),
        'created_at' => $overrides['created_at'] ?? now(),
        'updated_at' => now(),
    ]);

    return $uuid;
}

describe('Acesso', function () {
    it('recusa quem nao e admin', function () {
        $this->actingAs(Authentication::factory()->create(['role' => 'user']), 'sanctum');

        $this->getJson('/api/v1/dashboard/admin/logs')->assertStatus(403);
    });

    it('recusa anonimo', function () {
        $this->getJson('/api/v1/dashboard/admin/logs')->assertStatus(401);
    });
});

describe('Listagem', function () {
    beforeEach(fn () => $this->actingAs($this->admin, 'sanctum'));

    it('lista do mais recente para o mais antigo', function () {
        monitoringEntry(['message' => 'Antigo', 'created_at' => now()->subDays(2)]);
        monitoringEntry(['message' => 'Recente', 'created_at' => now()]);

        $response = $this->getJson('/api/v1/dashboard/admin/logs')->assertStatus(200);

        expect($response->json('data.data.0.message'))->toBe('Recente')
            ->and($response->json('data.total'))->toBe(2);
    });

    it('traz apenas entradas de log', function () {
        // A mesma tabela guarda requisicoes, queries e jobs; nada disso pertence
        // a esta tela.
        monitoringEntry(['message' => 'Um log']);
        monitoringEntry(['type' => 'request', 'message' => 'Uma requisicao']);
        monitoringEntry(['type' => 'query', 'message' => 'Uma query']);

        expect($this->getJson('/api/v1/dashboard/admin/logs')->json('data.total'))->toBe(1);
    });

    it('nao traz contexto na listagem', function () {
        // Em log de excecao o contexto passa de alguns KB; carrega-lo para
        // cinquenta linhas de tabela seria trazer megabytes para exibir texto.
        monitoringEntry(['context' => ['enorme' => str_repeat('x', 5000)]]);

        $row = $this->getJson('/api/v1/dashboard/admin/logs')->json('data.data.0');

        expect($row)->not->toHaveKey('context')
            ->and($row)->toHaveKeys(['id', 'level', 'message', 'origin', 'created_at']);
    });

    it('filtra por nivel', function () {
        monitoringEntry(['level' => 'error', 'message' => 'Falhou']);
        monitoringEntry(['level' => 'info', 'message' => 'Seguiu']);

        $response = $this->getJson('/api/v1/dashboard/admin/logs?level=error')->assertStatus(200);

        expect($response->json('data.total'))->toBe(1)
            ->and($response->json('data.data.0.message'))->toBe('Falhou');
    });

    it('recusa nivel invalido', function () {
        $this->getJson('/api/v1/dashboard/admin/logs?level=inventado')->assertStatus(422);
    });

    it('busca na mensagem e no contexto', function () {
        monitoringEntry(['message' => 'Refund failed']);
        monitoringEntry(['message' => 'Outro assunto', 'context' => ['payment_id' => '99887766']]);

        expect($this->getJson('/api/v1/dashboard/admin/logs?search=Refund')->json('data.total'))->toBe(1)
            ->and($this->getJson('/api/v1/dashboard/admin/logs?search=99887766')->json('data.total'))->toBe(1);
    });

    it('trata curinga digitado como texto literal', function () {
        // Sem escape, um % digitado devolve a base inteira — o oposto de filtrar.
        monitoringEntry(['message' => 'Qualquer coisa']);

        expect($this->getJson('/api/v1/dashboard/admin/logs?search=%25')->json('data.total'))->toBe(0);
    });

    it('filtra por periodo', function () {
        monitoringEntry(['message' => 'Velho', 'created_at' => now()->subDays(10)]);
        monitoringEntry(['message' => 'Novo', 'created_at' => now()]);

        $from = now()->subDay()->toDateString();

        expect($this->getJson("/api/v1/dashboard/admin/logs?from={$from}")->json('data.total'))->toBe(1);
    });

    it('limita o tamanho da pagina', function () {
        // Sem teto, um per_page grande carrega a tabela que mais cresce.
        $this->getJson('/api/v1/dashboard/admin/logs?per_page=5000')->assertStatus(422);
    });

    it('informa os niveis disponiveis para a tela montar o filtro', function () {
        expect($this->getJson('/api/v1/dashboard/admin/logs')->json('data.levels'))
            ->toContain('error')
            ->toContain('info');
    });
});

describe('Detalhe', function () {
    beforeEach(fn () => $this->actingAs($this->admin, 'sanctum'));

    it('traz contexto e propriedades', function () {
        $uuid = monitoringEntry(['context' => ['payment_id' => '4400111222', 'admin_id' => 'abc']]);

        $response = $this->getJson("/api/v1/dashboard/admin/logs/{$uuid}")->assertStatus(200);

        expect($response->json('data.context.payment_id'))->toBe('4400111222')
            ->and($response->json('data.properties.class'))->toContain('AdminController');
    });

    it('404 para registro inexistente', function () {
        $this->getJson('/api/v1/dashboard/admin/logs/'.Str::uuid())->assertStatus(404);
    });

    it('nao expoe entrada que nao e log pelo detalhe', function () {
        $uuid = monitoringEntry(['type' => 'request']);

        $this->getJson("/api/v1/dashboard/admin/logs/{$uuid}")->assertStatus(404);
    });
});

describe('Sem o pacote de monitoramento', function () {
    it('responde que o armazenamento nao esta disponivel', function () {
        // A tabela vem das migrations do monitoring; consultar sem checar daria
        // 500 onde a resposta honesta e "nao instalado".
        Schema::dropIfExists('monitoring');

        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/v1/dashboard/admin/logs')->assertStatus(410);
    });
});
