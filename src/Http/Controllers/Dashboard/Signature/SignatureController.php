<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Signature;

use App\Http\Controllers\Controller;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Signature\SignatureRequest;
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
}
