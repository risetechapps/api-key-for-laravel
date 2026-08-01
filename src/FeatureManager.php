<?php

namespace RiseTechApps\ApiKey;

use Closure;
use ReflectionFunction;
use RiseTechApps\ApiKey\Contracts\FeatureContextInterface;

class FeatureManager
{
    protected array $definitions = [];

    /**
     * Resolved context class per feature name.
     *
     * The context a resolver wants is a property of the closure, not of the
     * request, so reflecting on it once per definition is enough. It used to run
     * on every call — that is once per request on any route using `feature:`.
     *
     * @var array<string, string>
     */
    protected array $contextCache = [];

    public function define(string $name, $resolver): void
    {
        $this->definitions[$name] = $resolver;
        unset($this->contextCache[$name]);
    }

    public function resolve(string $name, ...$arguments): bool
    {
        if (! isset($this->definitions[$name])) {
            return false;
        }

        $resolver = $this->definitions[$name];

        if (! $resolver instanceof Closure) {
            return false;
        }

        $context = app()->make($this->contextClassFor($name, $resolver));

        return $resolver($context, ...$arguments);
    }

    /**
     * The context class to inject into a feature resolver: the first parameter's
     * type when it implements FeatureContextInterface, the default context
     * otherwise (untyped parameter, or a type outside the contract).
     */
    protected function contextClassFor(string $name, Closure $resolver): string
    {
        if (isset($this->contextCache[$name])) {
            return $this->contextCache[$name];
        }

        $contextClass = FeatureContext::class;
        $parameters = new ReflectionFunction($resolver)->getParameters();

        if (isset($parameters[0]) && $parameters[0]->hasType()) {
            $type = $parameters[0]->getType()->getName();

            if (is_subclass_of($type, FeatureContextInterface::class)) {
                $contextClass = $type;
            }
        }

        return $this->contextCache[$name] = $contextClass;
    }
}
