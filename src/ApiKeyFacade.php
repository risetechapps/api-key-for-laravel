<?php

namespace RiseTechApps\ApiKey;

use Illuminate\Support\Facades\Facade;

/**
 * @see \RiseTechApps\ApiKey\Skeleton\SkeletonClass
 * @method routes
 */
class ApiKeyFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'apikey';
    }
}
