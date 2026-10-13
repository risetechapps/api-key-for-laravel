<?php

namespace RiseTechApps\ApiKey\Support\Translation;

use Illuminate\Support\Arr;
use Illuminate\Translation\Translator;

/**
 * Mensagens de validação em português para o app que não tem as próprias.
 * Só preenche o que o app não traduziu: as dele sempre vencem.
 *
 * Vale para `pt` e para qualquer variante (pt_BR, pt_PT), aplicada quando o
 * locale passa a ser usado; idempotente por locale.
 */
class ValidationFallback
{
    /**
     * Locales já preenchidos, por instância do tradutor: cada app (e cada
     * teste) tem o seu, e um registro global pularia o tradutor novo.
     *
     * @var \WeakMap<Translator, array<string, true>>|null
     */
    private static ?\WeakMap $applied = null;

    public static function ensure(Translator $translator, string $locale): void
    {
        self::$applied ??= new \WeakMap;
        $done = self::$applied[$translator] ?? [];

        if (BaseLocaleLoader::base($locale) !== 'pt' || isset($done[$locale])) {
            return;
        }

        self::$applied[$translator] = $done + [$locale => true];

        $translator->load('*', 'validation', $locale);

        $lines = [];

        foreach (Arr::dot(require dirname(__DIR__, 2).'/lang/pt/validation.php') as $key => $value) {
            if (! $translator->hasForLocale("validation.{$key}", $locale)) {
                $lines["validation.{$key}"] = $value;
            }
        }

        $translator->addLines($lines, $locale);
    }

    /** Testes: esquece o que já foi aplicado. */
    public static function reset(): void
    {
        self::$applied = null;
    }
}
