<?php

namespace RiseTechApps\ApiKey\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RiseTechApps\HasUuid\Traits\HasUuid\HasUuid;

class Plan extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'name',
        'request_limit',
        'duration_days',
        'price',
        'visible'
    ];

    protected $hidden = [
        'id',
        'created_at',
        'updated_at'
    ];

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'plan_module');
    }
}
