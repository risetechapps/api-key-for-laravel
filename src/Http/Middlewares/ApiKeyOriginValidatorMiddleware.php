<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RiseTechApps\RiseTools\Features\Device\Device;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyOriginValidatorMiddleware
{
    /**
     * Validate request origin against allowed origins for the API key.
     *
     * SECURITY NOTICE: This validation relies on the Origin header and client IP,
     * which can be spoofed. For production environments, consider implementing:
     * - HMAC signature validation for critical endpoints
     * - Additional device fingerprinting
     * - Webhook signature verification for incoming requests
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-API-KEY') ?? $request->get('api_key');

        $apiKey = auth()->user()->apiKey;

        $requestOrigin = $request->header('Origin') ?? Device::getClientPublicIp();

        if (!$apiKey->isOriginAllowed($requestOrigin)) {
            Log::warning('API key origin validation failed', [
                'ip' => $request->ip(),
                'origin' => $requestOrigin,
                'api_key_id' => $apiKey?->id,
                'user_id' => auth()->id(),
                'url' => $request->url(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json(['message' => 'Unauthorized: Request origin/IP not permitted.'], Response::HTTP_FORBIDDEN);
        }

        $request->attributes->set('api_key_model', $apiKey);

        return $next($request);
    }
}
