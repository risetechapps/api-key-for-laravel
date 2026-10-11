<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RiseTechApps\ApiKey\Gateways\GatewayRegistry;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Services\AuditLogger;
use RiseTechApps\ApiKey\Services\PaymentSettingsService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cobranças, inadimplência e exportações do painel admin.
 */
class AdminBillingController extends Controller
{
    /**
     * Todas as cobranças, de qualquer gateway, numa lista só: assinaturas
     * pagas (e estornadas), excedentes pagos e compras em análise ou
     * recusadas.
     */
    public function payments(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        $page = $this->paymentsQuery($filters)
            ->orderByDesc('date')
            ->paginate(25);

        return response()->jsonSuccess([
            'data' => collect($page->items())->map(fn ($row) => $this->paymentRow($row))->values(),
            'total' => $page->total(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'summary' => $this->summary($filters),
        ]);
    }

    /**
     * Dinheiro a recuperar: renovações recusadas esperando nova tentativa,
     * renovações que esperam o assinante pagar pelo painel e excedente não
     * pago.
     */
    public function delinquency(): JsonResponse
    {
        $base = fn () => UserPlan::query()
            ->join('authentications', 'authentications.id', '=', 'user_plans.authentication_id')
            ->join('plans', 'plans.id', '=', 'user_plans.plan_id')
            ->select('user_plans.*', 'authentications.name as user_name', 'authentications.email as user_email', 'plans.name as plan_name', 'plans.price as plan_price');

        $row = fn (UserPlan $p, float $amount) => [
            'user_plan_id' => $p->getKey(),
            'user_id' => $p->authentication_id,
            'user_name' => $p->getAttribute('user_name'),
            'user_email' => $p->getAttribute('user_email'),
            'plan' => $p->getAttribute('plan_name'),
            'amount' => round($amount, 2),
            'end_date' => $p->end_date?->toIso8601String(),
            'renewal_attempts' => (int) $p->renewal_attempts,
            'next_renewal_attempt_at' => $p->next_renewal_attempt_at?->toIso8601String(),
            'reason' => $p->renewal_requires_payment,
        ];

        $retrying = $base()->where('user_plans.active', true)->whereNull('user_plans.cancelled_at')
            ->where('user_plans.renewal_attempts', '>', 0)->whereNull('user_plans.renewal_failed_at')
            ->orderBy('user_plans.next_renewal_attempt_at')->limit(200)->get()
            ->map(fn (UserPlan $p) => $row($p, (float) $p->getAttribute('plan_price')))->values();

        $awaiting = $base()->where('user_plans.active', true)->whereNull('user_plans.cancelled_at')
            ->whereNotNull('user_plans.renewal_requires_payment')
            ->orderBy('user_plans.end_date')->limit(200)->get()
            ->map(fn (UserPlan $p) => $row($p, (float) $p->getAttribute('plan_price')))->values();

        $overage = $base()->whereNotNull('user_plans.overage_billed_at')->whereNull('user_plans.overage_paid_at')
            ->where('user_plans.overage_amount', '>', 0)
            ->orderBy('user_plans.end_date')->limit(200)->get()
            ->map(fn (UserPlan $p) => $row($p, (float) $p->overage_amount))->values();

        return response()->jsonSuccess([
            'renewals_retrying' => $retrying,
            'renewals_awaiting_payment' => $awaiting,
            'unpaid_overage' => $overage,
            'totals' => [
                'renewals_retrying' => round($retrying->sum('amount'), 2),
                'renewals_awaiting_payment' => round($awaiting->sum('amount'), 2),
                'unpaid_overage' => round($overage->sum('amount'), 2),
            ],
        ]);
    }

    /**
     * CSV para a contabilidade: usuários, cobranças (com os mesmos filtros da
     * tela) ou estornos. Separador `;` e BOM, que é o que o Excel em
     * português abre sem bagunçar acento e coluna.
     */
    public function export(Request $request, string $type): StreamedResponse
    {
        [$header, $rows] = match ($type) {
            'payments' => $this->paymentsExport($this->filters($request)),
            'refunds' => $this->refundsExport(),
            'users' => $this->usersExport(),
            default => abort(404),
        };

        AuditLogger::record('admin.export_downloaded', null, ['type' => $type]);

        $filename = "{$type}-".now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ';', '"', '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => $this->csvSafe($v), $row), ';', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, string|null> */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:approved,refunded,pending,rejected'],
            'kind' => ['nullable', 'in:subscription,overage,pending'],
            'gateway' => ['nullable', 'in:'.implode(',', PaymentSettingsService::GATEWAYS)],
            'method' => ['nullable', 'in:card,pix'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        return array_map(fn ($v) => $v === null ? null : (string) $v, $data);
    }

    /** @param array<string, string|null> $filters */
    private function paymentsQuery(array $filters): Builder
    {
        $user = fn (Builder $q) => $q
            ->join('authentications', 'authentications.id', '=', 'user_plans.authentication_id')
            ->leftJoin('plans', 'plans.id', '=', 'user_plans.plan_id');

        $subscriptions = $user(DB::table('user_plans'))
            ->whereNotNull('user_plans.payment_id')
            ->selectRaw("'subscription' as kind, user_plans.payment_id as payment_id, user_plans.payment_amount as amount, user_plans.payment_method as method, CASE WHEN user_plans.refunded_at IS NULL THEN 'approved' ELSE 'refunded' END as status, user_plans.start_date as date, user_plans.authentication_id as user_id, authentications.name as user_name, authentications.email as user_email, plans.name as plan_name");

        $overage = $user(DB::table('user_plans'))
            ->whereNotNull('user_plans.overage_payment_id')
            ->whereNotNull('user_plans.overage_paid_at')
            ->selectRaw("'overage' as kind, user_plans.overage_payment_id as payment_id, user_plans.overage_amount as amount, NULL as method, 'approved' as status, user_plans.overage_paid_at as date, user_plans.authentication_id as user_id, authentications.name as user_name, authentications.email as user_email, plans.name as plan_name");

        // Aprovadas já aparecem como assinatura ou excedente; daqui saem só as
        // que ainda esperam ou foram recusadas.
        $pending = DB::table('pending_payments')
            ->join('authentications', 'authentications.id', '=', 'pending_payments.authentication_id')
            ->leftJoin('plans', 'plans.id', '=', 'pending_payments.plan_id')
            ->where(fn ($q) => $q->whereNull('pending_payments.outcome')->orWhere('pending_payments.outcome', '!=', 'approved'))
            ->selectRaw("'pending' as kind, pending_payments.payment_id as payment_id, pending_payments.amount as amount, NULL as method, CASE WHEN pending_payments.outcome = 'rejected' THEN 'rejected' ELSE 'pending' END as status, pending_payments.created_at as date, pending_payments.authentication_id as user_id, authentications.name as user_name, authentications.email as user_email, plans.name as plan_name");

        $query = DB::query()->fromSub($subscriptions->unionAll($overage)->unionAll($pending), 'p');

        if ($filters['status'] ?? null) {
            $query->where('status', $filters['status']);
        }
        if ($filters['kind'] ?? null) {
            $query->where('kind', $filters['kind']);
        }
        if ($filters['method'] ?? null) {
            $query->where('method', $filters['method']);
        }
        // Prefixo comparado exato: LIKE trata `_` como curinga e, no SQLite,
        // ignora maiúsculas, o que misturaria `or_` com `ORD` do Mercado Pago.
        if ($gateway = $filters['gateway'] ?? null) {
            $prefixes = collect(GatewayRegistry::all())->mapWithKeys(fn ($d) => [$d->name() => $d->paymentIdPrefix()]);

            if ($gateway === 'mercadopago') {
                foreach ($prefixes as $prefix) {
                    $query->whereRaw('SUBSTR(payment_id, 1, ?) <> ?', [strlen($prefix), $prefix]);
                }
            } else {
                $prefix = (string) $prefixes[$gateway];
                $query->whereRaw('SUBSTR(payment_id, 1, ?) = ?', [strlen($prefix), $prefix]);
            }
        }
        if ($filters['from'] ?? null) {
            $query->where('date', '>=', $filters['from']);
        }
        if ($filters['to'] ?? null) {
            $query->where('date', '<=', $filters['to'].' 23:59:59');
        }
        if ($search = $filters['search'] ?? null) {
            $term = '%'.addcslashes(strtolower($search), '%_\\').'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(user_email) LIKE ?', [$term])
                ->orWhereRaw('LOWER(user_name) LIKE ?', [$term])
                ->orWhere('payment_id', $search));
        }

        return $query;
    }

    /** @param array<string, string|null> $filters */
    private function summary(array $filters): array
    {
        $totals = $this->paymentsQuery($filters)
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return collect(['approved', 'refunded', 'pending', 'rejected'])
            ->mapWithKeys(fn ($s) => [$s => [
                'count' => (int) ($totals[$s]->count ?? 0),
                'amount' => round((float) ($totals[$s]->amount ?? 0), 2),
            ]])
            ->all();
    }

    /** @return array<string, mixed> */
    private function paymentRow(object $row): array
    {
        return [
            'kind' => $row->kind,
            'payment_id' => $row->payment_id,
            'gateway' => GatewayRegistry::forPaymentId((string) $row->payment_id)?->name() ?? 'mercadopago',
            'amount' => $row->amount !== null ? round((float) $row->amount, 2) : null,
            'method' => $row->method,
            'status' => $row->status,
            'date' => $row->date ? Carbon::parse($row->date)->toIso8601String() : null,
            'user_id' => $row->user_id,
            'user_name' => $row->user_name,
            'user_email' => $row->user_email,
            'plan' => $row->plan_name,
        ];
    }

    /** @param array<string, string|null> $filters */
    private function paymentsExport(array $filters): array
    {
        $rows = $this->paymentsQuery($filters)->orderByDesc('date')->lazy(500)
            ->map(function ($row) {
                $r = $this->paymentRow($row);

                return [$r['date'], $r['kind'], $r['status'], $r['gateway'], $r['method'], $r['payment_id'], number_format((float) $r['amount'], 2, ',', ''), $r['plan'], $r['user_name'], $r['user_email']];
            });

        return [['data', 'tipo', 'status', 'gateway', 'forma', 'id do pagamento', 'valor', 'plano', 'cliente', 'e-mail'], $rows];
    }

    private function refundsExport(): array
    {
        $rows = UserPlan::with(['authentication:id,name,email', 'plan:id,name'])
            ->whereNotNull('refunded_at')
            ->orderByDesc('refunded_at')
            ->lazy(500)
            ->map(fn (UserPlan $p) => [
                $p->refunded_at?->toIso8601String(),
                $p->payment_id,
                $p->refund_id,
                number_format((float) $p->payment_amount, 2, ',', ''),
                $p->plan?->name,
                $p->authentication?->name,
                $p->authentication?->email,
            ]);

        return [['data do estorno', 'id do pagamento', 'id do estorno', 'valor pago', 'plano', 'cliente', 'e-mail'], $rows];
    }

    private function usersExport(): array
    {
        $rows = DB::table('authentications')
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->select('id', 'name', 'email', 'role', 'status', 'email_verified_at', 'created_at')
            ->lazy(500)
            ->map(fn ($u) => [$u->id, $u->name, $u->email, $u->role, $u->status, $u->email_verified_at ? 'sim' : 'não', $u->created_at]);

        return [['id', 'nome', 'e-mail', 'papel', 'status', 'e-mail verificado', 'cadastro'], $rows];
    }

    /**
     * Valor que começa com `=`, `+`, `-` ou `@` vira fórmula no Excel. Nome de
     * cliente é texto livre, então é neutralizado com um apóstrofo.
     */
    private function csvSafe(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
