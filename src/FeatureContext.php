<?php

namespace RiseTechApps\ApiKey;

use RiseTechApps\ApiKey\Contracts\FeatureContextInterface;

class FeatureContext implements FeatureContextInterface
{
    public $user;
    public $plan;
    public array $features = [];

    public function __construct($user = null)
    {
        // Se não passarmos nada, ele tenta pegar o user logado
        $this->user = $user ?? auth()->user();

        if ($this->user) {
            $activePlan = $this->user->activePlan()->with('plan')->first();
            $this->plan = $activePlan?->plan;
            $this->features = $this->plan?->features ?? [];
        }
    }

    public function has(string $featureName): bool
    {
        return in_array($featureName, $this->features);
    }
}
