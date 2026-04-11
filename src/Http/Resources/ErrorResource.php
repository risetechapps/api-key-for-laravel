<?php

namespace RiseTechApps\ApiKey\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ErrorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param string $message Error message
     * @param int $code HTTP status code
     * @param array $errors Detailed errors (for validation errors)
     * @param string|null $error_code Application-specific error code
     */
    public function __construct(
        string $message,
        private int $code = 500,
        private array $errors = [],
        private ?string $error_code = null
    ) {
        parent::__construct(['message' => $message]);
    }

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $response = [
            'success' => false,
            'message' => $this->resource['message'],
            'code' => $this->code,
        ];

        if ($this->error_code) {
            $response['error_code'] = $this->error_code;
        }

        if (!empty($this->errors)) {
            $response['errors'] = $this->errors;
        }

        // Include request info in debug mode
        if (config('app.debug')) {
            $response['debug'] = [
                'request_id' => uniqid(),
                'timestamp' => now()->toIso8601String(),
            ];
        }

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
