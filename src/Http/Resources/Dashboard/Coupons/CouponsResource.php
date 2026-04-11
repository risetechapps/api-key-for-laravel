<?php

namespace RiseTechApps\ApiKey\Http\Resources\Dashboard\Coupons;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'code' => $this->code,
            'type' => $this->type,
            'value' => $this->value,
            'usage' => [
                'current' => $this->uses,
                'max' => $this->max_uses,
                'remaining' => $this->max_uses ? max(0, $this->max_uses - $this->uses) : null,
                'is_valid' => $this->isValid(),
            ],
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_active' => $this->is_active,
            'gateway_coupon_id' => $this->gateway_coupon_id,
            'dates' => [
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ],
        ];
    }
}
