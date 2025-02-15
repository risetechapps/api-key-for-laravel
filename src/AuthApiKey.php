<?php

namespace RiseTechApps\ApiKey;

use RiseTechApps\ApiKey\Models\ApiKey;


class AuthApiKey
{
    public function validateKey($key): ApiKey
    {
        return ApiKey::validateKey($key);
    }

    public static function routes($options = []): void
    {
        RoutesApiKey::register($options);
    }
}
