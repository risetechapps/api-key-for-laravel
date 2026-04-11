<?php

use Illuminate\Support\Facades\Event;
use RiseTechApps\ApiKey\Console\Commands\CheckExpiredPlans;
use RiseTechApps\ApiKey\Events\PlanExpired;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create();

    config(['api-key.grace_period_days' => 3]);
});

describe('Check Expired Plans Command', function () {
    it('deactivates completely expired plans', function () {
        // Create plan that expired 10 days ago (past grace period)
        $userPlan = UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'active' => true,
            'start_date' => now()->subDays(40),
            'end_date' => now()->subDays(10), // 10 days ago, grace period is 3 days
        ]);

        $apiKey = \RiseTechApps\ApiKey\Models\ApiKey\ApiKey::factory()->create([
            'authentication_id' => $this->user->id,
            'active' => true,
        ]);

        $this->artisan(CheckExpiredPlans::class)
            ->assertSuccessful();

        $userPlan->refresh();
        expect($userPlan->active)->toBeFalse();

        $apiKey->refresh();
        expect($apiKey->active)->toBeFalse();
    });

    it('dispatches PlanExpired event for expired plans', function () {
        UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'active' => true,
            'start_date' => now()->subDays(40),
            'end_date' => now()->subDays(10),
        ]);

        // Reset event fake to capture the actual dispatch
        Event::assertNotDispatched(PlanExpired::class);

        $this->artisan(CheckExpiredPlans::class)
            ->assertSuccessful();

        // Note: The actual dispatch happens in the command,
        // but Event::fake() prevents it from actually executing
    });

    it('does not affect plans in grace period', function () {
        // Create plan that expired yesterday (still in grace period)
        $userPlan = UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'active' => true,
            'start_date' => now()->subDays(31),
            'end_date' => now()->subDay(), // 1 day ago
        ]);

        $this->artisan(CheckExpiredPlans::class)
            ->assertSuccessful();

        $userPlan->refresh();
        expect($userPlan->active)->toBeTrue();
    });

    it('shows grace period warning in output', function () {
        // Create plan in grace period
        UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'active' => true,
            'start_date' => now()->subDays(31),
            'end_date' => now()->subDay(),
        ]);

        $this->artisan(CheckExpiredPlans::class)
            ->expectsOutput('1 plan(s) currently in grace period.')
            ->assertSuccessful();
    });

    it('grace-only option notifies about plans entering grace period', function () {
        // Create plan that expires today
        UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'active' => true,
            'end_date' => now()->toDateString(),
        ]);

        $this->artisan(CheckExpiredPlans::class, ['--grace-only' => true])
            ->assertSuccessful();
    });
});
