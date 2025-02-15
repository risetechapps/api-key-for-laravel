<?php

namespace RiseTechApps\ApiKey\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RiseTechApps\ApiKey\Models\UserPlan;

class DeactivateExpiredPlansCommand extends Command
{
    protected $signature = 'plans:deactivate-expired';

    protected $description = 'Command description';

    public function handle(): void
    {
        $this->info('🔍 Buscando planos vencidos...');

        $expiredPlans = UserPlan::where('end_date', '<', now())->where('active', true)->get();

        if ($expiredPlans->isEmpty()) {
            $this->info('✅ Nenhum plano vencido encontrado.');
            return;
        }

        DB::transaction(function () use ($expiredPlans) {
            foreach ($expiredPlans as $plan) {
                $plan->update(['active' => false]);
                $this->line("❌ Plano desativado para usuário ID: {$plan->authentication_id}");
            }
        });

        $this->info('🚀 Todos os planos vencidos foram desativados com sucesso!');
    }
}
