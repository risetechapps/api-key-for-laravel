<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

            logglyInfo()->withContext([
                'user_id' => $user->getKey(),
                'email' => $user->email,
                'ip' => $request->ip(),
            ])->log('User registered');

            return response()->jsonSuccess([
                'message' => __('api-key::messages.registration_success'),
                // The plain key is shown only once; afterwards only the hash is stored.
                'api_key' => $user->apiKey?->plainKey,
            ]);
        } catch (Throwable $exception) {
            logglyError()->withContext([
                'error' => $exception->getMessage(),
                'ip' => $request->ip(),
            ])->log('Registration failed');
            report($exception);

            return response()->jsonGone(__('api-key::messages.registration_failed'));
        }
    }

    public function verifyEmail(Request $request): RedirectResponse
    {
        $id = $request->route('id');
        $hash = $request->route('hash');

        // Enough to diagnose a failed verification (which link, when it expired,
        // whether it arrived signed) without writing the signature itself to the
        // log. The full URL and the APP_KEY fingerprint that used to be here were
        // instrumentation for the reverse-proxy signature bug fixed in 2.2.2 with
        // trustProxies(); the bug is gone, the token in the log outlived it.
        //
        // The second call site below logs *after* hasValidSignature() has passed,
        // so that entry would carry a genuinely valid signature. Replaying it is
        // inert today — the signature covers {id} and {hash}, so the same request
        // hits the same hash_equals that already rejected it — but that is a
        // property of this method's control flow, not of the log, and the next
        // person to touch this method should not have to rediscover it.
        $context = [
            'id' => $id,
            'path' => $request->path(),
            'expires' => $request->query('expires'),
            'has_signature' => $request->has('signature'),
        ];

        if (! URL::hasValidSignature($request)) {
            logglyWarning()->withContext($context)->log('Email verification failed: invalid signature');

            return redirect('/login?error=invalid_link');
        }

        $user = Authentication::find($id);

        if (! $user || ! hash_equals((string) $hash, sha1((string) $user->getEmailForVerification()))) {
            logglyWarning()->withContext($context)->log('Email verification failed: user not found or hash mismatch');

            return redirect('/login?error=invalid_link');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return redirect('/login?verified=1');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $context = ['email' => $credentials['email'], 'ip' => $request->ip()];

        $user = $this->authService->findUserByEmail($credentials['email']);

        if (! $user) {
            logglyInfo()->withContext($context)->log('Login failed: user not found');

            return response()->jsonGone(__('api-key::messages.user_not_found'));
        }

        if (! $user->hasVerifiedEmail()) {
            logglyInfo()->withContext($context)->log('Login failed: email not verified');
            $user->sendEmailVerificationNotification();

            return response()->jsonGone(__('api-key::messages.account_not_verified'));
        }

        $result = $this->authService->attemptLogin($credentials);

        if (! $result) {
            logglyInfo()->withContext($context)->log('Login failed: incorrect credentials');

            return response()->jsonGone(__('api-key::messages.incorrect_credentials'));
        }

        logglyInfo()->withContext(['user_id' => $result['user']->getKey(), ...$context])->log('User logged in');

        $data = AuthenticationMeResource::make($result['user'])->jsonSerialize();
        $data['token'] = $result['token'];

        return response()->jsonSuccess($data);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);
        $email = $request->input('email');

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            logglyInfo()->withContext(['email' => $email, 'ip' => $request->ip()])->log('Password reset link sent');

            return response()->jsonSuccess(['message' => __('api-key::messages.password_reset_sent')]);
        }

        logglyWarning()->withContext(['email' => $email, 'status' => $status, 'ip' => $request->ip()])->log('Password reset link failed');

        return response()->jsonGone(__('api-key::messages.password_reset_failed'));
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
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
            logglyInfo()->withContext(['email' => $email, 'ip' => $request->ip()])->log('Password reset completed');

            return response()->jsonSuccess(['message' => __('api-key::messages.password_reset_success')]);
        }

        logglyWarning()->withContext(['email' => $email, 'status' => $status, 'ip' => $request->ip()])->log('Password reset failed');

        return response()->jsonGone(__('api-key::messages.password_reset_failed'));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user?->currentAccessToken()->delete();

        logglyInfo()->withContext(['user_id' => $user?->getKey(), 'ip' => $request->ip()])->log('User logged out');

        return response()->jsonSuccess(['message' => __('api-key::messages.logout_success')]);
    }

    public function me(Request $request): JsonResponse
    {
        if (! $request->user()) {
            return response()->jsonGone();
        }

        $user = $request->user()->load(['activePlan.plan', 'apiKey']);

        return response()->jsonSuccess(AuthenticationMeResource::make($user));
    }
}
