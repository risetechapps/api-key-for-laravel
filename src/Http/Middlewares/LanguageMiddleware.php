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
        $locale = $this->resolve($request);

        app()->setLocale($locale);
        ValidationFallback::ensure(app('translator'), $locale);

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        foreach ($request->getLanguages() as $language) {
            $base = BaseLocaleLoader::base($language);

            if (in_array($base, self::SUPPORTED, true)) {
                return $this->forApp($base);
            }
        }

        return $this->forApp((string) config('api-key.default_language', 'pt'));
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
