<?php

namespace RiseTechApps\ApiKey\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Services\MpCustomerService;
use RiseTechApps\ApiKey\Traits\SendsIdempotentPayments;

/**
 * Charges the renewal of a single subscription.
 *
 * One plan per job so the daily billing run fans out across queue workers.
 * Running them inline meant two sequential Mercado Pago round-trips per
 * subscriber inside one command — at a few thousand subscribers the run no
 * longer fit in a day, and withoutOverlapping() would then skip the next one.
 */
class ProcessPlanRenewalJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SendsIdempotentPayments, SerializesModels;

    /**
     * Payments are never retried automatically: a timeout after the charge was
     * accepted upstream would double-bill. Failures are logged for manual review.
     */
    public int $tries = 1;

    public function __construct(public readonly string $userPlanId)
    {
    }

    /**
     * Guards against the same subscription being charged twice if the command is
     * run more than once in a day.
     */
    public function uniqueId(): string
    {
        return $this->userPlanId;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(MpCustomerService $mpService): void
    {
        $userPlan = UserPlan::with(['authentication', 'plan'])->find($this->userPlanId);

        if (!$userPlan || !$userPlan->active) {
            return;
        }

        // Re-checked here and not only in the command that dispatched it: the job
        // can sit in the queue while the subscriber cancels, and charging a card
        // after someone asked you to stop is the worst failure mode this has.
        if ($userPlan->isCancelled()) {
            Log::info('billing renewal skipped: subscription cancelled', [
                'user_plan_id' => $this->userPlanId,
                'cancelled_at' => $userPlan->cancelled_at?->toIso8601String(),
            ]);

            return;
        }

        $user = $userPlan->authentication;
        $plan = $userPlan->plan;

        if (!$user || !$plan) {
            Log::warning('billing renewal skipped: missing user or plan', [
                'user_plan_id' => $this->userPlanId,
            ]);
            return;
        }

        $card = UserCard::where('authentication_id', $user->getKey())
            ->where('is_default', true)
            ->whereNotNull('mp_card_id')
            ->first();

        if (!$card) {
            Log::warning('billing renewal skipped: no default card', [
                'user_id'      => $user->getKey(),
                'user_plan_id' => $this->userPlanId,
            ]);
            return;
        }

        MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));

        $token  = $mpService->tokenizeRecurring($card->mp_customer_id, $card->mp_card_id);
        $amount = (float) $plan->price;

        $payment = new PaymentClient()->create([
            'transaction_amount' => $amount,
            'token'              => $token,
            'installments'       => 1,
            'payment_method_id'  => $card->brand,
            'payer'              => [
                'id'    => $card->mp_customer_id,
                'email' => strtolower((string) $user->email),
            ],
            'description'          => __('api-key::messages.plan_renewal_description', ['plan' => $plan->name]),
            'external_reference'   => "renewal|{$user->getKey()}|{$plan->getKey()}|{$userPlan->getKey()}",
            'statement_descriptor' => mb_substr((string) config('app.name') ?: 'Assinatura', 0, 22),
            'additional_info'      => [
                'items' => [
                    [
                        'id'          => (string) $plan->getKey(),
                        'title'       => __('api-key::messages.plan_renewal_description', ['plan' => $plan->name]),
                        'description' => $plan->description ?? __('api-key::messages.plan_renewal_description', ['plan' => $plan->name]),
                        'category_id' => 'services',
                        'quantity'    => 1,
                        'unit_price'  => $amount,
                    ],
                ],
            ],
        ], $this->idempotentRequest(null, [
            // ShouldBeUnique stops the same job running twice at once, and
            // tries = 1 stops a retry after a timeout. Neither covers the gap
            // where the charge is accepted, the write that records it fails, and
            // uniqueFor (1h) then lets a later run dispatch the same renewal
            // again. The gateway settles that one.
            //
            // Keyed on the period being renewed, never on the subscription alone:
            // next month's renewal is a different charge and must go through.
            'renewal',
            $this->userPlanId,
            $userPlan->end_date?->toDateString(),
        ]));

        if ($payment->status === 'approved') {
            $newPlan = $user->subscribeToPlan($plan);
            $newPlan->update([
                'payment_id'     => (string) $payment->id,
                'payment_amount' => $amount,
            ]);

            Log::info('billing renewal approved', [
                'user_id'    => $user->getKey(),
                'plan'       => $plan->name,
                'payment_id' => $payment->id,
            ]);

            return;
        }

        Log::warning('billing renewal not approved', [
            'user_id'       => $user->getKey(),
            'user_plan_id'  => $this->userPlanId,
            'status'        => $payment->status,
            'status_detail' => $payment->status_detail,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('billing renewal failed', [
            'user_plan_id' => $this->userPlanId,
            'error'        => $e->getMessage(),
        ]);
    }
}
