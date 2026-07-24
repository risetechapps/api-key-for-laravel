<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use RiseTechApps\ApiKey\ApiKeyFacade;
use Symfony\Component\HttpFoundation\Response;

class CheckPlanFeatureMiddleware
{
    public function __construct(
        private readonly CheckRequestLimitMiddleware $requestLimit,
    ) {}

    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        if ($request->attributes->get('_internal')) {
            return $next($request);
        }

        $hasAccess = array_any($features, fn($feature) => ApiKeyFacade::resolve($feature));

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

        // Feature liberada: aplica automaticamente o controle de limite/log/contador.
        // O CheckRequestLimitMiddleware é idempotente (flag _request_limit_handled),
        // então não há contagem dupla caso `check.limit.plan` também esteja na rota.
        return $this->requestLimit->handle($request, $next);
    }
}
