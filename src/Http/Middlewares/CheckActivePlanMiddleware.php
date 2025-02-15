<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;

class CheckActivePlanMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || !$user->activePlan) {
            return response()->json(['error' => 'Seu plano expirou ou não está ativo'], 403);
        }

        return $next($request);
    }
}
