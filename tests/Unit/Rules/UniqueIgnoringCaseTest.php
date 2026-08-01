<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use RiseTechApps\ApiKey\Models\Coupon\Coupon;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Rules\Validation\UniqueIgnoringCase;

uses(RefreshDatabase::class);

/** Roda a regra e devolve a mensagem de falha, ou null se ela passou. */
function runRule(UniqueIgnoringCase $rule, string $value, string $attribute = 'name'): ?string
{
    $message = null;

    $rule->validate($attribute, $value, function (string $failure) use (&$message) {
        $message = $failure;
    });

    return $message;
}

describe('UniqueIgnoringCase', function () {
    it('accepts a value nobody is using', function () {
        Plan::factory()->create(['name' => 'PLANO BASICO']);

        expect(runRule(new UniqueIgnoringCase(Plan::class, 'name'), 'Plano Avancado'))->toBeNull();
    });

    it('rejects an exact match', function () {
        Plan::factory()->create(['name' => 'PLANO BASICO']);

        expect(runRule(new UniqueIgnoringCase(Plan::class, 'name'), 'PLANO BASICO'))->not->toBeNull();
    });

    it('rejects a match that differs only in case', function () {
        // O motivo de a regra existir: é este caso que o `unique:` deixa passar,
        // porque compara antes de o to-upper normalizar.
        Plan::factory()->create(['name' => 'PLANO BASICO']);

        expect(runRule(new UniqueIgnoringCase(Plan::class, 'name'), 'plano basico'))->not->toBeNull()
            ->and(runRule(new UniqueIgnoringCase(Plan::class, 'name'), 'Plano Basico'))->not->toBeNull();
    });

    it('ignores the record being edited', function () {
        $plan = Plan::factory()->create(['name' => 'PLANO BASICO']);

        expect(runRule(new UniqueIgnoringCase(Plan::class, 'name', $plan->getKey()), 'plano basico'))
            ->toBeNull();
    });

    it('accepts the record itself passed as a model', function () {
        // O route binding entrega o model resolvido; o id cru vem das rotas sem
        // binding. A regra tem de aceitar as duas formas.
        $plan = Plan::factory()->create(['name' => 'PLANO BASICO']);

        expect(runRule(new UniqueIgnoringCase(Plan::class, 'name', $plan), 'plano basico'))->toBeNull();
    });

    it('still rejects a conflict with a different record while editing', function () {
        Plan::factory()->create(['name' => 'PLANO BASICO']);
        $outro = Plan::factory()->create(['name' => 'PLANO AVANCADO']);

        expect(runRule(new UniqueIgnoringCase(Plan::class, 'name', $outro->getKey()), 'plano basico'))
            ->not->toBeNull();
    });

    it('uses the message it was given', function () {
        Plan::factory()->create(['name' => 'PLANO BASICO']);

        $rule = new UniqueIgnoringCase(Plan::class, 'name', null, 'Ja existe um plano com este nome.');

        expect(runRule($rule, 'plano basico'))->toBe('Ja existe um plano com este nome.');
    });

    it('works for any model with the to-upper scope', function () {
        Coupon::factory()->create(['code' => 'BLACKFRIDAY']);

        expect(runRule(new UniqueIgnoringCase(Coupon::class, 'code'), 'BlackFriday', 'code'))
            ->not->toBeNull();
    });
});
