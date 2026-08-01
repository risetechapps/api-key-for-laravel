<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Signature;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RiseTechApps\ApiKey\Events\PlanCancelled;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Signature\SignatureRequest;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Signature\LogHistoryResource;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Signature\SignatureHistoryResource;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Repositories\Plan\PlanRepository;

class SignatureController extends Controller
{
    public function __construct(protected readonly PlanRepository $planRepository) {}

    /**
     * Activate a free plan for the authenticated user.
     *
     * This endpoint grants a subscription outright and never talks to the payment
     * gateway, so it only ever applies to plans that cost nothing; a priced plan
     * must go through /dashboard/checkout/process, which charges before it
     * subscribes. Without the price guard below, any authenticated user could
     * POST the id of the most expensive plan and receive it for free.
     */
    public function signature(SignatureRequest $request): JsonResponse
    {
        try {

            $data = $request->validationData();

            $plan = $this->planRepository->findActiveById($data['plan']);

            if (! $plan) {
                return response()->json([
                    'success' => false,
                    'message' => __('api-key::messages.plan_not_found'),
                ], 404);
            }

            if ((float) $plan->price > 0) {
                Log::warning('Free subscription attempted on a paid plan', [
                    'user_id' => auth()->id(),
                    'plan_id' => $plan->getKey(),
                    'price' => $plan->price,
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => __('api-key::messages.plan_requires_payment'),
                ], 422);
            }

            auth()->user()->subscribeToPlan($plan);

            return response()->jsonSuccess();
        } catch (\Exception $e) {
            report($e);

            return response()->jsonGone(__('api-key::messages.error_creating_signature'));
        }
    }

    /**
     * Stop the subscription from renewing.
     *
     * Deliberately not a revocation. The period already paid for runs to its
     * end_date and the API key keeps working until then — cancelling on day 2 of
     * 30 must not cost the subscriber the other 28. All this does is stop the
     * next charge: `billing:process-renewals` skips cancelled subscriptions, and
     * the plan lapses on its own through the usual expiry path.
     *
     * Until this existed there was no way out at all. A subscriber with a saved
     * card was charged again at every end_date, and the only exit was asking an
     * administrator for a manual refund.
     */
    public function cancel(Request $request): JsonResponse
    {
        try {
            $userPlan = auth()->user()->activePlanWithGracePeriod()->with('plan')->first();

            if (! $userPlan) {
                return response()->json([
                    'success' => false,
                    'message' => __('api-key::messages.no_active_subscription'),
                ], 404);
            }

            // Idempotent: a second cancellation is the state the caller asked for,
            // not an error worth failing a retry over.
            if (! $userPlan->isCancelled()) {
                $userPlan->update(['cancelled_at' => now()]);

                /** @var Authentication $user */
                $user = auth()->user();

                if ($userPlan->plan && $userPlan->end_date) {
                    PlanCancelled::dispatch(
                        $user,
                        $userPlan,
                        $userPlan->plan,
                        $userPlan->end_date
                    );
                }

                Log::info('Subscription cancelled', [
                    'user_id' => auth()->id(),
                    'user_plan_id' => $userPlan->getKey(),
                    'plan_id' => $userPlan->plan_id,
                    'access_until' => $userPlan->end_date?->toIso8601String(),
                    'ip' => $request->ip(),
                ]);
            }

            return response()->jsonSuccess([
                'cancelled_at' => $userPlan->cancelled_at?->toIso8601String(),
                'access_until' => $userPlan->end_date?->toIso8601String(),
                'message' => __('api-key::messages.subscription_cancelled'),
            ]);
        } catch (\Exception $e) {
            report($e);

            return response()->jsonGone(__('api-key::messages.error_cancelling_signature'));
        }
    }

    /**
     * Undo a cancellation while the period is still running.
     *
     * Only possible before the subscription lapses — once end_date has passed
     * there is nothing left to renew, and the customer has to buy again through
     * the checkout.
     */
    public function resume(Request $request): JsonResponse
    {
        try {
            $userPlan = auth()->user()->activePlanWithGracePeriod()->with('plan')->first();

            if (! $userPlan || ! $userPlan->isCancelled()) {
                return response()->json([
                    'success' => false,
                    'message' => __('api-key::messages.no_cancelled_subscription'),
                ], 404);
            }

            if ($userPlan->isExpired()) {
                return response()->json([
                    'success' => false,
                    'message' => __('api-key::messages.subscription_cannot_resume'),
                ], 422);
            }

            $userPlan->update(['cancelled_at' => null]);

            Log::info('Subscription resumed', [
                'user_id' => auth()->id(),
                'user_plan_id' => $userPlan->getKey(),
                'ip' => $request->ip(),
            ]);

            return response()->jsonSuccess([
                'renews_on' => $userPlan->end_date?->toIso8601String(),
                'message' => __('api-key::messages.subscription_resumed'),
            ]);
        } catch (\Exception $e) {
            report($e);

            return response()->jsonGone(__('api-key::messages.error_resuming_signature'));
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

            $used = (int) ($activePlan?->requests_used ?? 0);
            $limit = (int) ($activePlan?->plan?->request_limit ?? 0);
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
                "api_key_stats_today:{$user->getKey()}:".now()->toDateString(),
                config('api-key.cache_ttl.stats', 30),
                fn () => $user->requestLog()
                    ->whereBetween('requested_at', [now()->startOfDay(), now()->endOfDay()])
                    ->count()
            );

            $payload = [
                'today' => $today,
                'used' => $used,
                'remaining' => $remaining,
                'limit' => $limit,
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
            "api_key_stats_series:{$user->getKey()}:{$days}:".now()->toDateString(),
            config('api-key.cache_ttl.stats', 30),
            fn () => $user->requestLog()
                ->where('requested_at', '>=', $since)
                ->selectRaw($this->dayExpression().' as day, COUNT(*) as total')
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
                'date' => $date,
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
        return match (DB::connection()->getDriverName()) {
            'pgsql' => 'CAST(requested_at AS date)',
            'sqlite' => "strftime('%Y-%m-%d', requested_at)",
            default => 'DATE(requested_at)',
        };
    }
}
