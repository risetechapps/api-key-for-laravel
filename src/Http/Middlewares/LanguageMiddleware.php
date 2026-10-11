<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LanguageMiddleware
{
    /**
     * Idiomas com arquivo de tradução no pacote. Variantes regionais caem no
     * idioma base: "pt-BR" e "pt_PT" viram "pt", "en-GB" vira "en".
     */
    private const SUPPORTED = ['pt', 'en'];

    /**
     * Usa o primeiro idioma do Accept-Language, em ordem de preferência, que o
     * pacote traduz; sem nenhum, o `default_language` da config.
     *
     * A versão anterior entregava a lista a getPreferredLanguage(), que nunca
     * devolve vazio: sem o header respondia em inglês e com um idioma não
     * suportado respondia no primeiro da lista, ignorando a config nos dois.
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        foreach ($request->getLanguages() as $language) {
            $base = strtolower(Str::before(str_replace('-', '_', $language), '_'));

            if (in_array($base, self::SUPPORTED, true)) {
                return $base;
            }
        }

        return (string) config('api-key.default_language', 'pt');
    }
}
