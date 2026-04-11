<?php

namespace RiseTechApps\ApiKey\Http\Resources\Dashboard\Signature;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LogHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'request' => [
                'endpoint' => $this->endpoint,
                'method' => $this->method,
                'requested_at' => $this->requested_at?->toIso8601String(),
            ],
            'response' => [
                'code' => $this->response_code,
                'status' => $this->getStatusText(),
            ],
            'timestamps' => [
                'created_at' => $this->created_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Get status text based on response code.
     */
    private function getStatusText(): string
    {
        $code = $this->response_code;

        return match (true) {
            $code >= 200 && $code < 300 => 'success',
            $code >= 400 && $code < 500 => 'client_error',
            $code >= 500 => 'server_error',
            default => 'unknown',
        };
    }
}
