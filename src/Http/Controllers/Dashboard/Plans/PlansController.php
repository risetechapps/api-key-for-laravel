<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Plans;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Plans\StorePlanRequest;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Plans\UpdatePlanRequest;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Plans\PlansResource;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Repositories\Plan\PlanRepository;
use RiseTechApps\ApiKey\Traits\HandlesUniqueViolation;

class PlansController extends Controller
{
    use HandlesUniqueViolation;

    private const string PLANS_CACHE_KEY = 'api_key:plans:active';

    public function __construct(protected readonly PlanRepository $planRepositor) {}

    public function index(Request $request): JsonResponse
    {
        try {

            // Cache à prova de driver: guardamos o ARRAY já renderizado (escalares),
            // não a Collection de objetos Eloquent. Serializar objetos quebra no
            // transporte de cache deste ambiente (predis corrompe os bytes \0 de
            // objetos); um array simples (texto puro) faz round-trip em qualquer driver.
            $data = Cache::remember(self::PLANS_CACHE_KEY, now()->addMinutes(10), fn () => PlansResource::collection(
                Plan::query()->where('is_active', true)->get()
            )->resolve());

            return response()->jsonSuccess($data);

        } catch (\Exception $e) {
            report($e);

            return response()->jsonGone(__('api-key::messages.error_loading_plans'));
        }
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        try {

            $data = $request->validated();

            $this->planRepositor->store($data);

            Cache::forget(self::PLANS_CACHE_KEY);

            return response()->jsonSuccess();
        } catch (QueryException $e) {
            if ($response = $this->uniqueViolationResponse($e, 'name', __('api-key::messages.plan_name_taken'))) {
                return $response;
            }

            report($e);

            return response()->jsonGone(__('api-key::messages.error_creating_plan'));
        } catch (\Exception $e) {
            report($e);

            return response()->jsonGone(__('api-key::messages.error_creating_plan'));

        }
    }

    public function show(Request $request, string $plan): JsonResponse
    {
        try {

            $plan = Plan::find($plan);

            if (! is_null($plan)) {
                return response()->jsonSuccess(PlansResource::make($plan));
            }

            return response()->jsonGone(__('api-key::messages.error_loading_plan'));

        } catch (\Exception $e) {
            report($e);

            return response()->jsonGone(__('api-key::messages.error_loading_plan'));
        }
    }

    public function update(UpdatePlanRequest $request, string $plan): JsonResponse
    {
        try {

            $data = $request->validated();

            $plan = Plan::find($plan);

            if (! is_null($plan)) {

                // Update direto no model, e não pelo repository: a detecção de
                // mudanças do BaseRepository faz `(string) $model->getAttribute($key)`
                // em cada campo enviado, e `billing_cycle` é cast para o enum
                // BillingCycle — que não converte para string. O resultado era um
                // Error (não Exception, logo fora do catch abaixo) e 500 em toda
                // edição de plano. O repository não cacheia leituras de plano
                // (findActiveById consulta o Eloquent direto) e a vitrine é
                // invalidada logo abaixo, então nada se perde neste caminho.
                $plan->update($data);

                Cache::forget(self::PLANS_CACHE_KEY);

                return response()->jsonSuccess();
            }

            return response()->jsonGone(__('api-key::messages.error_updating_plan'));

        } catch (QueryException $e) {
            if ($response = $this->uniqueViolationResponse($e, 'name', __('api-key::messages.plan_name_taken'))) {
                return $response;
            }

            report($e);

            return response()->jsonGone(__('api-key::messages.error_updating_plan'));
        } catch (\Exception $e) {
            report($e);

            return response()->jsonGone(__('api-key::messages.error_updating_plan'));
        }
    }

    public function delete(Request $request, string $plan): JsonResponse
    {

        try {

            $plan = Plan::find($plan);

            if (! is_null($plan)) {

                $plan->delete();

                Cache::forget(self::PLANS_CACHE_KEY);

                return response()->jsonSuccess();
            }

            return response()->jsonGone(__('api-key::messages.error_deleting_plan'));
        } catch (\Exception $e) {

            report($e);

            return response()->jsonGone(__('api-key::messages.error_deleting_plan'));
        }
    }
}
