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

        // Permissões da chave: uma chave restrita a algumas features só passa
        // por elas, mesmo que o plano tenha outras. Sem `scopes`, vale o plano.
        $scopes = $request->attributes->get('api_key_model')?->scopes;

        if (is_array($scopes) && ! array_intersect($features, $scopes)) {
            $request->attributes->set(CheckRequestLimitMiddleware::NOT_BILLABLE, true);

            return response()->json([
                'status' => 'forbidden_scope',
                'message' => __('api-key::messages.api_key_scope_denied'),
                'features' => $features,
            ], 403);
        }

        $hasAccess = array_any($features, fn ($feature) => ApiKeyFacade::resolve($feature));

        if (! $hasAccess) {
            $featuresList = implode(', ', $features);

            // O grupo `plan` já reservou a cota antes deste middleware rodar.
            // Recusar acesso a uma feature que o plano não tem não pode custar
            // uma requisição ao cliente.
            $request->attributes->set(CheckRequestLimitMiddleware::NOT_BILLABLE, true);

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'upgrade_required',
                    'message' => "You need at least one of these features: [{$featuresList}]",
                    'features' => $features,
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
