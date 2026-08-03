<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Comandos que o pacote agenda, na expressao cron registrada. */
function scheduledCommands(): array
{
    $map = [];

    foreach (app(Schedule::class)->events() as $event) {
        // A linha agendada e '<php>' 'artisan' <comando> [opcoes]; interessa so
        // o nome, que e a parte que o operador reconhece. Ele NAO vem entre
        // aspas, ao contrario do binario e do artisan.
        if (preg_match('/"artisan"\s+(\S+)/', $event->command ?? '', $matches)) {
            $map[$matches[1]] = $event->expression;
        }
    }

    return $map;
}

describe('Rotinas agendadas', function () {
    it('agenda a reconciliacao de pagamentos', function () {
        // Sem isto, uma compra que fica em analise e cujo webhook nao chega
        // permanece pendente para sempre: o comando existe mas ninguem o roda.
        // Foi assim que ele nasceu, e este teste existe para nao voltar a ser.
        expect(scheduledCommands())->toHaveKey('api-key:reconcile-payments');
    });

    it('reconcilia com folga menor que a espera minima do comando', function () {
        // O comando ignora esperas com menos de 15 minutos. Agendar mais
        // espacado que isso atrasaria a resolucao sem necessidade; mais apertado
        // so consultaria o que ele mesmo descarta.
        expect(scheduledCommands()['api-key:reconcile-payments'])->toBe('*/15 * * * *');
    });

    it('agenda o reprocessamento do estorno de validacao', function () {
        expect(scheduledCommands())->toHaveKey('api-key:retry-validation-refunds');
    });

    it('agenda a cobranca de renovacoes e a poda de logs', function () {
        expect(scheduledCommands())
            ->toHaveKey('billing:process-renewals')
            ->toHaveKey('api-key:prune-logs');
    });

    // As chaves que desligam cada rotina nao tem teste: o agendamento e montado
    // no boot do provider, e alternar a configuracao depois disso exigiria
    // reconstruir o container. Vale para `reconciliation.*` e para o
    // `request_log.prune_enabled`, que ja era assim.
});
