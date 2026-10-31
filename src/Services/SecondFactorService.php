<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Notifications\AccountSecurityNotification;
use RiseTechApps\ApiKey\Support\Totp;

/**
 * Conferência do segundo fator (código do aplicativo ou de recuperação), com o
 * bloqueio por conta.
 *
 * Um contador só para o login e para as ações sensíveis do painel (desligar a
 * 2FA, gerar códigos, trocar senha ou e-mail, excluir a conta): o throttle das
 * rotas é por IP ou por sessão, e com muitos IPs, ou com a sessão e a senha,
 * quem tentasse por um caminho escaparia do contador do outro.
 */
class SecondFactorService
{
    /**
     * Ação sensível do painel: com a 2FA confirmada, pede `code` ou
     * `recovery_code` além da senha (que o chamador já conferiu). Senha
     * vazada é justamente o caso que a 2FA cobre. Sem 2FA, não pede nada.
     *
     * @return JsonResponse|null A recusa (422 ou 429), ou null para seguir.
     */
    public function confirm(Request $request, Authentication $user): ?JsonResponse
    {
        if (! $user->two_factor_confirmed_at) {
            return null;
        }

        $data = $request->validate([
            'code' => ['required_without:recovery_code', 'nullable', 'string'],
            'recovery_code' => ['required_without:code', 'nullable', 'string'],
        ]);

        if ($locked = $this->lockedOut($user)) {
            return $locked;
        }

        if (! $this->passes($user, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
            AuditLogger::record('account.two_factor_failed', $user);
            $this->countFailure($user);

            return response()->jsonValidation(['code' => [__('api-key::messages.two_factor_code_invalid')]], __('api-key::messages.two_factor_code_invalid'));
        }

        $this->clearFailures($user);

        return null;
    }

    /**
     * Código do aplicativo, ou um código de recuperação (que deixa de valer
     * ao ser usado). O mesmo código do aplicativo não passa duas vezes.
     */
    public function passes(Authentication $user, ?string $code, ?string $recovery): bool
    {
        if ($code) {
            $step = Totp::verify((string) $user->two_factor_secret, $code);

            return $step !== null && Cache::add("api-key:2fa-used:{$user->getKey()}:{$step}", true, 120);
        }

        $hash = hash('sha256', strtoupper(trim((string) $recovery)));

        // Relê a conta com trava: duas requisições com o mesmo código não
        // podem ler a lista antes de uma delas riscá-lo.
        $remaining = DB::transaction(function () use ($user, $hash) {
            $fresh = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first();
            $codes = $fresh->two_factor_recovery_codes ?? [];

            if (! in_array($hash, $codes, true)) {
                return null;
            }

            $remaining = array_values(array_diff($codes, [$hash]));
            $fresh->forceFill(['two_factor_recovery_codes' => $remaining])->save();

            return $remaining;
        });

        if ($remaining === null) {
            return false;
        }

        $user->forceFill(['two_factor_recovery_codes' => $remaining])->syncOriginalAttribute('two_factor_recovery_codes');

        return true;
    }

    /** 429 enquanto a conta está bloqueada por códigos errados. */
    public function lockedOut(Authentication $user): ?JsonResponse
    {
        $maxAttempts = self::maxAttempts();

        if ($maxAttempts === 0 || ! RateLimiter::tooManyAttempts(self::attemptsKey($user), $maxAttempts)) {
            return null;
        }

        $retryAfter = RateLimiter::availableIn(self::attemptsKey($user));

        return response()->jsonBase(false, __('api-key::messages.two_factor_locked', ['minutes' => (int) ceil($retryAfter / 60)]), null, 429)
            ->header('Retry-After', (string) $retryAfter);
    }

    /** `$actor` vai para a auditoria: no login ainda não há usuário logado. */
    public function countFailure(Authentication $user, ?Authentication $actor = null): void
    {
        $maxAttempts = self::maxAttempts();

        if ($maxAttempts === 0) {
            return;
        }

        RateLimiter::hit(self::attemptsKey($user), max(1, (int) config('api-key.two_factor.lockout_minutes', 15)) * 60);

        // Só quem acertou a senha chega aqui: o bloqueio é sinal de senha
        // vazada, e o dono precisa saber.
        if (RateLimiter::attempts(self::attemptsKey($user)) === $maxAttempts) {
            AuditLogger::record('account.two_factor_locked', $user, [], $actor);
            $user->notify(AccountSecurityNotification::make(AccountSecurityNotification::TWO_FACTOR_LOCKED));
        }
    }

    public function clearFailures(Authentication $user): void
    {
        RateLimiter::clear(self::attemptsKey($user));
    }

    private static function attemptsKey(Authentication $user): string
    {
        return 'api-key:2fa-attempts:'.$user->getKey();
    }

    private static function maxAttempts(): int
    {
        return max(0, (int) config('api-key.two_factor.max_attempts', 10));
    }
}
