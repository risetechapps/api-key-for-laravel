<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Enums\ApiError;
use RiseTechApps\ApiKey\Http\Response\ApiResponse;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = [
            'ip' => $request->ip(),
            'url' => $request->url(),
            'method' => $request->method(),
        ];

        // Internal bypass: only valid from localhost (127.0.0.1 / ::1).
        //
        // Both addresses have to be loopback. REMOTE_ADDR alone is the socket
        // peer, which behind an nginx on the same host is 127.0.0.1 for every
        // request on the internet. $request->ip() alone follows X-Forwarded-For
        // when TrustProxies allows it, which a misconfigured trust list lets a
        // client forge. Requiring both is never looser than the old check.
        $internalToken = $request->header('X-Internal-Token');
        $loopback = ['127.0.0.1', '::1', '0:0:0:0:0:0:0:1'];
        $isLoopback = in_array($request->server('REMOTE_ADDR'), $loopback, true)
            && in_array($request->ip(), $loopback, true);

        if ($internalToken && $isLoopback && config('api-key.internal_token') && hash_equals(config('api-key.internal_token'), $internalToken)) {
            $userId = $request->header('X-User-Id');
            $user = Authentication::with('apiKey')->find($userId);
            if ($user && ! $user->isEnabled()) {
                return $this->disabled($user, $context);
            }
            if ($user) {
                auth()->setUser($user);
                $request->attributes->set('_internal', true);

                return $next($request);
            }
            logglyWarning()->withContext(['user_id' => $userId, ...$context])->log('Internal auth failed: user not found');
        }

        $headerName = config('api-key.header_name', 'X-API-KEY');
        $key = $request->header($headerName);

        if (! $key) {
            logglyInfo()->withContext($context)->log('API key authentication failed: missing key');

            return ApiResponse::apiError(ApiError::MISSING_API_KEY, ['header' => $headerName]);
        }

        $apiKey = ApiKey::validateKey($key);

        if (! $apiKey) {
            logglyInfo()->withContext($context)->log('API key authentication failed: invalid key');

            return ApiResponse::apiError(ApiError::INVALID_API_KEY);
        }

        $user = $apiKey->authentication;

        if (! $user) {
            logglyWarning()->withContext([
                'api_key_id' => $apiKey->id, ...$context,
            ])->log('API key authentication failed: key has no owner');

            return ApiResponse::apiError(ApiError::INVALID_API_KEY);
        }

        // A chave pode estar ativa e o dono suspenso: suspender não mexe na
        // chave, para que reativar a conta devolva o acesso sem trocar a chave
        // que o cliente já tem configurada.
        if (! $user->isEnabled()) {
            return $this->disabled($user, $context);
        }

        // Último uso, para a lista de chaves. No máximo uma escrita por minuto
        // por chave, e sem eventos do model: isto roda em toda requisição.
        if (! $apiKey->last_used_at || $apiKey->last_used_at->lt(now()->subMinute())) {
            ApiKey::whereKey($apiKey->getKey())->update(['last_used_at' => now()]);
        }

        $user->setRelation('apiKey', $apiKey);
        $apiKey->setRelation('authentication', $user);
        $request->attributes->set('api_key_model', $apiKey);

        auth()->setUser($user);

        return $next($request);
    }

    private function disabled(Authentication $user, array $context): Response
    {
        logglyWarning()->withContext([
            'user_id' => $user->getKey(),
            'status' => $user->status,
            ...$context,
        ])->log('API key authentication failed: account disabled');

        return ApiResponse::apiError(ApiError::ACCOUNT_DISABLED);
    }
}
