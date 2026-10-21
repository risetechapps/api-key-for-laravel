<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RiseTechApps\ApiKey\Enums\BillingCycle;
use RiseTechApps\ApiKey\Models\Plan\Plan;

class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            // codeGenerate() cria a coluna com 4 caracteres; o PostgreSQL recusa
            // um UUID ali (o SQLite ignora o tamanho e aceitava).
            'code' => strtoupper($this->faker->unique()->bothify('****')),
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'request_limit' => $this->faker->numberBetween(1000, 100000),
            'billing_cycle' => $this->faker->randomElement([BillingCycle::MONTHLY, BillingCycle::ANNUALLY]),
            'price' => $this->faker->randomFloat(2, 10, 500),
            'is_active' => true,
            'features' => [],
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function unlimited(): static
    {
        return $this->state(fn (array $attributes) => [
            'request_limit' => 0,
        ]);
    }
}
