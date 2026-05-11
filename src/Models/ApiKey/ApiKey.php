<?php

namespace RiseTechApps\ApiKey\Models\ApiKey;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

use RiseTechApps\ApiKey\Events\ApiKeyCreated;
use RiseTechApps\ApiKey\Events\ApiKeyStatusChanged;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\CodeGenerate\Traits\HasCodeGenerate;
use RiseTechApps\HasUuid\Traits\HasUuid;
use RiseTechApps\ToUpper\Traits\HasToUpper;

class ApiKey extends Model
{
    use HasUuid, HasCodeGenerate, HasToUpper;

    /**
     * The plain key value (only set during creation, not stored in DB).
     * This is returned to the user once after creation.
     */
    public ?string $plainKey = null;

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

    /**
     * Boot the model and hash the key before saving.
     * Also clears cache when key is updated.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (!empty($model->key)) {
                // Store plain key temporarily
                $model->plainKey = $model->key;
                // Hash the key for storage
                $model->key = Hash::make($model->key);
            }
        });

        static::created(function ($model) {
            // Fire event after key is created
            if ($model->authentication) {
                ApiKeyCreated::dispatch(
                    $model->authentication,
                    $model,
                    $model->plainKey
                );
            }
        });

        static::updated(function ($model) {
            // Clear any cache for this API key when it's updated
            // (e.g., when deactivated or expiration changed)
            self::clearValidationCache($model->id);

            // Clear origin cache if allowed_origins changed
            if ($model->wasChanged('allowed_origins')) {
                self::clearOriginCache($model->id);
            }

            // Fire event when active status changes
            if ($model->wasChanged('active') && $model->authentication) {
                ApiKeyStatusChanged::dispatch(
                    $model->authentication,
                    $model,
                    $model->getOriginal('active'),
                    $model->active
                );
            }
        });

        static::deleted(function ($model) {
            // Clear cache when key is deleted
            self::clearValidationCache($model->id);
        });
    }

    /**
     * Clear all cache entries related to a specific API key ID.
     * Note: This clears cache by ID pattern, individual key hashes cannot be cleared
     * as we don't store the plain keys in cache (only their IDs).
     */
    public static function clearValidationCache(string|int|null $keyId = null): void
    {
        // Note: We can't clear by key hash because we don't store the original key
        // The cache entries will naturally expire after 5 minutes
        // In production, use a proper cache driver like Redis for better control

        if ($keyId && Cache::getStore() instanceof \Illuminate\Cache\TaggableStore) {
            Cache::tags(['api_key_' . $keyId])->flush();
        }
    }

    /**
     * Clear origin validation cache for a specific API key.
     * Called when allowed_origins is modified.
     */
    public static function clearOriginCache(int|string $keyId): void
    {
        // Note: Since we use md5 hash in cache keys, we can't selectively clear
        // individual origin entries. They will expire naturally after 60 seconds.
        // For better control, use Redis with cache tags in production.
    }

    /**
     * Validate an API key against the hashed storage.
     * Uses caching to improve performance.
     *
     * @param string $key The plain API key to validate
     * @return self|null
     */
    public static function validateKey($key): ?self
    {
        if (empty($key)) {
            return null;
        }

        // Create a cache key based on the key hash (not the key itself for security)
        $cacheKey = 'api_key_valid:' . md5($key);

        // Try to get from cache first
        $cachedId = Cache::get($cacheKey);

        if ($cachedId) {
            // Verify the cached key still exists and is valid
            $cachedApiKey = self::where('id', $cachedId)
                ->where('active', true)
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->first();

            if ($cachedApiKey) {
                return $cachedApiKey;
            }

            // If not valid anymore, remove from cache
            Cache::forget($cacheKey);
        }

        // Find active keys that match the criteria
        $apiKeys = self::where('active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get();

        // Check each key using Hash::check or direct comparison
        foreach ($apiKeys as $apiKey) {
            // Tenta validar com hash primeiro (padrão)
            try {
                if (Hash::check($key, $apiKey->key)) {
                    $cacheTtl = config('api-key.cache_ttl.validation', 300);
                    Cache::put($cacheKey, $apiKey->id, $cacheTtl);
                    return $apiKey;
                }
            } catch (\Exception $e) {
                // Se falhar (ex: hash inválido), tenta comparação direta
                // Isso permite migração gradual de chaves antigas
                if ($key === $apiKey->key) {
                    $cacheTtl = config('api-key.cache_ttl.validation', 300);
                    Cache::put($cacheKey, $apiKey->id, $cacheTtl);
                    return $apiKey;
                }
            }
        }

        return null;
    }

    /**
     * Check if a given origin is allowed for this API key.
     * Uses caching to improve performance for repeated checks.
     */
    public function isOriginAllowed(string $origin): bool
    {
        $allowed = $this->allowed_origins;

        if (empty($allowed)) {
            return true;
        }

        $normalizedOrigin = strtolower(parse_url($origin, PHP_URL_HOST) ?? $origin);

        // Cache key for origin validation
        $cacheKey = 'api_key_origin:' . $this->id . ':' . md5($normalizedOrigin);

        $cacheTtl = config('api-key.cache_ttl.origin', 60);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($normalizedOrigin, $allowed) {
            foreach ($allowed as $allowedOrigin) {
                if (strtolower($allowedOrigin) === $normalizedOrigin) {
                    return true;
                }

                if (str_ends_with($allowedOrigin, '*') && str_starts_with($normalizedOrigin, rtrim($allowedOrigin, '*'))) {
                    return true;
                }
            }

            return false;
        });
    }

    public function authentication(): BelongsTo
    {
        return $this->belongsTo(Authentication::class);
    }
}
