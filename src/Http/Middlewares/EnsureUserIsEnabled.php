<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Support\Team;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recusa contas suspensas (status `disabled` ou `blocked`) nas rotas do painel
 * e resolve a equipe: o membro logado passa a agir como o dono da conta.
 *
 * Suspender revoga os tokens Sanctum, mas o token é só uma das portas: um
 * guard por sessão, ou um token emitido entre a leitura e a gravação do status,
 * ainda passaria. A checagem por requisição fecha isso sem depender da ordem.
 *
 * Equipe (ver Support\Team). Para o membro:
 *   - dono suspenso ou removido: recusa (a conta é dele);
 *   - rota `team.self` (perfil, senha, 2FA, sessões, /auth/me): segue como ele;
 *   - demais rotas: exige o papel da rota (`team.role:agent|admin|owner`,
 *     padrão admin; atendente só entra onde a rota libera) e troca o usuário
 *     da requisição pelo dono. Rotas do admin da plataforma: nunca.
 */
class EnsureUserIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        Team::forget();

        $user = $request->user();

        if ($user instanceof Authentication && ! $user->isEnabled()) {
            return $this->disabled($user, $request);
        }

        if ($user instanceof Authentication && $user->isTeamMember()) {
            $owner = $user->account;

            if (! $owner instanceof Authentication || ! $owner->isEnabled()) {
                return $this->disabled($owner ?? $user, $request);
            }

            Team::enter($user, $owner);

            if (! self::isSelfRoute($request)) {
                $required = self::requiredRole($request);

                if (! Team::allows(Team::roleOf($user), $required)) {
                    return response()->json([
                        'success' => false,
                        'code' => 'team_forbidden',
                        'message' => __('api-key::messages.team_forbidden'),
                    ], 403);
                }

                auth()->setUser($owner);
            }
        }

        return $next($request);
    }

    /** Rota pessoal: o membro segue como ele mesmo. */
    public static function isSelfRoute(Request $request): bool
    {
        return in_array('team.self', self::markers($request), true);
    }

    /** Papel mínimo da rota para um membro. */
    public static function requiredRole(Request $request): string
    {
        $required = Team::ADMIN;

        foreach (self::markers($request) as $middleware) {
            // Admin da plataforma (planos, usuários, gateways): só o dono, que
            // é quem tem o papel de equipe da plataforma.
            if ($middleware === 'admin' || str_starts_with($middleware, 'admin:')) {
                return Team::OWNER;
            }

            if (str_starts_with($middleware, 'team.role:')) {
                $required = substr($middleware, strlen('team.role:'));
            }
        }

        return $required;
    }

    /** @return list<string> */
    private static function markers(Request $request): array
    {
        return array_values(array_filter((array) $request->route()?->gatherMiddleware(), 'is_string'));
    }

    private function disabled(Authentication $user, Request $request): Response
    {
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
}
