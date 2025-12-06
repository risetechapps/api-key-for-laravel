<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Models\RequestLog;

class CheckRequestLimitMiddleware
{
    public function handle(Request $request, Closure $next)
    {

        $user = $request->user();

        $requestsMade = $user->countUsed();
        $requestsLimit = $user->requestLimit();

        if ($requestsMade >= $requestsLimit && $requestsLimit > 0) {
            $user->requestUsed(429);
            return response()->json(['error' => 'Limite de requisições atingido'], 429);
        }

        $response = $next($request);

        $user->requestUsed($response->getStatusCode());

        return $response;
    }
}
