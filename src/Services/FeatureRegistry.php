<?php

namespace RiseTechApps\ApiKey\Services;

use RiseTechApps\ApiKey\FeatureContext;
use RiseTechApps\ApiKey\FeatureManager;
use RiseTechApps\ApiKey\Models\Feature\Feature;

class FeatureRegistry
{
    protected array $registered = [];

    public function __construct(protected FeatureManager $manager) {}

    /**
     * Register a feature: stores metadata, auto-defines resolver in FeatureManager
     * and upserts to the features table.
     */
    public function register(string $key, array $metadata): void
    {
        $this->registered[$key] = array_merge($metadata, ['key' => $key]);

        // Auto-define in FeatureManager so `feature:key` middleware works automatically
        $this->manager->define($key, fn(FeatureContext $ctx) => $ctx->has($key));

        // Sync to database (silent fail if table not ready yet)
        try {
            Feature::updateOrCreate(
                ['key' => $key],
                [
                    'name'        => $metadata['name'] ?? $key,
                    'description' => $metadata['description'] ?? null,
                    'icon'        => $metadata['icon'] ?? null,
                ]
            );
        } catch (\Throwable) {}
    }

    /** All registered features as array. */
    public function all(): array
    {
        return array_values($this->registered);
    }

    /** Get metadata for a specific feature key. */
    public function get(string $key): ?array
    {
        return $this->registered[$key] ?? null;
    }

    /** All registered feature keys. */
    public function keys(): array
    {
        return array_keys($this->registered);
    }

    /** Check if a key is registered. */
    public function has(string $key): bool
    {
        return isset($this->registered[$key]);
    }

    /**
     * Force-sync all registered features to the database.
     * Useful in artisan commands after migrations run.
     */
    public function sync(): void
    {
        foreach ($this->registered as $key => $metadata) {
            Feature::updateOrCreate(
                ['key' => $key],
                [
                    'name'        => $metadata['name'] ?? $key,
                    'description' => $metadata['description'] ?? null,
                    'icon'        => $metadata['icon'] ?? null,
                ]
            );
        }
    }
}
