<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RiseTechApps\ApiKey\Http\Request\Authentication\LoginRequest;
use RiseTechApps\ApiKey\Http\Request\Authentication\RegisterRequest;
use RiseTechApps\ApiKey\Http\Resources\Authentication\AuthenticationMeResource;
use RiseTechApps\ApiKey\Models\Authentication;
use RuntimeException;
use Throwable;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $avatarPath = null;

        try {
            /** @var Authentication $auth */
            $auth = DB::transaction(function () use ($data, &$avatarPath) {
                $authentication = Authentication::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                ]);

                $authentication->apiKey()->create([
                    'key' => bin2hex(random_bytes(64)),
                    'active' => false,
                ]);

                $avatarPath = $authentication->getKey() . '.png';
                $profileImage = avatarGenerator()->generateBase64($data['name']);

                if (!Storage::put($avatarPath, $profileImage)) {
                    throw new RuntimeException('Unable to persist generated avatar.');
                }
                $authentication->addMediaFromDisk($avatarPath)->toMediaCollection('profile');

                return $authentication;
            });

            $auth->sendEmailVerificationNotification();

            return response()->jsonSuccess();
        } catch (Throwable $exception) {
            return response()->jsonGone($exception->getMessage());
        }
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        if (!URL::hasValidSignature($request)) {
            return response()->json(['success' => false], Response::HTTP_FORBIDDEN);
        }

        $user = Authentication::find($request->route('id'));

        if (!$user || !hash_equals((string)$request->route('hash'), sha1($user->getEmailForVerification()))) {
            return response()->json(['success' => false], Response::HTTP_FORBIDDEN);
        }

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return response()->json(['success' => true]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = Authentication::where('email', $credentials['email'])->first();

        if (!$user) {
            return response()->jsonGone('User not found');
        }

        if (!$user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();

            return response()->jsonGone('Account not verified, please check your email inbox.');
        }

        if (!Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            return response()->jsonGone('Incorrect username or password');
        }

        $user = Auth::user();

        $data = AuthenticationMeResource::make($user)->jsonSerialize();
        $token = $user->createToken($user->email);
        $data['token'] = $token->plainTextToken;

        return response()->jsonSuccess($data);
    }

    public function me(Request $request): JsonResponse
    {
        if (!$request->user()) {
            return response()->jsonGone();
        }

        return response()->jsonSuccess(AuthenticationMeResource::make($request->user()));
    }
}
