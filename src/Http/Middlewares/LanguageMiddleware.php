<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LanguageMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $language = $request->getPreferredLanguage();
            if (empty($language)) {
                $language = "pt_BR";
            }

            app()->setLocale($language);
            return $next($request);
        } catch (\Exception $exception) {

            $language = "pt_BR";
            app()->setLocale($language);
            return $next($request);
        }

        return $next($request);
    }
}
