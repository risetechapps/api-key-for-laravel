<?php

namespace RiseTechApps\ApiKey\Http\Resources\Dashboard\Plans;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Modules\ModulesResource;

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
            'visible' => $this->visible,
            'modules' => ModulesResource::collection($this->modules)
        ];
    }
}
