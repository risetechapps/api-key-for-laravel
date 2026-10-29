<?php

namespace RiseTechApps\ApiKey\Console\Commands\Billing;

use Illuminate\Console\Command;
use RiseTechApps\ApiKey\Console\Concerns\WarnsDeprecatedAlias;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Services\OverageBillingService;
use Throwable;

class ChargeOverageCommand extends Command
{
    use WarnsDeprecatedAlias;

    protected $signature = 'api-key:billing-charge-overage {--dry-run : List what would be charged without charging}';

    /** Nome até a 1.0; sai na próxima versão maior. */
    protected $aliases = ['billing:charge-overage'];

    protected $description = 'Charge requests over the plan limit for periods that have ended';

    /**
     * Períodos encerrados de planos com preço de excedente, ainda não
     * faturados. O próprio faturamento grava `overage_billed_at` antes de
     * cobrar, então rodar de novo não cobra duas vezes.
     */
    public function handle(OverageBillingService $service): int
    {
        $this->warnIfDeprecatedAlias();

        $count = 0;

        UserPlan::where('end_date', '<', now())
            ->whereNull('overage_billed_at')
            ->whereNull('refunded_at')
            ->whereHas('plan', fn ($q) => $q->whereNotNull('overage_price')->where('request_limit', '>', 0))
            ->whereColumn('requests_used', '>', 'plans.request_limit')
            ->join('plans', 'plans.id', '=', 'user_plans.plan_id')
            ->select('user_plans.*')
            ->with(['plan', 'authentication'])
            ->chunkById(100, function ($plans) use ($service, &$count) {
                foreach ($plans as $userPlan) {
                    if ($this->option('dry-run')) {
                        $this->line(sprintf(
                            '  - %s: %d requests, R$ %.2f',
                            $userPlan->authentication?->email,
                            OverageBillingService::overageRequests($userPlan),
                            OverageBillingService::overageAmount($userPlan),
                        ));
                        $count++;

                        continue;
                    }

                    try {
                        $outcome = $service->bill($userPlan);
                        $this->line("  - {$userPlan->getKey()}: {$outcome}");
                        $count++;
                    } catch (Throwable $e) {
                        report($e);
                        $this->error("  - {$userPlan->getKey()}: {$e->getMessage()}");
                    }
                }
            }, 'user_plans.id', 'id');

        $this->info($count === 0 ? 'No overage to charge.' : "Processed {$count} overage charge(s).");

        return self::SUCCESS;
    }
}
