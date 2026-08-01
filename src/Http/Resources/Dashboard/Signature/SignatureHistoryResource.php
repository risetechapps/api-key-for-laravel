<?php

namespace RiseTechApps\ApiKey\Http\Resources\Dashboard\Signature;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Plans\PlansResource;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

/**
 * @mixin UserPlan
 */
class SignatureHistoryResource extends JsonResource
{
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'status' => [
                'active' => $this->active,
                'label' => $this->getStatusLabel(),
            ],
            'requests' => [
                'used' => $this->requests_used,
                'limit' => $this->whenLoaded('plan', fn () => $this->plan?->request_limit),
            ],
            // O que foi efetivamente cobrado, que não é o preço de tabela do plano:
            // cupom e crédito de troca entram aqui. O histórico somava raw_price e
            // exibia um total que ninguém pagou.
            'payment' => [
                'amount' => $this->payment_amount !== null ? (float) $this->payment_amount : null,
                'credit_applied' => $this->credit_applied !== null ? (float) $this->credit_applied : null,
                'payment_id' => $this->payment_id,
            ],
            'cancellation' => [
                'cancelled' => $this->isCancelled(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            ],
            'dates' => [
                'start_date' => $this->start_date?->toIso8601String(),
                'end_date' => $this->end_date?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ],
            'plan' => $this->whenLoaded('plan', fn () => PlansResource::make($this->plan)),
        ];
    }

    /**
     * Get status label based on plan state.
     */
    private function getStatusLabel(): string
    {
        if ($this->active && $this->isActive()) {
            return 'active';
        }

        if ($this->isInGracePeriod()) {
            return 'grace_period';
        }

        if ($this->isCompletelyExpired()) {
            return 'expired';
        }

        return 'cancelled';
    }
}
