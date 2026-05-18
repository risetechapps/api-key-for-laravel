<?php

namespace RiseTechApps\ApiKey\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void register(string $key, array $metadata)
 * @method static array all()
 * @method static array|null get(string $key)
 * @method static array keys()
 * @method static bool has(string $key)
 * @method static void sync()
 *
 * @see \RiseTechApps\ApiKey\Services\FeatureRegistry
 */
class FeatureRegistry extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'apikey.features';
    }
}
