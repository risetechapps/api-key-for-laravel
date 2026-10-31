<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RiseTechApps\ApiKey\Http\Resources\Authentication\AuthenticationMeResource;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Notifications\AccountSecurityNotification;
use RiseTechApps\ApiKey\Services\AuditLogger;
use RiseTechApps\ApiKey\Services\AuthService;
use RiseTechApps\ApiKey\Services\SecondFactorService;
use RiseTechApps\ApiKey\Support\Totp;

/**
 * Verificação em duas etapas: ativar, confirmar, desativar, códigos de
 * recuperação e o segundo passo do login.
 */
class TwoFactorController extends Controller
{
    /** Validade do desafio entre a senha e o código. */
    private const CHALLENGE_MINUTES = 5;

    public function __construct(
        private readonly AuthService $authService,
        private readonly SecondFactorService $secondFactor,
    ) {}

    /**
     * Desafio emitido pelo login quando a conta tem 2FA. Cifrado e com prazo:
     * não serve para nada além de trocar por um token junto com o código.
     */
    public static function challengeFor(Authentication $user): string
    {
        return Crypt::encryptString(json_encode([
            'id' => $user->getKey(),
            'exp' => now()->addMinutes(self::CHALLENGE_MINUTES)->getTimestamp(),
        ]));
    }

    /** Segundo passo do login. */
    public function verifyLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => 'required|string',
            'code' => 'required_without:recovery_code|nullable|string',
            'recovery_code' => 'required_without:code|nullable|string',
        ]);

        $user = $this->userFromChallenge($data['challenge']);

        // 410 de propósito: o desafio venceu, e o login.vue volta para a senha.
        if (! $user || ! $user->two_factor_confirmed_at || ! $user->isEnabled()) {
            return response()->jsonGone(__('api-key::messages.two_factor_challenge_invalid'));
        }

        if ($locked = $this->secondFactor->lockedOut($user)) {
            return $locked;
        }

        if (! $this->secondFactor->passes($user, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
            AuditLogger::record('account.two_factor_failed', $user, [], $user);
            $this->secondFactor->countFailure($user, $user);

            return response()->jsonError(__('api-key::messages.two_factor_code_invalid'));
        }

        $this->secondFactor->clearFailures($user);

        $result = $this->authService->issueToken($user, $request);

        AuditLogger::record('account.login_two_factor', $user, [], $user);

        $payload = AuthenticationMeResource::make($result['user'])->jsonSerialize();
        $payload['token'] = $result['token'];

        return response()->jsonSuccess($payload);
    }

    /** Gera o segredo. A 2FA só vale depois de confirmada com um código. */
    public function enable(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);
        $user = $this->owner($request);

        if (! $this->authService->passwordMatches($user, (string) $request->input('password'))) {
            return response()->jsonError(__('api-key::messages.password_incorrect'));
        }

        if ($user->two_factor_confirmed_at) {
            return response()->jsonError(__('api-key::messages.two_factor_already_enabled'));
        }

        $secret = Totp::generateSecret();

        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => null])->save();

        return response()->jsonSuccess([
            'secret' => $secret,
            'otpauth_uri' => Totp::uri($secret, $user->email, (string) config('app.name', 'API')),
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string']);
        $user = $this->owner($request);

        if (! $user->two_factor_secret || $user->two_factor_confirmed_at) {
            return response()->jsonError(__('api-key::messages.two_factor_not_pending'));
        }

        if (Totp::verify($user->two_factor_secret, (string) $request->input('code')) === null) {
            return response()->jsonError(__('api-key::messages.two_factor_code_invalid'));
        }

        $codes = $this->newRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes),
        ])->save();

        $user->notify(AccountSecurityNotification::make(AccountSecurityNotification::TWO_FACTOR_ENABLED));
        AuditLogger::record('account.two_factor_enabled', $user);

        return response()->jsonSuccess(['recovery_codes' => $codes]);
    }

    /**
     * Pede a senha e um segundo fator: com só a senha, quem a descobriu
     * desligaria a própria proteção feita contra senha vazada. Configuração
     * ainda não confirmada sai só com a senha, que ali não há segundo fator.
     */
    public function disable(Request $request): JsonResponse
    {
        $user = $this->owner($request);

        if ($refusal = $this->checkPasswordAndFactor($request, $user)) {
            return $refusal;
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $user->notify(AccountSecurityNotification::make(AccountSecurityNotification::TWO_FACTOR_DISABLED));
        AuditLogger::record('account.two_factor_disabled', $user);

        return response()->jsonSuccess(['message' => __('api-key::messages.two_factor_disabled')]);
    }

    /**
     * Pede a senha e um segundo fator, como o disable(): códigos novos entram
     * no login sem o aplicativo, então só a senha não pode bastar.
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $this->owner($request);

        if (! $user->two_factor_confirmed_at) {
            return response()->jsonError(__('api-key::messages.two_factor_not_enabled'));
        }

        if ($refusal = $this->checkPasswordAndFactor($request, $user)) {
            return $refusal;
        }

        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save();

        AuditLogger::record('account.two_factor_recovery_regenerated', $user);

        return response()->jsonSuccess(['recovery_codes' => $codes]);
    }

    /** Senha e, com a 2FA confirmada, um segundo fator (ver SecondFactorService). */
    private function checkPasswordAndFactor(Request $request, Authentication $user): ?JsonResponse
    {
        $data = $request->validate(['password' => 'required|string']);

        if (! $this->authService->passwordMatches($user, (string) $data['password'])) {
            return response()->jsonValidation(['password' => [__('api-key::messages.password_incorrect')]], __('api-key::messages.password_incorrect'));
        }

        return $this->secondFactor->confirm($request, $user);
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        return array_map(
            fn () => strtoupper(Str::random(5).'-'.Str::random(5)),
            range(1, 8),
        );
    }

    private function userFromChallenge(string $challenge): ?Authentication
    {
        try {
            $data = json_decode(Crypt::decryptString($challenge), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($data) || ($data['exp'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return Authentication::find($data['id'] ?? null);
    }

    private function owner(Request $request): Authentication
    {
        $user = $request->user();

        abort_unless($user instanceof Authentication, 401);

        return $user;
    }
}
