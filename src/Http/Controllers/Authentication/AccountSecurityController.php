<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Notifications\AccountSecurityNotification;
use RiseTechApps\ApiKey\Notifications\EmailChangeNotification;
use RiseTechApps\ApiKey\Services\AuditLogger;
use RiseTechApps\ApiKey\Services\AuthService;
use RiseTechApps\ApiKey\Services\SecondFactorService;

/**
 * Senha, e-mail e sessões da própria conta.
 */
class AccountSecurityController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly SecondFactorService $secondFactor,
    ) {}

    /**
     * Troca a senha de quem está logado.
     *
     * Pede a atual: uma sessão esquecida aberta não deve bastar para tomar a
     * conta. As outras sessões são encerradas; a atual continua, para o
     * usuário não ser jogado para fora da tela onde acabou de trocar a senha.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $this->owner($request);

        if (! $this->authService->passwordMatches($user, $data['current_password'])) {
            return response()->jsonError(__('api-key::messages.password_incorrect'));
        }

        // Com 2FA, senha vazada mais sessão roubada não trocam a senha do dono.
        if ($refusal = $this->secondFactor->confirm($request, $user)) {
            return $refusal;
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        $revoked = $this->revokeAllExceptCurrent($request, $user);

        $user->notify(AccountSecurityNotification::make(AccountSecurityNotification::PASSWORD_CHANGED));

        AuditLogger::record('account.password_changed', $user, ['sessions_revoked' => $revoked]);

        return response()->jsonSuccess([
            'message' => __('api-key::messages.password_changed'),
            'sessions_revoked' => $revoked,
        ]);
    }

    /**
     * Pede a troca do e-mail. Nada muda até o novo endereço ser confirmado.
     */
    public function requestEmailChange(Request $request): JsonResponse
    {
        $user = $this->owner($request);

        $data = $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string',
        ]);

        if (! $this->authService->passwordMatches($user, $data['password'])) {
            return response()->jsonError(__('api-key::messages.password_incorrect'));
        }

        $email = strtolower(trim($data['email']));

        if ($email === strtolower($user->email)) {
            return response()->jsonError(__('api-key::messages.email_unchanged'));
        }

        if ($this->emailTaken($email, $user)) {
            return response()->jsonError(__('api-key::messages.email_taken'));
        }

        // Por último, para não gastar o código numa recusa que viria de
        // qualquer jeito. Com 2FA, senha e sessão não levam o e-mail (e, com
        // ele, a recuperação da conta) para outro dono.
        if ($refusal = $this->secondFactor->confirm($request, $user)) {
            return $refusal;
        }

        $user->forceFill(['pending_email' => $email])->save();

        Notification::route('mail', $email)->notify(EmailChangeNotification::make($user, $email));

        AuditLogger::record('account.email_change_requested', $user, ['new_email' => $email]);

        return response()->jsonSuccess([
            'message' => __('api-key::messages.email_change_sent'),
            'pending_email' => $email,
        ]);
    }

    /**
     * Link do e-mail de confirmação. Assinado e com validade de 60 minutos; o
     * hash amarra o link ao endereço pedido, então um pedido posterior para
     * outro endereço invalida o link anterior.
     */
    public function confirmEmailChange(Request $request): RedirectResponse
    {
        if (! URL::hasValidSignature($request)) {
            logglyWarning()->withContext(['id' => $request->route('id')])->log('Email change failed: invalid signature');

            return redirect('/login?error=invalid_link');
        }

        $user = Authentication::find($request->route('id'));
        $pending = $user?->pending_email;

        if (! $user || ! $pending || ! hash_equals((string) $request->route('hash'), sha1($pending))) {
            return redirect('/login?error=invalid_link');
        }

        // Alguém pode ter se cadastrado com o endereço entre o pedido e o clique.
        if ($this->emailTaken($pending, $user)) {
            $user->forceFill(['pending_email' => null])->save();

            return redirect('/login?error=email_taken');
        }

        $oldEmail = $user->email;

        try {
            DB::transaction(function () use ($user, $pending) {
                $user->forceFill([
                    'email' => $pending,
                    'pending_email' => null,
                    'email_verified_at' => now(),
                ])->save();
            });
        } catch (QueryException $e) {
            // Cadastro com o mesmo endereço entre a checagem acima e aqui.
            if (! Authentication::isEmailTaken($e)) {
                throw $e;
            }

            $user->refresh()->forceFill(['pending_email' => null])->save();

            return redirect('/login?error=email_taken');
        }

        Notification::route('mail', $oldEmail)->notify(
            AccountSecurityNotification::make(AccountSecurityNotification::EMAIL_CHANGED, ['new_email' => $pending])
        );

        AuditLogger::record('account.email_changed', $user, ['from' => $oldEmail, 'to' => $pending]);

        return redirect('/login?email_changed=1');
    }

    /**
     * Sessões abertas (tokens do painel), da mais recente para a mais antiga.
     */
    public function sessions(Request $request): JsonResponse
    {
        $user = $this->owner($request);
        $currentId = $this->currentTokenId($request);

        // Vencida não entra mais, então não é sessão aberta.
        $sessions = $user->tokens()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (PersonalAccessToken $token) use ($currentId) {
                $device = json_decode((string) $token->getAttribute('device'), true) ?: [];

                return [
                    'id' => $token->getKey(),
                    'ip' => $device['ip'] ?? null,
                    'user_agent' => $device['user_agent'] ?? null,
                    'created_at' => $token->getAttribute('created_at')?->toIso8601String(),
                    'last_used_at' => $token->getAttribute('last_used_at')?->toIso8601String(),
                    'expires_at' => $token->getAttribute('expires_at')?->toIso8601String(),
                    'current' => $token->getKey() === $currentId,
                ];
            })
            ->values()
            ->all();

        return response()->jsonSuccess($sessions);
    }

    public function revokeSession(Request $request, string $id): JsonResponse
    {
        $user = $this->owner($request);

        $deleted = $user->tokens()->whereKey($id)->delete();

        if (! $deleted) {
            abort(404);
        }

        AuditLogger::record('account.session_revoked', $user, ['token_id' => $id]);

        return response()->jsonSuccess(['message' => __('api-key::messages.session_revoked')]);
    }

    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $user = $this->owner($request);

        $revoked = $this->revokeAllExceptCurrent($request, $user);

        AuditLogger::record('account.sessions_revoked', $user, ['count' => $revoked]);

        return response()->jsonSuccess([
            'message' => __('api-key::messages.sessions_revoked'),
            'sessions_revoked' => $revoked,
        ]);
    }

    private function revokeAllExceptCurrent(Request $request, Authentication $user): int
    {
        $currentId = $this->currentTokenId($request);

        return $user->tokens()
            ->when($currentId !== null, fn ($q) => $q->whereKeyNot($currentId))
            ->delete();
    }

    /**
     * Id do token desta requisição, ou null quando a autenticação não veio de
     * um token persistido (cookie de sessão da SPA, ou Sanctum::actingAs).
     */
    private function currentTokenId(Request $request): int|string|null
    {
        $user = $request->user();
        $token = $user instanceof Authentication ? $user->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken ? $token->getKey() : null;
    }

    private function emailTaken(string $email, Authentication $except): bool
    {
        return Authentication::withTrashed()
            ->whereRaw('LOWER(email) = ?', [strtolower($email)])
            ->whereKeyNot($except->getKey())
            ->exists();
    }

    private function owner(Request $request): Authentication
    {
        $user = $request->user();

        abort_unless($user instanceof Authentication, 401);

        return $user;
    }
}
