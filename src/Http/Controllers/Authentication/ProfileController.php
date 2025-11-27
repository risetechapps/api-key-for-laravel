<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RiseTechApps\ApiKey\Http\Request\Authentication\ProfileRequest;
use RiseTechApps\ApiKey\Http\Resources\Authentication\ProfileResource;
use Throwable;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        if (! $request->user()) {
            return response()->jsonGone();
        }

        try {

            return response()->jsonSuccess(ProfileResource::make($request->user()));
        } catch (Throwable $exception) {
            report($exception);

            return response()->jsonGone();
        }
    }

    public function update(ProfileRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            $request->user()->update($data);

            return response()->json(['success' => true]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->jsonGone();
        }
    }
}
