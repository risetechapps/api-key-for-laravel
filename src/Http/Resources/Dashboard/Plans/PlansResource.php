<?php

namespace RiseTechApps\ApiKey\Http\Resources\Dashboard\Plans;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Support\FeatureResolver;

/**
 * @mixin Plan
 */
class PlansResource extends JsonResource
{
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'request_limit' => $this->request_limit,
            'price' => $this->formatted_price,
            'raw_price' => (float) $this->price,
            'billing_cycle' => $this->billing_cycle?->value,
            'is_active' => $this->is_active,
            // Resolve as keys para {key, name, description, icon}; a key nunca
            // é exibida como rótulo (ver FeatureResolver).
            'features' => FeatureResolver::resolve($this->features),
        ];
    }
}
