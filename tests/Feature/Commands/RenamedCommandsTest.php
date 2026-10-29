<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Services\SystemHealthService;

uses(RefreshDatabase::class);

/*
 * admin:make e billing:* viraram api-key:*. Os nomes antigos continuam na 1.x
 * para não quebrar cron e scripts, e avisam para trocar.
 */

it('o nome antigo ainda roda o comando e avisa para trocar', function (string $old, string $new, array $args) {
    Authentication::factory()->create(['email' => 'dono@example.com']);

    $this->artisan($old, $args)
        ->expectsOutputToContain("O comando {$old} foi renomeado para {$new}")
        ->assertSuccessful();
})->with([
    'admin:make' => ['admin:make', 'api-key:admin-make', ['email' => 'dono@example.com']],
    'billing:process-renewals' => ['billing:process-renewals', 'api-key:billing-process-renewals', ['--dry-run' => true]],
    'billing:charge-overage' => ['billing:charge-overage', 'api-key:billing-charge-overage', ['--dry-run' => true]],
]);

it('o nome novo não avisa nada', function () {
    $this->artisan('api-key:billing-charge-overage', ['--dry-run' => true])
        ->doesntExpectOutputToContain('foi renomeado')
        ->assertSuccessful();
});

it('a saúde do sistema conta a execução pelo nome antigo para o nome novo', function () {
    SystemHealthService::recordCommand('billing:process-renewals', 0);

    expect(Cache::get('api-key:health:command:api-key:billing-process-renewals'))->toMatchArray(['exit_code' => 0])
        ->and(Cache::has('api-key:health:command:billing:process-renewals'))->toBeFalse();
});

it('o registro gravado com o nome antigo vale até a primeira execução com o novo', function () {
    // Gravado pela 1.0, antes de atualizar.
    Cache::forever('api-key:health:command:billing:charge-overage', ['at' => now()->toIso8601String(), 'exit_code' => 0]);

    $detail = collect(app(SystemHealthService::class)->report()['checks'])->firstWhere('label', 'Cobrança de excedente');

    expect($detail['detail'])->not->toContain('Sem registro');
});
