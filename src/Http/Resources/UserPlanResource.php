<?php

namespace RiseTechApps\ApiKey\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserPlanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'authentication_id' => $this->authentication_id,
            'active' => $this->active,
            'status' => $this->getStatus(),
            'grace_period' => [
                'in_grace_period' => $this->isInGracePeriod(),
                'remaining_days' => $this->getGracePeriodRemainingDays(),
                'grace_period_end_date' => $this->when($this->isInGracePeriod(), fn() => $this->getGracePeriodEndDate()?->toIso8601String()),
            ],
            'requests' => [
                'used' => $this->requests_used,
                'limit' => $this->whenLoaded('plan', fn() => $this->plan?->request_limit),
                'remaining' => $this->whenLoaded('plan', fn() => $this->plan?->request_limit ? max(0, $this->plan->request_limit - $this->requests_used) : null),
            ],
            'dates' => [
                'start_date' => $this->start_date?->toIso8601String(),
                'end_date' => $this->end_date?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ],
            // Include plan details when loaded
            'plan' => $this->whenLoaded('plan', fn() => [
                'id' => $this->plan->getKey(),
                'code' => $this->plan->code,
                'name' => $this->plan->name,
                'description' => $this->plan->description,
                'request_limit' => $this->plan->request_limit,
                'billing_cycle' => $this->plan->billing_cycle?->value,
                'price' => $this->plan->price,
                'formatted_price' => $this->plan->formatted_price,
                'features' => $this->plan->features,
            ]),
        ];
    }

    /**
     * Get the status text for the plan.
     */
    private function getStatus(): string
    {
        if ($this->isActive()) {
            return 'active';
        }

        if ($this->isInGracePeriod()) {
            return 'grace_period';
        }

        if ($this->isCompletelyExpired()) {
            return 'expired';
        }

        return 'inactive';
    }
}
