<?php

namespace RiseTechApps\ApiKey\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SuccessResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param array|string $data Response data
     * @param string|null $message Success message
     * @param int $code HTTP status code
     */
    public function __construct(
        $data = [],
        private ?string $message = null,
        private int $code = 200
    ) {
        parent::__construct($data);
    }

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $response = [
            'success' => true,
        ];

        if ($this->message) {
            $response['message'] = $this->message;
        }

        // Handle different data types
        if (is_array($this->resource)) {
            if (isset($this->resource['data'])) {
                $response['data'] = $this->resource['data'];
                if (isset($this->resource['meta'])) {
                    $response['meta'] = $this->resource['meta'];
                }
            } else {
                $response['data'] = $this->resource;
            }
        } else {
            $response['data'] = $this->resource;
        }

        // Include metadata
        $response['meta'] = array_merge(
            $response['meta'] ?? [],
            [
                'timestamp' => now()->toIso8601String(),
            ]
        );

        return $response;
    }

    /**
     * Customize the response with proper status code.
     */
    public function withResponse($request, $response): void
    {
        $response->setStatusCode($this->code);
    }
}
