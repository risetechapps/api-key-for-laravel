<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Notifications\EmailVerifyNotification;
use RiseTechApps\ApiKey\Notifications\ResetPasswordNotification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    // No app real a chave e a tabela de tokens vêm do skeleton do Laravel; o
    // Testbench não traz nenhuma das duas.
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'auth.passwords.users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ]);

    if (! Schema::hasTable('password_reset_tokens')) {
        Schema::create('password_reset_tokens', function ($table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }
});

describe('Forgot password', function () {
    it('sends the reset link to an existing account', function () {
        $user = Authentication::factory()->create(['email' => 'ana@example.com']);

        $this->postJson('/api/v1/forgot-password', ['email' => 'ana@example.com'])
            ->assertOk()
            ->assertJsonPath('data.message', __('api-key::messages.password_reset_sent'));

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    });

    it('answers an unknown e-mail exactly like a known one', function () {
        Authentication::factory()->create(['email' => 'ana@example.com']);

        $known = $this->postJson('/api/v1/forgot-password', ['email' => 'ana@example.com']);
        $unknown = $this->postJson('/api/v1/forgot-password', ['email' => 'ghost@example.com']);

        expect($unknown->status())->toBe($known->status())
            ->and($unknown->json())->toBe($known->json());
    });

    it('answers a throttled repeat exactly like the first request', function () {
        Authentication::factory()->create(['email' => 'ana@example.com']);

        $first = $this->postJson('/api/v1/forgot-password', ['email' => 'ana@example.com']);
        // O broker recusa um segundo link para o mesmo e-mail dentro do intervalo.
        $second = $this->postJson('/api/v1/forgot-password', ['email' => 'ana@example.com']);

        expect($second->json())->toBe($first->json());
    });

    it('is rate limited', function () {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/forgot-password', ['email' => "user{$i}@example.com"]);
        }

        $this->postJson('/api/v1/forgot-password', ['email' => 'last@example.com'])
            ->assertStatus(429);
    });
});

describe('Reset password', function () {
    it('resets the password with a valid token and revokes existing sessions', function () {
        $user = Authentication::factory()->create(['email' => 'ana@example.com']);
        $user->createToken('old-session');
        $token = Password::createToken($user);

        $this->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => 'ana@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk()
            ->assertJsonPath('data.message', __('api-key::messages.password_reset_success'));

        expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue()
            ->and($user->tokens()->count())->toBe(0);
    });

    it('rejects an invalid token', function () {
        $user = Authentication::factory()->create(['email' => 'ana@example.com']);

        $this->postJson('/api/v1/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'ana@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422)
            ->assertJson(['message' => __('api-key::messages.password_reset_invalid')]);

        expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    });

    it('validates the confirmation', function () {
        $this->postJson('/api/v1/reset-password', [
            'token' => 'x',
            'email' => 'ana@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'different',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    });
});

describe('Resend verification', function () {
    it('resends the link to an unverified account', function () {
        $user = Authentication::factory()->unverified()->create(['email' => 'ana@example.com']);

        $this->postJson('/api/v1/email/resend', ['email' => 'ana@example.com'])
            ->assertOk()
            ->assertJsonPath('data.message', __('api-key::messages.verification_resent'));

        Notification::assertSentTo($user, EmailVerifyNotification::class);
    });

    it('sends nothing to a verified, suspended or unknown account, with the same answer', function () {
        $verified = Authentication::factory()->create(['email' => 'verified@example.com']);
        $suspended = Authentication::factory()->unverified()->disabled()->create(['email' => 'off@example.com']);

        $responses = collect(['verified@example.com', 'off@example.com', 'ghost@example.com'])
            ->map(fn ($email) => $this->postJson('/api/v1/email/resend', ['email' => $email])->assertOk()->json());

        expect($responses->unique()->count())->toBe(1);

        Notification::assertNotSentTo($verified, EmailVerifyNotification::class);
        Notification::assertNotSentTo($suspended, EmailVerifyNotification::class);
    });
});

describe('Login', function () {
    it('answers an unknown e-mail like a wrong password', function () {
        Authentication::factory()->create(['email' => 'ana@example.com']);

        $this->postJson('/api/v1/login', ['email' => 'ghost@example.com', 'password' => 'whatever'])
            ->assertStatus(422)
            ->assertJson(['message' => __('api-key::messages.incorrect_credentials')]);
    });

    it('does not reveal or resend for an unverified account when the password is wrong', function () {
        $user = Authentication::factory()->unverified()->create(['email' => 'ana@example.com']);

        $this->postJson('/api/v1/login', ['email' => 'ana@example.com', 'password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJson(['message' => __('api-key::messages.incorrect_credentials')]);

        Notification::assertNotSentTo($user, EmailVerifyNotification::class);
    });

    it('refuses a suspended account with the right password', function () {
        Authentication::factory()->disabled()->create(['email' => 'ana@example.com']);

        $this->postJson('/api/v1/login', ['email' => 'ana@example.com', 'password' => 'password'])
            ->assertStatus(403)
            ->assertJson(['message' => __('api-key::messages.account_disabled')]);
    });

    it('treats a missing status as enabled', function () {
        $user = Authentication::factory()->create(['email' => 'ana@example.com']);
        $user->forceFill(['status' => null])->save();

        $this->postJson('/api/v1/login', ['email' => 'ana@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token']]);
    });
});
