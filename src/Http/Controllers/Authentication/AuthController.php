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
use RiseTechApps\ApiKey\Support\Team;
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

            // A conta já foi criada (a transação do register() fechou). Uma
            // falha no envio do e-mail não pode virar "erro no cadastro": a
            // pessoa ficaria sem a chave mostrada aqui e com o e-mail "já
            // registrado". Ela pede o reenvio pela tela de login (email/resend).
            try {
                $user->sendEmailVerificationNotification();
            } catch (Throwable $exception) {
                logglyError()->withContext([
                    'user_id' => $user->getKey(),
                    'error' => $exception->getMessage(),
                ])->log('Registration: verification e-mail failed');
                report($exception);
            }

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

        // E-mail inexistente e senha errada têm a mesma resposta. Antes o login
        // dizia "usuário não encontrado", e quem quisesse saber se alguém tem
        // conta aqui só precisava tentar o e-mail. O hash é conferido mesmo sem
        // usuário para o tempo de resposta não denunciar a diferença.
        // Membro de integração (external_id) não entra pelo painel: quem fala
        // por ele é o sistema do cliente, pela API. Mesma resposta do erro.
        if ($user?->isExternal() || ! $this->authService->passwordMatches($user, $credentials['password'])) {
            logglyInfo()->withContext($context)->log($user
                ? 'Login failed: incorrect credentials'
                : 'Login failed: user not found');

            return response()->jsonGone(__('api-key::messages.incorrect_credentials'));
        }

        // Daqui em diante a senha está certa: contar que a conta está suspensa
        // ou sem verificação não revela nada a quem não é o dono.
        if (! $user->isEnabled()) {
            logglyWarning()->withContext(['user_id' => $user->getKey(), 'status' => $user->status, ...$context])
                ->log('Login failed: account disabled');

            return response()->json([
                'success' => false,
                'message' => __('api-key::messages.account_disabled'),
            ], 403);
        }

        if (! $user->hasVerifiedEmail()) {
            logglyInfo()->withContext($context)->log('Login failed: email not verified');
            $user->sendEmailVerificationNotification();

            return response()->jsonGone(__('api-key::messages.account_not_verified'));
        }

        // Com 2FA a senha não basta: devolve um desafio, trocado por token em
        // /login/two-factor junto com o código do aplicativo.
        if ($user->two_factor_confirmed_at) {
            logglyInfo()->withContext($context)->log('Login: two-factor challenge issued');

            return response()->jsonSuccess([
                'two_factor' => true,
                'challenge' => TwoFactorController::challengeFor($user),
            ]);
        }

        $result = $this->authService->issueToken($user, $request);

        logglyInfo()->withContext(['user_id' => $user->getKey(), ...$context])->log('User logged in');

        $data = AuthenticationMeResource::make($result['user'])->jsonSerialize();
        $data['token'] = $result['token'];

        return response()->jsonSuccess($data);
    }

    /**
     * Envia o link de redefinição de senha.
     *
     * A resposta é sempre a mesma, exista ou não a conta, e mesmo quando o
     * broker recusa por excesso de pedidos: responder diferente transformava
     * esta rota num verificador de quais e-mails têm cadastro.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);
        $email = $request->input('email');

        $status = Password::sendResetLink($request->only('email'));

        $context = ['email' => $email, 'status' => $status, 'ip' => $request->ip()];

        if ($status === Password::RESET_LINK_SENT) {
            logglyInfo()->withContext($context)->log('Password reset link sent');
        } else {
            logglyWarning()->withContext($context)->log('Password reset link not sent');
        }

        return response()->jsonSuccess(['message' => __('api-key::messages.password_reset_sent')]);
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

        return response()->jsonGone(__('api-key::messages.password_reset_invalid'));
    }

    /**
     * Reenvia o e-mail de verificação para quem perdeu o primeiro.
     *
     * Antes o único jeito de receber outro era tentar o login, que reenviava
     * — e reenviava antes de conferir a senha. Como no esqueci-a-senha, a
     * resposta não diz se o e-mail tem conta nem se ela já foi verificada.
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = $this->authService->findUserByEmail((string) $request->input('email'));
        $context = ['email' => $request->input('email'), 'ip' => $request->ip()];

        if ($user && $user->isEnabled() && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
            logglyInfo()->withContext(['user_id' => $user->getKey(), ...$context])->log('Verification e-mail resent');
        } else {
            logglyInfo()->withContext($context)->log('Verification e-mail not resent: no pending account');
        }

        return response()->jsonSuccess(['message' => __('api-key::messages.verification_resent')]);
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

        // Membro da equipe: o plano e a chave mostrados são os da conta.
        Team::account()?->loadMissing(['activePlan.plan', 'apiKey']);

        return response()->jsonSuccess(AuthenticationMeResource::make($user));
    }
}
