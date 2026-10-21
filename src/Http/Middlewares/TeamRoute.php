<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Support\Team;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marcadores de rota da equipe, lidos por EnsureUserIsEnabled (active.user):
 *
 *   team.self          rota pessoal: o membro não age como o dono
 *   team.role:agent    libera para atendentes (o padrão de rota da conta é admin)
 *   team.role:owner    só o dono (plano, cobrança)
 *
 * Aqui só se repete a checagem do papel, para a rota que não passar pelo
 * active.user não ficar aberta.
 */
class TeamRoute
{
    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        if ($role !== null && $role !== 'self' && Team::member() && ! Team::allows(Team::role(), $role)) {
            return response()->json([
                'success' => false,
                'code' => 'team_forbidden',
                'message' => __('api-key::messages.team_forbidden'),
            ], 403);
        }

        return $next($request);
    }
}
