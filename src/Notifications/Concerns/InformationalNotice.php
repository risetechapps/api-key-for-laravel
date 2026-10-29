<?php

namespace RiseTechApps\ApiKey\Notifications\Concerns;

use RiseTechApps\ApiKey\Models\Authentication\Authentication;

/**
 * Aviso informativo: o assinante pode desligar no perfil
 * (`usage_notices_enabled`). Só vale para avisos que não exigem ação para a
 * conta continuar funcionando; cobrança, segurança e expiração não usam isto.
 *
 * Classe própria configurada em `api-key.notifications.*` que estende a do
 * pacote herda a preferência.
 */
trait InformationalNotice
{
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! $notifiable instanceof Authentication || $notifiable->usage_notices_enabled !== false;
    }
}
