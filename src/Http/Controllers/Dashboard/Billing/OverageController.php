<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Billing;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MercadoPago\Exceptions\MPApiException;
use RiseTechApps\ApiKey\Exceptions\PaymentMessageException;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Plans\PlansResource;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Services\AuditLogger;
use RiseTechApps\ApiKey\Services\OverageBillingService;
use RiseTechApps\ApiKey\Services\PixService;

/**
 * Escolha do assinante sobre o excedente: ligar ou não, e até quanto gastar.
 *
 * Desligado por padrão. A troca vale na hora para as próximas requisições; o
 * excedente já feito no período continua sendo cobrado no fim dele.
 */
class OverageController extends Controller
{
    public function __construct(
        private readonly OverageBillingService $billing,
        private readonly PixService $pixService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->jsonSuccess($this->state($this->owner($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'spend_cap' => ['nullable', 'numeric', 'min:1', 'max:1000000'],
        ]);

        $user = $this->owner($request);
        $before = ['enabled' => (bool) $user->overage_enabled, 'spend_cap' => $user->overage_spend_cap];

        $user->forceFill([
            'overage_enabled' => $data['enabled'],
            'overage_spend_cap' => $data['spend_cap'] ?? null,
        ])->save();

        AuditLogger::record('billing.overage_preferences_changed', $user, [
            'from' => $before,
            'to' => ['enabled' => (bool) $user->overage_enabled, 'spend_cap' => $user->overage_spend_cap],
        ]);

        return response()->jsonSuccess($this->state($user->fresh()));
    }

    /**
     * Renovação que espera o assinante, incluindo a assinatura já em carência
     * (que o perfil não mostra mais como plano ativo). O painel usa isto para
     * exibir "Renovar agora" e abrir o checkout do mesmo plano.
     */
    public function renewal(Request $request): JsonResponse
    {
        $user = $this->owner($request);
        $userPlan = $user->activePlanWithGracePeriod()->with('plan')->first();

        if (! $userPlan || ! $userPlan->renewal_requires_payment || ! $userPlan->plan) {
            return response()->jsonSuccess(['required' => false]);
        }

        $graceDays = (int) config('api-key.grace_period_days', 3);

        return response()->jsonSuccess([
            'required' => true,
            'reason' => $userPlan->renewal_requires_payment,
            'end_date' => $userPlan->end_date?->toIso8601String(),
            'access_until' => $userPlan->end_date?->copy()->addDays($graceDays)->toIso8601String(),
            'plan' => PlansResource::make($userPlan->plan)->resolve(),
        ]);
    }

    /**
     * Gera, no painel, o PIX de um excedente em aberto. O QR não vai por
     * e-mail: o assinante vem ao painel pagar.
     */
    public function payWithPix(Request $request, string $id): JsonResponse
    {
        $user = $this->owner($request);

        if (! $this->pixService->enabled()) {
            return response()->jsonError(__('api-key::messages.pix_disabled'));
        }

        $userPlan = OverageBillingService::unpaid($user->getKey())->firstWhere('id', $id);

        if (! $userPlan instanceof UserPlan) {
            abort(404);
        }

        try {
            return response()->jsonSuccess($this->billing->payWithPix($userPlan));
        } catch (MPApiException $e) {
            logglyError()->withContext(['body' => $e->getApiResponse()->getContent()])->log('MP PIX creation failed (overage)');

            return response()->jsonError(__('api-key::messages.pix_failed'));
        } catch (PaymentMessageException $e) {
            return response()->jsonError($e->getMessage());
        } catch (\RuntimeException $e) {
            logglyError()->withContext(['error' => $e->getMessage()])->log('Overage PIX failed');

            return response()->jsonError(__('api-key::messages.pix_failed'));
        }
    }

    /**
     * Paga um excedente em aberto com um cartão salvo. Cartão do Mercado Pago
     * que não cobra sozinho pede o CVV, que chega como token gerado no
     * navegador (`token`), nunca o código.
     */
    public function payWithCard(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'card_id' => ['required', 'integer'],
            'token' => ['nullable', 'string', 'max:64'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $this->owner($request);
        $userPlan = OverageBillingService::unpaid($user->getKey())->firstWhere('id', $id);

        if (! $userPlan instanceof UserPlan) {
            abort(404);
        }

        $card = UserCard::where('authentication_id', $user->getKey())->chargeable()->find($data['card_id']);

        if (! $card) {
            return response()->jsonError(__('api-key::messages.card_not_found'));
        }

        $state = $this->billing->payWithCard($userPlan, $card, $data['token'] ?? null, $data['idempotency_key'] ?? null);

        return match ($state) {
            'paid' => response()->jsonSuccess(['status' => 'approved', 'message' => __('api-key::messages.overage_paid')]),
            'pending' => response()->jsonSuccess(['status' => 'pending', 'message' => __('api-key::messages.payment_pending')]),
            'cvv_required' => response()->json(['success' => false, 'status' => 'cvv_required', 'message' => __('api-key::messages.overage_cvv_required')], 422),
            'error' => response()->json(['success' => false, 'message' => __('api-key::messages.error_processing_payment')], 502),
            default => response()->json(['success' => false, 'status' => 'rejected', 'message' => __('api-key::messages.payment_declined')], 422),
        };
    }

    /** @return array<string, mixed> */
    private function state(Authentication $user): array
    {
        $userPlan = $user->activePlan()->with('plan')->first();
        $plan = $userPlan?->plan;
        $userPlan?->setRelation('authentication', $user);

        return [
            'enabled' => (bool) $user->overage_enabled,
            'spend_cap' => $user->overage_spend_cap !== null ? (float) $user->overage_spend_cap : null,
            // O plano atual oferece excedente? Sem isso a opção não tem efeito.
            'available' => $plan !== null && $plan->overage_price !== null && (int) $plan->request_limit > 0,
            'price_per_request' => $plan?->overage_price !== null ? (float) $plan->overage_price : null,
            'plan_max_requests' => $plan?->overage_max_requests,
            'extra_allowed' => $userPlan ? OverageBillingService::extraAllowed($userPlan) : 0,
            'current' => $userPlan ? [
                'requests' => OverageBillingService::overageRequests($userPlan),
                'amount' => OverageBillingService::overageAmount($userPlan),
            ] : null,
            // Períodos encerrados com excedente a pagar pelo painel.
            'unpaid' => OverageBillingService::unpaid($user->getKey())->map(fn ($p) => [
                'id' => $p->getKey(),
                'plan' => $p->plan?->name,
                'requests' => (int) $p->overage_requests,
                'amount' => (float) $p->overage_amount,
                'period_end' => $p->end_date?->toIso8601String(),
            ])->values()->all(),
            'pix_enabled' => $this->pixService->enabled(),
        ];
    }

    private function owner(Request $request): Authentication
    {
        $user = $request->user();

        abort_unless($user instanceof Authentication, 401);

        return $user;
    }
}
