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

            $data = LogHistoryResource::collection(auth()->user()->requestLog);
            return response()->jsonSuccess($data);
        } catch (\Exception $e) {
            report($e);
            return response()->jsonGone(__('api-key::messages.error_loading_request_log') . $e->getMessage());
        }
    }
}
