<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;
use RiseTechApps\ApiKey\Models\Plan\Plan;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Instalação saudável como ponto de partida; cada teste estraga uma coisa.
    config([
        'api-key.mercadopago.access_token' => 'APP_USR-token',
        'api-key.mercadopago.public_key' => 'APP_USR-public',
        'api-key.mercadopago.webhook_secret' => 'segredo',
        'api-key.mercadopago.notification_url' => 'https://exemplo.com/api/v1/dashboard/checkout/webhook',
        'api-key.request_log.queue' => null,
        'api-key.refund.window_days' => 0,
    ]);
});

describe('Configuracao do gateway', function () {
    it('passa com a instalacao completa', function () {
        $this->artisan('api-key:check')->assertSuccessful();
    });

    it('falha sem access token, porque nenhum pagamento pode ser criado', function () {
        config(['api-key.mercadopago.access_token' => null]);

        $this->artisan('api-key:check')
            ->expectsOutputToContain('MP_ACCESS_TOKEN')
            ->assertFailed();
    });

    it('falha sem segredo de webhook, porque a confirmacao nunca chega', function () {
        config(['api-key.mercadopago.webhook_secret' => null]);

        $this->artisan('api-key:check')->assertFailed();
    });

    it('falha com notification_url que nao e HTTPS', function () {
        // O gateway valida a URL e recusa a criacao do pagamento; o checkout
        // inteiro para, entao isto e problema e nao aviso.
        config(['api-key.mercadopago.notification_url' => 'http://localhost/webhook']);

        $this->artisan('api-key:check')
            ->expectsOutputToContain('HTTPS')
            ->assertFailed();
    });

    it('apenas avisa quando notification_url esta vazia', function () {
        // Correto em desenvolvimento: preencher com localhost derrubaria o
        // checkout local. Aviso nao pode derrubar um deploy.
        config(['api-key.mercadopago.notification_url' => null]);

        $this->artisan('api-key:check')->assertSuccessful();
    });
});

describe('Fila do log de requisicoes', function () {
    it('nao cobra worker quando o log grava no proprio processo', function () {
        config(['api-key.request_log.queue' => null]);

        $this->artisan('api-key:check')
            ->expectsOutputToContain('afterResponse')
            ->assertSuccessful();
    });

    it('acusa Horizon que nao observa a fila configurada', function () {
        // O caso real: o log vai para `logs`, o Horizon padrao observa apenas
        // `default`, e os jobs se acumulam sem ninguem acusar erro.
        config([
            'api-key.request_log.queue' => 'logs',
            'api-key.request_log.connection' => 'redis',
            'horizon.defaults' => [
                'supervisor-1' => ['connection' => 'redis', 'queue' => ['default']],
            ],
        ]);

        $this->artisan('api-key:check')
            ->expectsOutputToContain('NENHUM supervisor observa a fila `logs`')
            ->assertFailed();
    });

    it('aceita Horizon com a fila declarada', function () {
        config([
            'api-key.request_log.queue' => 'logs',
            'api-key.request_log.connection' => 'redis',
            'horizon.defaults' => [
                'supervisor-1' => ['connection' => 'redis', 'queue' => ['default', 'logs']],
            ],
        ]);

        $this->artisan('api-key:check')->expectsOutputToContain('Horizon observa a fila `logs`');
    });

    it('encontra a fila declarada em um environment, nao so no defaults', function () {
        config([
            'api-key.request_log.queue' => 'logs',
            'horizon.defaults' => [],
            'horizon.environments' => [
                'production' => [
                    'supervisor-logs' => ['connection' => 'redis', 'queue' => ['logs']],
                ],
            ],
        ]);

        $this->artisan('api-key:check')->expectsOutputToContain('Horizon observa a fila `logs`');
    });
});

describe('Agendador', function () {
    it('acusa pagamento em analise represado', function () {
        // Nao da para verificar o cron de fora. O indicio e o efeito: a
        // reconciliacao resolveria isto em no maximo 15 minutos.
        $pending = PendingPayment::create([
            'authentication_id' => Authentication::factory()->create()->getKey(),
            'plan_id' => Plan::factory()->create()->getKey(),
            'payment_id' => '9001',
            'amount' => 100.00,
            'status' => 'in_process',
        ]);

        $pending->forceFill(['created_at' => now()->subHours(5)])->saveQuietly();

        $this->artisan('api-key:check')
            ->expectsOutputToContain('schedule:run')
            ->assertFailed();
    });

    it('ignora espera recente, que ainda pode ser resolvida', function () {
        PendingPayment::create([
            'authentication_id' => Authentication::factory()->create()->getKey(),
            'plan_id' => Plan::factory()->create()->getKey(),
            'payment_id' => '9002',
            'amount' => 100.00,
            'status' => 'in_process',
        ]);

        $this->artisan('api-key:check')->assertSuccessful();
    });
});
