<?php

namespace RiseTechApps\ApiKey\Models\Coupon;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RiseTechApps\HasUuid\Traits\HasUuid;
use RiseTechApps\ToUpper\Traits\HasToUpper;

/**
 * @property string $id
 * @property string $code
 * @property string $type 'percentage' ou 'fixed'
 * @property float $value
 * @property int|null $max_uses null = ilimitado
 * @property int $uses
 * @property Carbon|null $expires_at
 * @property bool $is_active
 * @property string|null $gateway_coupon_id
 */
class Coupon extends Model
{
    use HasFactory, HasToUpper, HasUuid;

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

    // type é vocabulário controlado ('percentage'/'fixed') e gateway_coupon_id é
    // um identificador externo (Stripe/Mercado Pago) case-sensitive — nenhum dos
    // dois pode ser normalizado para maiúsculo pelo HasToUpper.
    protected array $no_upper = ['type', 'gateway_coupon_id'];

    /**
     * Checks if the coupon is valid for use.
     */
    public function isValid(): bool
    {
        if (! $this->is_active) {
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
     */
    public function getGatewayCouponId(): string
    {
        // Prioriza o ID do Gateway, se existir, senão usa o código interno.
        return $this->gateway_coupon_id ?? $this->code;
    }
}
