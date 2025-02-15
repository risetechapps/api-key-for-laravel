<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Modules;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Modules\UpdateModulesRequest;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Modules\ModulesResource;
use RiseTechApps\ApiKey\Models\Module;

class ModulesController extends Controller
{
    public function index(Request $request)
    {
        try {

            $data = ModulesResource::collection(Module::get())->jsonSerialize();

            return response()->json(['success' => true, 'data' => $data]);

        } catch (\Exception $e) {
            return response()->json(["success" => false]);
        }
    }

    public function show(Module $module)
    {
        try {

            $data = ModulesResource::make($module)->jsonSerialize();

            return response()->json(['success' => true, 'data' => $data]);

        } catch (\Exception $e) {
            return response()->json(["success" => false]);
        }
    }

    public function update(UpdateModulesRequest $request, Module $module)
    {
        try {

            $data = $request->validated();

            $module->update($data);

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            return response()->json(["success" => false]);
        }
    }

    public function delete(Module $module){

        try {

            $module->delete();

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            return response()->json(["success" => false]);
        }
    }
}
