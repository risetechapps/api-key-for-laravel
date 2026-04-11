<?php

use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Services\AuthService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->service = new AuthService();
});

describe('Login', function () {
    it('returns user and token on successful login', function () {
        $user = Authentication::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'email_verified_at' => now(),
        ]);

        $result = $this->service->attemptLogin([
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        expect($result)->toBeArray();
        expect($result['user'])->toBeInstanceOf(Authentication::class);
        expect($result['token'])->not->toBeNull();
        expect($result['token'])->toBeString();
    });

    it('returns null for non-existent user', function () {
        $result = $this->service->attemptLogin([
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        expect($result)->toBeNull();
    });

    it('returns null for incorrect password', function () {
        Authentication::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'email_verified_at' => now(),
        ]);

        $result = $this->service->attemptLogin([
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        expect($result)->toBeNull();
    });
});

describe('Find User', function () {
    it('finds user by email', function () {
        $user = Authentication::factory()->create(['email' => 'find@example.com']);

        $found = $this->service->findUserByEmail('find@example.com');

        expect($found)->not->toBeNull();
        expect($found->id)->toBe($user->id);
    });

    it('returns null when user not found', function () {
        $found = $this->service->findUserByEmail('notfound@example.com');

        expect($found)->toBeNull();
    });
});

describe('Static Methods', function () {
    it('returns valid login statuses', function () {
        $statuses = AuthService::statusLogin();

        expect($statuses)->toContain('enabled', 'disabled', 'blocked');
    });

    it('returns valid genres', function () {
        $genres = AuthService::genreProfile();

        expect($genres)->toContain('MASCULINE', 'FEMALE', 'OTHER');
    });

    it('returns valid marital statuses', function () {
        $statuses = AuthService::maritalStatusProfile();

        expect($statuses)->toContain('SINGLE', 'MARRIED', 'WIDOWER', 'JUDICIALLY SEPARATED');
    });

    it('returns valid roles', function () {
        $roles = AuthService::roles();

        expect($roles)->toContain('employee', 'client');
        expect($roles)->not->toContain('admin');
    });
});
