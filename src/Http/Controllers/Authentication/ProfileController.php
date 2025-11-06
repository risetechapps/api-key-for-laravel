<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RiseTechApps\ApiKey\Http\Request\Authentication\ProfileRequest;
use RiseTechApps\ApiKey\Http\Resources\Authentication\ProfileResource;
use Throwable;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        if (! $request->user()) {
            return response()->json(['success' => false], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $data = ProfileResource::make($request->user())->jsonSerialize();

            return response()->json(['success' => true, 'data' => $data]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(ProfileRequest $request)
    {
        try {
            $data = $request->validated();

            $request->user()->update($data);

            return response()->json(['success' => true]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
