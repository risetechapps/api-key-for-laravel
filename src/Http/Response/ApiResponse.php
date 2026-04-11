<?php

namespace RiseTechApps\ApiKey\Http\Response;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use RiseTechApps\ApiKey\Http\Resources\ErrorResource;
use RiseTechApps\ApiKey\Http\Resources\SuccessResource;

class ApiResponse
{
    /**
     * Return a successful response.
     *
     * @param array|object|null $data
     * @param string|null $message
     * @param int $code
     * @return JsonResponse
     */
    public static function success(array|object|null $data = [], ?string $message = null, int $code = 200): JsonResponse
    {
        $resource = new SuccessResource($data, $message, $code);

        return $resource->response()->setStatusCode($code);
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
    public static function error(string $message, int $code = 500, array $errors = [], ?string $error_code = null): JsonResponse
    {
        $resource = new ErrorResource($message, $code, $errors, $error_code);

        return $resource->response()->setStatusCode($code);
    }

    /**
     * Return a paginated collection response.
     *
     * @param ResourceCollection $collection
     * @param string|null $message
     * @param int $code
     * @return JsonResponse
     */
    public static function collection(ResourceCollection $collection, ?string $message = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $collection,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ], $code);
    }

    /**
     * Return a not found error response.
     *
     * @param string $resource
     * @return JsonResponse
     */
    public static function notFound(string $resource = 'Resource'): JsonResponse
    {
        return self::error(
            message: "{$resource} not found",
            code: 404,
            error_code: 'NOT_FOUND'
        );
    }

    /**
     * Return an unauthorized error response.
     *
     * @param string|null $message
     * @return JsonResponse
     */
    public static function unauthorized(?string $message = null): JsonResponse
    {
        return self::error(
            message: $message ?? 'Unauthorized',
            code: 401,
            error_code: 'UNAUTHORIZED'
        );
    }

    /**
     * Return a forbidden error response.
     *
     * @param string|null $message
     * @return JsonResponse
     */
    public static function forbidden(?string $message = null): JsonResponse
    {
        return self::error(
            message: $message ?? 'Forbidden',
            code: 403,
            error_code: 'FORBIDDEN'
        );
    }

    /**
     * Return a validation error response.
     *
     * @param array $errors
     * @param string|null $message
     * @return JsonResponse
     */
    public static function validationError(array $errors, ?string $message = null): JsonResponse
    {
        return self::error(
            message: $message ?? 'Validation failed',
            code: 422,
            errors: $errors,
            error_code: 'VALIDATION_ERROR'
        );
    }
}
