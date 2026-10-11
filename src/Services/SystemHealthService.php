<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RiseTechApps\ApiKey\Gateways\GatewayRegistry;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;

/**
 * Saúde da instalação para o painel admin: gateway, webhooks, rotinas
 * agendadas, fila e pagamentos parados.
 *
 * Nada aqui chama o gateway: a tela abre rápido e não gasta cota de API. O
 * teste de credenciais fica no botão "Testar conexão" de Pagamentos.
 *
 * Último webhook e última execução de cada rotina são gravados no cache por
 * quem os recebe ou executa (ver record*()). Com cache `array` (sem
 * persistência) eles aparecem como "sem registro".
 */
class SystemHealthService
{
    /**
     * Rotinas do pacote e de quanto em quanto tempo devem rodar, em minutos.
     *
     * @var array<string, array{label: string, every: int}>
     */
    public const COMMANDS = [
        'billing:process-renewals' => ['label' => 'Renovações', 'every' => 1440],
        'billing:charge-overage' => ['label' => 'Cobrança de excedente', 'every' => 1440],
        'api-key:reconcile-payments' => ['label' => 'Reconciliação de pagamentos', 'every' => 15],
        'api-key:retry-validation-refunds' => ['label' => 'Estornos de validação', 'every' => 1440],
        'api-key:prune-logs' => ['label' => 'Limpeza do log de requisições', 'every' => 1440],
    ];

    public static function recordWebhook(string $gateway): void
    {
        try {
            Cache::forever("api-key:health:webhook:{$gateway}", now()->toIso8601String());
        } catch (\Throwable) {
            // Saúde é informativa: falha de cache não derruba o webhook.
        }
    }

    public static function recordCommand(string $command, int $exitCode): void
    {
        if (! isset(self::COMMANDS[$command])) {
            return;
        }

        try {
            Cache::forever("api-key:health:command:{$command}", ['at' => now()->toIso8601String(), 'exit_code' => $exitCode]);
        } catch (\Throwable) {
        }
    }

    /**
     * @return array{status: string, checks: list<array{group: string, label: string, status: string, detail: string}>}
     */
    public function report(): array
    {
        $checks = [
            ...$this->gatewayChecks(),
            ...$this->commandChecks(),
            ...$this->queueChecks(),
            ...$this->paymentChecks(),
            ...$this->appChecks(),
        ];

        $status = collect($checks)->contains('status', 'error')
            ? 'error'
            : (collect($checks)->contains('status', 'warning') ? 'warning' : 'ok');

        return ['status' => $status, 'checks' => $checks];
    }

    /** @return list<array<string, string>> */
    private function gatewayChecks(): array
    {
        $active = PaymentSettingsService::activeGateway();
        $configured = ['mercadopago' => (string) config('api-key.mercadopago.access_token') !== ''];
        foreach (GatewayRegistry::all() as $driver) {
            $configured[$driver->name()] = $driver->configured();
        }
        $names = array_intersect_key(GatewayRegistry::LABELS, $configured);

        $checks = [$this->check(
            'Gateway',
            "Gateway ativo: {$names[$active]}",
            $configured[$active] ? 'ok' : 'error',
            $configured[$active] ? 'Credenciais configuradas.' : 'O gateway ativo está sem credenciais: checkout e cadastro de cartão vão falhar.',
        )];

        foreach ($names as $gateway => $name) {
            if (! $configured[$gateway]) {
                continue;
            }

            $last = $this->cached("api-key:health:webhook:{$gateway}");
            $checks[] = $this->check(
                'Gateway',
                "Último webhook do {$name}",
                $last ? 'ok' : ($gateway === $active ? 'warning' : 'ok'),
                $last ? $this->ago($last) : 'Nenhum webhook registrado ainda. Confira a URL cadastrada no painel do gateway.',
            );
        }

        if ($active === 'mercadopago' && (string) config('api-key.mercadopago.webhook_secret') === '') {
            $checks[] = $this->check('Gateway', 'Assinatura do webhook do Mercado Pago', 'error', 'Sem segredo configurado: webhooks assinados são recusados.');
        }

        return $checks;
    }

    /** @return list<array<string, string>> */
    private function commandChecks(): array
    {
        $checks = [];

        foreach (self::COMMANDS as $command => $meta) {
            $last = $this->cached("api-key:health:command:{$command}");

            if (! is_array($last) || empty($last['at'])) {
                $checks[] = $this->check('Rotinas', $meta['label'], 'warning', "Sem registro de execução de {$command}. Confira se o agendador (schedule:run) está no cron.");

                continue;
            }

            $at = Carbon::parse($last['at']);
            $late = $at->lt(now()->subMinutes((int) ($meta['every'] * 1.5)));
            $failed = (int) ($last['exit_code'] ?? 0) !== 0;

            $checks[] = $this->check(
                'Rotinas',
                $meta['label'],
                $failed ? 'error' : ($late ? 'warning' : 'ok'),
                ($failed ? 'Última execução terminou com erro, ' : 'Última execução ').$this->ago($last['at']).($late ? '. Está atrasada.' : '.'),
            );
        }

        return $checks;
    }

    /** @return list<array<string, string>> */
    private function queueChecks(): array
    {
        $checks = [];
        $connection = (string) config('queue.default');

        $checks[] = $this->check(
            'Fila',
            'Conexão da fila',
            $connection === 'sync' ? 'warning' : 'ok',
            $connection === 'sync'
                ? 'Fila síncrona: e-mails, webhooks e cobranças rodam dentro da requisição.'
                : "Usando {$connection}.",
        );

        if (Schema::hasTable('failed_jobs')) {
            $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->count();
            $checks[] = $this->check('Fila', 'Jobs com falha (7 dias)', $failed > 0 ? 'warning' : 'ok', $failed > 0 ? "{$failed} job(s) falharam. Veja com queue:failed." : 'Nenhuma falha.');
        }

        if ($connection === 'database' && Schema::hasTable('jobs')) {
            $stuck = DB::table('jobs')->where('created_at', '<', now()->subMinutes(30)->getTimestamp())->count();
            $checks[] = $this->check('Fila', 'Jobs parados há mais de 30 minutos', $stuck > 0 ? 'error' : 'ok', $stuck > 0 ? "{$stuck} job(s) esperando: o worker pode estar parado." : 'Fila andando.');
        }

        return $checks;
    }

    /** @return list<array<string, string>> */
    private function paymentChecks(): array
    {
        $stale = PendingPayment::query()->unsettled()->where('created_at', '<', now()->subHours(2))->count();

        return [$this->check(
            'Pagamentos',
            'Pagamentos em análise há mais de 2 horas',
            $stale > 0 ? 'warning' : 'ok',
            $stale > 0 ? "{$stale} pagamento(s) sem desfecho. A reconciliação deveria resolvê-los." : 'Nenhum pagamento parado.',
        )];
    }

    /** @return list<array<string, string>> */
    private function appChecks(): array
    {
        $production = app()->environment('production');
        $checks = [];

        $checks[] = $this->check('Aplicação', 'APP_KEY', (string) config('app.key') !== '' ? 'ok' : 'error', (string) config('app.key') !== '' ? 'Definida.' : 'Sem APP_KEY: as credenciais salvas no painel não podem ser lidas.');

        if ($production) {
            $checks[] = $this->check('Aplicação', 'Modo debug', config('app.debug') ? 'error' : 'ok', config('app.debug') ? 'APP_DEBUG ligado em produção expõe detalhes de erro.' : 'Desligado.');
            $checks[] = $this->check('Aplicação', 'E-mail', in_array(config('mail.default'), ['log', 'array'], true) ? 'error' : 'ok', 'Driver: '.config('mail.default').'.');
        }

        $https = str_starts_with((string) config('app.url'), 'https://');
        $checks[] = $this->check('Aplicação', 'APP_URL com HTTPS', $https ? 'ok' : ($production ? 'error' : 'warning'), $https ? (string) config('app.url') : 'Os gateways só entregam webhook em HTTPS.');

        return $checks;
    }

    /** @return array{group: string, label: string, status: string, detail: string} */
    private function check(string $group, string $label, string $status, string $detail): array
    {
        return compact('group', 'label', 'status', 'detail');
    }

    private function cached(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    private function ago(string $iso): string
    {
        return Carbon::parse($iso)->locale('pt_BR')->diffForHumans();
    }
}
