<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RiseTechApps\ApiKey\Exceptions\SubscriptionAlreadyRefundedException;
use RiseTechApps\ApiKey\Facades\FeatureRegistry;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Plans\PlansResource;
use RiseTechApps\ApiKey\Models\AuditLog\AuditLog;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Services\AuditLogger;
use RiseTechApps\ApiKey\Services\AuthService;
use RiseTechApps\ApiKey\Services\MetricsService;
use RiseTechApps\ApiKey\Services\RefundService;

class AdminController extends Controller
{
    public function __construct(protected readonly RefundService $refundService) {}

    public function processRefund(string $id): JsonResponse
    {
        $userPlan = UserPlan::whereNotNull('payment_id')->findOrFail($id);

        try {
            // Mesmo serviço do estorno automático, para que os dois caminhos
            // gravem `refunded_at` e `refund_id`. Antes o painel só marcava
            // `active = false`, e não havia como distinguir uma assinatura
            // encerrada de uma devolvida nem impedir um segundo estorno.
            $refundId = $this->refundService->refund($userPlan);

            AuditLogger::record('admin.refund_processed', $userPlan->authentication, [
                'user_plan_id' => $userPlan->getKey(),
                'payment_id' => $userPlan->payment_id,
                'refund_id' => $refundId,
            ]);

            return response()->jsonSuccess([
                'refund_id' => $refundId,
                'status' => 'processed',
            ]);
        } catch (SubscriptionAlreadyRefundedException) {
            return response()->jsonError(__('api-key::messages.refund.already_refunded'));
        } catch (\Exception $e) {
            // A correlation code goes to the panel, the detail goes to the log.
            // Concatenating $e->getMessage() into the response rendered whatever
            // the catch happened to swallow straight into the admin screen — a
            // QueryException carries the SQL with its bound values, an
            // MPApiException carries Mercado Pago's internal detail — and from
            // there it travels into screenshots and support tickets. report()
            // already puts the full exception where operators can read it.
            $errorId = strtoupper(Str::random(8));

            logglyError()->withContext([
                'error_id' => $errorId,
                'user_plan_id' => $userPlan->getKey(),
                'payment_id' => $userPlan->payment_id,
                'admin_id' => auth()->id(),
                'exception' => $e->getMessage(),
            ])->log('Refund failed');

            report($e);

            return response()->jsonInternal(
                __('api-key::messages.error_processing_refund', ['id' => $errorId])
            );
        }
    }

    public function refunds(Request $request): JsonResponse
    {
        $subscriptions = UserPlan::with(['plan', 'authentication'])
            ->whereNotNull('payment_id')
            ->latest()
            ->paginate(20);

        return response()->jsonSuccess([
            'data' => $subscriptions->map(fn ($up) => [
                'id' => $up->getKey(),
                'payment_id' => $up->payment_id,
                'active' => $up->active,
                'start_date' => $up->start_date?->toIso8601String(),
                'end_date' => $up->end_date?->toIso8601String(),
                'plan' => [
                    'name' => $up->plan?->name,
                    'price' => $up->plan?->formatted_price,
                ],
                'payment_amount' => $up->payment_amount
                    ? 'R$ '.number_format((float) $up->payment_amount, 2, ',', '.')
                    : $up->plan?->formatted_price,
                'user' => [
                    'id' => $up->authentication?->getKey(),
                    'name' => $up->authentication?->name,
                    'email' => $up->authentication?->email,
                ],
            ]),
            'total' => $subscriptions->total(),
            'current_page' => $subscriptions->currentPage(),
            'last_page' => $subscriptions->lastPage(),
        ]);
    }

    public function plans(): JsonResponse
    {
        $plans = Plan::orderBy('price')->get();

        return response()->jsonSuccess(PlansResource::collection($plans));
    }

    public function features(): JsonResponse
    {
        return response()->jsonSuccess(FeatureRegistry::all());
    }

    public function users(Request $request): JsonResponse
    {
        $search = $request->get('search');

        // Escaped so a % or _ typed by the operator is matched literally instead
        // of turning into a wildcard. The LOWER(col) LIKE shape is kept because
        // that is what the pg_trgm indexes are built on.
        $term = $search ? '%'.addcslashes(strtolower((string) $search), '%_\\').'%' : null;

        $users = Authentication::with(['activePlan.plan'])
            ->when($term, fn ($q) => $q->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term]);
            }))
            ->latest()
            ->paginate(20);

        return response()->jsonSuccess([
            'data' => $users->map(fn ($u) => [
                'id' => $u->getKey(),
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role ?? 'user',
                'status' => $u->status,
                'created_at' => $u->created_at?->toIso8601String(),
                'active_plan' => $u->activePlan ? [
                    'name' => $u->activePlan->plan?->name,
                    'end_date' => $u->activePlan->end_date?->toIso8601String(),
                    'active' => $u->activePlan->active,
                ] : null,
            ]),
            'total' => $users->total(),
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
        ]);
    }

    /**
     * Suspende ou reativa uma conta.
     *
     * Suspender revoga os tokens do painel na hora; a API key fica intacta, mas
     * é recusada enquanto a conta estiver suspensa (AuthenticateApiKey), para
     * que reativar devolva o acesso sem obrigar o cliente a trocar a chave.
     * A assinatura não é tocada: suspensão é sobre acesso, não sobre cobrança.
     */
    public function updateUserStatus(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(AuthService::statusLogin())],
        ]);

        $user = Authentication::findOrFail($id);

        if ($user->is($request->user())) {
            return response()->jsonError(__('api-key::messages.admin_cannot_change_self'));
        }

        $previous = $user->status;

        $user->forceFill(['status' => $data['status']])->save();

        if (! $user->isEnabled()) {
            $user->tokens()->delete();
        }

        AuditLogger::record('admin.user_status_changed', $user, ['from' => $previous, 'to' => $user->status]);

        return response()->jsonSuccess([
            'message' => __('api-key::messages.user_status_updated'),
            'status' => $user->status,
        ]);
    }

    /**
     * Concede um plano sem cobrança: cortesia, parceria, compensação.
     *
     * Passa pelo mesmo subscribeToPlan() do checkout, então encerra a
     * assinatura em curso, ativa a API key e dispara PlanChanged. Sem
     * payment_id, a assinatura não aparece na tela de estorno e não é renovada
     * com cobrança.
     */
    public function grantPlan(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'string'],
        ]);

        $user = Authentication::findOrFail($id);
        // A chave é uuid: no PostgreSQL um valor que não é uuid derruba a
        // consulta (500) em vez de não achar o plano.
        abort_unless(Str::isUuid($data['plan_id']), 404);
        $plan = Plan::findOrFail($data['plan_id']);

        // Concessão sem cobrança: vale zero na troca de plano, então não gera
        // crédito de troca.
        $userPlan = $user->subscribeToPlan($plan, ['payment_amount' => 0]);

        AuditLogger::record('admin.plan_granted', $user, [
            'plan_id' => $plan->getKey(),
            'plan' => $plan->name,
            'user_plan_id' => $userPlan->getKey(),
        ]);

        return response()->jsonSuccess([
            'message' => __('api-key::messages.plan_granted'),
            'user_plan_id' => $userPlan->getKey(),
            'end_date' => $userPlan->end_date?->toIso8601String(),
        ]);
    }

    public function metrics(MetricsService $metrics): JsonResponse
    {
        return response()->jsonSuccess($metrics->summary());
    }

    /**
     * Trilha de auditoria, da mais recente para a mais antiga. Filtra por ação
     * (prefixo, ex.: `admin.`), por quem agiu e por quem foi afetado.
     */
    public function audit(Request $request): JsonResponse
    {
        $logs = AuditLog::with(['actor:id,name,email', 'subject:id,name,email'])
            ->when($request->query('action'), fn ($q, $action) => $q->where('action', 'like', addcslashes((string) $action, '%_\\').'%'))
            ->when($request->query('actor_id'), fn ($q, $id) => $q->where('actor_id', $id))
            ->when($request->query('subject_id'), fn ($q, $id) => $q->where('subject_id', $id))
            ->latest('created_at')
            ->paginate(50);

        return response()->jsonSuccess([
            'data' => $logs->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->getKey(),
                'action' => $log->action,
                'actor' => $log->actor ? ['id' => $log->actor->getKey(), 'name' => $log->actor->name, 'email' => $log->actor->email] : null,
                'subject' => $log->subject ? ['id' => $log->subject->getKey(), 'name' => $log->subject->name, 'email' => $log->subject->email] : null,
                'context' => $log->context,
                'ip' => $log->ip,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values()->all(),
            'total' => $logs->total(),
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
        ]);
    }
}
