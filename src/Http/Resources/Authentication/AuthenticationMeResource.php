<?php

namespace RiseTechApps\ApiKey\Http\Resources\Authentication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RiseTechApps\ApiKey\Gateways\GatewayRegistry;
use RiseTechApps\ApiKey\Http\Resources\ApiKeyResource;
use RiseTechApps\ApiKey\Http\Resources\UserPlanResource;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Services\PaymentSettingsService;
use RiseTechApps\ApiKey\Services\PixService;
use RiseTechApps\ApiKey\Support\AdminRoles;

/**
 * @mixin Authentication
 */
class AuthenticationMeResource extends JsonResource
{
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'code' => $this->code,
            'email' => $this->email,
            'name' => $this->name,
            'role' => $this->role ?? 'user',
            'status' => $this->status,
            'email_verified' => ! is_null($this->email_verified_at),
            'locale' => $this->locale,
            'pending_email' => $this->pending_email,
            'two_factor_enabled' => $this->two_factor_confirmed_at !== null,
            // O admin precisa ativar a 2FA antes de usar as rotas de admin.
            'two_factor_required' => AdminRoles::isStaff($this->resource)
                && (bool) config('api-key.two_factor.required_for_admins', true)
                && $this->two_factor_confirmed_at === null,
            'trial_available' => $this->trial_used_at === null,
            // Permissões no painel admin (vazio para quem não é da equipe).
            'admin_abilities' => AdminRoles::abilities($this->resource),
            'overage_enabled' => (bool) $this->overage_enabled,

            // Profile
            'profile' => [
                'photo_url' => $this->getPhotoUrl(),
                'rg' => $this->rg,
                'cpf' => $this->cpf,
                'birth_date' => $this->birth_date,
                'cellphone' => $this->cellphone,
            ],

            // Relationships
            'api_key' => $this->whenLoaded('apiKey', fn () => ApiKeyResource::make($this->apiKey)),
            'active_plan' => $this->whenLoaded('activePlan', fn () => UserPlanResource::make($this->activePlan)),

            // Usage statistics (requests_used nunca acima do limite)
            'usage' => $this->usageStats(),

            // Payment gateway
            'mp_public_key' => config('api-key.mercadopago.public_key'),
            // O painel só oferece "Ativar renovação automática" com isto ligado.
            'mp_automatic_payments' => (bool) config('api-key.mercadopago.automatic_payments.enabled', true),
            'pix_enabled' => app(PixService::class)->enabled(),
            // Gateway ativo: o painel escolhe o formulário de cartão por ele.
            'payment_gateway' => PaymentSettingsService::activeGateway(),
            'pagarme_public_key' => config('api-key.pagarme.public_key'),
            // O que o formulário do gateway ativo precisa para tokenizar o
            // cartão no navegador (chaves públicas, ambiente). Sem segredos.
            'payment_gateway_config' => GatewayRegistry::active()?->publicConfig(),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Estatísticas de uso com requests_used limitado ao teto do plano
     * (requisições bloqueadas não contam, então nunca deve passar do limite).
     */
    private function usageStats(): array
    {
        $limit = (int) $this->requestLimit();
        $used = (int) $this->countUsed();

        if ($limit > 0) {
            $used = min($used, $limit);
        }

        return [
            'requests_used' => $used,
            'requests_limit' => $limit,
            'remaining_requests' => $limit > 0 ? max(0, $limit - $used) : 0,
        ];
    }

    /**
     * Get the user's profile photo URL.
     */
    private function getPhotoUrl(): ?string
    {
        try {
            $photo = $this->getMedia('profile')->first();

            if (is_null($photo)) {
                return null;
            }

            return $photo->getFullUrl();
        } catch (\Exception) {
            return null;
        }
    }
}
