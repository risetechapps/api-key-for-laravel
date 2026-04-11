<?php

use Illuminate\Support\Facades\Notification;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Notifications\EmailVerifyNotification;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
});

describe('Registration', function () {
    it('registers a new user', function () {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'message',
                    'api_key',
                ],
            ]);

        $this->assertDatabaseHas('authentications', [
            'email' => 'john@example.com',
            'name' => 'John Doe',
        ]);
    });

    it('sends email verification notification', function () {
        $this->postJson('/api/v1/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $user = Authentication::where('email', 'john@example.com')->first();

        Notification::assertSentTo($user, EmailVerifyNotification::class);
    });

    it('validates required fields', function () {
        $response = $this->postJson('/api/v1/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    });

    it('validates email uniqueness', function () {
        Authentication::factory()->create(['email' => 'exists@example.com']);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'John Doe',
            'email' => 'exists@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });

    it('validates password confirmation', function () {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'different',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    });
});

describe('Login', function () {
    it('logs in with valid credentials', function () {
        $user = Authentication::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'id',
                    'email',
                ],
            ]);
    });

    it('rejects invalid credentials', function () {
        Authentication::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(410)
            ->assertJson(['message' => __('api-key::messages.incorrect_credentials')]);
    });

    it('requires email verification', function () {
        $user = Authentication::factory()->unverified()->create([
            'email' => 'unverified@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'unverified@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(410)
            ->assertJson(['message' => __('api-key::messages.account_not_verified')]);

        Notification::assertSentTo($user, EmailVerifyNotification::class);
    });

    it('validates required fields', function () {
        $response = $this->postJson('/api/v1/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    });
});

describe('Me', function () {
    it('returns authenticated user data', function () {
        $user = Authentication::factory()->create();
        $apiKey = \RiseTechApps\ApiKey\Models\ApiKey\ApiKey::factory()->create([
            'authentication_id' => $user->id,
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $apiKey->plainKey,
        ])->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'email',
                    'name',
                ],
            ]);
    });

    it('requires authentication', function () {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    });
});

describe('Rate Limiting', function () {
    it('rate limits login attempts', function () {
        // Make 5 failed attempts
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', [
                'email' => 'test@example.com',
                'password' => 'wrong',
            ]);
        }

        // 6th attempt should be rate limited
        $response = $this->postJson('/api/v1/login', [
            'email' => 'test@example.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(429);
    });
});
