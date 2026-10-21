<?php

namespace RiseTechApps\ApiKey;

class ApiKeyService
{
    public static function routes($options = []): void
    {
        RoutesApiKey::register($options);
    }
}
