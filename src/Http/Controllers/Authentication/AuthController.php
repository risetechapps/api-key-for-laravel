<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use RiseTechApps\ApiKey\Http\Request\Authentication\LoginRequest;
use RiseTechApps\ApiKey\Http\Request\Authentication\RegisterRequest;
use RiseTechApps\ApiKey\Http\Resources\Authentication\AuthenticationMeResource;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Services\AuthService;
use RiseTechApps\ApiKey\Services\UserRegistrationService;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        private readonly UserRegistrationService $registrationService,
        private readonly AuthService $authService
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $user = $this->registrationService->register($request->validated());

            $user->sendEmailVerificationNotification();

            return response()->jsonSuccess([
                'message' => __('api-key::messages.registration_success'),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            return response()->jsonGone(__('api-key::messages.registration_failed'));
        }
    }

    public function verifyEmail(Request $request): \Illuminate\Http\RedirectResponse
    {
        if (!URL::hasValidSignature($request)) {
            return redirect('/login?error=invalid_link');
        }

        $user = Authentication::find($request->route('id'));

        if (!$user || !hash_equals((string)$request->route('hash'), sha1($user->getEmailForVerification()))) {
            return redirect('/login?error=invalid_link');
        }

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return redirect('/login?verified=1');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        // Find user and check verification
        $user = $this->authService->findUserByEmail($credentials['email']);

        if (!$user) {
            return response()->jsonGone(__('api-key::messages.user_not_found'));
        }

        if (!$user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
            return response()->jsonGone(__('api-key::messages.account_not_verified'));
        }

        // Attempt login
        $result = $this->authService->attemptLogin($credentials);

        if (!$result) {
            return response()->jsonGone(__('api-key::messages.incorrect_credentials'));
        }

        $data = AuthenticationMeResource::make($result['user'])->jsonSerialize();
        $data['token'] = $result['token'];

        return response()->jsonSuccess($data);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return response()->jsonSuccess(['message' => __('api-key::messages.password_reset_sent')]);
        }

        return response()->jsonGone(__('api-key::messages.password_reset_failed'));
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'                 => 'required',
            'email'                 => 'required|email',
            'password'              => 'required|min:8|confirmed',
            'password_confirmation' => 'required',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Authentication $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->jsonSuccess(['message' => __('api-key::messages.password_reset_success')]);
        }

        return response()->jsonGone(__('api-key::messages.password_reset_failed'));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->jsonSuccess(['message' => __('api-key::messages.logout_success')]);
    }

    public function me(Request $request): JsonResponse
    {
        if (!$request->user()) {
            return response()->jsonGone();
        }

        $user = $request->user()->load(['activePlan.plan', 'apiKey']);

        return response()->jsonSuccess(AuthenticationMeResource::make($user));
    }
}
