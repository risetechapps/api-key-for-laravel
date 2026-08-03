<?php

namespace RiseTechApps\ApiKey\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;

/**
 * Confere se a instalação está completa.
 *
 * A maioria das falhas deste pacote é silenciosa por natureza: fila sem worker
 * não dá erro, cron parado não dá erro, `notification_url` ausente não dá erro.
 * O sintoma aparece dias depois, na forma de histórico vazio ou de um pagamento
 * que ninguém resolveu. Este comando existe para transformar essas ausências em
 * saída legível, antes de virarem incidente.
 *
 * O que ele NÃO faz: adivinhar. Onde não dá para verificar de fora — se existe
 * um worker vivo, se o cron chama o scheduler — ele diz o que observou e qual é
 * o indício, em vez de afirmar.
 */
class CheckInstallationCommand extends Command
{
    protected $signature = 'api-key:check';

    protected $description = 'Verifica a instalação do pacote e aponta o que está faltando';

    /** @var list<string> */
    private array $failures = [];

    /** @var list<string> */
    private array $warnings = [];

    public function handle(): int
    {
        $this->info('Verificando a instalação do api-key-for-laravel...');
        $this->newLine();

        $this->checkTables();
        $this->checkMercadoPago();
        $this->checkRequestLog();
        $this->checkScheduler();
        $this->checkApiKeys();
        $this->checkRefundPolicy();

        return $this->summary();
    }

    private function checkTables(): void
    {
        $this->components->info('Banco de dados');

        $tables = [
            'authentications', 'api_keys', 'plans', 'user_plans', 'coupons',
            'request_logs', 'user_cards', 'features', 'pending_payments',
        ];

        $missing = array_values(array_filter($tables, fn (string $t) => ! Schema::hasTable($t)));

        if ($missing === []) {
            $this->ok('Todas as tabelas do pacote existem');

            return;
        }

        $this->problem('Tabelas faltando: '.implode(', ', $missing).'. Rode `php artisan migrate`.');
    }

    private function checkMercadoPago(): void
    {
        $this->components->info('Mercado Pago');

        if (! config('api-key.mercadopago.access_token')) {
            $this->problem('MP_ACCESS_TOKEN não definido: nenhum pagamento pode ser criado.');
        } else {
            $this->ok('Access token definido');
        }

        if (! config('api-key.mercadopago.public_key')) {
            $this->caution('MP_PUBLIC_KEY não definido: o checkout do painel não consegue tokenizar cartão.');
        }

        if (! config('api-key.mercadopago.webhook_secret')) {
            $this->problem('MP_WEBHOOK_SECRET não definido: o webhook assinado é recusado com 400, então nenhuma confirmação de pagamento chega.');
        } else {
            $this->ok('Segredo do webhook definido');
        }

        $notificationUrl = config('api-key.mercadopago.notification_url');

        if (! $notificationUrl) {
            $this->caution('MP_NOTIFICATION_URL vazia. Correto em desenvolvimento; em produção a revisão de qualidade do Mercado Pago exige o campo, e sem ele as notificações dependem apenas do que estiver cadastrado no painel.');
        } elseif (! str_starts_with((string) $notificationUrl, 'https://')) {
            $this->problem('MP_NOTIFICATION_URL precisa ser HTTPS pública. O gateway valida a URL e RECUSA a criação do pagamento se ela não for — o checkout inteiro para.');
        } else {
            $this->ok('notification_url configurada: '.$notificationUrl);
        }
    }

    private function checkRequestLog(): void
    {
        $this->components->info('Log de requisições');

        $queue = config('api-key.request_log.queue');

        if (! $queue) {
            $this->ok('Gravação no próprio processo (afterResponse); não depende de worker');

            return;
        }

        $connection = config('api-key.request_log.connection');
        $this->line("  fila <options=bold>{$queue}</> na conexão <options=bold>".($connection ?: 'default').'</>');

        $this->checkHorizonWatches($queue, $connection);

        // Não dá para saber daqui se existe worker vivo. O acúmulo é o indício:
        // fila cheia com o dashboard contando certo é a assinatura exata de log
        // enfileirado sem ninguém consumindo.
        try {
            $size = Queue::connection($connection ?: null)->size($queue);

            if ($size > 100) {
                $this->problem("{$size} jobs acumulados na fila `{$queue}`. Nenhum worker parece estar consumindo, e o histórico de requisições fica vazio enquanto isso.");
            } else {
                $this->ok("Fila `{$queue}` com {$size} job(s) pendente(s)");
            }
        } catch (\Throwable $e) {
            $this->caution("Não foi possível medir a fila `{$queue}`: ".$e->getMessage());
        }
    }

    /**
     * Se o Horizon está declarado e observa a fila.
     *
     * O Horizon processa apenas o que está nos supervisors; job enviado a uma
     * fila não declarada fica acumulado sem que nada acuse erro.
     */
    private function checkHorizonWatches(string $queue, ?string $connection): void
    {
        $horizon = config('horizon');

        if (! is_array($horizon)) {
            return;
        }

        $supervisors = array_merge(
            [config('horizon.defaults', [])],
            array_values(config('horizon.environments', []))
        );

        foreach ($supervisors as $group) {
            foreach ((array) $group as $supervisor) {
                if (in_array($queue, (array) ($supervisor['queue'] ?? []), true)) {
                    $this->ok("Horizon observa a fila `{$queue}`");

                    return;
                }
            }
        }

        $this->problem("O Horizon está instalado mas NENHUM supervisor observa a fila `{$queue}`. Acrescente-a em config/horizon.php, senão o log de requisições nunca é gravado.");
    }

    private function checkScheduler(): void
    {
        $this->components->info('Agendador');

        if (! Schema::hasTable('pending_payments')) {
            return;
        }

        // Não há como verificar o cron de fora. O que dá para observar é o
        // efeito: espera antiga sem desfecho significa que a reconciliação não
        // rodou, porque ela resolveria em no máximo 15 minutos.
        $stale = PendingPayment::query()
            ->unsettled()
            ->where('created_at', '<', now()->subHours(2))
            ->count();

        if ($stale > 0) {
            $this->problem("{$stale} pagamento(s) em análise há mais de duas horas sem desfecho. A reconciliação roda a cada 15 minutos, então isto indica `schedule:run` parado no cron — há dinheiro cobrado sem assinatura entregue.");

            return;
        }

        $this->ok('Nenhum pagamento em análise represado');
        $this->line('  <fg=gray>o cron em si não é verificável daqui; confirme que `schedule:run` roda a cada minuto</>');
    }

    private function checkApiKeys(): void
    {
        $this->components->info('API keys');

        if (! Schema::hasTable('api_keys')) {
            return;
        }

        $legacy = ApiKey::query()->where('active', true)->whereNull('lookup_hash')->count();

        if ($legacy > 0) {
            $this->caution("{$legacy} chave(s) ativa(s) sem lookup_hash. Elas ainda funcionam, mas cada autenticação passa por um scan com bcrypt até encontrá-las. O backfill acontece sozinho no primeiro uso de cada uma; `api-key:rotate-keys --legacy` resolve de uma vez.");

            return;
        }

        $this->ok('Todas as chaves ativas usam busca indexada');
    }

    private function checkRefundPolicy(): void
    {
        $this->components->info('Política de reembolso');

        $window = (int) config('api-key.refund.window_days', 0);

        if ($window <= 0) {
            $this->line('  <fg=gray>desligada (API_KEY_REFUND_WINDOW_DAYS=0); cancelar não estorna</>');

            return;
        }

        $this->ok("Janela de {$window} dia(s), teto de ".config('api-key.refund.max_usage_percent', 50).'% de consumo');
    }

    private function ok(string $message): void
    {
        $this->line("  <fg=green>✓</> {$message}");
    }

    private function caution(string $message): void
    {
        $this->warnings[] = $message;
        $this->line("  <fg=yellow>!</> {$message}");
    }

    private function problem(string $message): void
    {
        $this->failures[] = $message;
        $this->line("  <fg=red>✗</> {$message}");
    }

    private function summary(): int
    {
        $this->newLine();

        if ($this->failures === [] && $this->warnings === []) {
            $this->components->info('Instalação completa.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Problemas', (string) count($this->failures));
        $this->components->twoColumnDetail('Avisos', (string) count($this->warnings));

        // Falha só em problema: aviso não pode derrubar um deploy, senão o
        // comando deixa de ser usado em pipeline.
        return $this->failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
