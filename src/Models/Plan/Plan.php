<?php

namespace RiseTechApps\ApiKey\Models\Plan;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RiseTechApps\ApiKey\Enums\BillingCycle;
use RiseTechApps\CodeGenerate\Traits\HasCodeGenerate;
use RiseTechApps\HasUuid\Traits\HasUuid;
use RiseTechApps\ToUpper\Traits\HasToUpper;

/**
 * @property string $id
 * @property string|null $code
 * @property string $name
 * @property string|null $description
 * @property int $request_limit
 * @property BillingCycle|null $billing_cycle
 * @property float $price
 * @property bool $is_active
 * @property array $features
 * @property int $trial_days
 * @property string|null $overage_price
 * @property int|null $overage_max_requests
 * @property int|null $rate_limit_per_minute
 * @property-read string $formatted_price
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Plan extends Model
{
    use HasCodeGenerate, HasFactory, HasToUpper, HasUuid;

    protected $fillable = [
        'code',
        'name',
        'description',
        'request_limit',
        'billing_cycle',
        'price',
        'is_active',
        'features',
        'trial_days',
        'overage_price',
        'overage_max_requests',
        'rate_limit_per_minute',
    ];

    protected $casts = [
        'request_limit' => 'integer',
        'is_active' => 'boolean',
        'trial_days' => 'integer',
        'overage_price' => 'decimal:4',
        'overage_max_requests' => 'integer',
        'rate_limit_per_minute' => 'integer',
        'billing_cycle' => BillingCycle::class,
    ];

    /**
     * features como array, sempre. O cast 'array' devolvia null quando a coluna
     * era null; este accessor normaliza para [] (contrato consistente para a
     * Resource/FeatureResolver e para os consumidores da API).
     */
    protected function features(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_array($value) ? $value : (json_decode($value ?? '[]', true) ?: []),
            set: fn ($value) => json_encode($value ?? []),
        );
    }

    protected $hidden = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected array $no_upper = ['billing_cycle'];

    /** Lista pública de planos ativos, em cache no PlansController::index(). */
    public const ACTIVE_CACHE_KEY = 'api_key:plans:active';

    /**
     * Qualquer gravação invalida a lista pública: seeder, tinker, código do app
     * ou o painel. Antes só o controller limpava, e um plano criado por outro
     * caminho ficava até 10 minutos fora da vitrine.
     */
    #[\Override]
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::ACTIVE_CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::ACTIVE_CACHE_KEY));
    }

    /**
     * Verifica se o plano tem limite de requisições.
     */
    public function hasRequestLimit(): bool
    {
        return $this->request_limit > 0;
    }

    /**
     * Retorna o preço formatado.
     */
    public function getFormattedPriceAttribute(): string
    {
        return 'R$ '.number_format($this->price, 2, ',', '.');
    }
}
