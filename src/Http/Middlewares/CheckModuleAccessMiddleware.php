<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;

class CheckModuleAccessMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $module = get_class($request->route()->getController()) . '@' . $request->route()->getActionMethod();

        if (!$request->user() || !$request->user()->hasModule($module)) {
            return response()->json(['error' => 'Acesso negado'], 403);
        }

        return $next($request);
    }
}
