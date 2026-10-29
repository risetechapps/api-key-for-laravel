<?php

namespace RiseTechApps\ApiKey\Console\Commands\Billing;

use Illuminate\Console\Command;
use RiseTechApps\ApiKey\Console\Concerns\WarnsDeprecatedAlias;
use RiseTechApps\ApiKey\Jobs\ProcessPlanRenewalJob;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

class ProcessRenewalsCommand extends Command
{
    use WarnsDeprecatedAlias;

    protected $signature = 'api-key:billing-process-renewals
                            {--dry-run : List due plans without charging}
                            {--sync : Charge inline instead of dispatching to the queue}';

    protected $description = 'Process automatic plan renewals for subscriptions expiring today';

    /** Nome até a 1.0; sai na próxima versão maior. */
    protected $aliases = ['billing:process-renewals'];

    /**
     * Dispatches one job per due subscription.
     *
     * The command itself only reads ids, in chunks — it never holds the full set
     * of due plans in memory, and it does not block on Mercado Pago. The actual
     * charging happens in ProcessPlanRenewalJob, which queue workers run in
     * parallel; previously every charge was serial inside this command, so the
     * run time grew linearly with the subscriber base.
     */
    public function handle(): int
    {
        $this->warnIfDeprecatedAlias();

        $queue = config('api-key.billing.queue');
        $dryRun = $this->option('dry-run');
        $sync = $this->option('sync');

        $dispatched = 0;

        UserPlan::where('active', true)
            // Cancelada: o período pago segue até o end_date, mas não há próxima
            // cobrança. Filtrar aqui evita enfileirar o job só para ele desistir.
            ->whereNull('cancelled_at')
            ->whereNull('renewal_failed_at')
            // A primeira cobrança no dia do vencimento, ou uma nova tentativa
            // de renovação recusada que já chegou.
            ->where(function ($q) {
                $q->where(fn ($q) => $q
                    ->where('renewal_attempts', 0)
                    ->whereBetween('end_date', [today()->startOfDay(), today()->endOfDay()]))
                    ->orWhere('next_renewal_attempt_at', '<=', now());
            })
            ->with(['authentication:id,email', 'plan:id,name'])
            ->chunkById(200, function ($plans) use ($dryRun, $sync, $queue, &$dispatched) {
                foreach ($plans as $userPlan) {
                    if ($dryRun) {
                        $this->line("  - {$userPlan->authentication?->email} → {$userPlan->plan?->name}");
                        $dispatched++;

                        continue;
                    }

                    $job = new ProcessPlanRenewalJob($userPlan->getKey());

                    $sync
                        ? dispatch_sync($job)
                        : dispatch($queue ? $job->onQueue($queue) : $job);

                    $dispatched++;
                }
            });

        if ($dispatched === 0) {
            $this->info('No renewals due today.');

            return Command::SUCCESS;
        }

        $this->info(match (true) {
            $dryRun => "{$dispatched} renewal(s) due today (dry run, nothing charged).",
            $sync => "Processed {$dispatched} renewal(s) inline.",
            default => "Queued {$dispatched} renewal(s) for processing.",
        });

        return Command::SUCCESS;
    }
}
