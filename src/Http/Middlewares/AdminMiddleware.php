<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Support\AdminRoles;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * @param  string  $ability  O que a rota exige (ver AdminRoles). Sem
     *                           parâmetro vale `manage`, que só o papel admin
     *                           tem: rota nova esquecida sem permissão fica
     *                           fechada, não aberta à equipe toda.
     */
    public function handle(Request $request, Closure $next, string $ability = 'manage'): Response
    {
        $user = auth()->user();

        if (! $user instanceof Authentication || ! AdminRoles::isStaff($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Área restrita a administradores.',
            ], 403);
        }

        if (! AdminRoles::can($user, $ability)) {
            return response()->json([
                'success' => false,
                'code' => 'admin_ability_required',
                'message' => __('api-key::messages.admin_ability_required'),
            ], 403);
        }

        // A equipe estorna, concede plano e suspende contas: uma senha vazada
        // não pode bastar para isso. Sem 2FA ativa, as rotas de admin ficam
        // fechadas até a pessoa ativar no perfil (que segue acessível).

        if (config('api-key.two_factor.required_for_admins', true)
            && ! $user->two_factor_confirmed_at) {
            return response()->json([
                'success' => false,
                'code' => 'two_factor_required',
                'message' => __('api-key::messages.two_factor_required_admin'),
            ], 403);
        }

        return $next($request);
    }
}
