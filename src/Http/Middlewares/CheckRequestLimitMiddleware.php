<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RiseTechApps\ApiKey\Events\RequestLimitReached;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use Symfony\Component\HttpFoundation\Response;

class CheckRequestLimitMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('_internal')) {
            return $next($request);
        }

        /** @var Authentication $user */
        $user = $request->user();

        // Load active plan with eager loading to avoid N+1
        /** @var UserPlan|null $activePlan */
        $activePlan = $user->activePlan()->with(['plan'])->first();

        $requestsMade = $activePlan?->requests_used ?? 0;
        $requestsLimit = $activePlan?->plan?->request_limit ?? 0;

        if ($requestsLimit > 0 && $requestsMade >= $requestsLimit) {
            // Dispatch event when request limit is reached
            if ($activePlan && $activePlan->plan) {
                RequestLimitReached::dispatch(
                    $user,
                    $activePlan,
                    $activePlan->plan,
                    $requestsMade,
                    $requestsLimit
                );
            }

            // Use dispatch to make logging asynchronous
            dispatch(function () use ($user) {
                $user->requestUsed(429);
            })->afterResponse();

            return response()->json(['error' => __('api-key::messages.request_limit_reached')], 429);
        }

        $response = $next($request);

        $userId = $user->id;
        $statusCode = $response->getStatusCode();

        dispatch(function () use ($userId, $statusCode) {
            $user = Authentication::find($userId);
            if ($user) {
                $user->requestUsed($statusCode);
            }
        })->afterResponse();

        return $response;
    }
}
