<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\RequestLog\RequestLog;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = Authentication::factory()->create();
});

/** Uma linha de log registrada em `$requestedAt`. */
function logEntry(\DateTimeInterface|string $requestedAt): RequestLog
{
    return RequestLog::create([
        'authentication_id' => test()->user->getKey(),
        'endpoint' => '/api/v1/algo',
        'method' => 'GET',
        'response_code' => '200',
        'requested_at' => $requestedAt,
    ]);
}

describe('Retention window', function () {
    it('deletes what is older than the configured window', function () {
        config(['api-key.request_log.retention_days' => 90]);

        logEntry(now()->subDays(120));
        logEntry(now()->subDays(91));
        $keep = logEntry(now()->subDays(30));

        $this->artisan('api-key:prune-logs')->assertSuccessful();

        expect(RequestLog::count())->toBe(1)
            ->and(RequestLog::first()->getKey())->toBe($keep->getKey());
    });

    it('keeps a row that sits exactly on the cutoff', function () {
        // O corte é `< cutoff`; um registro na borda ainda está dentro da
        // janela contratada.
        config(['api-key.request_log.retention_days' => 30]);

        logEntry(now()->subDays(30));

        $this->artisan('api-key:prune-logs')->assertSuccessful();

        expect(RequestLog::count())->toBe(1);
    });

    it('honours the --days override', function () {
        config(['api-key.request_log.retention_days' => 90]);

        logEntry(now()->subDays(45));

        $this->artisan('api-key:prune-logs', ['--days' => 30])->assertSuccessful();

        expect(RequestLog::count())->toBe(0);
    });

    it('does nothing when retention is disabled', function () {
        // days = 0 desliga a poda; interpretar isso como "apagar tudo" destruiria
        // o histórico de quem só queria desativar a rotina.
        config(['api-key.request_log.retention_days' => 0]);

        logEntry(now()->subYears(2));

        $this->artisan('api-key:prune-logs')
            ->expectsOutputToContain('Retention is disabled')
            ->assertSuccessful();

        expect(RequestLog::count())->toBe(1);
    });
});

describe('Dry run', function () {
    it('reports the count without deleting', function () {
        config(['api-key.request_log.retention_days' => 30]);

        logEntry(now()->subDays(60));
        logEntry(now()->subDays(60));

        $this->artisan('api-key:prune-logs', ['--dry-run' => true])
            ->expectsOutputToContain('2 row(s)')
            ->assertSuccessful();

        expect(RequestLog::count())->toBe(2);
    });
});

describe('Batching', function () {
    it('drains everything even when the batch is smaller than the backlog', function () {
        // A poda roda em lotes por causa do PostgreSQL, que não tem DELETE LIMIT;
        // o laço tem de continuar até esvaziar, senão sobra resto a cada execução.
        config([
            'api-key.request_log.retention_days' => 30,
            'api-key.request_log.prune_chunk' => 100,
        ]);

        for ($i = 0; $i < 5; $i++) {
            logEntry(now()->subDays(60));
        }

        $this->artisan('api-key:prune-logs')
            ->expectsOutputToContain('Pruned 5 request log row(s)')
            ->assertSuccessful();

        expect(RequestLog::count())->toBe(0);
    });

    it('reports zero when there is nothing old enough', function () {
        config(['api-key.request_log.retention_days' => 90]);

        logEntry(now()->subDay());

        $this->artisan('api-key:prune-logs')
            ->expectsOutputToContain('Pruned 0 request log row(s)')
            ->assertSuccessful();

        expect(RequestLog::count())->toBe(1);
    });
});
