<?php

namespace RiseTechApps\ApiKey\Http\Resources\Dashboard\Plans;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RiseTechApps\ApiKey\Services\FeatureRegistry;

class PlansResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->getKey(),
            'code'                 => $this->code,
            'name'                 => $this->name,
            'description'          => $this->description,
            'request_limit'        => $this->request_limit,
            'price'                => $this->formatted_price,
            'raw_price'            => (float) $this->price,
            'billing_cycle'        => $this->billing_cycle?->value,
            'is_active'            => $this->is_active,
            'features'             => $this->resolveFeatures(),
            'features_description' => $this->features_description ?? [],
        ];
    }

    private function resolveFeatures(): array
    {
        $keys = $this->features ?? [];

        if (empty($keys)) {
            return [];
        }

        $registry = app(FeatureRegistry::class);

        return collect($keys)->map(function (string $key) use ($registry) {
            $meta = $registry->get($key);

            return [
                'key'  => $key,
                'name' => $meta['name'] ?? $key,
                'icon' => $meta['icon'] ?? null,
            ];
        })->values()->all();
    }
}
