<?php

namespace RiseTechApps\ApiKey\Http\Resources\Dashboard\Modules;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ModulesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'module' => $this->module
        ];
    }
}
