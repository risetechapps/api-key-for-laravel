<?php

namespace RiseTechApps\ApiKey\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequestLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'endpoint' => $this->endpoint,
            'method' => $this->method,
            'response_code' => $this->response_code,
            'status' => $this->getStatusText(),
            'requested_at' => $this->requested_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Get status text based on response code.
     */
    private function getStatusText(): string
    {
        $code = $this->response_code;

        if ($code >= 200 && $code < 300) {
            return 'success';
        }

        if ($code >= 400 && $code < 500) {
            return 'client_error';
        }

        if ($code >= 500) {
            return 'server_error';
        }

        return 'unknown';
    }
}
