<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = [
            'ip' => $request->ip(),
            'url' => $request->url(),
            'method' => $request->method(),
        ];

        // Internal bypass: only valid from localhost (127.0.0.1 / ::1)
        $internalToken = $request->header('X-Internal-Token');
        $isLoopback = in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1', '0:0:0:0:0:0:0:1'], true);

        if ($internalToken && $isLoopback && config('api-key.internal_token') && hash_equals(config('api-key.internal_token'), $internalToken)) {
            $userId = $request->header('X-User-Id');
            $user = Authentication::with('apiKey')->find($userId);
            if ($user) {
                auth()->setUser($user);
                $request->attributes->set('_internal', true);

                return $next($request);
            }
            Log::warning('Internal auth failed: user not found', ['user_id' => $userId, ...$context]);
        }

        $headerName = config('api-key.header_name', 'X-API-KEY');
        $key = $request->header($headerName);

        if (! $key) {
            Log::info('API key authentication failed: missing key', $context);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $apiKey = ApiKey::validateKey($key);

        if (! $apiKey) {
            Log::info('API key authentication failed: invalid key', $context);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user = $apiKey->authentication;

        if (! $user) {
            Log::warning('API key authentication failed: key has no owner', [
                'api_key_id' => $apiKey->id, ...$context,
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user->setRelation('apiKey', $apiKey);
        $apiKey->setRelation('authentication', $user);
        $request->attributes->set('api_key_model', $apiKey);

        auth()->setUser($user);

        return $next($request);
    }
}
