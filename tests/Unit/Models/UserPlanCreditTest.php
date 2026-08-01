<?php

use Illuminate\Support\Carbon;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Congelado: unusedCredit() compara timestamps, e sem isto o now() de dentro
    // do método difere em microssegundos do now() usado para montar as datas do
    // teste, deslocando o crédito esperado.
    Carbon::setTestNow('2026-07-31 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('UserPlan proration credit', function () {
    it('credits the unused share of the period', function () {
        $plan = Plan::factory()->create(['price' => 30.00]);

        // 30 dias de período, 3 consumidos: sobram 27 -> 90% de R$30,00.
        $userPlan = UserPlan::factory()->create([
            'plan_id' => $plan->id,
            'start_date' => now()->subDays(3),
            'end_date' => now()->addDays(27),
            'active' => true,
        ]);

        expect($userPlan->unusedCredit())->toBe(27.00);
    });

    it('credits the whole price when the period has just started', function () {
        $plan = Plan::factory()->create(['price' => 99.90]);

        $userPlan = UserPlan::factory()->create([
            'plan_id' => $plan->id,
            'start_date' => now(),
            'end_date' => now()->addDays(30),
            'active' => true,
        ]);

        expect($userPlan->unusedCredit())->toBe(99.90);
    });

    it('credits nothing for a free plan', function () {
        $plan = Plan::factory()->create(['price' => 0]);

        $userPlan = UserPlan::factory()->create([
            'plan_id' => $plan->id,
            'start_date' => now()->subDays(3),
            'end_date' => now()->addDays(27),
            'active' => true,
        ]);

        expect($userPlan->unusedCredit())->toBe(0.0);
    });

    it('credits nothing once the period has run out', function () {
        $plan = Plan::factory()->create(['price' => 30.00]);

        $userPlan = UserPlan::factory()->create([
            'plan_id' => $plan->id,
            'start_date' => now()->subDays(30),
            'end_date' => now()->subDay(),
            'active' => true,
        ]);

        expect($userPlan->unusedCredit())->toBe(0.0);
    });

    it('credits nothing inside the grace period', function () {
        config(['api-key.grace_period_days' => 3]);

        $plan = Plan::factory()->create(['price' => 30.00]);

        // Ainda acessível pela carência, mas a janela paga acabou — creditar aqui
        // devolveria dinheiro por dias que o usuário já usou de graça.
        $userPlan = UserPlan::factory()->create([
            'plan_id' => $plan->id,
            'start_date' => now()->subDays(30),
            'end_date' => now()->subHours(12),
            'active' => true,
        ]);

        expect($userPlan->isInGracePeriod())->toBeTrue()
            ->and($userPlan->unusedCredit())->toBe(0.0);
    });

    it('credits nothing for an inactive subscription', function () {
        $plan = Plan::factory()->create(['price' => 30.00]);

        $userPlan = UserPlan::factory()->create([
            'plan_id' => $plan->id,
            'start_date' => now()->subDays(3),
            'end_date' => now()->addDays(27),
            'active' => false,
        ]);

        expect($userPlan->unusedCredit())->toBe(0.0);
    });

    it('never credits more than the plan price', function () {
        $plan = Plan::factory()->create(['price' => 30.00]);

        // start_date no futuro põe a razão acima de 1; sem o clamp isto pagaria
        // mais crédito do que jamais foi cobrado.
        $userPlan = UserPlan::factory()->create([
            'plan_id' => $plan->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(30),
            'active' => true,
        ]);

        expect($userPlan->unusedCredit())->toBe(30.00);
    });

    it('prorates over the real period length, not the plan cycle', function () {
        $plan = Plan::factory()->create(['price' => 100.00]);

        // Período de 10 dias (encurtado por uma troca anterior), 5 restantes.
        // Medindo pelo ciclo nominal de 30 dias o crédito sairia errado.
        $userPlan = UserPlan::factory()->create([
            'plan_id' => $plan->id,
            'start_date' => now()->subDays(5),
            'end_date' => now()->addDays(5),
            'active' => true,
        ]);

        expect($userPlan->unusedCredit())->toBe(50.00);
    });
});
