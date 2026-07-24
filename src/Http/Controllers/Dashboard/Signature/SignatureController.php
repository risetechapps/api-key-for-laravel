<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Signature;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Signature\SignatureRequest;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Signature\LogHistoryResource;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Signature\SignatureHistoryResource;
use RiseTechApps\ApiKey\Repositories\Plan\PlanRepository;

class SignatureController extends Controller
{
    public function __construct(protected readonly PlanRepository $planRepository)
    {
    }

    public function signature(SignatureRequest $request): JsonResponse
    {
        try {

            $data = $request->validationData();

            $plan = $this->planRepository->findById($data['plan']);
            auth()->user()->subscribeToPlan($plan);

            return response()->jsonSuccess();
        } catch (\Exception $e) {
            report($e);
            return response()->jsonGone(__('api-key::messages.error_creating_signature'));
        }
    }

    public function history(Request $request): JsonResponse
    {
        try {
            $subscriptions = auth()->user()->userPlan()
                ->with('plan')
                ->latest()
                ->paginate($this->perPage($request, 25, 100));

            return response()->jsonSuccess(
                $this->paginated(SignatureHistoryResource::collection($subscriptions), $subscriptions)
            );
        } catch (\Exception $e) {
            report($e);
            return response()->jsonGone(__('api-key::messages.error_loading_signature_history'));
        }
    }

    /**
     * Paginated request log.
     *
     * `request_logs` gains one row per authenticated API request and is never
     * capped, so this must never load the relation in full — a busy key reaches
     * millions of rows and would exhaust memory before the response is built.
     */
    public function log(Request $request): JsonResponse
    {
        try {
            $query = auth()->user()->requestLog()->latest('requested_at');

            if ($from = $request->date('from')) {
                $query->where('requested_at', '>=', $from->startOfDay());
            }

            if ($to = $request->date('to')) {
                $query->where('requested_at', '<=', $to->endOfDay());
            }

            $logs = $query->paginate($this->perPage($request, 50, 200));

            return response()->jsonSuccess(
                $this->paginated(LogHistoryResource::collection($logs), $logs)
            );
        } catch (\Exception $e) {
            report($e);
            return response()->jsonGone(__('api-key::messages.error_loading_request_log'));
        }
    }

    /**
     * Clamp a client-supplied page size so a caller cannot ask for the whole table.
     */
    private function perPage(Request $request, int $default, int $max): int
    {
        return max(1, min((int) $request->integer('per_page', $default), $max));
    }

    /**
     * Envelope used by the dashboard: resource data plus pagination metadata.
     */
    private function paginated($resource, $paginator): array
    {
        return [
            'data' => $resource,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    /**
     * Estatísticas agregadas e leves para o dashboard (polling em tempo real).
     * Usa COUNT e o contador do plano ativo — não carrega a lista de logs.
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            $activePlan = $user->activePlan()->with('plan')->first();

            $used      = (int) ($activePlan?->requests_used ?? 0);
            $limit     = (int) ($activePlan?->plan?->request_limit ?? 0);
            // Nunca exibe acima do limite (cobre dado legado / overshoot de concorrência).
            if ($limit > 0) {
                $used = min($used, $limit);
            }
            $remaining = $limit > 0 ? max(0, $limit - $used) : 0;

            // whereDate() wraps the column in a function, which no index can serve
            // — on request_logs that means a sequential scan on every poll. A plain
            // range is sargable and uses request_logs_user_time_idx.
            // Cached briefly as well: the dashboard polls this on a timer and the
            // number does not need to be exact to the second.
            $today = Cache::remember(
                "api_key_stats_today:{$user->getKey()}:" . now()->toDateString(),
                config('api-key.cache_ttl.stats', 30),
                fn() => $user->requestLog()
                    ->whereBetween('requested_at', [now()->startOfDay(), now()->endOfDay()])
                    ->count()
            );

            $payload = [
                'today'     => $today,
                'used'      => $used,
                'remaining' => $remaining,
                'limit'     => $limit,
            ];

            // The chart series is only built when asked for. The dashboard polls
            // this endpoint on a timer for the counters alone and has no reason to
            // re-aggregate the whole window every few seconds.
            if ($request->has('days')) {
                $payload['series'] = $this->dailySeries(
                    $user,
                    max(1, min($request->integer('days', 30), 90))
                );
            }

            return response()->jsonSuccess($payload);
        } catch (\Exception $e) {
            report($e);
            return response()->jsonGone(__('api-key::messages.error_loading_request_log'));
        }
    }

    /**
     * Daily request counts for the dashboard chart.
     *
     * Aggregated in the database over a bounded window. The dashboard used to
     * download the entire request log and count the rows in the browser, which
     * cost one full-table read per page load and could not survive a log of any
     * real size.
     *
     * @return array<int, array{date: string, total: int}>
     */
    private function dailySeries($user, int $days): array
    {
        $since = now()->subDays($days - 1)->startOfDay();

        $counts = Cache::remember(
            "api_key_stats_series:{$user->getKey()}:{$days}:" . now()->toDateString(),
            config('api-key.cache_ttl.stats', 30),
            fn() => $user->requestLog()
                ->where('requested_at', '>=', $since)
                ->selectRaw($this->dayExpression() . ' as day, COUNT(*) as total')
                ->groupBy('day')
                ->pluck('total', 'day')
                ->all()
        );

        // Emit every day in the window, including the empty ones, so the chart
        // does not have to reconstruct gaps.
        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $since->copy()->addDays($i)->toDateString();
            $series[] = [
                'date'  => $date,
                'total' => (int) ($counts[$date] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Portable "truncate timestamp to day" expression.
     * Postgres has no DATE() function for timestamps; SQLite has no CAST to date.
     */
    private function dayExpression(): string
    {
        return match (\Illuminate\Support\Facades\DB::connection()->getDriverName()) {
            'pgsql' => 'CAST(requested_at AS date)',
            'sqlite' => "strftime('%Y-%m-%d', requested_at)",
            default => 'DATE(requested_at)',
        };
    }
}
