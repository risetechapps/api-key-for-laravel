<?php

namespace RiseTechApps\ApiKey\Models;

use Illuminate\Database\Eloquent\Model;
use RiseTechApps\HasUuid\Traits\HasUuid;
use RiseTechApps\ToUpper\Traits\HasToUpper;

class Coupon extends Model
{
    use HasUuid, HasToUpper;

    protected $fillable = [
        'code',
        'type',
        'value',
        'max_uses',
        'uses',
        'expires_at',
        'is_active',
        'gateway_coupon_id',
    ];

    protected $casts = [
        'expires_at' => 'date',
        'is_active' => 'boolean',
        'value' => 'float',
        'max_uses' => 'integer',
        'uses' => 'integer',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    protected array $no_upper = ['type'];
    /**
     * Checks if the coupon is valid for use.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->max_uses !== null && $this->uses >= $this->max_uses) {
            return false;
        }

        return true;
    }

    /**
     * Returns the Coupon ID for the Gateway (Stripe, Pagar.me, etc.).
     *
     * @return string
     */
    public function getGatewayCouponId(): string
    {
        // Prioriza o ID do Gateway, se existir, senão usa o código interno.
        return $this->gateway_coupon_id ?? $this->code;
    }
}
