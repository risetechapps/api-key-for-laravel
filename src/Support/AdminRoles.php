<?php

namespace RiseTechApps\ApiKey\Support;

use RiseTechApps\ApiKey\Models\Authentication\Authentication;

/**
 * Papéis da equipe no painel admin e o que cada um pode fazer.
 *
 *   view      ver usuários, cobranças, métricas, auditoria, logs e saúde
 *   accounts  ações de suporte na conta: reenviar verificação, encerrar
 *             sessões, revogar API keys
 *   billing   dinheiro: estornos, nova tentativa de renovação, perdoar
 *             excedente, ajustar assinatura, exportações
 *   manage    configuração e poder sobre a equipe: planos, cupons, gateways,
 *             papéis, suspender conta, conceder plano, desligar 2FA
 *
 * Desligar a 2FA de alguém fica só com o admin: é o pedido que um golpe de
 * engenharia social faria ao suporte para tomar uma conta.
 */
final class AdminRoles
{
    public const ADMIN = 'admin';

    public const FINANCE = 'finance';

    public const SUPPORT = 'support';

    /** @var array<string, list<string>> */
    public const ABILITIES = [
        self::ADMIN => ['view', 'accounts', 'billing', 'manage'],
        self::FINANCE => ['view', 'billing'],
        self::SUPPORT => ['view', 'accounts'],
    ];

    public static function role(?Authentication $user): ?string
    {
        $role = strtolower((string) $user?->role);

        return isset(self::ABILITIES[$role]) ? $role : null;
    }

    public static function isStaff(?Authentication $user): bool
    {
        return self::role($user) !== null;
    }

    public static function can(?Authentication $user, string $ability): bool
    {
        $role = self::role($user);

        return $role !== null && in_array($ability, self::ABILITIES[$role], true);
    }

    /** @return list<string> */
    public static function abilities(?Authentication $user): array
    {
        $role = self::role($user);

        return $role ? self::ABILITIES[$role] : [];
    }
}
