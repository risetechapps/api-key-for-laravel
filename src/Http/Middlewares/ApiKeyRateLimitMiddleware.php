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
 * Limite de requisições por minuto, por conta e por chave.
 *
 * A cota do plano é por período: sem isto, um cliente pode gastar o mês numa
 * rajada de minutos e derrubar o servidor de todos. Roda logo depois do
 * `api.key` e antes da reserva de cota, para que a requisição recusada aqui
 * não gaste o plano.
 *
 * O limite do plano vale para a conta inteira: com um contador por chave,
 * cinco chaves dariam cinco vezes o plano. A chave pode ter um limite próprio,
 * que só aperta — nunca passa do plano.
 */
class ApiKeyRateLimitMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->attributes->get('api_key_model');

        if (! $apiKey instanceof ApiKey) {
            return $next($request);
        }

        $counters = $this->countersFor($apiKey);

        if ($counters === []) {
            return $next($request);
        }

        foreach ($counters as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $retryAfter = RateLimiter::availableIn($key);

                return ApiResponse::apiError(ApiError::RATE_LIMITED, extra: ['retry_after' => $retryAfter], headers: [
                    'Retry-After' => $retryAfter,
                    'X-RateLimit-Limit' => $limit,
                    'X-RateLimit-Remaining' => 0,
                ]);
            }
        }

        foreach ($counters as $key => $limit) {
            RateLimiter::hit($key, 60);
        }

        $response = $next($request);

        // Os headers mostram o contador que acaba primeiro.
        [$limit, $remaining] = collect($counters)
            ->map(fn (int $limit, string $key) => [$limit, max(0, RateLimiter::remaining($key, $limit))])
            ->sortBy(fn (array $pair) => $pair[1])
            ->first();

        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);

        return $response;
    }

    /**
     * Contadores que a requisição gasta, com o limite de cada um.
     *
     * @return array<string, int>
     */
    private function countersFor(ApiKey $apiKey): array
    {
        $accountLimit = $apiKey->authentication->activePlan->plan->rate_limit_per_minute
            ?? config('api-key.rate_limit.per_minute');
        $accountLimit = $accountLimit ? (int) $accountLimit : null;

        $keyLimit = $apiKey->rate_limit_per_minute ? (int) $apiKey->rate_limit_per_minute : null;

        if ($keyLimit !== null && $accountLimit !== null) {
            $keyLimit = min($keyLimit, $accountLimit);
        }

        $counters = [];

        if ($accountLimit !== null) {
            $counters['api-key-rate:account:'.$apiKey->authentication_id] = $accountLimit;
        }

        // Igual ao da conta não precisa de contador próprio: o da conta já basta.
        if ($keyLimit !== null && $keyLimit !== $accountLimit) {
            $counters['api-key-rate:'.$apiKey->getKey()] = $keyLimit;
        }

        return $counters;
    }
}
