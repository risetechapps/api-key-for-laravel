<?php

namespace RiseTechApps\ApiKey\Models\Plan;

use Illuminate\Database\Eloquent\Model;
use RiseTechApps\ApiKey\Enums\BillingCycle;
use RiseTechApps\CodeGenerate\Traits\HasCodeGenerate;
use RiseTechApps\HasUuid\Traits\HasUuid;
use RiseTechApps\ToUpper\Traits\HasToUpper;

class Plan extends Model
{
    use HasUuid, HasCodeGenerate, HasToUpper;

    protected $fillable = [
        'code',
        'name',
        'description',
        'request_limit',
        'billing_cycle',
        'price',
        'is_active',
        'features',
    ];

    protected $casts = [
        'request_limit' => 'integer',
        'is_active' => 'boolean',
        'billing_cycle' => BillingCycle::class,
        'features' => 'array',
    ];

    protected $hidden = [
        'id',
        'created_at',
        'updated_at'
    ];

    protected array $no_upper   = ['billing_cycle'];

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
        return 'R$ ' . number_format($this->price, 2, ',', '.');
    }
}
