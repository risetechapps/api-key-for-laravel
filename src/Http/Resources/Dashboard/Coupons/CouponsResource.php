<?php

namespace RiseTechApps\ApiKey\Http\Resources\Dashboard\Coupons;

use Carbon\Carbon;
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
            'max_uses' => $this->max_uses,
            'uses' => $this->uses,
            'expires_at' => !is_null($this->expires_at) ? Carbon::parse($this->expires_at)->format('Y-m-d') : null,
            'is_active' => $this->is_active,
            'gateway_coupon_id' => $this->gateway_coupon_id,
        ];
    }
}
