<?php

namespace RiseTechApps\ApiKey\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;

/**
 * Uma compra que estava em análise acabou recusada.
 *
 * Só existe para o caminho assíncrono. Recusa imediata o comprador vê na hora,
 * na resposta do checkout; esta aqui chega minutos ou horas depois, quando ele
 * já saiu da tela acreditando que seria avisado.
 */
class PaymentRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Authentication $user,
        public readonly Plan $plan,
        public readonly string $paymentId,
        public readonly float $amount,
        /** status_detail do gateway, quando informado. */
        public readonly ?string $reason = null,
        /**
         * Renovação de assinatura existente, e não compra nova.
         *
         * Muda o que dizer: numa compra a assinatura não chegou a existir;
         * numa renovação ela existe e apenas não vai continuar.
         */
        public readonly bool $isRenewal = false,
    ) {}
}
