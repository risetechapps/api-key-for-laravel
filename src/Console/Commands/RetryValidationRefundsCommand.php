<?php

namespace RiseTechApps\ApiKey\Console\Commands;

use Illuminate\Console\Command;
use MercadoPago\Client\Payment\PaymentRefundClient;
use MercadoPago\MercadoPagoConfig;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;

/**
 * Reprocessa estornos da cobrança de validação de cartão.
 *
 * Salvar um cartão cobra um valor simbólico e o estorna em seguida. O estorno é
 * best-effort de propósito — o cartão já foi validado e associado ao cliente no
 * gateway, e derrubar o cadastro porque a devolução não saiu seria pior. O que
 * não pode é a falha desaparecer: sem isto, o cliente ficava com a cobrança e o
 * único vestígio era uma linha de log.
 *
 * Agende junto das outras rotinas do pacote; sem pendências ele não faz nada.
 */
class RetryValidationRefundsCommand extends Command
{
    protected $signature = 'api-key:retry-validation-refunds
                            {--amount=5.00 : Valor cobrado na validação}
                            {--limit=200 : Máximo de pendências por execução}
                            {--dry-run : Lista as pendências sem estornar}';

    protected $description = 'Reprocessa estornos pendentes da validação de cartão';

    public function handle(): int
    {
        $amount = (float) $this->option('amount');
        $limit = max(1, (int) $this->option('limit'));

        $pending = UserCard::query()
            ->pendingValidationRefund()
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No pending validation refunds.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($pending as $card) {
                $this->line("  - card {$card->getKey()} / payment {$card->validation_payment_id}");
            }

            $this->info("{$pending->count()} pending validation refund(s), nothing charged back.");

            return self::SUCCESS;
        }

        MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));

        $refunded = 0;
        $failed = 0;

        foreach ($pending as $card) {
            try {
                new PaymentRefundClient()->refund((int) $card->validation_payment_id, $amount);

                $card->update(['validation_refunded_at' => now()]);
                $refunded++;
            } catch (\Exception $e) {
                // Uma falha não interrompe as outras: cada pendência é um cliente
                // diferente com dinheiro parado.
                $failed++;

                logglyWarning()->withContext([
                    'card_id' => $card->getKey(),
                    'payment_id' => $card->validation_payment_id,
                    'error' => $e->getMessage(),
                ])->log('Validation refund retry failed');
            }
        }

        $this->info("Refunded {$refunded} validation charge(s).");

        if ($failed > 0) {
            // Falha de saída para o agendador enxergar; a pendência continua
            // marcada e volta na próxima execução.
            $this->warn("{$failed} still pending — see the log for the reason.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
