<?php

namespace RiseTechApps\ApiKey;

use Illuminate\Support\Facades\Facade;

/**
 * O accessor 'apikey' resolve o FeatureManager, então é a interface dele que
 * vale aqui. Rotas não passam por este facade: `RoutesApiKey::register()`.
 *
 * @see FeatureManager
 *
 * @method static void define(string $name, \Closure $resolver)
 * @method static bool resolve(string $name, mixed ...$arguments)
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
