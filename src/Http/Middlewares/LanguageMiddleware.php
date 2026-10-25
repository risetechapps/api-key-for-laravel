<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Support\Translation\BaseLocaleLoader;
use RiseTechApps\ApiKey\Support\Translation\ValidationFallback;
use Symfony\Component\HttpFoundation\Response;

class LanguageMiddleware
{
    /**
     * Idiomas com arquivo de tradução no pacote.
     */
    private const SUPPORTED = ['pt', 'en'];

    /**
     * Idioma fixo por superfície, sem olhar o Accept-Language: o painel (SPA)
     * responde em português (`language` ou `language:pt`) e a API do cliente,
     * no grupo `plan`, em inglês (`language:en`).
     */
    public function handle(Request $request, Closure $next, string $language = 'pt'): Response
    {
        $locale = $this->forApp(in_array($language, self::SUPPORTED, true) ? $language : 'pt');

        app()->setLocale($locale);
        ValidationFallback::ensure(app('translator'), $locale);

        return $next($request);
    }

    /**
     * O idioma no locale do app quando os dois são o mesmo idioma: num app em
     * pt_BR, "pt" vira "pt_BR". Antes ficava "pt", e as traduções do próprio
     * app (lang/pt_BR, lang/pt_BR.json) não eram achadas: parte da resposta
     * saía em inglês. As do pacote continuam vindo de `pt` (BaseLocaleLoader).
     */
    private function forApp(string $language): string
    {
        $appLocale = (string) config('app.locale');

        return BaseLocaleLoader::base($appLocale) === BaseLocaleLoader::base($language) ? $appLocale : $language;
    }
}
