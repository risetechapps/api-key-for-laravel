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
            'name' => $this->name,
            'request_limit' => $this->request_limit,
            'price' => $this->price,
            'billing_cycle' => $this->billing_cycle,
            'is_active' => $this->is_active,
            'features' => $this->features,
        ];
    }
}
