<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Plans;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Plans\StorePlanRequest;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Plans\UpdatePlanRequest;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Plans\PlansResource;
use RiseTechApps\ApiKey\Models\Plan\Plan;
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
            report($e);
            return response()->jsonGone(__('api-key::messages.error_loading_plans'));
        }
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        try {

            $data = $request->validated();

            $this->planRepositor->store($data);

            return response()->jsonSuccess();
        } catch (\Exception $e) {
            report($e);
            return response()->jsonGone(__('api-key::messages.error_creating_plan'));

        }
    }

    public function show(Request $request,string $plan): JsonResponse
    {
        try {

            $plan = $this->planRepositor->findById($plan);

            if (!is_null($plan)) {
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

            $plan = $this->planRepositor->findById($plan);

            if (!is_null($plan)) {

                $this->planRepositor->update($plan->getKey(), $data);

                return response()->jsonSuccess();
            }
            return response()->jsonGone(__('api-key::messages.error_updating_plan'));

        } catch (\Exception $e) {
            report($e);
            return response()->jsonGone(__('api-key::messages.error_updating_plan'));
        }
    }

    public function delete(Request $request,string $plan): JsonResponse
    {

        try {

            $plan = $this->planRepositor->findById($plan);

            if (!is_null($plan)) {

                $plan = $this->planRepositor->find($plan->getKey())->delete();

                return response()->jsonSuccess();
            }

            return response()->jsonGone(__('api-key::messages.error_deleting_plan'));
        } catch (\Exception $e) {

            report($e);
            return response()->jsonGone(__('api-key::messages.error_deleting_plan'));
        }
    }

}
