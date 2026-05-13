<?php

namespace RiseTechApps\ApiKey\Console\Commands\Billing;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Services\MpCustomerService;

class ProcessRenewalsCommand extends Command
{
    protected $signature = 'billing:process-renewals
                            {--dry-run : List due plans without charging}';

    protected $description = 'Process automatic plan renewals for subscriptions expiring today';

    public function handle(MpCustomerService $mpService): int
    {
        $duePlans = UserPlan::where('active', true)
            ->whereDate('end_date', today())
            ->with(['authentication', 'plan'])
            ->get();

        if ($duePlans->isEmpty()) {
            $this->info('No renewals due today.');
            return Command::SUCCESS;
        }

        $this->info("Found {$duePlans->count()} plan(s) expiring today.");

        if ($this->option('dry-run')) {
            foreach ($duePlans as $userPlan) {
                $this->line("  - {$userPlan->authentication->email} → {$userPlan->plan->name}");
            }
            return Command::SUCCESS;
        }

        MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));
        $paymentClient = new PaymentClient();

        $succeeded = 0;
        $failed    = 0;

        foreach ($duePlans as $userPlan) {
            try {
                $this->processRenewal($userPlan, $mpService, $paymentClient)
                    ? $succeeded++
                    : $failed++;
            } catch (\Exception $e) {
                $failed++;
                Log::error('billing:process-renewals unexpected error', [
                    'user_plan_id' => $userPlan->getKey(),
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        $this->info("Done. Succeeded: {$succeeded} | Failed: {$failed}");

        return Command::SUCCESS;
    }

    private function processRenewal(UserPlan $userPlan, MpCustomerService $mpService, PaymentClient $paymentClient): bool
    {
        $user = $userPlan->authentication;
        $plan = $userPlan->plan;

        $card = UserCard::where('authentication_id', $user->getKey())
            ->where('is_default', true)
            ->whereNotNull('mp_card_id')
            ->first();

        if (! $card) {
            $this->warn("  [{$user->email}] No default card with mp_card_id — skipping.");
            Log::warning('billing:process-renewals no default card', [
                'user_id'      => $user->getKey(),
                'user_plan_id' => $userPlan->getKey(),
            ]);
            return false;
        }

        $token  = $mpService->tokenizeRecurring($card->mp_customer_id, $card->mp_card_id);
        $amount = (float) $plan->price;

        $payment = $paymentClient->create([
            'transaction_amount' => $amount,
            'token'              => $token,
            'installments'       => 1,
            'payment_method_id'  => $card->brand,
            'payer'              => [
                'id'    => $card->mp_customer_id,
                'email' => strtolower($user->email),
            ],
            'description'        => "Renovação do plano {$plan->name}",
            'external_reference'   => "renewal|{$user->getKey()}|{$plan->getKey()}|{$userPlan->getKey()}",
            'statement_descriptor' => mb_substr(config('app.name'), 0, 22),
            'additional_info'      => [
                'items' => [
                    [
                        'id'          => (string) $plan->getKey(),
                        'title'       => "Renovação do plano {$plan->name}",
                        'description' => $plan->description ?? "Renovação do plano {$plan->name}",
                        'category_id' => 'services',
                        'quantity'    => 1,
                        'unit_price'  => $amount,
                    ],
                ],
            ],
        ]);

        if ($payment->status === 'approved') {
            $newPlan = $user->subscribeToPlan($plan);
            $newPlan->update(['payment_id' => (string) $payment->id]);

            $this->info("  [{$user->email}] Renewed → {$plan->name} (payment {$payment->id})");
            Log::info('billing:process-renewals approved', [
                'user_id'    => $user->getKey(),
                'plan'       => $plan->name,
                'payment_id' => $payment->id,
            ]);
            return true;
        }

        $this->error("  [{$user->email}] Payment {$payment->status} ({$payment->status_detail})");
        Log::warning('billing:process-renewals payment not approved', [
            'user_id'       => $user->getKey(),
            'user_plan_id'  => $userPlan->getKey(),
            'status'        => $payment->status,
            'status_detail' => $payment->status_detail,
        ]);

        return false;
    }
}
