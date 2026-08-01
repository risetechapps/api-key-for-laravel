<?php

namespace RiseTechApps\ApiKey\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

/**
 * O dinheiro foi devolvido e o acesso encerrado no mesmo ato.
 *
 * Distinto de PlanCancelled, onde nada é retirado: ali o período pago segue até
 * o fim e só a renovação para. Aqui o assinante recebeu o valor de volta, então
 * manter o acesso seria entregar o período de graça.
 */
class PlanRefunded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Authentication $user,
        public readonly UserPlan $userPlan,
        public readonly Plan $plan,
        public readonly float $amount,
        public readonly string $refundId,
    ) {}
}
