<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        // Internal bypass: only valid from localhost (127.0.0.1 / ::1)
        $internalToken = $request->header('X-Internal-Token');
        $isLoopback    = in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1', '0:0:0:0:0:0:0:1'], true);

        if ($internalToken && $isLoopback && config('api-key.internal_token') && hash_equals(config('api-key.internal_token'), $internalToken)) {
            $user = Authentication::with('apiKey')->find($request->header('X-User-Id'));
            if ($user) {
                auth()->setUser($user);
                $request->attributes->set('_internal', true);
                return $next($request);
            }
        }

        $headerName = config('api-key.header_name', 'X-API-KEY');
        $key = $request->header($headerName);

        $apiKey = ApiKey::validateKey($key);

        if (!$apiKey) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        auth()->setUser($apiKey->authentication);

        return $next($request);
    }
}
