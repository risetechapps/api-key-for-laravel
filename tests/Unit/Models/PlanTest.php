<?php

use RiseTechApps\ApiKey\Enums\BillingCycle;
use RiseTechApps\ApiKey\Models\Plan\Plan;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Plan Attributes', function () {
    it('has request limit', function () {
        $plan = Plan::factory()->create([
            'request_limit' => 1000,
        ]);

        expect($plan->hasRequestLimit())->toBeTrue();
    });

    it('has no request limit when set to 0', function () {
        $plan = Plan::factory()->unlimited()->create();

        expect($plan->hasRequestLimit())->toBeFalse();
    });

    it('has no request limit when set to negative', function () {
        $plan = Plan::factory()->create([
            'request_limit' => -1,
        ]);

        expect($plan->hasRequestLimit())->toBeFalse();
    });
});

describe('Formatted Price', function () {
    it('formats price with currency', function () {
        $plan = Plan::factory()->create([
            'price' => 29.90,
        ]);

        expect($plan->formatted_price)->toBe('R$ 29,90');
    });

    it('formats price with thousands separator', function () {
        $plan = Plan::factory()->create([
            'price' => 1500.00,
        ]);

        expect($plan->formatted_price)->toBe('R$ 1.500,00');
    });
});

describe('Billing Cycle', function () {
    it('stores billing cycle as enum', function () {
        $plan = Plan::factory()->create([
            'billing_cycle' => BillingCycle::MONTHLY,
        ]);

        expect($plan->billing_cycle)->toBe(BillingCycle::MONTHLY);
        expect($plan->billing_cycle->value)->toBe('monthly');
    });

    it('can convert billing cycle to days', function () {
        expect(BillingCycle::convertInDays(BillingCycle::WEEKLY))->toBe(7);
        expect(BillingCycle::convertInDays(BillingCycle::MONTHLY))->toBe(30);
        expect(BillingCycle::convertInDays(BillingCycle::ANNUALLY))->toBe(365);
    });

    it('returns label for billing cycle', function () {
        $plan = Plan::factory()->create([
            'billing_cycle' => BillingCycle::MONTHLY,
        ]);

        expect($plan->billing_cycle->label())->toBe('monthly');
    });
});

describe('Features', function () {
    it('stores features as array', function () {
        $plan = Plan::factory()->create([
            'features' => ['feature1', 'feature2'],
        ]);

        expect($plan->features)->toBeArray();
        expect($plan->features)->toHaveCount(2);
    });

    it('returns empty array when no features', function () {
        $plan = Plan::factory()->create([
            'features' => null,
        ]);

        expect($plan->features)->toBeArray();
        expect($plan->features)->toHaveCount(0);
    });
});
