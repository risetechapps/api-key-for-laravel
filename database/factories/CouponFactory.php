<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RiseTechApps\ApiKey\Models\Coupon\Coupon;

class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->bothify('PROMO####')),
            'type' => 'percentage',
            'value' => 10,
            'max_uses' => null,
            'uses' => 0,
            'expires_at' => null,
            'is_active' => true,
            'gateway_coupon_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn(array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
