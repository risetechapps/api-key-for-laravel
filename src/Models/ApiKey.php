<?php

namespace RiseTechApps\ApiKey\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RiseTechApps\CodeGenerate\Traits\HasCodeGenerate;
use RiseTechApps\HasUuid\Traits\HasUuid;
use RiseTechApps\ToUpper\Traits\HasToUpper;

class ApiKey extends Model
{
    use HasUuid, HasCodeGenerate, HasToUpper;

    protected $fillable = [
        'code',
        'key',
        'expires_at',
        'active',
        'allowed_origins'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'active' => 'boolean',
        'allowed_origins' => 'array',
    ];

    public static function validateKey($key)
    {
        return self::where('key', $key)
            ->where('active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    public function isOriginAllowed(string $origin): bool
    {
        $allowed = $this->allowed_origins;

        if (empty($allowed)) {
            return true;
        }

        $normalizedOrigin = strtolower(parse_url($origin, PHP_URL_HOST) ?? $origin);

        foreach ($allowed as $allowedOrigin) {
            if (strtolower($allowedOrigin) === $normalizedOrigin) {
                return true;
            }

            if (str_ends_with($allowedOrigin, '*') && str_starts_with($normalizedOrigin, rtrim($allowedOrigin, '*'))) {
                return true;
            }
        }

        return false;
    }

    public function authentication(): BelongsTo
    {
        return $this->belongsTo(Authentication::class);
    }
}
