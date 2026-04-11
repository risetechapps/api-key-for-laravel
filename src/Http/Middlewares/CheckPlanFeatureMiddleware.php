<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use RiseTechApps\ApiKey\ApiKeyFacade;
use Symfony\Component\HttpFoundation\Response;

class CheckPlanFeatureMiddleware
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $hasAccess = false;

        foreach ($features as $feature) {
            if (ApiKeyFacade::resolve($feature)) {
                $hasAccess = true;
                break;
            }
        }

        if (!$hasAccess) {
            $featuresList = implode(', ', $features);

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'upgrade_required',
                    'message' => "You need at least one of these features: [{$featuresList}]",
                    'features' => $features
                ], 402);
            }

            return abort(402, "Upgrade required for: {$featuresList}");
        }

        return $next($request);
    }
}
