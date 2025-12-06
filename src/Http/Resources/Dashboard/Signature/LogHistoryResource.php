<?php

namespace RiseTechApps\ApiKey\Http\Resources\Dashboard\Signature;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LogHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'endpoint' => $this->endpoint,
            'requested_at' => $this->requested_at,
            'method' => $this->method,
            'response_code' => $this->response_code,
        ];
    }
}
