<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Modules;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Modules\StoreModulesRequest;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Modules\UpdateModulesRequest;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Modules\ModulesResource;
use RiseTechApps\ApiKey\Models\Module;
use RiseTechApps\ApiKey\Repositories\Module\ModuleRepository;
use Throwable;

class ModulesController extends Controller
{

    public function __construct(protected readonly ModuleRepository $moduleRepository)
    {
    }

    public function index(): JsonResponse
    {
        try {
            $data = $this->moduleRepository->get();

            return response()->jsonSuccess(ModulesResource::collection($data));
        } catch (Throwable $exception) {

            return response()->jsonGone();
        }
    }
}
