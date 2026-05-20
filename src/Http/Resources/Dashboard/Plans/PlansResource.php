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

        return collect($keys)->map(function ($item) use ($registry) {
            $key = is_array($item) ? ($item['key'] ?? null) : $item;

            if (! is_string($key)) {
                return null;
            }

            $meta = $registry->get($key);

            return [
                'key'  => $key,
                'name' => $meta['name'] ?? (is_array($item) ? ($item['name'] ?? $key) : $key),
                'icon' => $meta['icon'] ?? (is_array($item) ? ($item['icon'] ?? null) : null),
            ];
        })->filter()->values()->all();
    }
}
