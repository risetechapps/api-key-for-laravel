<?php

namespace RiseTechApps\ApiKey\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;
use RiseTechApps\ApiKey\Services\PaymentOutcomeService;

/**
 * Resolve compras que ficaram em análise e nunca receberam o webhook.
 *
 * O caminho normal e imediato é o webhook. Ele falha de várias formas fora do
 * controle do pacote — `notification_url` ausente, indisponibilidade do servidor
 * na hora da entrega, assinatura recusada por segredo trocado — e cada falha
 * deixa dinheiro em jogo sem assinatura e sem ninguém avisado.
 *
 * Este comando pergunta ao gateway o que aconteceu com cada espera aberta e
 * aplica o mesmo desfecho que o webhook aplicaria. Agende junto das demais
 * rotinas; sem pendências ele não faz nada.
 */
class ReconcilePendingPaymentsCommand extends Command
{
    protected $signature = 'api-key:reconcile-payments
                            {--minutes=15 : Idade mínima da espera, em minutos}
                            {--limit=200 : Máximo de esperas por execução}
                            {--dry-run : Consulta o gateway e relata, sem aplicar}';

    protected $description = 'Consulta o gateway sobre compras em análise cujo webhook não chegou';

    public function handle(PaymentOutcomeService $outcome): int
    {
        // Uma espera recém-criada ainda tem chance de receber o webhook. Consultar
        // imediatamente só duplicaria trabalho e correria contra ele.
        $cutoff = now()->subMinutes(max(1, (int) $this->option('minutes')));

        $pending = PendingPayment::query()
            ->unsettled()
            ->where('created_at', '<', $cutoff)
            ->orderBy('created_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No pending payments to reconcile.');

            return self::SUCCESS;
        }

        MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));
        $client = new PaymentClient;

        $dryRun = (bool) $this->option('dry-run');
        $stillPending = 0;
        $resolved = 0;
        $failed = 0;

        foreach ($pending as $payment) {
            try {
                $remote = $client->get((int) $payment->payment_id);
                $status = (string) $remote->status;

                if (! in_array($status, ['approved', 'rejected', 'cancelled'], true)) {
                    $stillPending++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("  - payment {$payment->payment_id} -> {$status}");
                    $resolved++;

                    continue;
                }

                // Mesmo serviço do webhook: assinar na aprovação, devolver o
                // cupom e notificar na recusa. Aqui só chega o que o webhook
                // deixou passar, e o desfecho tem de ser idêntico ao que ele
                // aplicaria.
                $outcome->apply($remote);

                Log::warning('Pending payment resolved without webhook', [
                    'payment_id' => $payment->payment_id,
                    'gateway_status' => $status,
                    'user_id' => $payment->authentication_id,
                    'plan_id' => $payment->plan_id,
                    'created_at' => $payment->created_at?->toIso8601String(),
                ]);

                $this->line("  - payment {$payment->payment_id} -> {$status}");
                $resolved++;
            } catch (\Exception $e) {
                // Uma consulta que falha nao interrompe as demais: cada espera e
                // um comprador diferente aguardando resposta.
                $failed++;

                Log::warning('Pending payment reconciliation failed', [
                    'payment_id' => $payment->payment_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("{$resolved} resolved, {$stillPending} still under review, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
