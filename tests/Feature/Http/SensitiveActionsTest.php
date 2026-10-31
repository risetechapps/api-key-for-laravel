<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Team\TeamInvitation;
use RiseTechApps\ApiKey\Services\TeamService;
use RiseTechApps\ApiKey\Support\Totp;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    $this->user = Authentication::factory()->create(['email' => 'ana@example.com']);
});

/** 2FA confirmada, com um código de recuperação conhecido (AAAAA-BBBBB). */
function confirmTwoFactor(Authentication $user): string
{
    $secret = Totp::generateSecret();
    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => [hash('sha256', 'AAAAA-BBBBB')],
    ])->save();

    return $secret;
}

describe('Sensitive actions with 2FA', function () {
    // Senha vazada mais sessão roubada é o caso que a 2FA cobre: nenhuma
    // dessas ações pode passar só com a senha.

    it('needs a code to change the password', function () {
        $secret = confirmTwoFactor($this->user);
        Sanctum::actingAs($this->user);
        $body = ['current_password' => 'password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'];

        $this->putJson('/api/v1/dashboard/profile/password', $body)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->putJson('/api/v1/dashboard/profile/password', $body + ['code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');

        expect(Hash::check('password', $this->user->fresh()->password))->toBeTrue();

        $this->putJson('/api/v1/dashboard/profile/password', $body + ['code' => Totp::code($secret)])->assertOk();

        expect(Hash::check('new-password-1', $this->user->fresh()->password))->toBeTrue();
    });

    it('needs a code to change the e-mail', function () {
        confirmTwoFactor($this->user);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/dashboard/profile/email', ['email' => 'nova@example.com', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        expect($this->user->fresh()->pending_email)->toBeNull();

        $this->postJson('/api/v1/dashboard/profile/email', ['email' => 'nova@example.com', 'password' => 'password', 'recovery_code' => 'AAAAA-BBBBB'])
            ->assertOk();

        expect($this->user->fresh()->pending_email)->toBe('nova@example.com');
    });

    it('does not spend the code on a refusal that comes anyway', function () {
        $secret = confirmTwoFactor($this->user);
        Authentication::factory()->create(['email' => 'outra@example.com']);
        Sanctum::actingAs($this->user);
        $code = Totp::code($secret);

        $this->postJson('/api/v1/dashboard/profile/email', ['email' => 'outra@example.com', 'password' => 'password', 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api-key::messages.email_taken'));

        $this->postJson('/api/v1/dashboard/profile/email', ['email' => 'nova@example.com', 'password' => 'password', 'code' => $code])
            ->assertOk();
    });

    it('needs a code to delete the account', function () {
        $secret = confirmTwoFactor($this->user);
        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/v1/dashboard/profile', ['password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->deleteJson('/api/v1/dashboard/profile', ['password' => 'password', 'code' => '000000'])->assertUnprocessable();

        expect(Authentication::find($this->user->getKey()))->not->toBeNull();

        $this->deleteJson('/api/v1/dashboard/profile', ['password' => 'password', 'code' => Totp::code($secret)])->assertOk();

        expect(Authentication::find($this->user->getKey()))->toBeNull();
    });

    it('counts wrong codes toward the account lockout', function () {
        config(['api-key.two_factor.max_attempts' => 2]);
        $secret = confirmTwoFactor($this->user);
        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/v1/dashboard/profile', ['password' => 'password', 'code' => '000000'])->assertUnprocessable();
        $this->postJson('/api/v1/dashboard/profile/email', ['email' => 'nova@example.com', 'password' => 'password', 'code' => '000000'])->assertUnprocessable();

        $this->putJson('/api/v1/dashboard/profile/password', [
            'current_password' => 'password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
            'code' => Totp::code($secret),
        ])->assertStatus(429);
    });

    it('asks nothing more without 2FA', function () {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/dashboard/profile/email', ['email' => 'nova@example.com', 'password' => 'password'])->assertOk();
        $this->deleteJson('/api/v1/dashboard/profile', ['password' => 'password'])->assertOk();
    });
});

describe('Panel session lifetime', function () {
    function panelLogin(): string
    {
        return test()->postJson('/api/v1/login', ['email' => 'ana@example.com', 'password' => 'password'])
            ->assertOk()
            ->json('data.token');
    }

    it('gives each login an expiry and refuses it afterwards', function () {
        config(['api-key.sessions.lifetime_days' => 30]);
        $token = panelLogin();

        $stored = PersonalAccessToken::findToken($token);
        expect($stored->expires_at->isSameDay(now()->addDays(30)))->toBeTrue();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->travel(31)->days();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    });

    it('keeps logins without expiry when the lifetime is 0', function () {
        config(['api-key.sessions.lifetime_days' => 0]);

        expect(PersonalAccessToken::findToken(panelLogin())->expires_at)->toBeNull();
    });

    it('does not list an expired login as an open session', function () {
        $current = panelLogin();
        $this->user->createToken('old', ['*'], now()->subDay());

        $this->withToken($current)->getJson('/api/v1/dashboard/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.current', true);
    });
});

describe('Unique e-mail', function () {
    it('refuses a second account with the same e-mail in any case', function () {
        try {
            Authentication::factory()->create(['email' => 'ANA@example.com']);
            $this->fail('o índice deveria recusar');
        } catch (QueryException $e) {
            expect(Authentication::isEmailTaken($e))->toBeTrue();
        }
    });

    it('does not accept the same invitation twice', function () {
        // Dois aceites ao mesmo tempo: o segundo chega com o convite ainda
        // "aberto" na mão. O e-mail livre deixa ele chegar até a marca.
        $invitation = TeamInvitation::create([
            'account_id' => $this->user->getKey(),
            'name' => 'Bia',
            'email' => 'bia@example.com',
            'role' => 'agent',
            'token_hash' => TeamInvitation::hashToken('tok'),
            'expires_at' => now()->addDay(),
        ]);
        $stale = TeamInvitation::find($invitation->getKey());

        $member = app(TeamService::class)->accept($invitation, 'senha-forte-1');
        $member->forceDelete();

        try {
            app(TeamService::class)->accept($stale, 'senha-forte-1');
            $this->fail('o segundo aceite deveria ser recusado');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('token');
        }

        expect(Authentication::where('email', 'bia@example.com')->count())->toBe(0);
    });

    it('stops the migration when the table already has repeated e-mails', function () {
        $migration = require __DIR__.'/../../../database/migrations/2026_10_30_000002_add_unique_email_to_authentications_table.php';
        $migration->down();
        DB::table('authentications')->insert([
            'id' => (string) Str::uuid(),
            'name' => 'Duplicada',
            'email' => 'Ana@Example.com',
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'e-mail(s) repetido(s)');

        DB::table('authentications')->where('name', 'Duplicada')->delete();
        $migration->up();

        expect(DB::selectOne("SELECT COUNT(*) AS n FROM pg_indexes WHERE indexname = 'authentications_email_lower_unique'")->n)->toBe(1);
    });

    it('stops the CPF migration when the table already has repeated CPFs', function () {
        $migration = require __DIR__.'/../../../database/migrations/2026_10_30_000003_add_unique_cpf_to_authentications_table.php';
        $migration->down();
        // Mesmo número, um com máscara: só os dígitos contam. Vazio não conta.
        Authentication::factory()->create(['cpf' => '52998224725']);
        Authentication::factory()->create(['cpf' => '529.982.247-25']);
        Authentication::factory()->count(2)->create(['cpf' => '']);

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'CPF(s) repetido(s)');

        Authentication::where('cpf', '529.982.247-25')->forceDelete();
        $migration->up();

        expect(DB::selectOne("SELECT COUNT(*) AS n FROM pg_indexes WHERE indexname = 'authentications_cpf_digits_unique'")->n)->toBe(1);
    });
});
