<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check() || strtolower(auth()->user()->role) !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Área restrita a administradores.',
            ], 403);
        }

        return $next($request);
    }
}
