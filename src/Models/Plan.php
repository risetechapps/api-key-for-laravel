<?php

namespace RiseTechApps\ApiKey\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
    ];

    protected $casts = [
        'request_limit' => 'integer',
        'is_active' => 'boolean',
        'billing_cycle' => BillingCycle::class,
    ];

    protected $hidden = [
        'id',
        'created_at',
        'updated_at'
    ];

    protected array $no_upper   = ['billing_cycle'];

    /**
     * Os módulos que este plano possui.
     */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'plan_module');
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
        return 'R$ ' . number_format($this->price, 2, ',', '.');
    }
}
