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

        if ($requestsMade >= $requestsLimit) {
            return response()->json(['error' => 'Limite de requisições atingido'], 429);
        }

        $user->requestUsed();

        return $next($request);


//        $user = $request->user();
//
//        if (!$user || !$user->plan || !$user->plan->isActive()) {
//            return response()->json(['error' => 'Usuário sem plano ativo'], 403);
//        }
//
//        $userPlan = $user->userPlan;
//
//        if ($userPlan->requests_used >= $userPlan->plan->request_limit) {
//            return response()->json(['error' => 'Limite de requisições atingido'], 429);
//        }
//
//        $response = $next($request);
//
//        RequestLog::create([
//            'user_id' => $user->id,
//            'endpoint' => $request->path(),
//        ]);
//
//        $userPlan->increment('requests_used');
//
//        return $response;
    }
}
