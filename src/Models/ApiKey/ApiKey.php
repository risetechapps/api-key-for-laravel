<?php

namespace RiseTechApps\ApiKey\Models\ApiKey;

use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    use HasCodeGenerate, HasFactory, HasToUpper, HasUuid;

    /**
     * The plain key value (only set during creation, not stored in DB).
     * This is returned to the user once after creation.
     */
    public ?string $plainKey = null;

    /**
     * Attributes excluded from HasToUpper normalization.
     * The hashed key must never be uppercased, otherwise bcrypt
     * verification (Hash::check) breaks and the key can never validate.
     */
    protected $no_upper = ['key', 'lookup_hash'];

    protected $fillable = [
        'code',
        'key',
        'lookup_hash',
        'expires_at',
        'active',
        'allowed_origins',
    ];

    protected $hidden = ['key', 'lookup_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'active' => 'boolean',
        'allowed_origins' => 'array',
    ];

    /**
     * Boot the model and hash the key before saving.
     * Also clears cache when key is updated.
     */
    #[\Override]
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function ($model) {
            // Hash the key whenever it is set or changed (creation or regeneration).
            // isDirty('key') is false on unrelated updates (e.g. toggling `active`),
            // so an already-hashed key is never re-hashed.
            if ($model->isDirty('key') && ! empty($model->key)) {
                // Keep the plain key in memory so it can be shown once to the user.
                $model->plainKey = $model->key;
                // Deterministic keyed digest used for the indexed lookup.
                $model->lookup_hash = self::lookupHash($model->key);
                // Hash the key for storage.
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
     * Revocation is not cache-dependent.
     *
     * The validation cache only ever stores an id, and validateKey() re-checks
     * `active` / `expires_at` against the database on every cache hit. A revoked
     * or expired key is therefore rejected immediately, without waiting for the
     * entry to expire — the stale entry is simply forgotten on the next attempt.
     *
     * Kept as a hook so callers can drop the entry eagerly if a store ever needs
     * it; deliberately a no-op for the default stores.
     */
    public static function clearValidationCache(string|int|null $keyId = null): void
    {
        //
    }

    /**
     * Clear origin validation cache for a specific API key.
     * Uses a version counter so all existing entries become stale immediately,
     * without needing to enumerate individual origin hashes.
     */
    public static function clearOriginCache(int|string $keyId): void
    {
        $versionKey = 'api_key_origin_version:'.$keyId;

        // increment() does not create a missing entry on the file/database/array
        // stores, which would leave the version pinned at 1 and the origin cache
        // permanently stale. Seed at 2 (readers default to 1) so the first
        // invalidation already moves the version forward.
        if (Cache::add($versionKey, 2)) {
            return;
        }

        Cache::increment($versionKey);
    }

    /**
     * Deterministic keyed digest of a plain key, used for the indexed lookup.
     *
     * Keyed (HMAC) rather than a bare hash so that a leaked database dump alone
     * does not allow an attacker to enumerate keys offline — they would also need
     * the application secret.
     */
    public static function lookupHash(string $key): string
    {
        $secret = config('api-key.lookup_secret') ?: config('app.key');

        return hash_hmac('sha256', $key, (string) $secret);
    }

    /**
     * Base query for keys that are usable right now.
     */
    protected static function usableScope($query)
    {
        return $query->where('active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Validate an API key against the hashed storage.
     *
     * Resolution order, cheapest first:
     *   1. Positive cache  — one indexed SELECT by id.
     *   2. Negative cache  — no query at all; stops invalid keys from reopening
     *                        the expensive path on every request.
     *   3. lookup_hash     — one indexed SELECT, then a single bcrypt check.
     *   4. Legacy scan     — only over rows created before lookup_hash existed
     *                        (lookup_hash IS NULL). Each row that validates is
     *                        backfilled, so this set drains to empty over time.
     *
     * @param  string|null  $key  The plain API key to validate
     */
    public static function validateKey($key): ?self
    {
        if (empty($key)) {
            return null;
        }

        $cacheTtl = config('api-key.cache_ttl.validation', 300);
        // Cache handle derived from the key, never the key itself.
        $digest = self::lookupHash($key);
        $cacheKey = 'api_key_valid:'.$digest;
        $missCacheKey = 'api_key_invalid:'.$digest;

        $cachedId = Cache::get($cacheKey);

        if ($cachedId) {
            $cachedApiKey = self::usableScope(self::where('id', $cachedId))->first();

            if ($cachedApiKey) {
                return $cachedApiKey;
            }

            // Key was revoked or expired since it was cached.
            Cache::forget($cacheKey);
        }

        // Short-lived negative cache. Without it, a client hammering an invalid
        // key would re-run the lookup (and, worse, the legacy scan) every request.
        if (Cache::get($missCacheKey)) {
            return null;
        }

        $candidate = self::usableScope(self::where('lookup_hash', $digest))->first();

        if ($candidate && Hash::check($key, $candidate->key)) {
            Cache::put($cacheKey, $candidate->id, $cacheTtl);

            return $candidate;
        }

        if ($legacy = self::resolveLegacyKey($key)) {
            Cache::put($cacheKey, $legacy->id, $cacheTtl);

            return $legacy;
        }

        Cache::put($missCacheKey, true, config('api-key.cache_ttl.invalid', 30));

        return null;
    }

    /**
     * Fallback for keys hashed before lookup_hash existed.
     *
     * Bounded on two axes:
     *   - Memory: only NULL-lookup_hash rows are read, 200 at a time (chunkById).
     *   - Time: each Hash::check is a bcrypt (tens to hundreds of ms), so a large
     *     backlog could otherwise turn a single authentication request into a
     *     minutes-long scan. A wall-clock budget (`api-key.legacy_scan.max_seconds`)
     *     caps the work done in one request.
     *
     * A match is backfilled immediately, so each legacy key costs the scan exactly
     * once and the NULL set drains over time. When the budget is spent before a
     * match, the request is treated as unauthenticated and a warning is logged so
     * operators know unmigrated keys remain — the recommended resolution for a
     * large backlog is to rotate the legacy keys. Set max_seconds to 0 to remove
     * the cap (unbounded scan, previous behaviour).
     */
    protected static function resolveLegacyKey(string $key): ?self
    {
        $match = null;

        $maxSeconds = (float) config('api-key.legacy_scan.max_seconds', 3);
        $deadline = $maxSeconds > 0 ? microtime(true) + $maxSeconds : null;
        $budgetSpent = false;

        self::usableScope(self::whereNull('lookup_hash'))
            ->orderBy('id')
            ->chunkById(200, function ($apiKeys) use ($key, &$match, $deadline, &$budgetSpent) {
                foreach ($apiKeys as $apiKey) {
                    if ($deadline !== null && microtime(true) >= $deadline) {
                        $budgetSpent = true;

                        return false; // stop: time budget for this request is spent
                    }

                    if (Hash::check($key, $apiKey->key)) {
                        $match = $apiKey;

                        return false; // stop chunking
                    }
                }

                return true;
            });

        if ($match) {
            // Backfill without touching `key`, so the saving hook does not re-hash.
            $match->newQuery()
                ->whereKey($match->getKey())
                ->update(['lookup_hash' => self::lookupHash($key)]);

            return $match;
        }

        if ($budgetSpent) {
            logglyWarning()->withContext([
                'max_seconds' => $maxSeconds,
            ])->log('api-key: legacy key scan hit the time budget before resolving a key; unmigrated keys remain. Consider rotating legacy API keys.');
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

        $version = Cache::get('api_key_origin_version:'.$this->id, 1);
        $cacheKey = 'api_key_origin:'.$this->id.':v'.$version.':'.md5($normalizedOrigin);

        $cacheTtl = config('api-key.cache_ttl.origin', 60);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($normalizedOrigin, $allowed) {
            foreach ($allowed as $allowedOrigin) {
                $allowedOrigin = strtolower($allowedOrigin);

                if ($allowedOrigin === $normalizedOrigin) {
                    return true;
                }

                // Curinga à direita: "app.example.*" casa "app.example.com".
                if (str_ends_with($allowedOrigin, '*')
                    && str_starts_with($normalizedOrigin, rtrim($allowedOrigin, '*'))) {
                    return true;
                }

                // Curinga de subdomínio "*.example.com": casa qualquer subdomínio
                // (sub.example.com) e o domínio base (example.com).
                if (str_starts_with($allowedOrigin, '*.')) {
                    $base = substr($allowedOrigin, 2);

                    if ($normalizedOrigin === $base || str_ends_with($normalizedOrigin, '.'.$base)) {
                        return true;
                    }
                }
            }

            return false;
        });
    }

    /**
     * Captura a key em texto puro assim que é atribuída, para poder ser exibida
     * uma única vez após a criação — inclusive antes de persistir. O hash de
     * armazenamento é aplicado no hook `saving`; o guard isHashed evita capturar o
     * próprio hash quando `saving` reatribui a key já hasheada.
     */
    public function setKeyAttribute($value): void
    {
        if (! empty($value) && ! Hash::isHashed($value)) {
            $this->plainKey = $value;
        }

        $this->attributes['key'] = $value;
    }

    public function authentication(): BelongsTo
    {
        return $this->belongsTo(Authentication::class);
    }
}
