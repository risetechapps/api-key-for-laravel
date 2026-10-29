<?php

namespace RiseTechApps\ApiKey\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

/**
 * Uma chave vai vencer em breve (`api_keys.expiry_warning_days`).
 *
 * Disparado pelo `api-key:notify-expiring`, uma vez por chave e por data de
 * validade: mudar o `expires_at` arma o aviso de novo.
 */
class ApiKeyExpiring
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Authentication $user,
        public readonly ApiKey $apiKey,
        public readonly int $daysLeft
    ) {}
}
