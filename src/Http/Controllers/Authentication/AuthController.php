<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use RiseTechApps\ApiKey\Features\AvatarGenerator;
use RiseTechApps\ApiKey\Http\Request\Authentication\LoginRequest;
use RiseTechApps\ApiKey\Http\Request\Authentication\RegisterRequest;
use RiseTechApps\ApiKey\Http\Resources\Authentication\AuthenticationMeResource;
use RiseTechApps\ApiKey\Models\Authentication;
use Throwable;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
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

                $profileImage = (new AvatarGenerator())->generateAvatar($data['name']);
                $avatarPath = 'profiles/' . $authentication->getKey() . '.png';

                if (! Storage::put($avatarPath, $profileImage)) {
                    throw new RuntimeException('Unable to persist generated avatar.');
                }
                $authentication->addMediaFromDisk($avatarPath)->toMediaCollection('profile');

                return $authentication;
            });

            $auth->sendEmailVerificationNotification();

            return response()->json(['success' => true], Response::HTTP_CREATED);
        } catch (Throwable $exception) {
            if ($avatarPath && Storage::exists($avatarPath)) {
                Storage::delete($avatarPath);
            }

            report($exception);

            return response()->json(['success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function verifyEmail(Request $request)
    {
        if (! URL::hasValidSignature($request)) {
            return response()->json(['success' => false], Response::HTTP_FORBIDDEN);
        }

        $user = Authentication::find($request->route('id'));

        if (! $user || ! hash_equals((string) $request->route('hash'), sha1($user->getEmailForVerification()))) {
            return response()->json(['success' => false], Response::HTTP_FORBIDDEN);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return response()->json(['success' => true]);
    }

    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();

        $user = Authentication::where('email', $credentials['email'])->first();

        if (! $user) {
            return response()->json(['success' => false], Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();

            return response()->json([
                'success' => false,
                'email_verify' => false,
            ], Response::HTTP_FORBIDDEN);
        }

        if (! Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            return response()->json(['success' => false], Response::HTTP_UNAUTHORIZED);
        }

        $user = Auth::user();

        $data = AuthenticationMeResource::make($user)->jsonSerialize();
        $token = $user->createToken($user->email);
        $data['token'] = $token->plainTextToken;

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function me(Request $request)
    {
        if (! $request->user()) {
            return response()->json(['success' => false], Response::HTTP_UNAUTHORIZED);
        }

        $data = AuthenticationMeResource::make($request->user())->jsonSerialize();

        return response()->json(['success' => true, 'data' => $data]);
    }
}
