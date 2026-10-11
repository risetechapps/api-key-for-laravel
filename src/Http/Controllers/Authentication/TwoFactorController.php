<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RiseTechApps\ApiKey\Http\Resources\Authentication\AuthenticationMeResource;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Notifications\AccountSecurityNotification;
use RiseTechApps\ApiKey\Services\AuditLogger;
use RiseTechApps\ApiKey\Services\AuthService;
use RiseTechApps\ApiKey\Support\Totp;

/**
 * Verificação em duas etapas: ativar, confirmar, desativar, códigos de
 * recuperação e o segundo passo do login.
 */
class TwoFactorController extends Controller
{
    /** Validade do desafio entre a senha e o código. */
    private const CHALLENGE_MINUTES = 5;

    public function __construct(private readonly AuthService $authService) {}

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

        if (! $user || ! $user->two_factor_confirmed_at || ! $user->isEnabled()) {
            return response()->jsonGone(__('api-key::messages.two_factor_challenge_invalid'));
        }

        if (! $this->passesSecondFactor($user, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
            AuditLogger::record('account.two_factor_failed', $user, [], $user);

            return response()->jsonError(__('api-key::messages.two_factor_code_invalid'));
        }

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

    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);
        $user = $this->owner($request);

        if (! $this->authService->passwordMatches($user, (string) $request->input('password'))) {
            return response()->jsonError(__('api-key::messages.password_incorrect'));
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

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);
        $user = $this->owner($request);

        if (! $this->authService->passwordMatches($user, (string) $request->input('password'))) {
            return response()->jsonError(__('api-key::messages.password_incorrect'));
        }

        if (! $user->two_factor_confirmed_at) {
            return response()->jsonError(__('api-key::messages.two_factor_not_enabled'));
        }

        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save();

        AuditLogger::record('account.two_factor_recovery_regenerated', $user);

        return response()->jsonSuccess(['recovery_codes' => $codes]);
    }

    /**
     * Código do aplicativo, ou um código de recuperação (que deixa de valer
     * ao ser usado). O mesmo código do aplicativo não passa duas vezes.
     */
    private function passesSecondFactor(Authentication $user, ?string $code, ?string $recovery): bool
    {
        if ($code) {
            $step = Totp::verify((string) $user->two_factor_secret, $code);

            return $step !== null && Cache::add("api-key:2fa-used:{$user->getKey()}:{$step}", true, 120);
        }

        $hash = hash('sha256', strtoupper(trim((string) $recovery)));
        $codes = $user->two_factor_recovery_codes ?? [];

        if (! in_array($hash, $codes, true)) {
            return false;
        }

        $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$hash]))])->save();

        return true;
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
