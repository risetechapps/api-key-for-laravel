<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Services\RefundDecision;
use RiseTechApps\ApiKey\Services\RefundPolicy;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'api-key.refund.window_days' => 7,
        'api-key.refund.max_usage_percent' => 50,
    ]);

    $this->policy = new RefundPolicy;
    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create(['price' => 100.00, 'request_limit' => 1000]);
});

/** Assinatura paga, dentro da janela e com consumo baixo, salvo o que for sobrescrito. */
function paidPlan(array $attributes = []): UserPlan
{
    return UserPlan::factory()->create(array_merge([
        'authentication_id' => test()->user->getKey(),
        'plan_id' => test()->plan->getKey(),
        'active' => true,
        'start_date' => now()->subDays(2),
        'end_date' => now()->addDays(28),
        'requests_used' => 100,
        'payment_id' => '4400111222',
        'payment_amount' => 100.00,
    ], $attributes))->load('plan');
}

describe('Comportas de configuracao', function () {
    it('recusa quando a politica esta desligada', function () {
        // Padrão de fábrica: 0 dias. Mover dinheiro sozinho não pode ser
        // comportamento herdado por quem só atualizou o pacote.
        config(['api-key.refund.window_days' => 0]);

        $decision = $this->policy->decide(paidPlan());

        expect($decision->eligible)->toBeFalse()
            ->and($decision->reason)->toBe(RefundDecision::REASON_DISABLED);
    });

    it('concede dentro da janela e abaixo do teto', function () {
        $decision = $this->policy->decide(paidPlan());

        expect($decision->eligible)->toBeTrue()
            ->and($decision->amount)->toBe(100.00);
    });
});

describe('Janela de arrependimento', function () {
    it('recusa depois de a janela vencer', function () {
        $decision = $this->policy->decide(paidPlan(['start_date' => now()->subDays(8)]));

        expect($decision->reason)->toBe(RefundDecision::REASON_WINDOW_EXPIRED);
    });

    it('aceita no ultimo dia da janela', function () {
        $decision = $this->policy->decide(paidPlan(['start_date' => now()->subDays(7)->addMinutes(5)]));

        expect($decision->eligible)->toBeTrue();
    });

    it('nao reabre a janela a cada renovacao', function () {
        // O ponto da regra: a renovação cria um UserPlan novo com start_date
        // novo. Contar dele deixaria o cliente assinar, usar abaixo do teto e
        // cancelar no dia 6, todo mês, sem nunca pagar de fato.
        UserPlan::factory()->create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $this->plan->getKey(),
            'active' => false,
            'start_date' => now()->subDays(40),
            'end_date' => now()->subDays(10),
        ]);

        $renovado = paidPlan(['start_date' => now()->subDay()]);

        expect($this->policy->decide($renovado)->reason)
            ->toBe(RefundDecision::REASON_WINDOW_EXPIRED);
    });

    it('conta a janela por plano, nao por cliente', function () {
        // Assinar um plano diferente e uma contratação nova, com direito próprio.
        UserPlan::factory()->create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => Plan::factory()->create()->getKey(),
            'active' => false,
            'start_date' => now()->subDays(90),
            'end_date' => now()->subDays(60),
        ]);

        expect($this->policy->decide(paidPlan())->eligible)->toBeTrue();
    });
});

describe('Teto de consumo', function () {
    it('recusa acima do teto configurado', function () {
        $decision = $this->policy->decide(paidPlan(['requests_used' => 501]));

        expect($decision->reason)->toBe(RefundDecision::REASON_USAGE_EXCEEDED);
    });

    it('aceita exatamente no teto', function () {
        expect($this->policy->decide(paidPlan(['requests_used' => 500]))->eligible)->toBeTrue();
    });

    it('respeita um teto diferente', function () {
        config(['api-key.refund.max_usage_percent' => 10]);

        expect($this->policy->decide(paidPlan(['requests_used' => 200]))->reason)
            ->toBe(RefundDecision::REASON_USAGE_EXCEEDED);
    });

    it('nao aplica o teto a plano ilimitado', function () {
        // request_limit 0 é ilimitado: não existe percentual de um limite que
        // não existe, então a janela vira a única barreira.
        $ilimitado = Plan::factory()->unlimited()->create(['price' => 50.00]);

        $userPlan = UserPlan::factory()->create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $ilimitado->getKey(),
            'active' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDays(29),
            'requests_used' => 999999,
            'payment_id' => '999',
            'payment_amount' => 50.00,
        ])->load('plan');

        expect($this->policy->decide($userPlan)->eligible)->toBeTrue();
    });
});

describe('Assinaturas sem dinheiro envolvido', function () {
    it('recusa quando nao houve pagamento', function () {
        // Plano gratuito, cupom de 100% ou crédito de troca cobrindo tudo.
        $decision = $this->policy->decide(paidPlan(['payment_id' => null, 'payment_amount' => null]));

        expect($decision->reason)->toBe(RefundDecision::REASON_NOT_PAID);
    });

    it('recusa quando o valor pago foi zero', function () {
        $decision = $this->policy->decide(paidPlan(['payment_amount' => 0]));

        expect($decision->reason)->toBe(RefundDecision::REASON_NOT_PAID);
    });

    it('recusa um segundo estorno do mesmo pagamento', function () {
        $decision = $this->policy->decide(paidPlan(['refunded_at' => now()->subDay()]));

        expect($decision->reason)->toBe(RefundDecision::REASON_ALREADY_REFUNDED);
    });
});
