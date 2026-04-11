<?php

use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Services\PlanService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->service = new PlanService();
    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create([
        'request_limit' => 1000,
        'billing_cycle' => \RiseTechApps\ApiKey\Enums\BillingCycle::MONTHLY,
    ]);
});

describe('Subscription', function () {
    it('creates user plan subscription', function () {
        $userPlan = $this->service->subscribe($this->user, $this->plan);

        expect($userPlan)->toBeInstanceOf(UserPlan::class);
        expect($userPlan->plan_id)->toBe($this->plan->id);
        expect($userPlan->authentication_id)->toBe($this->user->id);
    });

    it('deactivates previous plan when subscribing', function () {
        // Create existing plan
        $oldPlan = Plan::factory()->create();
        $oldUserPlan = UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $oldPlan->id,
            'active' => true,
        ]);

        // Subscribe to new plan
        $this->service->subscribe($this->user, $this->plan);

        // Old plan should be deactivated
        $oldUserPlan->refresh();
        expect($oldUserPlan->active)->toBeFalse();
    });

    it('activates api key when subscribing', function () {
        $apiKey = \RiseTechApps\ApiKey\Models\ApiKey\ApiKey::factory()->create([
            'authentication_id' => $this->user->id,
            'active' => false,
        ]);

        $this->service->subscribe($this->user, $this->plan);

        $apiKey->refresh();
        expect($apiKey->active)->toBeTrue();
    });
});

describe('Request Limits', function () {
    it('checks if user has reached limit', function () {
        $userPlan = UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'requests_used' => 1000,
            'active' => true,
        ]);

        $hasReached = $this->service->hasReachedLimit($this->user);

        expect($hasReached)->toBeTrue();
    });

    it('returns false when under limit', function () {
        UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'requests_used' => 500,
            'active' => true,
        ]);

        $hasReached = $this->service->hasReachedLimit($this->user);

        expect($hasReached)->toBeFalse();
    });

    it('returns false for unlimited plans', function () {
        $unlimitedPlan = Plan::factory()->unlimited()->create();
        UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $unlimitedPlan->id,
            'requests_used' => 99999,
            'active' => true,
        ]);

        $hasReached = $this->service->hasReachedLimit($this->user);

        expect($hasReached)->toBeFalse();
    });
});

describe('Remaining Requests', function () {
    it('calculates remaining requests', function () {
        UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'requests_used' => 300,
            'active' => true,
        ]);

        $remaining = $this->service->getRemainingRequests($this->user);

        expect($remaining)->toBe(700);
    });

    it('returns null for unlimited plans', function () {
        $unlimitedPlan = Plan::factory()->unlimited()->create();
        UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $unlimitedPlan->id,
            'active' => true,
        ]);

        $remaining = $this->service->getRemainingRequests($this->user);

        expect($remaining)->toBeNull();
    });
});
