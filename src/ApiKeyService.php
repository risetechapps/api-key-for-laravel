<?php

namespace RiseTechApps\ApiKey;

use RiseTechApps\ApiKey\RoutesApiKey;

class ApiKeyService
{
    public static function routes($options = []): void
    {
        RoutesApiKey::register($options);
    }
}
