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

        $user = $apiKey->authentication;

        // The owner can be gone while the key row survives (soft-deleted user,
        // or a key detached from its account). Without this the request would
        // reach setUser(null) and fail with a 500 instead of a 401.
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Both sides of the relation are already in memory. Priming them stops
        // the downstream middlewares from re-selecting the same two rows.
        $user->setRelation('apiKey', $apiKey);
        $apiKey->setRelation('authentication', $user);
        $request->attributes->set('api_key_model', $apiKey);

        auth()->setUser($user);

        return $next($request);
    }
}
