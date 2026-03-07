<?php

namespace RiseTechApps\ApiKey\Contracts;

interface FeatureContextInterface
{
    /**
     * Define a lógica padrão de verificação
     */
    public function has(string $feature): bool;
}
