<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Enums\ApiError;
use RiseTechApps\ApiKey\Events\PlanExpired;
use RiseTechApps\ApiKey\Http\Response\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class CheckActivePlanMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('_internal')) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return ApiResponse::apiError(ApiError::UNAUTHENTICATED);
        }

        $userPlan = $user->activePlanWithGracePeriod()->with('plan')->first();
        $request->attributes->set('user_plan', $userPlan);

        $context = ['user_id' => $user->getKey(), 'url' => $request->url()];

        if (! $userPlan) {
            $expiredPlan = $user->userPlan()
                ->where('active', true)
                ->latest('end_date')
                ->first();

            if ($expiredPlan?->isCompletelyExpired()) {
                logglyInfo()->withContext([
                    'plan_id' => $expiredPlan->plan_id, ...$context,
                ])->log('Plan completely expired, deactivating');

                $expiredPlan->update(['active' => false]);
                $user->apiKey?->update(['active' => false]);

                if ($expiredPlan->plan) {
                    PlanExpired::dispatch($user, $expiredPlan, $expiredPlan->plan, now());
                }

                return ApiResponse::apiError(ApiError::PLAN_EXPIRED);
            }

            logglyWarning()->withContext($context)->log('No active plan found');

            return ApiResponse::apiError(ApiError::PLAN_INACTIVE);
        }

        if ($userPlan->isExpired()) {
            $remainingDays = $userPlan->getGracePeriodRemainingDays();

            logglyInfo()->withContext([
                'plan_id' => $userPlan->plan_id,
                'remaining_days' => $remainingDays,
                ...$context,
            ])->log('Plan in grace period');

            $response = $next($request);
            $response->header('X-Plan-Status', 'grace-period');
            $response->header('X-Grace-Period-Days-Remaining', $remainingDays);

            return $response;
        }

        return $next($request);
    }
}
