<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;


class DisableRouteWebMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $userAgent = $request->header('User-Agent');

        if (Str::contains($userAgent, ['Mozilla', 'Chrome', 'Safari', 'Firefox', 'Edge'])) {
            return response()->view('view-suite::errors.404');
        } else {
            return response()->json(['error' => 'Request not Supported'], Response::HTTP_VERSION_NOT_SUPPORTED);
        }
    }
}
