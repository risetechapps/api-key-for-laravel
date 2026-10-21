<?php

namespace RiseTechApps\ApiKey\Traits;

use RiseTechApps\ApiKey\Scope\ApiKeyScope;

trait HasApiKey
{
    protected static function bootHasApiKey(): void
    {
        static::addGlobalScope(new ApiKeyScope);
    }
}
