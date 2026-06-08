<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Signature;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Signature\SignatureRequest;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Signature\LogHistoryResource;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Signature\SignatureHistoryResource;
use RiseTechApps\ApiKey\Repositories\Plan\PlanRepository;

class SignatureController extends Controller
{
    public function __construct(protected readonly PlanRepository $planRepository)
    {
    }

    public function signature(SignatureRequest $request): JsonResponse
    {
        try {

            $data = $request->validationData();

            $plan = $this->planRepository->findById($data['plan']);
            auth()->user()->subscribeToPlan($plan);

            return response()->jsonSuccess();
        } catch (\Exception $e) {
            report($e);
            return response()->jsonGone(__('api-key::messages.error_creating_signature'));
        }
    }

    public function history(Request $request): JsonResponse
    {
        try {

            $data = SignatureHistoryResource::collection(
                auth()->user()->userPlan()->with('plan')->latest()->get()
            );
            return response()->jsonSuccess($data);
        } catch (\Exception $e) {
            report($e);
            return response()->jsonGone(__('api-key::messages.error_loading_signature_history'));
        }
    }

    public function log(Request $request): JsonResponse
    {
        try {

            $data = LogHistoryResource::collection(
                auth()->user()->requestLog()->latest('requested_at')->get()
            );
            return response()->jsonSuccess($data);
        } catch (\Exception $e) {
            report($e);
            return response()->jsonGone(__('api-key::messages.error_loading_request_log') . $e->getMessage());
        }
    }

    /**
     * Estatísticas agregadas e leves para o dashboard (polling em tempo real).
     * Usa COUNT e o contador do plano ativo — não carrega a lista de logs.
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            $activePlan = $user->activePlan()->with('plan')->first();

            $used      = (int) ($activePlan?->requests_used ?? 0);
            $limit     = (int) ($activePlan?->plan?->request_limit ?? 0);
            // Nunca exibe acima do limite (cobre dado legado / overshoot de concorrência).
            if ($limit > 0) {
                $used = min($used, $limit);
            }
            $remaining = $limit > 0 ? max(0, $limit - $used) : 0;

            $today = $user->requestLog()
                ->whereDate('requested_at', now()->toDateString())
                ->count();

            return response()->jsonSuccess([
                'today'     => $today,
                'used'      => $used,
                'remaining' => $remaining,
                'limit'     => $limit,
            ]);
        } catch (\Exception $e) {
            report($e);
            return response()->jsonGone(__('api-key::messages.error_loading_request_log'));
        }
    }
}
