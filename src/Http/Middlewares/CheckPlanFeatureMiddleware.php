<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;

class CheckPlanFeatureMiddleware
{
    public function handle(Request $request, Closure $next, string $feature)
    {

        if (Feature::inactive($feature)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'upgrade_required',
                    'message' => "The [{$feature}] feature is not available on your current plan.",
                    'feature' => $feature
                ], 402);
            }

            return abort(402, "Upgrade to access: {$feature}");
        }

        return $next($request);
    }
}
