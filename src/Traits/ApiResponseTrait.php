<?php

namespace RiseTechApps\ApiKey\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use RiseTechApps\ApiKey\Http\Response\ApiResponse;

trait ApiResponseTrait
{
    /**
     * Return a successful response.
     *
     * @param array|object|null $data
     * @param string|null $message
     * @param int $code
     * @return JsonResponse
     */
    protected function successResponse(array|object|null $data = [], ?string $message = null, int $code = 200): JsonResponse
    {
        return ApiResponse::success($data, $message, $code);
    }

    /**
     * Return an error response.
     *
     * @param string $message
     * @param int $code
     * @param array $errors
     * @param string|null $error_code
     * @return JsonResponse
     */
    protected function errorResponse(string $message, int $code = 500, array $errors = [], ?string $error_code = null): JsonResponse
    {
        return ApiResponse::error($message, $code, $errors, $error_code);
    }

    /**
     * Return a paginated collection response.
     *
     * @param ResourceCollection $collection
     * @param string|null $message
     * @param int $code
     * @return JsonResponse
     */
    protected function collectionResponse(ResourceCollection $collection, ?string $message = null, int $code = 200): JsonResponse
    {
        return ApiResponse::collection($collection, $message, $code);
    }

    /**
     * Return a not found error response.
     *
     * @param string $resource
     * @return JsonResponse
     */
    protected function notFoundResponse(string $resource = 'Resource'): JsonResponse
    {
        return ApiResponse::notFound($resource);
    }

    /**
     * Return an unauthorized error response.
     *
     * @param string|null $message
     * @return JsonResponse
     */
    protected function unauthorizedResponse(?string $message = null): JsonResponse
    {
        return ApiResponse::unauthorized($message);
    }

    /**
     * Return a forbidden error response.
     *
     * @param string|null $message
     * @return JsonResponse
     */
    protected function forbiddenResponse(?string $message = null): JsonResponse
    {
        return ApiResponse::forbidden($message);
    }
}
