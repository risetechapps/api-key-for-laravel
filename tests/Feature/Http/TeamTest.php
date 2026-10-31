<?php

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use RiseTechApps\ApiKey\Models\AuditLog\AuditLog;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Team\TeamInvitation;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Notifications\TeamInvitationNotification;
use RiseTechApps\ApiKey\Services\AccountService;
use RiseTechApps\ApiKey\Services\TeamService;
use RiseTechApps\ApiKey\Support\Team;

beforeEach(function () {
    Notification::fake();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

    $this->owner = createUser(['email' => 'dono@example.com', 'name' => 'Dona Ana']);
    $this->plan = createPlan(['team_members_limit' => 2]);
    UserPlan::factory()->create([
        'authentication_id' => $this->owner->getKey(),
        'plan_id' => $this->plan->getKey(),
        'active' => true,
        'start_date' => now()->subDay(),
        'end_date' => now()->addMonth(),
    ]);
});

/** Convida e aceita, devolvendo o membro criado. */
function teamMember(string $role = Team::AGENT, string $email = 'bia@example.com'): Authentication
{
    $token = null;
    Sanctum::actingAs(test()->owner);
    test()->postJson('/api/v1/dashboard/team/invitations', ['name' => 'Bia', 'email' => $email, 'role' => $role])->assertOk();
    Notification::assertSentOnDemand(TeamInvitationNotification::class, function ($n) use (&$token, $email) {
        if ($n->invitation->email === $email) {
            $token = $n->token;
        }

        return true;
    });

    app('auth')->forgetGuards();
    test()->postJson("/api/v1/team/invitations/{$token}/accept", ['password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1'])->assertOk();

    return Authentication::where('email', $email)->sole();
}

function actAs(Authentication $user): void
{
    app('auth')->forgetGuards();
    Sanctum::actingAs($user);
}

describe('Convite', function () {
    it('o dono convida, o e-mail leva o link e o aceite cria o membro verificado na conta', function () {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/dashboard/team/invitations', ['name' => 'Bia', 'email' => 'Bia@Example.com', 'role' => 'agent'])->assertOk();

        $invitation = TeamInvitation::sole();
        expect($invitation->email)->toBe('bia@example.com')->and($invitation->token_hash)->toHaveLength(64);

        $token = null;
        Notification::assertSentOnDemand(TeamInvitationNotification::class, function ($n, $channels, AnonymousNotifiable $to) use (&$token) {
            $token = $n->token;

            return $to->routes['mail'] === 'bia@example.com' && str_contains($n->toMail($to)->actionUrl, '/invite/'.$n->token);
        });

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/team/invitations/{$token}")->assertOk()->assertJsonPath('data.role', 'agent');
        $response = $this->postJson("/api/v1/team/invitations/{$token}/accept", ['name' => 'Beatriz', 'password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1'])->assertOk();

        $member = Authentication::where('email', 'bia@example.com')->sole();
        expect($member->account_id)->toBe($this->owner->getKey())
            ->and($member->team_role)->toBe('agent')
            ->and($member->email_verified_at)->not->toBeNull()
            ->and($response->json('data.token'))->toBeString()
            ->and($invitation->fresh()->accepted_at)->not->toBeNull()
            ->and(AuditLog::where('action', 'team.member_joined')->exists())->toBeTrue();

        // O link não vale de novo.
        $this->postJson("/api/v1/team/invitations/{$token}/accept", ['password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1'])->assertStatus(422);
    });

    it('respeita o limite do plano (contando convites), e-mail com conta e convite repetido', function () {
        Sanctum::actingAs($this->owner);
        $invite = fn (string $email) => $this->postJson('/api/v1/dashboard/team/invitations', ['name' => 'X', 'email' => $email, 'role' => 'agent']);

        $invite('dono@example.com')->assertStatus(422)->assertJsonValidationErrors('email');
        $invite('a@example.com')->assertOk();
        $invite('a@example.com')->assertStatus(422);
        $invite('b@example.com')->assertOk();
        $invite('c@example.com')->assertStatus(422)->assertJsonPath('errors.email.0', __('api-key::messages.team_limit_reached', ['limit' => 2]));
    });

    it('convite expirado não abre nem aceita', function () {
        teamMember(); // gasta um lugar, só para ter fluxo completo
        $invitation = TeamInvitation::create(['account_id' => $this->owner->getKey(), 'name' => 'Z', 'email' => 'z@example.com', 'role' => 'agent', 'token_hash' => TeamInvitation::hashToken('velho'), 'expires_at' => now()->subMinute()]);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/team/invitations/velho')->assertNotFound();
        $this->postJson('/api/v1/team/invitations/velho/accept', ['password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1'])->assertStatus(422);
        expect($invitation->fresh()->accepted_at)->toBeNull();
    });
});

describe('Membro no painel', function () {
    it('o atendente entra, vê a si mesmo no /auth/me com o plano da conta, e não acessa rotas da conta', function () {
        $agent = teamMember();
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/login', ['email' => 'bia@example.com', 'password' => 'senha-forte-1'])->assertOk()->json('data.token');

        $this->app['auth']->forgetGuards();
        $me = $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        expect($me->json('data.email'))->toBe('bia@example.com')
            ->and($me->json('data.team'))->toMatchArray(['role' => 'agent', 'is_member' => true])
            ->and($me->json('data.team.account.id'))->toBe($this->owner->getKey())
            ->and($me->json('data.active_plan'))->not->toBeNull();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/dashboard/api-keys')->assertForbidden()->assertJsonPath('code', 'team_forbidden');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/dashboard/profile')->assertOk()->assertJsonPath('data.contact.email', 'bia@example.com');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/dashboard/team/members')->assertOk()->assertJsonCount(2, 'data');
    });

    it('o admin age na conta do dono, mas não na cobrança nem no admin da plataforma', function () {
        $this->owner->forceFill(['role' => 'admin', 'two_factor_confirmed_at' => now()])->save();
        $admin = teamMember(Team::ADMIN);
        createApiKey(['authentication_id' => $this->owner->getKey(), 'name' => 'Chave do dono']);

        actAs($admin);
        $keys = $this->getJson('/api/v1/dashboard/api-keys')->assertOk();
        expect(collect($keys->json('data'))->pluck('name'))->toContain('Chave do dono');

        actAs($admin);
        $this->getJson('/api/v1/dashboard/receipts')->assertForbidden();
        actAs($admin);
        $this->getJson('/api/v1/dashboard/admin/users')->assertForbidden();
        actAs($admin);
        $this->getJson('/api/v1/dashboard/team')->assertOk()->assertJsonPath('data.me.role', 'admin');
    });

    it('rota do app liberada a atendentes enxerga o dono como usuário e o membro em Team::actor()', function () {
        Route::middleware(['auth:sanctum', 'active.user', 'team.role:agent'])->get('/api/v1/teste-equipe', fn () => [
            'user' => auth()->id(),
            'actor' => Team::actor()?->getKey(),
            'role' => Team::role(),
        ]);
        $agent = teamMember();

        actAs($agent);
        $this->getJson('/api/v1/teste-equipe')->assertOk()->assertExactJson([
            'user' => $this->owner->getKey(), 'actor' => $agent->getKey(), 'role' => 'agent',
        ]);
    });

    it('dono suspenso bloqueia a equipe', function () {
        $agent = teamMember();
        $this->owner->forceFill(['status' => 'disabled'])->save();

        actAs($agent);
        $this->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('code', 'account_disabled');
    });
});

describe('Gestão da equipe', function () {
    it('admin convida atendente mas não admin; não mexe em outro admin; o dono muda papéis', function () {
        $admin = teamMember(Team::ADMIN, 'admin@example.com');

        actAs($admin);
        $this->postJson('/api/v1/dashboard/team/invitations', ['name' => 'C', 'email' => 'c@example.com', 'role' => 'admin'])->assertStatus(422)->assertJsonValidationErrors('role');
        actAs($admin);
        $this->postJson('/api/v1/dashboard/team/invitations', ['name' => 'C', 'email' => 'c@example.com', 'role' => 'agent'])->assertOk();
        actAs($admin);
        $this->deleteJson("/api/v1/dashboard/team/members/{$admin->getKey()}")->assertStatus(422);

        actAs($this->owner);
        $this->putJson("/api/v1/dashboard/team/members/{$admin->getKey()}", ['role' => 'agent'])->assertOk();
        expect($admin->fresh()->team_role)->toBe('agent');
    });

    it('remover o membro encerra as sessões e libera o e-mail', function () {
        $agent = teamMember();
        $agent->createToken('x');

        actAs($this->owner);
        $this->deleteJson("/api/v1/dashboard/team/members/{$agent->getKey()}")->assertOk();

        expect(Authentication::where('email', 'bia@example.com')->exists())->toBeFalse()
            ->and($agent->tokens()->count())->toBe(0);

        actAs($this->owner);
        $this->postJson('/api/v1/dashboard/team/invitations', ['name' => 'Bia', 'email' => 'bia@example.com', 'role' => 'agent'])->assertOk();
    });

    it('membro de integração: criado pelo external_id, sem login, atualizado na segunda vez e contando no limite', function () {
        $team = app(TeamService::class);

        $ana = $team->provisionExternal($this->owner, 'user-123', 'Ana Souza');
        expect($ana->fresh()->external_id)->toBe('user-123') // sem maiúsculas: é o id do sistema do cliente
            ->and($ana->account_id)->toBe($this->owner->getKey())
            ->and($ana->team_role)->toBe('agent')
            ->and($ana->isExternal())->toBeTrue()
            ->and($ana->email)->toEndWith('@external.invalid');

        // De novo, mesmo id: atualiza, não duplica.
        $again = $team->provisionExternal($this->owner, 'user-123', 'Ana S.', 'admin');
        expect($again->getKey())->toBe($ana->getKey())->and($again->name)->toBe('ANA S.')->and($again->team_role)->toBe('admin')
            ->and($this->owner->members()->count())->toBe(1);

        // Login recusado mesmo com a senha certa.
        $ana->forceFill(['password' => 'senha-forte-1'])->save();
        $this->postJson('/api/v1/login', ['email' => $ana->email, 'password' => 'senha-forte-1'])->assertStatus(422);

        // A equipe mostra o id, sem o e-mail fictício.
        Sanctum::actingAs($this->owner);
        $listed = collect($this->getJson('/api/v1/dashboard/team')->json('data.members'))->firstWhere('external_id', 'user-123');
        expect($listed['email'])->toBeNull();

        // Limite do plano: 2.
        $team->provisionExternal($this->owner, 'user-456', 'Bruno');
        expect(fn () => $team->provisionExternal($this->owner, 'user-789', 'Carla'))->toThrow(ValidationException::class);
    });

    it('excluir a conta do dono leva a equipe junto', function () {
        $agent = teamMember();

        app(AccountService::class)->delete($this->owner);

        expect(Authentication::find($agent->getKey()))->toBeNull()
            ->and(TeamInvitation::count())->toBe(0);
    });
});
