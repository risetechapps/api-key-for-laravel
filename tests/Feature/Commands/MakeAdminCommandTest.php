<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

uses(RefreshDatabase::class);

describe('admin:make', function () {
    it('grants the admin role', function () {
        $user = Authentication::factory()->create(['email' => 'dono@example.com', 'role' => 'user']);

        $this->artisan('admin:make', ['email' => 'dono@example.com'])->assertSuccessful();

        expect($user->fresh()->role)->toBe('admin');
    });

    it('matches the e-mail regardless of case', function () {
        // Quem digita o e-mail no terminal não sabe como ele foi gravado.
        $user = Authentication::factory()->create(['email' => 'dono@example.com', 'role' => 'user']);

        $this->artisan('admin:make', ['email' => 'DONO@Example.COM'])->assertSuccessful();

        expect($user->fresh()->role)->toBe('admin');
    });

    it('fails when nobody has that e-mail', function () {
        $this->artisan('admin:make', ['email' => 'ninguem@example.com'])->assertFailed();
    });

    it('promotes nobody else', function () {
        // Um LIKE frouxo aqui distribuiria acesso administrativo em silêncio.
        $target = Authentication::factory()->create(['email' => 'dono@example.com', 'role' => 'user']);
        $other = Authentication::factory()->create(['email' => 'outro@example.com', 'role' => 'user']);

        $this->artisan('admin:make', ['email' => 'dono@example.com'])->assertSuccessful();

        expect($target->fresh()->role)->toBe('admin')
            ->and($other->fresh()->role)->toBe('user');
    });

    it('is idempotent on a user who is already admin', function () {
        $user = Authentication::factory()->create(['email' => 'chefe@example.com', 'role' => 'admin']);

        $this->artisan('admin:make', ['email' => 'chefe@example.com'])->assertSuccessful();

        expect($user->fresh()->role)->toBe('admin');
    });
});
