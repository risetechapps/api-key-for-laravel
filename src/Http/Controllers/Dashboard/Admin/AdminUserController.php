<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use RiseTechApps\ApiKey\Jobs\ProcessPlanRenewalJob;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\AuditLog\AuditLog;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Notifications\AccountSecurityNotification;
use RiseTechApps\ApiKey\Services\AuditLogger;
use RiseTechApps\ApiKey\Services\OverageBillingService;
use RiseTechApps\ApiKey\Support\AdminRoles;

/**
 * Ficha do usuário no painel admin e as ações da equipe sobre ele.
 *
 * Cada ação passa pela auditoria com o antes e o depois. A permissão de cada
 * rota (ver AdminRoles) é aplicada no middleware, não aqui.
 */
class AdminUserController extends Controller
{
    public function show(string $id): JsonResponse
    {
        $user = Authentication::findOrFail($id);
        $current = $user->activePlanWithGracePeriod()->with('plan')->first();

        return response()->jsonSuccess([
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'pending_email' => $user->pending_email,
                'role' => $user->role,
                'status' => $user->status,
                // Só o fim: basta para conferir com o cliente no atendimento.
                'cpf' => $user->cpf ? '***.***.***-'.substr((string) preg_replace('/\D/', '', (string) $user->cpf), -2) : null,
                'cellphone' => $user->cellphone,
                'email_verified' => $user->email_verified_at !== null,
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'overage_enabled' => (bool) $user->overage_enabled,
                'trial_used_at' => $user->trial_used_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'current' => $current ? $this->subscription($current) : null,
            'subscriptions' => $user->userPlan()->with('plan')->latest('start_date')->limit(30)->get()
                ->map(fn (UserPlan $p) => $this->subscription($p))->values(),
            'pending_payments' => PendingPayment::with('plan')->where('authentication_id', $user->getKey())
                ->latest()->limit(20)->get()
                ->map(fn (PendingPayment $p) => [
                    'payment_id' => $p->payment_id,
                    'plan' => $p->plan?->name,
                    'amount' => (float) $p->amount,
                    'status' => $p->status,
                    'outcome' => $p->outcome,
                    'created_at' => $p->created_at?->toIso8601String(),
                ])->values(),
            'unpaid_overage' => OverageBillingService::unpaid($user->getKey())
                ->map(fn (UserPlan $p) => ['id' => $p->getKey(), 'plan' => $p->plan?->name, 'amount' => (float) $p->overage_amount, 'requests' => $p->overage_requests, 'period_end' => $p->end_date?->toIso8601String()])
                ->values(),
            'cards' => UserCard::where('authentication_id', $user->getKey())->latest()->get()
                ->map(fn (UserCard $c) => [
                    'id' => $c->getKey(),
                    'brand' => $c->brand,
                    'last_four' => $c->last_four,
                    'expiry' => "{$c->expiry_month}/{$c->expiry_year}",
                    'gateway' => $c->gateway,
                    'is_default' => (bool) $c->is_default,
                    'automatic_billing' => $c->automatic_billing,
                ])->values(),
            'api_keys' => $user->apiKeys()->get()
                ->map(fn (ApiKey $k) => [
                    'id' => $k->getKey(),
                    'name' => $k->name,
                    'active' => (bool) $k->active,
                    'last_used_at' => $k->last_used_at?->toIso8601String(),
                    'expires_at' => $k->expires_at?->toIso8601String(),
                ])->values(),
            'sessions' => $user->tokens()->orderByDesc('last_used_at')->limit(20)->get()
                ->map(function (PersonalAccessToken $t) {
                    $device = json_decode((string) $t->getAttribute('device'), true) ?: [];

                    return [
                        'id' => $t->getKey(),
                        'ip' => $device['ip'] ?? null,
                        'user_agent' => $device['user_agent'] ?? null,
                        'last_used_at' => $t->getAttribute('last_used_at')?->toIso8601String(),
                    ];
                })->values(),
            'audit' => AuditLog::with('actor:id,name,email')
                ->where(fn ($q) => $q->where('subject_id', $user->getKey())->orWhere('actor_id', $user->getKey()))
                ->latest('created_at')->limit(30)->get()
                ->map(fn (AuditLog $a) => [
                    'action' => $a->action,
                    'actor' => $a->actor?->name,
                    'context' => $a->context,
                    'created_at' => $a->created_at?->toIso8601String(),
                ])->values(),
        ]);
    }

    // ── Assinatura (billing) ────────────────────────────────────────────

    /**
     * Cancela a renovação: o acesso segue até o fim do período pago. Para
     * devolver o dinheiro, use o estorno.
     */
    public function cancelSubscription(string $id, string $planId): JsonResponse
    {
        $userPlan = $this->userPlan($id, $planId);

        if ($userPlan->cancelled_at) {
            return response()->jsonError(__('api-key::messages.admin.subscription_already_cancelled'));
        }

        $userPlan->update(['cancelled_at' => now(), 'next_renewal_attempt_at' => null]);

        AuditLogger::record('admin.subscription_cancelled', $userPlan->authentication, ['user_plan_id' => $userPlan->getKey()]);

        return response()->jsonSuccess(['message' => __('api-key::messages.admin.subscription_cancelled')]);
    }

    /**
     * Estende o vencimento. Uma renovação parada (recusa ou pagamento pelo
     * painel) é zerada: o novo vencimento recomeça o ciclo.
     */
    public function extendSubscription(Request $request, string $id, string $planId): JsonResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:365']]);
        $userPlan = $this->userPlan($id, $planId);
        $before = $userPlan->end_date?->toIso8601String();

        $userPlan->update([
            'end_date' => ($userPlan->end_date ?? now())->copy()->addDays($data['days']),
            'active' => true,
            'renewal_attempts' => 0,
            'next_renewal_attempt_at' => null,
            'renewal_failed_at' => null,
            'renewal_requires_payment' => null,
        ]);

        AuditLogger::record('admin.subscription_extended', $userPlan->authentication, [
            'user_plan_id' => $userPlan->getKey(),
            'days' => $data['days'],
            'from' => $before,
            'to' => $userPlan->end_date?->toIso8601String(),
        ]);

        return response()->jsonSuccess(['message' => __('api-key::messages.admin.subscription_extended'), 'end_date' => $userPlan->end_date?->toIso8601String()]);
    }

    /** Ajusta o consumo do período (por exemplo, devolver requisições de uma falha do serviço). */
    public function adjustUsage(Request $request, string $id, string $planId): JsonResponse
    {
        $data = $request->validate(['requests_used' => ['required', 'integer', 'min:0']]);
        $userPlan = $this->userPlan($id, $planId);
        $before = (int) $userPlan->requests_used;

        $userPlan->update(['requests_used' => $data['requests_used']]);

        AuditLogger::record('admin.usage_adjusted', $userPlan->authentication, [
            'user_plan_id' => $userPlan->getKey(),
            'from' => $before,
            'to' => $data['requests_used'],
        ]);

        return response()->jsonSuccess(['message' => __('api-key::messages.admin.usage_adjusted')]);
    }

    /** Cobra a renovação agora, fora do calendário de novas tentativas. */
    public function retryRenewal(string $id, string $planId): JsonResponse
    {
        $userPlan = $this->userPlan($id, $planId);

        if (! $userPlan->active || $userPlan->cancelled_at) {
            return response()->jsonError(__('api-key::messages.admin.renewal_not_retryable'));
        }

        $userPlan->update(['renewal_failed_at' => null, 'renewal_requires_payment' => null]);

        $queue = config('api-key.billing.queue');
        $job = new ProcessPlanRenewalJob($userPlan->getKey());
        dispatch($queue ? $job->onQueue($queue) : $job);

        AuditLogger::record('admin.renewal_retried', $userPlan->authentication, ['user_plan_id' => $userPlan->getKey()]);

        return response()->jsonSuccess(['message' => __('api-key::messages.admin.renewal_retried')]);
    }

    /**
     * Perdoa o excedente em aberto. O valor original fica na auditoria; o
     * período sai da lista de excedentes a pagar e não gera recibo.
     */
    public function waiveOverage(string $id, string $planId): JsonResponse
    {
        $userPlan = OverageBillingService::unpaid($id)->firstWhere('id', $planId);

        if (! $userPlan instanceof UserPlan) {
            abort(404);
        }

        $amount = (float) $userPlan->overage_amount;
        $userPlan->update(['overage_amount' => 0]);

        AuditLogger::record('admin.overage_waived', $userPlan->authentication, [
            'user_plan_id' => $userPlan->getKey(),
            'amount' => $amount,
            'requests' => $userPlan->overage_requests,
        ]);

        return response()->jsonSuccess(['message' => __('api-key::messages.admin.overage_waived')]);
    }

    // ── Conta (accounts) ────────────────────────────────────────────────

    public function resendVerification(string $id): JsonResponse
    {
        $user = Authentication::findOrFail($id);

        if ($user->email_verified_at) {
            return response()->jsonError(__('api-key::messages.admin.email_already_verified'));
        }

        $user->sendEmailVerificationNotification();
        AuditLogger::record('admin.verification_resent', $user);

        return response()->jsonSuccess(['message' => __('api-key::messages.admin.verification_resent')]);
    }

    public function revokeSessions(Request $request, string $id): JsonResponse
    {
        $user = $this->other($request, $id);
        $count = $user->tokens()->delete();

        AuditLogger::record('admin.sessions_revoked', $user, ['count' => $count]);

        return response()->jsonSuccess(['message' => __('api-key::messages.admin.sessions_revoked')]);
    }

    public function revokeApiKeys(string $id): JsonResponse
    {
        $user = Authentication::findOrFail($id);
        $count = $user->apiKeys()->where('active', true)->update(['active' => false]);

        AuditLogger::record('admin.api_keys_revoked', $user, ['count' => $count]);

        return response()->jsonSuccess(['message' => __('api-key::messages.admin.api_keys_revoked')]);
    }

    // ── Gestão (manage) ─────────────────────────────────────────────────

    /**
     * Desliga a 2FA de quem perdeu o aparelho e os códigos de recuperação. As
     * sessões abertas caem e o titular é avisado por e-mail, para notar se não
     * foi ele quem pediu.
     */
    public function resetTwoFactor(Request $request, string $id): JsonResponse
    {
        $user = $this->other($request, $id);

        if (! $user->two_factor_confirmed_at && ! $user->two_factor_secret) {
            return response()->jsonError(__('api-key::messages.admin.two_factor_not_enabled'));
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $user->tokens()->delete();

        $user->notify(AccountSecurityNotification::make(AccountSecurityNotification::TWO_FACTOR_DISABLED));
        AuditLogger::record('admin.two_factor_reset', $user);

        return response()->jsonSuccess(['message' => __('api-key::messages.admin.two_factor_reset')]);
    }

    /**
     * Papel na equipe: admin, finance, support, ou nenhum (cliente). O último
     * admin não pode ser rebaixado, senão o painel fica sem ninguém que
     * consiga promover outro.
     */
    public function updateRole(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'role' => ['nullable', Rule::in([...array_keys(AdminRoles::ABILITIES), 'client'])],
        ]);

        $user = $this->other($request, $id);
        $role = $data['role'] ?? 'client';
        $before = $user->role;

        if (AdminRoles::role($user) === AdminRoles::ADMIN && $role !== AdminRoles::ADMIN
            && Authentication::whereRaw('LOWER(role) = ?', [AdminRoles::ADMIN])->count() <= 1) {
            return response()->jsonError(__('api-key::messages.admin.last_admin'));
        }

        $user->forceFill(['role' => $role])->save();

        // Quem perdeu poder não fica com uma sessão aberta no painel.
        if (count(AdminRoles::abilities($user)) < count(AdminRoles::ABILITIES[strtolower((string) $before)] ?? [])) {
            $user->tokens()->delete();
        }

        AuditLogger::record('admin.role_changed', $user, ['from' => $before, 'to' => $role]);

        return response()->jsonSuccess(['message' => __('api-key::messages.admin.role_changed'), 'role' => $role]);
    }

    /** @return array<string, mixed> */
    private function subscription(UserPlan $p): array
    {
        return [
            'id' => $p->getKey(),
            'plan' => $p->plan?->name,
            'request_limit' => $p->plan?->request_limit,
            'requests_used' => (int) $p->requests_used,
            'start_date' => $p->start_date?->toIso8601String(),
            'end_date' => $p->end_date?->toIso8601String(),
            'active' => (bool) $p->active,
            'is_trial' => (bool) $p->is_trial,
            'payment_id' => $p->payment_id,
            'payment_amount' => $p->payment_amount !== null ? (float) $p->payment_amount : null,
            'payment_method' => $p->payment_method,
            'cancelled_at' => $p->cancelled_at?->toIso8601String(),
            'refunded_at' => $p->refunded_at?->toIso8601String(),
            'renewal_attempts' => (int) $p->renewal_attempts,
            'next_renewal_attempt_at' => $p->next_renewal_attempt_at?->toIso8601String(),
            'renewal_failed_at' => $p->renewal_failed_at?->toIso8601String(),
            'renewal_requires_payment' => $p->renewal_requires_payment,
            'overage_amount' => $p->overage_amount !== null ? (float) $p->overage_amount : null,
            'overage_paid_at' => $p->overage_paid_at?->toIso8601String(),
        ];
    }

    private function userPlan(string $userId, string $planId): UserPlan
    {
        return UserPlan::with(['authentication', 'plan'])
            ->where('authentication_id', $userId)
            ->findOrFail($planId);
    }

    /** Usuário alvo que não pode ser a própria pessoa da equipe. */
    private function other(Request $request, string $id): Authentication
    {
        $user = Authentication::findOrFail($id);

        if ($user->is($request->user())) {
            abort(response()->jsonError(__('api-key::messages.admin_cannot_change_self')));
        }

        return $user;
    }
}
