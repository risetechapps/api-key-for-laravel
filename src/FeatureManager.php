<?php

namespace RiseTechApps\ApiKey;

use Closure;
use ReflectionFunction;
use RiseTechApps\ApiKey\Contracts\FeatureContextInterface;

class FeatureManager
{
    protected array $definitions = [];

    public function define(string $name, $resolver): void
    {
        $this->definitions[$name] = $resolver;
    }

    public function resolve(string $name, ...$arguments): bool
    {
        if (!isset($this->definitions[$name])) {
            return false;
        }

        $resolver = $this->definitions[$name];

        if ($resolver instanceof Closure) {
            $reflection = new ReflectionFunction($resolver);
            $parameters = $reflection->getParameters();

            // Se a Closure pedir um parâmetro e ele for uma classe
            if (isset($parameters[0]) && $parameters[0]->hasType()) {
                $type = $parameters[0]->getType()->getName();

                // Verificamos se a classe pedida implementa o nosso contrato
                if (is_subclass_of($type, FeatureContextInterface::class)) {
                    // O "app()->make()" resolve a classe e injeta dependências automaticamente
                    $context = app()->make($type);
                    return $resolver($context, ...$arguments);
                }
            }

            // Fallback para o contexto padrão caso não seja tipado ou não siga a interface
            return $resolver(app()->make(FeatureContext::class), ...$arguments);
        }

        return false;
    }
}
