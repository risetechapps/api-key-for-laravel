<?php

namespace RiseTechApps\ApiKey\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

class ApiKeyCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Authentication $user,
        public readonly ApiKey $apiKey,
        public readonly ?string $plainKey = null
    ) {}
}
