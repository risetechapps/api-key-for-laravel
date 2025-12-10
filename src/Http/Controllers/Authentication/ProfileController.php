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

            return response()->jsonSuccess();
        } catch (Throwable $exception) {
            report($exception);

            return response()->jsonGone();
        }
    }

    public function getAllowedOrigins(Request $request)
    {
        try{
            $data = auth()->user()->apiKey->allowed_origins;
            return response()->jsonSuccess($data );
        }catch (\Exception $exception){
            report($exception);
            return response()->jsonGone();
        }
    }

    public function updateAllowedOrigins(Request $request)
    {
        try{
            auth()->user()->apiKey->update([
                'allowed_origins' => $request->get('allowed')
            ]);
            return response()->jsonSuccess();
        }catch (\Exception $exception){
            report($exception);
            return response()->jsonGone($exception->getMessage());
        }
    }

}
