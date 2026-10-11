<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use RiseTechApps\ApiKey\Models\AuditLog\AuditLog;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Notifications\AccountSecurityNotification;
use RiseTechApps\ApiKey\Notifications\EmailChangeNotification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    $this->user = Authentication::factory()->create(['email' => 'ana@example.com']);
});

/** Faz login de verdade, para ter um token persistido como sessão atual. */
function loginToken(string $email = 'ana@example.com'): string
{
    return test()->postJson('/api/v1/login', ['email' => $email, 'password' => 'password'])
        ->assertOk()
        ->json('data.token');
}

describe('Change password', function () {
    it('changes the password, keeps the current session and ends the others', function () {
        $other = loginToken();
        $current = loginToken();

        $this->withToken($current)->putJson('/api/v1/dashboard/profile/password', [
            'current_password' => 'password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk()->assertJsonPath('data.sessions_revoked', 1);

        expect(Hash::check('new-password-1', $this->user->fresh()->password))->toBeTrue()
            ->and($this->user->tokens()->count())->toBe(1);

        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/auth/me')->assertUnauthorized();

        Notification::assertSentTo($this->user, AccountSecurityNotification::class,
            fn ($n) => $n->event === AccountSecurityNotification::PASSWORD_CHANGED);

        expect(AuditLog::where('action', 'account.password_changed')->exists())->toBeTrue();
    });

    it('requires the current password', function () {
        Sanctum::actingAs($this->user);

        $this->putJson('/api/v1/dashboard/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(422);

        expect(Hash::check('password', $this->user->fresh()->password))->toBeTrue();
    });

    it('validates the confirmation', function () {
        Sanctum::actingAs($this->user);

        $this->putJson('/api/v1/dashboard/profile/password', [
            'current_password' => 'password',
            'password' => 'new-password-1',
            'password_confirmation' => 'other',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    });
});

describe('Change e-mail', function () {
    it('only switches after the new address confirms', function () {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/dashboard/profile/email', ['email' => 'Nova@Example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.pending_email', 'nova@example.com');

        expect($this->user->fresh()->email)->toBe('ana@example.com');

        $url = null;
        Notification::assertSentTo(new AnonymousNotifiable, EmailChangeNotification::class,
            function ($n, $channels, $notifiable) use (&$url) {
                $url = $n->confirmationUrl();

                return $notifiable->routes['mail'] === 'nova@example.com';
            });

        $this->get($url)->assertRedirect('/login?email_changed=1');

        $fresh = $this->user->fresh();
        expect($fresh->email)->toBe('nova@example.com')
            ->and($fresh->pending_email)->toBeNull();

        Notification::assertSentTo(new AnonymousNotifiable, AccountSecurityNotification::class,
            fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'ana@example.com'
                && $n->event === AccountSecurityNotification::EMAIL_CHANGED);
    });

    it('rejects an address used by another account', function () {
        Authentication::factory()->create(['email' => 'taken@example.com']);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/dashboard/profile/email', ['email' => 'taken@example.com', 'password' => 'password'])
            ->assertStatus(422)
            ->assertJson(['message' => __('api-key::messages.email_taken')]);
    });

    it('requires the password', function () {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/dashboard/profile/email', ['email' => 'nova@example.com', 'password' => 'nope-nope'])
            ->assertStatus(422);

        expect($this->user->fresh()->pending_email)->toBeNull();
    });

    it('invalidates an old link when a different address is requested later', function () {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/dashboard/profile/email', ['email' => 'first@example.com', 'password' => 'password']);
        $firstUrl = (new EmailChangeNotification($this->user, 'first@example.com'))->confirmationUrl();

        $this->postJson('/api/v1/dashboard/profile/email', ['email' => 'second@example.com', 'password' => 'password']);

        $this->get($firstUrl)->assertRedirect('/login?error=invalid_link');
        expect($this->user->fresh()->email)->toBe('ana@example.com');
    });

    it('refuses a tampered link', function () {
        $this->user->forceFill(['pending_email' => 'nova@example.com'])->save();

        $this->get("/api/v1/email/change/{$this->user->getKey()}/".sha1('nova@example.com'))
            ->assertRedirect('/login?error=invalid_link');

        expect($this->user->fresh()->email)->toBe('ana@example.com');
    });
});

describe('Sessions', function () {
    it('lists sessions with device and flags the current one', function () {
        loginToken();
        $current = loginToken();

        $sessions = $this->withToken($current)
            ->withHeaders(['User-Agent' => 'TestBrowser/1.0'])
            ->getJson('/api/v1/dashboard/sessions')
            ->assertOk()
            ->json('data');

        expect($sessions)->toHaveCount(2)
            ->and(collect($sessions)->where('current', true))->toHaveCount(1)
            ->and($sessions[0])->toHaveKeys(['ip', 'user_agent', 'last_used_at']);
    });

    it('signs out one session', function () {
        $other = loginToken();
        $current = loginToken();
        $otherId = $this->user->tokens()->oldest('id')->first()->getKey();

        $this->withToken($current)->deleteJson("/api/v1/dashboard/sessions/{$otherId}")->assertOk();

        expect($this->user->tokens()->count())->toBe(1);
    });

    it('cannot sign out another user session', function () {
        $stranger = Authentication::factory()->create();
        $strangerToken = $stranger->createToken('x')->accessToken;
        Sanctum::actingAs($this->user);

        $this->deleteJson("/api/v1/dashboard/sessions/{$strangerToken->getKey()}")->assertNotFound();

        expect($stranger->tokens()->count())->toBe(1);
    });

    it('signs out all other sessions', function () {
        loginToken();
        loginToken();
        $current = loginToken();

        $this->withToken($current)->deleteJson('/api/v1/dashboard/sessions')
            ->assertOk()
            ->assertJsonPath('data.sessions_revoked', 2);

        expect($this->user->tokens()->count())->toBe(1);
    });
});

describe('Overriding notifications', function () {
    it('uses the notification class configured by the app', function () {
        $custom = new class('x') extends AccountSecurityNotification {};
        config(['api-key.notifications.account_security' => $custom::class]);
        Sanctum::actingAs($this->user);

        $this->putJson('/api/v1/dashboard/profile/password', [
            'current_password' => 'password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk();

        Notification::assertSentTo($this->user, $custom::class);
    });
});
