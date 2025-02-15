<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Models\ApiKey;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('X-API-KEY');

        $apiKey = ApiKey::validateKey($key);

        if (!$apiKey) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        auth()->setUser($apiKey->authentication);


        return $next($request);
    }
}
