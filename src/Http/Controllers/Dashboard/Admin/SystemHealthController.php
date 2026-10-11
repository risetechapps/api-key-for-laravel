<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use RiseTechApps\ApiKey\Services\SystemHealthService;

class SystemHealthController extends Controller
{
    public function __invoke(SystemHealthService $health): JsonResponse
    {
        return response()->jsonSuccess($health->report());
    }
}
