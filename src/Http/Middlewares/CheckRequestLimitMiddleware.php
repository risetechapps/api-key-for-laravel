<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Events\RequestLimitReached;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use Symfony\Component\HttpFoundation\Response;

class CheckRequestLimitMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var \RiseTechApps\ApiKey\Models\Authentication\Authentication $user */
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

        // Log request asynchronously to not delay response
        dispatch(function () use ($user, $response) {
            $user->requestUsed($response->getStatusCode());
        })->afterResponse();

        return $response;
    }
}
