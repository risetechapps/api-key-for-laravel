<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RiseTechApps\ApiKey\Events\PlanUsageThresholdReached;
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

        // Idempotência: garante que o controle de limite/log/contador rode uma única
        // vez por request, mesmo quando este middleware é aplicado em mais de um ponto
        // (ex.: presente no grupo `plan` E delegado pelo middleware `feature`).
        if ($request->attributes->get('_request_limit_handled')) {
            return $next($request);
        }
        $request->attributes->set('_request_limit_handled', true);

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

            // Registra a requisição bloqueada no log, mas NÃO conta na cota
            // (countUsage = false) — assim o contador não ultrapassa o limite.
            dispatch(function () use ($user) {
                $user->requestUsed(429, false);
            })->afterResponse();

            return response()->json(['error' => __('api-key::messages.request_limit_reached')], 429);
        }

        // Aviso de uso próximo do limite (ex.: 80%). Dispara em toda requisição
        // dentro da faixa [threshold%, 100%); o listener faz throttle para enviar
        // no máximo um e-mail por período do plano.
        if ($requestsLimit > 0 && $activePlan && $activePlan->plan) {
            $threshold = (int) config('api-key.request_limit.warning_threshold', 80);

            if ($threshold > 0 && $threshold < 100) {
                $warnAt = (int) ceil($requestsLimit * $threshold / 100);

                if ($requestsMade >= $warnAt && $requestsMade < $requestsLimit) {
                    PlanUsageThresholdReached::dispatch(
                        $user,
                        $activePlan,
                        $activePlan->plan,
                        $requestsMade,
                        $requestsLimit,
                        $threshold
                    );
                }
            }
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
