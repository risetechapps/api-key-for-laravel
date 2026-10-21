<?php

namespace RiseTechApps\ApiKey\Support\Translation;

use Illuminate\Contracts\Translation\Loader;

/**
 * As mensagens do pacote existem em `pt` e `en`. Um app em `pt_BR` (o padrão
 * do Laravel no Brasil) procurava `api-key::messages` em pt_BR, não achava, ia
 * para o fallback do app (em geral também pt_BR) e devolvia a chave crua:
 * "api-key::messages.account_disabled".
 *
 * Este carregador envolve o do Laravel e, só para o namespace do pacote, cai
 * no idioma base quando a variante regional não tem arquivo: pt_BR → pt,
 * en_US → en. Um app que publique as próprias traduções em pt_BR continua
 * tendo prioridade, porque elas são achadas antes do fallback.
 */
class BaseLocaleLoader implements Loader
{
    public function __construct(private readonly Loader $loader, private readonly string $namespace = 'api-key') {}

    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->loader->load($locale, $group, $namespace);

        if ($lines !== [] || $namespace !== $this->namespace) {
            return $lines;
        }

        $base = self::base($locale);

        return $base !== $locale ? $this->loader->load($base, $group, $namespace) : $lines;
    }

    /** "pt_BR", "pt-BR" → "pt". */
    public static function base(string $locale): string
    {
        return strtolower(explode('_', str_replace('-', '_', $locale))[0]);
    }

    public function addNamespace($namespace, $hint)
    {
        $this->loader->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->loader->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->loader->namespaces();
    }

    /** Repassa o que a interface não declara (ex.: addPath do FileLoader). */
    public function __call($method, $arguments)
    {
        return $this->loader->{$method}(...$arguments);
    }
}
