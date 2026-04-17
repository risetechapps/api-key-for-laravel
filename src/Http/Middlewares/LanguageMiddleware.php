<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LanguageMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $language = $request->getPreferredLanguage();

            if (empty($language)) {
                $language = config('api-key.default_language', 'en');
            }

            app()->setLocale($language);
            return $next($request);
        } catch (\Exception $exception) {

            $language = config('api-key.default_language', 'en');
            app()->setLocale($language);
            return $next($request);
        }
    }
}
