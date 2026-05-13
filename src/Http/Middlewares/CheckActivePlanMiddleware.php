<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Events\PlanExpired;
use Symfony\Component\HttpFoundation\Response;

class CheckActivePlanMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('_internal')) {
            return $next($request);
        }

        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => __('api-key::messages.unauthorized')], 401);
        }

        $userPlan = $user->activePlan;

        if (!$userPlan) {
            return response()->json(['error' => __('api-key::messages.plan_expired_or_inactive')], 403);
        }

        // Check if plan is expired but in grace period
        if ($userPlan->isExpired()) {
            if ($userPlan->isInGracePeriod()) {
                $remainingDays = $userPlan->getGracePeriodRemainingDays();

                // Add grace period warning header
                $response = $next($request);
                $response->header('X-Plan-Status', 'grace-period');
                $response->header('X-Grace-Period-Days-Remaining', $remainingDays);

                return $response;
            }

            // Plan is completely expired (past grace period)
            if ($userPlan->isCompletelyExpired()) {
                // Deactivate the plan and API key
                $userPlan->update(['active' => false]);
                $user->apiKey?->update(['active' => false]);

                // Fire event if not already fired
                if ($userPlan->plan) {
                    PlanExpired::dispatch($user, $userPlan, $userPlan->plan, now());
                }

                return response()->json([
                    'error' => __('api-key::messages.plan_expired_grace_ended'),
                    'grace_period_ended' => true
                ], 403);
            }
        }

        return $next($request);
    }
}
