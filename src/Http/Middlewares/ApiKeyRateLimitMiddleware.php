<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use RiseTechApps\ApiKey\Enums\ApiError;
use RiseTechApps\ApiKey\Http\Response\ApiResponse;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limite de requisições por minuto, por chave.
 *
 * A cota do plano é por período: sem isto, um cliente pode gastar o mês numa
 * rajada de minutos e derrubar o servidor de todos. Roda logo depois do
 * `api.key` e antes da reserva de cota, para que a requisição recusada aqui
 * não gaste o plano.
 */
class ApiKeyRateLimitMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->attributes->get('api_key_model');

        if (! $apiKey instanceof ApiKey) {
            return $next($request);
        }

        $limit = $this->limitFor($request, $apiKey);

        if ($limit === null) {
            return $next($request);
        }

        $key = 'api-key-rate:'.$apiKey->getKey();

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retryAfter = RateLimiter::availableIn($key);

            return ApiResponse::apiError(ApiError::RATE_LIMITED, extra: ['retry_after' => $retryAfter], headers: [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $limit,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($key, 60);

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($key, $limit)));

        return $response;
    }

    private function limitFor(Request $request, ApiKey $apiKey): ?int
    {
        $limit = $apiKey->rate_limit_per_minute
            ?? $apiKey->authentication->activePlan->plan->rate_limit_per_minute
            ?? config('api-key.rate_limit.per_minute');

        return $limit ? (int) $limit : null;
    }
}
