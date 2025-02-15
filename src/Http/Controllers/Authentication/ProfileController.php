<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Http\Request\Authentication\ProfileRequest;
use RiseTechApps\ApiKey\Http\Resources\Authentication\ProfileResource;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        try {

            $data = ProfileResource::make($request->user())->jsonSerialize();

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['success' => false]);
        }
    }

    public function update(ProfileRequest $request){

        try {

            $data = $request->validated();

            auth()->user()->update($data);
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['success' => false]);
        }
    }
}
