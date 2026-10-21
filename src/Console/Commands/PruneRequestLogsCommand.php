<?php

namespace RiseTechApps\ApiKey\Console\Commands;

use Illuminate\Console\Command;
use RiseTechApps\ApiKey\Models\RequestLog\RequestLog;

/**
 * Deletes request log entries older than the retention window.
 *
 * `request_logs` grows by one row per authenticated API request and nothing ever
 * removed them, so the table only got larger while being read on every dashboard
 * visit. Retention is configured through `api-key.request_log.retention_days`.
 */
class PruneRequestLogsCommand extends Command
{
    protected $signature = 'api-key:prune-logs
                            {--days= : Override the configured retention window}
                            {--dry-run : Report how many rows would be deleted}';

    protected $description = 'Delete request log entries older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('api-key.request_log.retention_days', 90));

        if ($days <= 0) {
            $this->info('Retention is disabled (days = 0); nothing to prune.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $query = RequestLog::where('requested_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info("{$query->count()} row(s) older than {$cutoff->toDateTimeString()} would be deleted.");

            return self::SUCCESS;
        }

        // Deleted in bounded batches: a single DELETE over months of history
        // holds one long transaction and can block writes on the hot path that
        // is still inserting into this table.
        $chunk = max(100, (int) config('api-key.request_log.prune_chunk', 5000));
        $deleted = 0;

        // Selecting ids first, then deleting by key: PostgreSQL has no
        // DELETE ... LIMIT, so the batching has to happen in the SELECT.
        do {
            $ids = RequestLog::where('requested_at', '<', $cutoff)
                ->orderBy('requested_at')
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += RequestLog::whereIn('id', $ids)->delete();
        } while (true);

        $this->info("Pruned {$deleted} request log row(s) older than {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
