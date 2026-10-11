<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\ApiKeys;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RiseTechApps\ApiKey\Facades\FeatureRegistry;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Services\AuditLogger;

/**
 * As várias chaves da conta.
 *
 * O texto puro da chave só existe na resposta de criação e de regeneração;
 * depois disso fica apenas o hash. Revogar apaga a chave: `active` é do plano
 * (vencer desativa todas, renovar reativa todas), e misturar as duas coisas
 * faria uma renovação ressuscitar uma chave revogada.
 */
class ApiKeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $keys = $this->owner($request)->apiKeys()->get()->map(fn (ApiKey $key) => $this->present($key));

        return response()->jsonSuccess($keys->values()->all());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->owner($request);
        $data = $this->validated($request, creating: true);

        $max = (int) config('api-key.api_keys.max_per_user', 5);

        if ($user->apiKeys()->count() >= $max) {
            return response()->jsonError(__('api-key::messages.api_key_limit_reached', ['max' => $max]));
        }

        $plain = bin2hex(random_bytes(64));

        $key = $user->apiKeys()->create([
            ...$data,
            'key' => $plain,
            // Chave nova acompanha o plano: ativa se há assinatura em curso.
            'active' => $user->hasActivePlan(),
        ]);

        AuditLogger::record('api_key.created', $user, ['api_key_id' => $key->getKey(), 'name' => $key->name]);

        return response()->jsonSuccess([...$this->present($key), 'key' => $plain]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->owner($request);
        $key = $user->apiKeys()->findOrFail($id);

        $key->update($this->validated($request, creating: false));

        AuditLogger::record('api_key.updated', $user, ['api_key_id' => $key->getKey()]);

        return response()->jsonSuccess($this->present($key->fresh()));
    }

    public function regenerate(Request $request, string $id): JsonResponse
    {
        $user = $this->owner($request);
        $key = $user->apiKeys()->findOrFail($id);

        $plain = bin2hex(random_bytes(64));
        $key->update(['key' => $plain]);

        AuditLogger::record('api_key.regenerated', $user, ['api_key_id' => $key->getKey()]);

        return response()->jsonSuccess([...$this->present($key->fresh()), 'key' => $plain]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->owner($request);
        $key = $user->apiKeys()->findOrFail($id);

        // A última chave não sai: a conta ficaria sem como chamar a API, e o
        // perfil, o registro e a assinatura contam com uma chave principal.
        if ($user->apiKeys()->count() <= 1) {
            return response()->jsonError(__('api-key::messages.api_key_last'));
        }

        $key->delete();
        ApiKey::clearValidationCache($key->getKey());
        ApiKey::clearOriginCache($key->getKey());

        AuditLogger::record('api_key.revoked', $user, ['api_key_id' => $id, 'name' => $key->name]);

        return response()->jsonSuccess(['message' => __('api-key::messages.api_key_revoked')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $features = FeatureRegistry::keys();

        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            // null = todas as features do plano.
            'scopes' => ['sometimes', 'nullable', 'array'],
            'scopes.*' => ['string', Rule::in($features)],
            'allowed_origins' => ['sometimes', 'nullable', 'array'],
            'allowed_origins.*' => ['string', 'max:255'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'rate_limit_per_minute' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ApiKey $key): array
    {
        return [
            'id' => $key->getKey(),
            'name' => $key->name,
            'active' => (bool) $key->active,
            'scopes' => $key->scopes,
            'allowed_origins' => $key->allowed_origins,
            'rate_limit_per_minute' => $key->rate_limit_per_minute,
            'expires_at' => $key->expires_at?->toIso8601String(),
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'created_at' => $key->created_at?->toIso8601String(),
        ];
    }

    private function owner(Request $request): Authentication
    {
        $user = $request->user();

        abort_unless($user instanceof Authentication, 401);

        return $user;
    }
}
