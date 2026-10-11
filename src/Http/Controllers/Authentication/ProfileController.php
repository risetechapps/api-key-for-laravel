<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Http\Request\Authentication\ProfileRequest;
use RiseTechApps\ApiKey\Http\Resources\Authentication\ProfileResource;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Services\AccountService;
use RiseTechApps\ApiKey\Services\AuditLogger;
use RiseTechApps\ApiKey\Services\AuthService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProfileController extends Controller
{
    public function __construct(
        private readonly AccountService $accountService,
        private readonly AuthService $authService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        if (! $request->user()) {
            return response()->jsonGone();
        }

        try {
            $user = $request->user()->load(['address', 'activePlan.plan', 'apiKey']);

            return response()->jsonSuccess(ProfileResource::make($user));
        } catch (Throwable $exception) {
            report($exception);

            return response()->jsonGone(__('api-key::messages.error_loading_profile'));
        }
    }

    public function update(ProfileRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            $user = $request->user();

            $addressData = $data['address'] ?? null;
            unset($data['address']);

            $user->update($data);

            if ($addressData) {
                if ($user->address) {
                    $user->address->update($addressData);
                } else {
                    $user->address()->create(array_merge($addressData, ['type' => 'DEFAULT']));
                }
            }

            return response()->jsonSuccess();
        } catch (Throwable $exception) {
            report($exception);

            return response()->jsonGone(__('api-key::messages.error_updating_profile'));
        }
    }

    public function getAllowedOrigins(Request $request): JsonResponse
    {
        try {
            $data = auth()->user()->apiKey->allowed_origins;

            return response()->jsonSuccess($data);
        } catch (\Exception $exception) {
            report($exception);

            return response()->jsonGone(__('api-key::messages.error_loading_allowed_origins'));
        }
    }

    public function updateAllowedOrigins(Request $request): JsonResponse
    {
        try {
            auth()->user()->apiKey->update([
                'allowed_origins' => $request->get('allowed') ?? $request->get('allowed_origins'),
            ]);

            return response()->jsonSuccess();
        } catch (\Exception $exception) {
            report($exception);

            return response()->jsonGone(__('api-key::messages.error_updating_allowed_origins'));
        }
    }

    public function regenerateKey(Request $request): JsonResponse
    {
        try {
            $apiKey = auth()->user()->apiKey;

            if (! $apiKey) {
                return response()->jsonGone(__('api-key::messages.api_key_not_found'));
            }

            $newKey = bin2hex(random_bytes(64));

            $apiKey->update(['key' => $newKey]);

            return response()->jsonSuccess(['key' => $newKey]);
        } catch (\Exception $exception) {
            report($exception);

            return response()->jsonGone(__('api-key::messages.error_regenerating_api_key'));
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
     * não deve bastar para apagar a conta de alguém.
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

        try {
            $this->accountService->delete($user);
        } catch (Throwable $exception) {
            report($exception);

            return response()->jsonGone(__('api-key::messages.account_delete_failed'));
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
