<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Enums\ApiError;
use RiseTechApps\ApiKey\Http\Response\ApiResponse;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Support\Quota;
use RiseTechApps\ApiKey\Support\UsageHistory;

/**
 * Uso da conta, consultado com a própria API key.
 *
 * Fica fora do `check.limit.plan` de propósito: consultar a cota não gasta a
 * cota. O que segura abuso é o `api.key.rate` da rota, o mesmo limite por
 * minuto das outras chamadas.
 */
class UsageController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $userPlan = $this->userPlan($request);

        if (! $userPlan) {
            return ApiResponse::apiError(ApiError::PLAN_INACTIVE);
        }

        $apiKey = $request->attributes->get('api_key_model');

        $data = Quota::report($userPlan);
        $data['rate_limit'] = [
            'per_minute' => $this->rateLimit($apiKey, $userPlan),
        ];
        $data['api_key'] = $apiKey instanceof ApiKey ? [
            'id' => $apiKey->getKey(),
            'name' => $apiKey->name,
            'expires_at' => $apiKey->expires_at?->toIso8601String(),
        ] : null;

        return $this->respond($data, $userPlan);
    }

    /**
     * Requisições por dia nos últimos `days` dias (padrão 30, de 1 a 90; fora
     * disso, o valor é trazido para dentro da faixa).
     */
    public function daily(Request $request): JsonResponse
    {
        $userPlan = $this->userPlan($request);

        if (! $userPlan) {
            return ApiResponse::apiError(ApiError::PLAN_INACTIVE);
        }

        $days = UsageHistory::daily($request->user(), $request->integer('days', 30));

        return $this->respond([
            'from' => $days[0]['date'],
            'to' => $days[array_key_last($days)]['date'],
            'timezone' => config('app.timezone'),
            'days' => $days,
        ], $userPlan);
    }

    private function userPlan(Request $request): ?UserPlan
    {
        /** @var UserPlan|null $userPlan */
        $userPlan = $request->attributes->get('user_plan')
            ?? $request->user()?->activePlanWithGracePeriod()->with('plan')->first();

        if ($userPlan && $request->user() && ! $userPlan->relationLoaded('authentication')) {
            $userPlan->setRelation('authentication', $request->user());
        }

        return $userPlan;
    }

    /** @param  array<string, mixed>  $data */
    private function respond(array $data, UserPlan $userPlan): JsonResponse
    {
        $response = response()->json(['success' => true, 'data' => $data]);

        if (config('api-key.request_limit.headers', true)) {
            $response->headers->add(Quota::headers($userPlan));
        }

        return $response;
    }

    /** Mesma precedência do ApiKeyRateLimitMiddleware: chave, plano, config. */
    private function rateLimit(mixed $apiKey, UserPlan $userPlan): ?int
    {
        $limit = ($apiKey instanceof ApiKey ? $apiKey->rate_limit_per_minute : null)
            ?? $userPlan->plan->rate_limit_per_minute
            ?? config('api-key.rate_limit.per_minute');

        return $limit ? (int) $limit : null;
    }
}
