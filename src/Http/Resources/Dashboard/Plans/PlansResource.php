<?php

namespace RiseTechApps\ApiKey\Http\Resources\Dashboard\Plans;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlansResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'=> $this->getKey(),
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'request_limit' => $this->request_limit,
            'price' => [
                'value' => $this->price,
                'formatted' => $this->formatted_price,
            ],
            'billing_cycle' => $this->billing_cycle?->value,
            'is_active' => $this->is_active,
            'features' => $this->features ?? [],
            'dates' => [
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ],
        ];
    }
}
