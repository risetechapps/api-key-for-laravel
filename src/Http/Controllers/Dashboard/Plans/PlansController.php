<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Plans;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Plans\StorePlanRequest;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Plans\UpdatePlanRequest;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Plans\PlansResource;
use RiseTechApps\ApiKey\Models\Authentication;
use RiseTechApps\ApiKey\Models\Plan;
use RiseTechApps\ApiKey\Repositories\Plan\PlanRepository;

class PlansController extends Controller
{

    public function __construct(protected readonly PlanRepository $planRepositor)
    {

    }

    public function index(Request $request): JsonResponse
    {
        try {

            $data = $this->planRepositor->findWhere(['is_active' => true]);

            return response()->jsonSuccess(PlansResource::collection($data));

        } catch (\Exception $e) {
            return response()->jsonGone();
        }
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        try {

            $data = $request->validated();

            $plan = $this->planRepositor->store($data);

            $plan->modules()->sync($data['modules']);

            return response()->jsonSuccess();
        } catch (\Exception $e) {
            return response()->jsonGone();
        }
    }

    public function show(Plan $plan): JsonResponse
    {
        try {

            if (!is_null($plan)) {
                return response()->jsonSuccess(PlansResource::make($plan));
            }

            return response()->jsonGone();

        } catch (\Exception $e) {
            return response()->jsonGone();
        }
    }

    public function update(UpdatePlanRequest $request, Plan $plan): JsonResponse
    {
        try {

            $data = $request->validated();

            if (!is_null($plan)) {

                $this->planRepositor->update($plan->getKey(), $data);

                $plan->modules()->sync($data['modules']);

                return response()->jsonSuccess();
            }
            return response()->jsonGone();

        } catch (\Exception $e) {
            return response()->jsonGone();
        }
    }

    public function delete(Plan $plan): JsonResponse
    {

        try {
            if (!is_null($plan)) {

                $this->planRepositor->findById($plan->getKey())->delete();
                return response()->jsonSuccess();
            }

            return response()->jsonGone();
        } catch (\Exception $e) {
            return response()->jsonGone();
        }
    }

}
