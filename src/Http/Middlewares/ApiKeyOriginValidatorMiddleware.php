<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Models\ApiKey;
use RiseTechApps\RiseTools\Features\Device\Device;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyOriginValidatorMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('X-API-KEY') ?? $request->get('api_key');

        $apiKey = auth()->user()->apiKey;

        $requestOrigin = $request->header('Origin') ?? Device::getClientPublicIp();

        if (!$apiKey->isOriginAllowed($requestOrigin)) {
            $logMessage = "Request from unauthorized origin/IP: {$requestOrigin}";

            return response()->json(['message' => 'Unauthorized: Request origin/IP not permitted.'], Response::HTTP_FORBIDDEN);
        }

        $request->attributes->set('api_key_model', $apiKey);

        return $next($request);
    }
}
