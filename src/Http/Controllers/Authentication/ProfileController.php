<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RiseTechApps\ApiKey\Http\Request\Authentication\ProfileRequest;
use RiseTechApps\ApiKey\Http\Resources\Authentication\ProfileResource;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Services\AccountService;
use RiseTechApps\ApiKey\Services\AuditLogger;
use RiseTechApps\ApiKey\Services\AuthService;
use RiseTechApps\ApiKey\Services\SecondFactorService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProfileController extends Controller
{
    public function __construct(
        private readonly AccountService $accountService,
        private readonly AuthService $authService,
        private readonly SecondFactorService $secondFactor,
    ) {}

    public function show(Request $request): JsonResponse
    {
        if (! $request->user()) {
            return response()->jsonUnauthorized();
        }

        try {
            $user = $request->user()->load(['address', 'activePlan.plan', 'apiKey']);

            return response()->jsonSuccess(ProfileResource::make($user));
        } catch (Throwable $exception) {
            report($exception);

            return response()->jsonInternal(__('api-key::messages.error_loading_profile'));
        }
    }

    public function update(ProfileRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            $user = $request->user();

            $addressData = $data['address'] ?? null;
            unset($data['address']);

            // Perfil e endereço juntos, ou nada: uma recusa do índice no meio
            // não deixa metade gravada.
            DB::transaction(function () use ($user, $data, $addressData) {
                $user->update($data);

                if ($addressData) {
                    if ($user->address) {
                        $user->address->update($addressData);
                    } else {
                        $user->address()->create(array_merge($addressData, ['type' => 'DEFAULT']));
                    }
                }
            });

            return response()->jsonSuccess();
        } catch (Throwable $exception) {
            // Mesmo CPF gravado ao mesmo tempo por outra conta, ou já gravado
            // com máscara antes da normalização: o índice é quem recusa.
            if (Authentication::isCpfTaken($exception)) {
                return response()->jsonValidation(['cpf' => [__('api-key::messages.cpf_taken')]], __('api-key::messages.cpf_taken'));
            }

            report($exception);

            return response()->jsonInternal(__('api-key::messages.error_updating_profile'));
        }
    }

    /**
     * Liga ou desliga os avisos informativos por e-mail (uso em 80%, chave
     * vencendo, excedente iniciado). Cobrança, segurança e expiração do plano
     * saem sempre. Fica na conta do dono, que é quem recebe os avisos.
     */
    public function updateNotifications(Request $request): JsonResponse
    {
        $data = $request->validate([
            'usage_notices' => ['required', 'boolean'],
        ]);

        $user = $this->owner($request);
        // Sem a coluna carregada (modelo recém-criado), vale o padrão do banco: ligado.
        $before = $user->usage_notices_enabled !== false;

        $user->forceFill(['usage_notices_enabled' => $data['usage_notices']])->save();

        if ($before !== (bool) $data['usage_notices']) {
            AuditLogger::record('account.notification_preferences_changed', $user, [
                'from' => ['usage_notices' => $before],
                'to' => ['usage_notices' => (bool) $data['usage_notices']],
            ]);
        }

        return response()->jsonSuccess(['usage_notices' => (bool) $user->usage_notices_enabled]);
    }

    /**
     * @deprecated Desde a 1.1: o painel não usa mais. Use GET /dashboard/api-keys,
     *             que mostra as origens de cada chave. Sai na próxima versão maior.
     */
    public function getAllowedOrigins(Request $request): JsonResponse
    {
        try {
            // Conta sem chave (ex.: admin da plataforma): não há origens, não é erro.
            $data = auth()->user()->apiKey->allowed_origins ?? [];

            return response()->jsonSuccess($data);
        } catch (\Exception $exception) {
            report($exception);

            return response()->jsonInternal(__('api-key::messages.error_loading_allowed_origins'));
        }
    }

    /**
     * @deprecated Desde a 1.1: use PUT /dashboard/api-keys/{id}, que valida a
     *             lista e vale para qualquer chave. Sai na próxima versão maior.
     */
    public function updateAllowedOrigins(Request $request): JsonResponse
    {
        // As mesmas regras do PUT /dashboard/api-keys/{id}: a rota é antiga,
        // mas grava no mesmo campo que a origem confere a cada requisição.
        $field = $request->has('allowed') ? 'allowed' : 'allowed_origins';
        $validated = $request->validate([
            $field => ['present', 'nullable', 'array', 'max:'.ApiKey::MAX_ALLOWED_ORIGINS],
            $field.'.*' => ['string', 'max:255'],
        ]);

        try {
            $apiKey = auth()->user()->apiKey;

            if (! $apiKey) {
                return response()->jsonNotFound(__('api-key::messages.api_key_not_found'));
            }

            $apiKey->update([
                'allowed_origins' => $validated[$field] === null ? null : array_values($validated[$field]),
            ]);

            return response()->jsonSuccess();
        } catch (\Exception $exception) {
            report($exception);

            return response()->jsonInternal(__('api-key::messages.error_updating_allowed_origins'));
        }
    }

    /**
     * @deprecated Desde a 1.1: use POST /dashboard/api-keys/{id}/regenerate.
     *             Sai na próxima versão maior.
     */
    public function regenerateKey(Request $request): JsonResponse
    {
        try {
            $apiKey = auth()->user()->apiKey;

            if (! $apiKey) {
                return response()->jsonNotFound(__('api-key::messages.api_key_not_found'));
            }

            $newKey = bin2hex(random_bytes(64));

            $apiKey->update(['key' => $newKey]);

            // Mesmo registro da tela API Keys: o horário da troca é o que separa,
            // no log, o uso de uma chave vazada do uso legítimo depois dela.
            AuditLogger::record('api_key.regenerated', auth()->user(), ['api_key_id' => $apiKey->getKey()]);

            return response()->jsonSuccess(['key' => $newKey]);
        } catch (\Exception $exception) {
            report($exception);

            return response()->jsonInternal(__('api-key::messages.error_regenerating_api_key'));
        }
    }

    /**
     * Baixa todos os dados da conta em JSON (portabilidade, LGPD art. 18, V).
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $this->owner($request);

        AuditLogger::record('account.data_exported', $user);

        $filename = 'meus-dados-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(
            fn () => $this->accountService->streamExport($user),
            $filename,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    /**
     * Exclui a própria conta. Pede a senha de novo: um token de sessão roubado
     * não deve bastar para apagar a conta de alguém. Com 2FA, pede também o
     * código.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);

        $user = $this->owner($request);

        if (! $this->authService->passwordMatches($user, (string) $request->input('password'))) {
            return response()->jsonError(__('api-key::messages.password_incorrect'));
        }

        // Um admin que se exclui pode deixar o painel sem nenhum administrador,
        // e não há como recriar um pelo próprio painel.
        if (strtolower((string) $user->role) === 'admin') {
            return response()->jsonError(__('api-key::messages.account_delete_admin'));
        }

        if ($refusal = $this->secondFactor->confirm($request, $user)) {
            return $refusal;
        }

        try {
            $this->accountService->delete($user);
        } catch (Throwable $exception) {
            report($exception);

            return response()->jsonInternal(__('api-key::messages.account_delete_failed'));
        }

        AuditLogger::record('account.deleted', $user);

        return response()->jsonSuccess(['message' => __('api-key::messages.account_deleted')]);
    }

    private function owner(Request $request): Authentication
    {
        $user = $request->user();

        abort_unless($user instanceof Authentication, 401);

        return $user;
    }
}
