<?php

namespace RiseTechApps\ApiKey\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use RiseTechApps\ApiKey\Http\Response\ApiResponse;

trait ApiResponseTrait
{
    /**
     * Return a successful response.
     */
    protected function successResponse(array|object|null $data = [], ?string $message = null, int $code = 200): JsonResponse
    {
        return ApiResponse::success($data, $message, $code);
    }

    /**
     * Return an error response.
     */
    protected function errorResponse(string $message, int $code = 500, array $errors = [], ?string $error_code = null): JsonResponse
    {
        return ApiResponse::error($message, $code, $errors, $error_code);
    }

    /**
     * Return a paginated collection response.
     */
    protected function collectionResponse(ResourceCollection $collection, ?string $message = null, int $code = 200): JsonResponse
    {
        return ApiResponse::collection($collection, $message, $code);
    }

    /**
     * Return a not found error response.
     */
    protected function notFoundResponse(string $resource = 'Resource'): JsonResponse
    {
        return ApiResponse::notFound($resource);
    }

    /**
     * Return an unauthorized error response.
     */
    protected function unauthorizedResponse(?string $message = null): JsonResponse
    {
        return ApiResponse::unauthorized($message);
    }

    /**
     * Return a forbidden error response.
     */
    protected function forbiddenResponse(?string $message = null): JsonResponse
    {
        return ApiResponse::forbidden($message);
    }
}
