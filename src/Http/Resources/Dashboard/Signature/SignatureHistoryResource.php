<?php

namespace RiseTechApps\ApiKey\Http\Resources\Dashboard\Signature;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SignatureHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'active' => $this->active,
            'requests_used' => $this->requests_used,
        ];
    }
}
