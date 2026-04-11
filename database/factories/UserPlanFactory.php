<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RiseTechApps\ApiKey\Enums\BillingCycle;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

class UserPlanFactory extends Factory
{
    protected $model = UserPlan::class;

    public function definition(): array
    {
        return [
            'authentication_id' => Authentication::factory(),
            'plan_id' => Plan::factory(),
            'start_date' => now(),
            'end_date' => now()->addDays(30),
            'active' => true,
            'requests_used' => 0,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn(array $attributes) => [
            'start_date' => now()->subDays(60),
            'end_date' => now()->subDay(),
        ]);
    }

    public function inGracePeriod(): static
    {
        return $this->state(fn(array $attributes) => [
            'start_date' => now()->subDays(32),
            'end_date' => now()->subDay(),
            'active' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn(array $attributes) => [
            'active' => false,
        ]);
    }

    public function withRequests(int $used): static
    {
        return $this->state(fn(array $attributes) => [
            'requests_used' => $used,
        ]);
    }
}
