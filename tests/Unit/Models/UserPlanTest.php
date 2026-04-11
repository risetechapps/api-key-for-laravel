<?php

use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->plan = Plan::factory()->create([
        'billing_cycle' => \RiseTechApps\ApiKey\Enums\BillingCycle::MONTHLY,
        'request_limit' => 1000,
    ]);
});

describe('UserPlan Status', function () {
    it('returns active when within date range and active flag is true', function () {
        $userPlan = UserPlan::factory()->create([
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'active' => true,
        ]);

        expect($userPlan->isActive())->toBeTrue();
    });

    it('returns inactive when outside date range', function () {
        $userPlan = UserPlan::factory()->create([
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDay(),
            'active' => true,
        ]);

        expect($userPlan->isActive())->toBeFalse();
    });

    it('returns inactive when active flag is false', function () {
        $userPlan = UserPlan::factory()->create([
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'active' => false,
        ]);

        expect($userPlan->isActive())->toBeFalse();
    });
});

describe('UserPlan Expiration', function () {
    it('correctly identifies expired plans', function () {
        $userPlan = UserPlan::factory()->expired()->create();

        expect($userPlan->isExpired())->toBeTrue();
        expect($userPlan->isActive())->toBeFalse();
    });

    it('correctly identifies non-expired plans', function () {
        $userPlan = UserPlan::factory()->create([
            'end_date' => now()->addDay(),
        ]);

        expect($userPlan->isExpired())->toBeFalse();
    });
});

describe('Grace Period', function () {
    it('identifies when plan is in grace period', function () {
        $userPlan = UserPlan::factory()->inGracePeriod()->create();

        expect($userPlan->isExpired())->toBeTrue();
        expect($userPlan->isInGracePeriod())->toBeTrue();
    });

    it('returns remaining days in grace period', function () {
        $userPlan = UserPlan::factory()->create([
            'end_date' => now()->subHours(12),
        ]);

        config(['api-key.grace_period_days' => 3]);

        expect($userPlan->getGracePeriodRemainingDays())->toBeGreaterThan(0);
        expect($userPlan->getGracePeriodRemainingDays())->toBeLessThanOrEqual(3);
    });

    it('identifies completely expired plans past grace period', function () {
        $userPlan = UserPlan::factory()->create([
            'end_date' => now()->subDays(10),
        ]);

        config(['api-key.grace_period_days' => 3]);

        expect($userPlan->isCompletelyExpired())->toBeTrue();
        expect($userPlan->isInGracePeriod())->toBeFalse();
    });

    it('allows access when active or in grace period', function () {
        $userPlan = UserPlan::factory()->inGracePeriod()->create();

        expect($userPlan->isActiveOrInGracePeriod())->toBeTrue();
    });
});

describe('Plan Relationship', function () {
    it('belongs to a plan', function () {
        $userPlan = UserPlan::factory()->create([
            'plan_id' => $this->plan->id,
        ]);

        expect($userPlan->plan)->not->toBeNull();
        expect($userPlan->plan->id)->toBe($this->plan->id);
    });
});
