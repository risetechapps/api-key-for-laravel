<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            $user = $request->user()->load(['address', 'activePlan.plan', 'apiKey']);

            return response()->jsonSuccess(ProfileResource::make($user));
        } catch (Throwable $exception) {
            report($exception);

            return response()->jsonGone(__('api-key::messages.error_loading_profile'));
        }
    }

    public function update(ProfileRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            $user = $request->user();

            $addressData = $data['address'] ?? null;
            unset($data['address']);

            $user->update($data);

            if ($addressData) {
                if ($user->address) {
                    $user->address->update($addressData);
                } else {
                    $user->address()->create(array_merge($addressData, ['type' => 'DEFAULT']));
                }
            }

            return response()->jsonSuccess();
        } catch (Throwable $exception) {
            report($exception);

            return response()->jsonGone(__('api-key::messages.error_updating_profile'));
        }
    }

    public function getAllowedOrigins(Request $request): JsonResponse
    {
        try {
            $data = auth()->user()->apiKey->allowed_origins;
            return response()->jsonSuccess($data);
        } catch (\Exception $exception) {
            report($exception);
            return response()->jsonGone(__('api-key::messages.error_loading_allowed_origins'));
        }
    }

    public function updateAllowedOrigins(Request $request): JsonResponse
    {
        try {
            auth()->user()->apiKey->update([
                'allowed_origins' => $request->get('allowed') ?? $request->get('allowed_origins')
            ]);
            return response()->jsonSuccess();
        } catch (\Exception $exception) {
            report($exception);
            return response()->jsonGone(__('api-key::messages.error_updating_allowed_origins'));
        }
    }

    public function regenerateKey(Request $request): JsonResponse
    {
        try {
            $apiKey = auth()->user()->apiKey;

            if (!$apiKey) {
                return response()->jsonGone(__('api-key::messages.api_key_not_found'));
            }

            $newKey = bin2hex(random_bytes(64));

            $apiKey->update(['key' => $newKey]);

            return response()->jsonSuccess(['key' => $newKey]);
        } catch (\Exception $exception) {
            report($exception);
            return response()->jsonGone(__('api-key::messages.error_regenerating_api_key'));
        }
    }
}
