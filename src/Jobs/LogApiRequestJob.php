<?php

namespace RiseTechApps\ApiKey\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RiseTechApps\ApiKey\Models\RequestLog\RequestLog;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

/**
 * Records one request in the log off the web worker.
 *
 * `CheckRequestLimitMiddleware` used to insert the log entry with a
 * dispatch(...)->afterResponse() closure: off the response's critical path, but
 * still inside the web worker, which then had to finish the INSERT before
 * accepting the next request. On a busy key that halves throughput and doubles
 * the write load on `request_logs`.
 *
 * When a queue is configured (`api-key.request_log.queue`) the middleware
 * dispatches this job instead, so a queue worker absorbs the write. Only scalar
 * ids are carried — never the Eloquent models — to keep the payload small.
 *
 * The quota was already claimed atomically in the middleware, so this job never
 * increments usage; it only writes the log row.
 */
class LogApiRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $authenticationId,
        public readonly ?string $planId,
        public readonly string $endpoint,
        public readonly string $method,
        public readonly int $status,
    ) {}

    public function handle(): void
    {
        // Mesmo contrato do Authentication::requestUsed(): só registra quando há
        // um plano ativo. O middleware já resolve o plano e passa o id; o fallback
        // cobre o raro caso de o job ser despachado sem ele.
        $planId = $this->planId ?? UserPlan::where('authentication_id', $this->authenticationId)
            ->where('active', true)
            ->where('end_date', '>=', now())
            ->value('id');

        if (! $planId) {
            return;
        }

        RequestLog::create([
            'authentication_id' => $this->authenticationId,
            'endpoint' => $this->endpoint,
            'requested_at' => now(),
            'method' => $this->method,
            'response_code' => $this->status,
        ]);
    }
}
