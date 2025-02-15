<?php

namespace RiseTechApps\ApiKey\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RiseTechApps\HasUuid\Traits\HasUuid\HasUuid;

class ApiKey extends Model
{
    use HasUuid;

    protected $fillable = [
        'key',
        'expires_at',
        'active'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'active' => 'boolean',
    ];

    public static function validateKey($key)
    {
        return self::where('key', $key)->first();
    }

    public function authentication(): BelongsTo
    {
        return $this->belongsTo(Authentication::class);
    }
}
