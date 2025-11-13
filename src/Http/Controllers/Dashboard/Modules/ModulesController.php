<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Modules;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Modules\StoreModulesRequest;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Modules\UpdateModulesRequest;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Modules\ModulesResource;
use RiseTechApps\ApiKey\Models\Module;
use Throwable;

class ModulesController extends Controller
{
    public function index()
    {
        try {
            $data = ModulesResource::collection(Module::get())->jsonSerialize();

            return response()->json(['success' => true, 'data' => $data]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(StoreModulesRequest $request)
    {
        try {
            $module = Module::create($request->validated());

            $data = ModulesResource::make($module)->jsonSerialize();

            return response()->json(['success' => true, 'data' => $data], Response::HTTP_CREATED);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show(Module $module)
    {
        try {

            $data = ModulesResource::make($module)->jsonSerialize();

            return response()->json(['success' => true, 'data' => $data]);

        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(UpdateModulesRequest $request, Module $module)
    {
        try {
            $data = $request->validated();

            $module->update($data);

            return response()->json(['success' => true]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function delete(Module $module)
    {
        try {
            $module->delete();

            return response()->json(['success' => true]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
