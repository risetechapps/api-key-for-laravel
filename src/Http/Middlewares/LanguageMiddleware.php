<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LanguageMiddleware
{
    /**
     * Maps browser locale variants to the base locale used by translation files.
     * e.g. "pt-BR" and "pt_BR" both resolve to "pt".
     */
    private array $localeMap = [
        'pt-BR' => 'pt',
        'pt-PT' => 'pt',
        'pt_BR' => 'pt',
        'pt_PT' => 'pt',
        'en-US' => 'en',
        'en-GB' => 'en',
        'en_US' => 'en',
        'en_GB' => 'en',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $language = $request->getPreferredLanguage(array_keys($this->localeMap) + ['pt', 'en']);

            if (empty($language)) {
                $language = config('api-key.default_language', 'pt');
            }

            $language = $this->localeMap[$language] ?? Str::before($language, '-');

            app()->setLocale($language);
            return $next($request);
        } catch (\Exception $exception) {
            app()->setLocale(config('api-key.default_language', 'pt'));
            return $next($request);
        }
    }
}
