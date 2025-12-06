<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Signature;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Signature\SignatureRequest;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Signature\LogHistoryResource;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Signature\SignatureHistoryResource;
use RiseTechApps\ApiKey\Repositories\Plan\PlanRepository;

class SignatureController extends Controller
{
    public function __construct(protected readonly PlanRepository $planRepository)
    {
    }

    public function signature(SignatureRequest $request)
    {
        try {

            $data = $request->validationData();

            $plan = $this->planRepository->findById($data['plan']);
            auth()->user()->subscribeToPlan($plan);

            return response()->jsonSuccess();
        } catch (\Exception $e) {
            return response()->jsonGone();
        }
    }

    public function history(Request $request)
    {
        try {

            $data = SignatureHistoryResource::collection(auth()->user()->userPlan);
            return response()->jsonSuccess($data);
        } catch (\Exception $e) {
            return response()->jsonGone($e->getMessage());
        }
    }

    public function log(Request $request)
    {
        try {

            $data = LogHistoryResource::collection(auth()->user()->requestLog);
            return response()->jsonSuccess($data);
        } catch (\Exception $e) {
            return response()->jsonGone($e->getMessage());
        }
    }
}
