<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Team\TeamInvitation;
use RiseTechApps\ApiKey\Notifications\TeamInvitationNotification;
use RiseTechApps\ApiKey\Support\Team;

/**
 * Equipe da conta: convidar, aceitar, mudar papel e remover.
 *
 * Regras:
 *   - o plano do dono limita quantos membros (contando convites pendentes);
 *   - admin convida, altera e remove atendentes; admins, só o dono;
 *   - o e-mail do convite não pode ter conta (uma conta por e-mail);
 *   - remover um membro exclui a conta dele (anonimizada, como a exclusão de
 *     conta), o que encerra as sessões e libera o e-mail para outro convite.
 */
class TeamService
{
    public const INVITATION_DAYS = 7;

    public function __construct(private readonly AccountService $accounts) {}

    /** @return array{members: Collection, invitations: Collection, limit: int, used: int} */
    public function summary(Authentication $owner): array
    {
        $members = $owner->members()->orderBy('name')->get();
        $invitations = $owner->teamInvitations()->pending()->orderByDesc('created_at')->get();

        return [
            'members' => $members,
            'invitations' => $invitations,
            'limit' => $owner->teamMembersLimit(),
            'used' => $members->count() + $invitations->count(),
        ];
    }

    public function invite(Authentication $owner, Authentication $actor, string $name, string $email, string $role): TeamInvitation
    {
        $email = mb_strtolower(trim($email));
        $this->assertCanManage($actor, $role);

        if ($this->emailTaken($email)) {
            throw ValidationException::withMessages(['email' => __('api-key::messages.team_email_taken')]);
        }

        if ($owner->teamInvitations()->pending()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => __('api-key::messages.team_already_invited')]);
        }

        $summary = $this->summary($owner);

        if ($summary['used'] >= $summary['limit']) {
            throw ValidationException::withMessages(['email' => __('api-key::messages.team_limit_reached', ['limit' => $summary['limit']])]);
        }

        $token = Str::random(48);
        $invitation = TeamInvitation::create([
            'account_id' => $owner->getKey(),
            'invited_by' => $actor->getKey(),
            'name' => trim($name),
            'email' => $email,
            'role' => $role,
            'token_hash' => TeamInvitation::hashToken($token),
            'expires_at' => now()->addDays(self::INVITATION_DAYS),
        ]);

        $this->send($invitation, $token, $owner);
        AuditLogger::record('team.member_invited', $owner, ['email' => $email, 'role' => $role], $actor);

        return $invitation;
    }

    /**
     * Membro de integração: a pessoa existe no sistema do cliente, que fala
     * com a API em nome dela (`external_id` = id dela lá). Cria na primeira
     * vez e atualiza nome e papel depois. Sem login aqui: e-mail fictício
     * (.invalid, nunca recebe nada) e senha aleatória que ninguém conhece; o
     * login recusa membros externos. Conta no limite de equipe do plano.
     */
    public function provisionExternal(Authentication $owner, string $externalId, string $name, ?string $role = null): Authentication
    {
        $externalId = trim($externalId);
        $member = $owner->members()->where('external_id', $externalId)->first();

        if ($member) {
            $changes = array_filter([
                // O modelo guarda o nome em maiúsculas: compara do mesmo jeito.
                'name' => trim($name) !== '' && mb_strtoupper(trim($name)) !== mb_strtoupper((string) $member->name) ? trim($name) : null,
                'team_role' => $role && $role !== $member->team_role ? $role : null,
            ]);

            if (isset($changes['team_role']) && ! in_array($changes['team_role'], Team::MEMBER_ROLES, true)) {
                throw ValidationException::withMessages(['role' => __('validation.in', ['attribute' => 'role'])]);
            }

            if ($changes) {
                $member->forceFill($changes)->save();
            }

            return $member;
        }

        $role ??= Team::AGENT;

        if (! in_array($role, Team::MEMBER_ROLES, true)) {
            throw ValidationException::withMessages(['role' => __('validation.in', ['attribute' => 'role'])]);
        }

        $summary = $this->summary($owner);

        if ($summary['used'] >= $summary['limit']) {
            throw ValidationException::withMessages(['external_id' => __('api-key::messages.team_limit_reached', ['limit' => $summary['limit']])]);
        }

        $member = new Authentication;
        $member->forceFill([
            'name' => trim($name) ?: $externalId,
            'email' => 'agent+'.Str::uuid().'@external.invalid',
            'password' => Str::random(64),
            'locale' => $owner->locale,
            'account_id' => $owner->getKey(),
            'team_role' => $role,
            'external_id' => $externalId,
            'role' => AuthService::$CLIENT,
            'status' => AuthService::$ENABLE,
        ])->save();

        AuditLogger::record('team.external_member_created', $owner, ['external_id' => $externalId, 'role' => $role]);

        return $member;
    }

    /** Novo link (o anterior deixa de valer) e mais 7 dias. */
    public function resend(Authentication $owner, Authentication $actor, TeamInvitation $invitation): void
    {
        $this->assertCanManage($actor, $invitation->role);

        $token = Str::random(48);
        $invitation->update(['token_hash' => TeamInvitation::hashToken($token), 'expires_at' => now()->addDays(self::INVITATION_DAYS)]);
        $this->send($invitation, $token, $owner);
    }

    public function cancel(Authentication $owner, Authentication $actor, TeamInvitation $invitation): void
    {
        $this->assertCanManage($actor, $invitation->role);
        $invitation->delete();
        AuditLogger::record('team.invitation_cancelled', $owner, ['email' => $invitation->email], $actor);
    }

    /**
     * Aceita o convite: cria a conta do membro, já verificada (o link chegou
     * no e-mail), com a senha que ele escolheu.
     */
    public function accept(TeamInvitation $invitation, string $password, ?string $name = null): Authentication
    {
        if (! $invitation->isUsable() || ! $invitation->account?->isEnabled()) {
            throw ValidationException::withMessages(['token' => __('api-key::messages.team_invitation_invalid')]);
        }

        if ($this->emailTaken($invitation->email)) {
            throw ValidationException::withMessages(['email' => __('api-key::messages.team_email_taken')]);
        }

        try {
            $member = $this->createMember($invitation, $password, $name);
        } catch (QueryException $e) {
            // Mesmo e-mail cadastrado ao mesmo tempo por outro caminho.
            if (Authentication::isEmailTaken($e)) {
                throw ValidationException::withMessages(['email' => __('api-key::messages.team_email_taken')]);
            }

            throw $e;
        }

        $invitation->refresh();

        AuditLogger::record('team.member_joined', $invitation->account, ['email' => $member->email, 'role' => $member->team_role], $member);

        return $member;
    }

    private function createMember(TeamInvitation $invitation, string $password, ?string $name): Authentication
    {
        return DB::transaction(function () use ($invitation, $password, $name) {
            // Marca o convite antes de criar a conta, e só se ninguém marcou:
            // dois aceites ao mesmo tempo passariam os dois pelo isUsable() lá
            // em cima e virariam dois membros.
            $claimed = TeamInvitation::query()
                ->whereKey($invitation->getKey())
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->update(['accepted_at' => now()]);

            if ($claimed === 0) {
                throw ValidationException::withMessages(['token' => __('api-key::messages.team_invitation_invalid')]);
            }

            $member = new Authentication;
            $member->forceFill([
                'name' => trim((string) ($name ?: $invitation->name)),
                'email' => $invitation->email,
                'password' => $password,
                'locale' => $invitation->account->locale,
                'account_id' => $invitation->account_id,
                'team_role' => $invitation->role,
                'role' => AuthService::$CLIENT,
                'status' => AuthService::$ENABLE,
                'email_verified_at' => now(),
            ])->save();

            return $member;
        });
    }

    public function updateRole(Authentication $owner, Authentication $actor, Authentication $member, string $role): void
    {
        $this->assertMemberOf($owner, $member);
        $this->assertCanManage($actor, $member->team_role);
        $this->assertCanManage($actor, $role);

        $before = $member->team_role;
        $member->forceFill(['team_role' => $role])->save();
        AuditLogger::record('team.member_role_changed', $owner, ['member' => $member->email, 'from' => $before, 'to' => $role], $actor);
    }

    public function remove(Authentication $owner, Authentication $actor, Authentication $member): void
    {
        $this->assertMemberOf($owner, $member);
        $this->assertCanManage($actor, $member->team_role);

        $email = $member->email;
        $this->accounts->delete($member);
        AuditLogger::record('team.member_removed', $owner, ['member' => $email], $actor);
    }

    private function send(TeamInvitation $invitation, string $token, Authentication $owner): void
    {
        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation, $token, (string) $owner->name));
    }

    /** Admin mexe em atendentes; admins (e dar papel de admin) só o dono. */
    private function assertCanManage(Authentication $actor, ?string $role): void
    {
        if (! in_array($role, Team::MEMBER_ROLES, true)) {
            throw ValidationException::withMessages(['role' => __('validation.in', ['attribute' => 'role'])]);
        }

        $actorRole = Team::roleOf($actor);

        if ($actorRole === Team::OWNER || ($actorRole === Team::ADMIN && $role === Team::AGENT)) {
            return;
        }

        throw ValidationException::withMessages(['role' => __('api-key::messages.team_cannot_manage')]);
    }

    private function assertMemberOf(Authentication $owner, Authentication $member): void
    {
        abort_unless($member->account_id === $owner->getKey(), 404);
    }

    private function emailTaken(string $email): bool
    {
        return Authentication::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->exists();
    }
}
