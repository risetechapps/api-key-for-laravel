<?php

namespace RiseTechApps\ApiKey\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RiseTechApps\ApiKey\Models\UserPlan;

class DeactivateExpiredPlansCommand extends Command
{
    protected $signature = 'plans:deactivate-expired';

    protected $description = 'Deactivate all users with expired plans';

    public function handle(): void
    {
        $this->info('🔍 Looking for expired shots...');

        $expiredPlans = UserPlan::where('end_date', '<', now())->where('active', true)->get();

        if ($expiredPlans->isEmpty()) {
            $this->info('✅ No overdue plans found.');
            return;
        }

        DB::transaction(function () use ($expiredPlans) {
            foreach ($expiredPlans as $plan) {
                $plan->update(['active' => false]);
                $this->line("❌ Disabled plan for user ID: {$plan->authentication_id}");
            }
        });

        $this->info('🚀 All expired plans have been successfully deactivated!');
    }
}
