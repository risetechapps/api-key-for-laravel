<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recusa contas suspensas (status `disabled` ou `blocked`) nas rotas do painel.
 *
 * Suspender revoga os tokens Sanctum, mas o token é só uma das portas: um
 * guard por sessão, ou um token emitido entre a leitura e a gravação do status,
 * ainda passaria. A checagem por requisição fecha isso sem depender da ordem.
 */
class EnsureUserIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof Authentication && ! $user->isEnabled()) {
            logglyWarning()->withContext([
                'user_id' => $user->getKey(),
                'status' => $user->status,
                'url' => $request->url(),
            ])->log('Request rejected: account disabled');

            // `code` fixo para o painel reconhecer o caso sem depender do texto,
            // que muda com o idioma.
            return response()->json([
                'success' => false,
                'code' => 'account_disabled',
                'message' => __('api-key::messages.account_disabled'),
            ], 403);
        }

        return $next($request);
    }
}
