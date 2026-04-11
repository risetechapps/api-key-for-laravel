<?php

use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Services\UserRegistrationService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->service = new UserRegistrationService();
});

describe('Registration', function () {
    it('creates a new user', function () {
        $data = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret123',
        ];

        $user = $this->service->register($data);

        expect($user)->toBeInstanceOf(Authentication::class);
        expect($user->name)->toBe('John Doe');
        expect($user->email)->toBe('john@example.com');
    });

    it('creates an api key for the user', function () {
        $data = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret123',
        ];

        $user = $this->service->register($data);

        expect($user->apiKey)->not->toBeNull();
        expect($user->apiKey->plainKey)->not->toBeNull();
    });

    it('throws exception when avatar generator is not available', function () {
        // Mock function_exists to return false
        $this->app->bind('avatarGenerator', fn() => null);

        $data = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret123',
        ];

        expect(fn() => $this->service->register($data))
            ->toThrow(RuntimeException::class, 'avatarGenerator helper is not available');
    });
});

describe('Transaction Safety', function () {
    it('rolls back on error', function () {
        $initialCount = Authentication::count();

        try {
            $this->service->register([
                'name' => 'John Doe',
                'email' => 'invalid-email',
                'password' => 'secret123',
            ]);
        } catch (Exception $e) {
            // Expected
        }

        expect(Authentication::count())->toBe($initialCount);
    });
});
