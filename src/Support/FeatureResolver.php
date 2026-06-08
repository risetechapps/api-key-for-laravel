<?php

namespace RiseTechApps\ApiKey\Support;

use RiseTechApps\ApiKey\Services\FeatureRegistry;

class FeatureResolver
{
    /**
     * Resolve uma lista de keys de feature para os metadados registrados
     * (name/description/icon) via FeatureRegistry.
     *
     * A key nunca é exibida como rótulo — quando falta metadado, ela é
     * humanizada como último recurso. Aceita tanto uma lista de strings
     * quanto de arrays no formato ['key' => '...'].
     */
    public static function resolve(?array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $registry = app(FeatureRegistry::class);

        return collect($keys)->map(function ($item) use ($registry) {
            $key = is_array($item) ? ($item['key'] ?? null) : $item;

            if (! is_string($key)) {
                return null;
            }

            $meta = $registry->get($key) ?? [];

            return [
                'key'         => $key,
                'name'        => $meta['name'] ?? self::humanize($key),
                'description' => $meta['description'] ?? null,
                'icon'        => $meta['icon'] ?? null,
            ];
        })->filter()->values()->all();
    }

    private static function humanize(string $key): string
    {
        return ucfirst(str_replace(['_', '-', '.'], ' ', $key));
    }
}
