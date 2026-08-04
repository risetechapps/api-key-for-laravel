<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RiseTechApps\ApiKey\Events\PlanUsageThresholdReached;
use RiseTechApps\ApiKey\Events\RequestLimitReached;
use RiseTechApps\ApiKey\Jobs\LogApiRequestJob;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use Symfony\Component\HttpFoundation\Response;

class CheckRequestLimitMiddleware
{
    /**
     * Marca uma requisição que não deve consumir cota.
     *
     * Usada pelas recusas do próprio pacote que acontecem DEPOIS da reserva —
     * origem não permitida e feature ausente. Sem ela, ser barrado custa uma
     * requisição do plano.
     */
    public const NOT_BILLABLE = '_quota_not_billable';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('_internal')) {
            return $next($request);
        }

        // Idempotência: garante que o controle de limite/log/contador rode uma única
        // vez por request, mesmo quando este middleware é aplicado em mais de um ponto
        // (ex.: presente no grupo `plan` E delegado pelo middleware `feature`).
        if ($request->attributes->get('_request_limit_handled')) {
            return $next($request);
        }
        $request->attributes->set('_request_limit_handled', true);

        /** @var Authentication $user */
        $user = $request->user();

        /** @var UserPlan|null $activePlan */
        $activePlan = $this->resolveActivePlan($request, $user);

        $requestsLimit = $activePlan?->plan?->request_limit ?? 0;

        // Quota is claimed up front with a conditional atomic UPDATE, so the
        // decision and the increment are one operation the database serialises.
        //
        // Reading the counter and incrementing it after the response let every
        // concurrent request observe the same pre-increment value: N simultaneous
        // requests all passed the check and the quota was overrun by N-1.
        if ($activePlan && ! $this->reserveQuota($activePlan, $requestsLimit)) {
            $requestsMade = $requestsLimit;

            logglyWarning()->withContext([
                'user_id' => $user?->getKey(),
                'plan_id' => $activePlan->plan_id,
                'requests_limit' => $requestsLimit,
                'url' => $request->url(),
            ])->log('Request limit reached');

            // Dispatch event when request limit is reached.
            // O listener já deduplica com Cache::add (um e-mail por período), mas
            // sem este gate cada requisição bloqueada enfileiraria um job de
            // listener. Checar a mesma chave aqui evita esse enfileiramento no
            // caso comum (após o primeiro aviso). A dedup final continua no
            // listener — a chave espelha SendRequestLimitReachedNotification.
            if ($activePlan->plan && ! Cache::has('api-key:limit-notified:'.$activePlan->getKey())) {
                RequestLimitReached::dispatch(
                    $user,
                    $activePlan,
                    $activePlan->plan,
                    $requestsMade,
                    $requestsLimit
                );
            }

            // A requisição bloqueada entra no log, mas não consumiu cota: o
            // UPDATE condicional não alterou nenhuma linha.
            $this->logRequest($request, $user, $activePlan, 429);

            return response()->json(['error' => __('api-key::messages.request_limit_reached')], 429);
        }

        // Aviso de uso próximo do limite (ex.: 80%). Dispara em toda requisição
        // dentro da faixa [threshold%, 100%); o listener faz throttle para enviar
        // no máximo um e-mail por período do plano.
        if ($requestsLimit > 0 && $activePlan && $activePlan->plan) {
            $threshold = (int) config('api-key.request_limit.warning_threshold', 80);
            // Post-claim value: this request already counts towards the quota.
            $requestsMade = (int) $activePlan->requests_used;

            if ($threshold > 0 && $threshold < 100) {
                $warnAt = (int) ceil($requestsLimit * $threshold / 100);

                if ($requestsMade >= $warnAt && $requestsMade < $requestsLimit) {
                    logglyInfo()->withContext([
                        'user_id' => $user?->getKey(),
                        'plan_id' => $activePlan->plan_id,
                        'requests_used' => $requestsMade,
                        'requests_limit' => $requestsLimit,
                        'threshold' => $threshold,
                    ])->log('Usage threshold reached');

                    if (! Cache::has('api-key:usage-threshold-notified:'.$activePlan->getKey())) {
                        PlanUsageThresholdReached::dispatch(
                            $user,
                            $activePlan,
                            $activePlan->plan,
                            $requestsMade,
                            $requestsLimit,
                            $threshold
                        );
                    }
                }
            }
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // The slot was claimed before the request ran, so an exception on the
            // way through would otherwise leave it spent on a request that
            // produced nothing. Hand it back, then let the handler take over.
            $this->releaseQuota($activePlan);

            throw $e;
        }

        $status = $response->getStatusCode();

        // Server faults are not the caller's doing and must not be billed. Client
        // errors (4xx) stay charged on purpose: those come from the caller's own
        // malformed requests, and refunding them would make the quota trivially
        // bypassable by anyone willing to send garbage.
        //
        // A recusa do próprio pacote é o terceiro caso, e não é nenhum dos dois.
        // Este middleware reserva a cota antes de `api.key.origin` e do
        // `feature:` da rota, então uma recusa vinda deles já consumiu a vaga —
        // o cliente pagaria uma requisição para ouvir "não", e o que decide isso
        // é a ordem dos middlewares, não uma política. Elas se marcam como não
        // cobráveis e a vaga volta.
        if ($status >= 500 || $request->attributes->get(self::NOT_BILLABLE)) {
            $this->releaseQuota($activePlan);
        }

        // The counter was already incremented by reserveQuota(); only the log
        // entry is deferred.
        $this->logRequest($request, $user, $activePlan, $status);

        return $response;
    }

    /**
     * Claim one request from the plan's quota.
     *
     * A single conditional UPDATE: the row is incremented only while the stored
     * counter is still below the limit, and the affected-row count tells us
     * whether this request got a slot. Concurrent callers are serialised by the
     * database on the row lock, so exactly `limit` requests can ever succeed.
     *
     * A limit of 0 means unlimited: the counter still moves (the dashboard
     * reports usage for those plans too), it just never blocks.
     *
     * Returns false only when the quota is exhausted.
     */
    private function reserveQuota(UserPlan $activePlan, int $limit): bool
    {
        $query = UserPlan::whereKey($activePlan->getKey());

        if ($limit > 0) {
            $query->where('requests_used', '<', $limit);
        }

        $claimed = $query->increment('requests_used');

        if ($claimed > 0) {
            // Keep the in-memory model in step with the row for the threshold
            // check and for anything downstream reading the relation.
            $activePlan->requests_used = $activePlan->requests_used + 1;
            $activePlan->syncOriginal();
        }

        return $claimed > 0;
    }

    /**
     * Give a claimed slot back to the plan's quota.
     *
     * Counterpart to reserveQuota(), for requests that were billed up front and
     * then failed on the server side. The guard on `requests_used > 0` keeps the
     * counter from going negative if the period was reset while the request was
     * still in flight.
     *
     * Null plan means nothing was ever claimed (reserveQuota only runs for a
     * resolved plan), so there is nothing to return.
     */
    private function releaseQuota(?UserPlan $activePlan): void
    {
        if (! $activePlan) {
            return;
        }

        $released = UserPlan::whereKey($activePlan->getKey())
            ->where('requests_used', '>', 0)
            ->decrement('requests_used');

        if ($released > 0) {
            $activePlan->requests_used = max(0, (int) $activePlan->requests_used - 1);
            $activePlan->syncOriginal();
        }
    }

    /**
     * Record the request in the log.
     *
     * Two delivery modes:
     *   - Queue (`api-key.request_log.queue` set): a LogApiRequestJob is pushed
     *     so a queue worker absorbs the INSERT and the web worker is free for the
     *     next request. Preferred under load.
     *   - afterResponse (default): the write runs in this same process after the
     *     response is flushed. Off the response's critical path, but still on the
     *     web worker — kept as the zero-configuration default for setups without a
     *     queue worker.
     */
    private function logRequest(Request $request, ?Authentication $user, ?UserPlan $activePlan, int $status): void
    {
        if (! $user) {
            return;
        }

        $planId = $activePlan?->getKey();
        $path = $request->path();
        $method = $request->method();

        $queue = config('api-key.request_log.queue');

        if ($queue !== null && $queue !== '') {
            try {
                $job = new LogApiRequestJob((string) $user->getKey(), $planId, $path, $method, $status)
                    ->onQueue($queue);

                // Força a conexão (por padrão redis) para o Horizon processar o job.
                // Sem isto ele usaria o QUEUE_CONNECTION default (database) e ficaria
                // fora do alcance do Horizon.
                $connection = config('api-key.request_log.connection');
                if ($connection !== null && $connection !== '') {
                    $job->onConnection($connection);
                }

                // dispatch() sem capturar: o PendingDispatch é destruído ainda
                // dentro deste statement (logo, dentro do try), então uma falha de
                // fila é capturada aqui em vez de estourar no destrutor.
                dispatch($job);
            } catch (\Throwable $e) {
                // O log é best-effort. A resposta da API já foi gerada; uma
                // indisponibilidade da fila (ex.: redis fora) não pode transformar
                // uma requisição bem-sucedida em 500. Registra e segue.
                report($e);
            }

            return;
        }

        dispatch(function () use ($user, $status, $planId, $path, $method) {
            $user->requestUsed($status, false, $planId, $path, $method);
        })->afterResponse();
    }

    /**
     * The active plan, preferring the instance CheckActivePlanMiddleware already
     * resolved. That one includes the grace period, so the stricter "not expired"
     * condition of activePlan() is reapplied here in memory rather than as a
     * second query. Falls back to querying when this middleware runs on its own
     * (for example when reached through CheckPlanFeatureMiddleware).
     */
    private function resolveActivePlan(Request $request, ?Authentication $user): ?UserPlan
    {
        if ($request->attributes->has('user_plan')) {
            $plan = $request->attributes->get('user_plan');

            return $plan && ! $plan->isExpired() ? $plan : null;
        }

        return $user?->activePlan()->with(['plan'])->first();
    }
}
