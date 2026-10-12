<?php

namespace RiseTechApps\ApiKey\Support;

use RiseTechApps\ApiKey\Models\Authentication\Authentication;

/**
 * Equipe da conta: papéis e o membro da requisição atual.
 *
 *   owner  o dono da conta (quem assina o plano): tudo
 *   admin  tudo menos plano, cobrança e excluir a conta; gerencia a equipe
 *   agent  só as rotas liberadas para atendentes (`team.role:agent`)
 *
 * Nas rotas da conta, o membro age como o dono: `auth()->user()` é o dono, e
 * o código do app que filtra por `auth()->user()->id` continua certo. Quem
 * está de fato logado fica em Team::member() (e no helper teamMember()).
 * Rotas pessoais (perfil, senha, 2FA, sessões) marcadas com `team.self`
 * continuam com o próprio membro. Ver EnsureUserIsEnabled.
 */
final class Team
{
    public const OWNER = 'owner';

    public const ADMIN = 'admin';

    public const AGENT = 'agent';

    /** Papéis que um membro pode ter (o dono não é um papel atribuível). */
    public const MEMBER_ROLES = [self::ADMIN, self::AGENT];

    private const RANK = [self::AGENT => 1, self::ADMIN => 2, self::OWNER => 3];

    private static ?Authentication $member = null;

    private static ?Authentication $account = null;

    /** Papel de quem está logado: owner para o dono, o team_role para o membro. */
    public static function roleOf(?Authentication $user): ?string
    {
        if (! $user) {
            return null;
        }

        return $user->account_id ? (in_array($user->team_role, self::MEMBER_ROLES, true) ? $user->team_role : self::AGENT) : self::OWNER;
    }

    /** O papel alcança o mínimo exigido? (agent < admin < owner) */
    public static function allows(?string $role, string $minimum): bool
    {
        return (self::RANK[$role] ?? 0) >= (self::RANK[$minimum] ?? PHP_INT_MAX);
    }

    // ── Membro da requisição atual ───────────────────────────────────────────

    public static function enter(Authentication $member, Authentication $account): void
    {
        self::$member = $member;
        self::$account = $account;
    }

    public static function forget(): void
    {
        self::$member = null;
        self::$account = null;
    }

    /** Membro logado agindo pela conta; null quando é o próprio dono. */
    public static function member(): ?Authentication
    {
        return self::$member;
    }

    /** Dono da conta do membro logado; null quando é o próprio dono. */
    public static function account(): ?Authentication
    {
        return self::$account;
    }

    /** Quem de fato está logado: o membro, ou o usuário autenticado. */
    public static function actor(): ?Authentication
    {
        $user = auth()->user();

        return self::$member ?? ($user instanceof Authentication ? $user : null);
    }

    /** Papel de quem de fato está logado: o do membro, ou owner para qualquer outro autenticado. */
    public static function role(): ?string
    {
        if (self::$member) {
            return self::roleOf(self::$member);
        }

        return auth()->check() ? self::OWNER : null;
    }
}
