<?php

namespace RiseTechApps\ApiKey;

use Illuminate\Support\Facades\Facade;

/**
 * O accessor 'apikey' resolve o FeatureManager, então é a interface dele que
 * vale aqui. A anotação anterior descrevia `ApiKeyService::routes()`, que não
 * passa por este facade, e era malformada (faltavam os parênteses); a
 * referência apontava para uma SkeletonClass que não existe no pacote.
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
