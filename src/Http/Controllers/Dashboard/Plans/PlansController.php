<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Plans;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Plans\AssociatePlanRequest;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Plans\StorePlanRequest;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Plans\UpdatePlanRequest;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Plans\PlansResource;
use RiseTechApps\ApiKey\Models\Authentication;
use RiseTechApps\ApiKey\Models\Plan;

class PlansController extends Controller
{
    public function index(Request $request)
    {
        try {

            $data = PlansResource::collection(Plan::where('visible', true)->get())->jsonSerialize();

            return response()->json(['success' => true, 'data' => $data]);

        } catch (\Exception $e) {
            return response()->json(["success" => false]);
        }
    }

    public function store(StorePlanRequest $request)
    {
        try {

            $data = $request->validated();

            $plan = Plan::create($data);

            $plan->modules()->sync($data['modules']);

            return response()->json(["success" => true]);
        } catch (\Exception $e) {
            return response()->json(["success" => false, $e->getMessage()]);
        }
    }

    public function show(Plan $plan)
    {
        try {

            $plan = PlansResource::make($plan)->jsonSerialize();
            return response()->json(['success' => true, 'data' => $plan]);
        } catch (\Exception $e) {
            return response()->json(["success" => false]);
        }
    }

    public function update(UpdatePlanRequest $request, Plan $plan)
    {
        try {

            $data = $request->validated();
            $plan->update($data);
            $plan->modules()->sync($data['modules']);
            return response()->json(['success' => true, 'data' => $plan]);
        } catch (\Exception $e) {
            return response()->json(["success" => false]);
        }
    }

    public function delete(Plan $plan)
    {

        try {
            $plan->delete();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(["success" => false]);
        }
    }

    public function associate(AssociatePlanRequest $request)
    {
        try {

            $data = $request->validated();
            $plan = Plan::where('id', $data['plan_id'])->first();
            $auth = Authentication::where('id', $data['auth_id'])->first();

            if(is_null($plan)){

                return response()->json(["success" => false, "data" =>"plano não encontrado"]);
            }

            if(is_null($auth)){
                return response()->json(["success" => false, "data" =>"usuário não encontrado"]);
            }

            $auth->subscribeToPlan($plan);

            return response()->json(["success" => true]);
        } catch (\Exception $e) {
            return response()->json(["success" => false]);
        }
    }
}
