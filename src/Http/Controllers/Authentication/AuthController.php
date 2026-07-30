<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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

            Log::info('User registered', [
                'user_id' => $user->getKey(),
                'email' => $user->email,
                'ip' => $request->ip(),
            ]);

            return response()->jsonSuccess([
                'message' => __('api-key::messages.registration_success'),
                // The plain key is shown only once; afterwards only the hash is stored.
                'api_key' => $user->apiKey?->plainKey,
            ]);
        } catch (Throwable $exception) {
            Log::error('Registration failed', [
                'error' => $exception->getMessage(),
                'ip' => $request->ip(),
            ]);
            report($exception);
            return response()->jsonGone(__('api-key::messages.registration_failed'));
        }
    }

    public function verifyEmail(Request $request): \Illuminate\Http\RedirectResponse
    {
        $id = $request->route('id');
        $hash = $request->route('hash');

        $context = [
            'id' => $id,
            'url' => $request->fullUrl(),
            'app_url' => config('app.url'),
            'app_key_hash' => substr(hash('sha256', (string) config('app.key')), 0, 8),
            'expires' => $request->query('expires'),
            'has_signature' => $request->has('signature'),
        ];

        if (!URL::hasValidSignature($request)) {
            Log::warning('Email verification failed: invalid signature', $context);
            return redirect('/login?error=invalid_link');
        }

        $user = Authentication::find($id);

        if (!$user || !hash_equals((string) $hash, sha1((string) $user->getEmailForVerification()))) {
            Log::warning('Email verification failed: user not found or hash mismatch', $context);
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
        $context = ['email' => $credentials['email'], 'ip' => $request->ip()];

        $user = $this->authService->findUserByEmail($credentials['email']);

        if (!$user) {
            Log::info('Login failed: user not found', $context);
            return response()->jsonGone(__('api-key::messages.user_not_found'));
        }

        if (!$user->hasVerifiedEmail()) {
            Log::info('Login failed: email not verified', $context);
            $user->sendEmailVerificationNotification();
            return response()->jsonGone(__('api-key::messages.account_not_verified'));
        }

        $result = $this->authService->attemptLogin($credentials);

        if (!$result) {
            Log::info('Login failed: incorrect credentials', $context);
            return response()->jsonGone(__('api-key::messages.incorrect_credentials'));
        }

        Log::info('User logged in', ['user_id' => $result['user']->getKey(), ...$context]);

        $data = AuthenticationMeResource::make($result['user'])->jsonSerialize();
        $data['token'] = $result['token'];

        return response()->jsonSuccess($data);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            Log::info('Password reset link sent', ['email' => $email, 'ip' => $request->ip()]);
            return response()->jsonSuccess(['message' => __('api-key::messages.password_reset_sent')]);
        }

        Log::warning('Password reset link failed', ['email' => $email, 'status' => $status, 'ip' => $request->ip()]);
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

        $email = $request->input('email');

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Authentication $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            Log::info('Password reset completed', ['email' => $email, 'ip' => $request->ip()]);
            return response()->jsonSuccess(['message' => __('api-key::messages.password_reset_success')]);
        }

        Log::warning('Password reset failed', ['email' => $email, 'status' => $status, 'ip' => $request->ip()]);
        return response()->jsonGone(__('api-key::messages.password_reset_failed'));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user?->currentAccessToken()->delete();

        Log::info('User logged out', ['user_id' => $user?->getKey(), 'ip' => $request->ip()]);

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
