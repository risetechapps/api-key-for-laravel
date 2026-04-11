<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    public function definition(): array
    {
        return [
            'authentication_id' => Authentication::factory(),
            'code' => $this->faker->uuid(),
            'key' => Hash::make(bin2hex(random_bytes(64))),
            'expires_at' => null,
            'active' => true,
            'allowed_origins' => [],
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn(array $attributes) => [
            'active' => false,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn(array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function withAllowedOrigins(array $origins): static
    {
        return $this->state(fn(array $attributes) => [
            'allowed_origins' => $origins,
        ]);
    }
}
