<?php

namespace RiseTechApps\ApiKey\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyOriginValidatorMiddleware
{
    /**
     * Validate request origin against allowed origins for the API key.
     *
     * SECURITY NOTICE: This validation relies on the Origin header and client IP,
     * which can be spoofed. For production environments, consider implementing:
     * - HMAC signature validation for critical endpoints
     * - Additional device fingerprinting
     * - Webhook signature verification for incoming requests
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('_internal')) {
            return $next($request);
        }

        // AuthenticateApiKey already resolved and validated this model; reading it
        // back from the request avoids a second SELECT on api_keys per request.
        $apiKey = $request->attributes->get('api_key_model') ?? auth()->user()?->apiKey;

        if (! $apiKey) {
            // Este middleware roda depois da reserva de cota no grupo `plan`;
            // ser barrado aqui não pode custar uma requisição do plano.
            $request->attributes->set(CheckRequestLimitMiddleware::NOT_BILLABLE, true);

            return response()->json(['message' => 'Unauthorized: Request origin/IP not permitted.'], Response::HTTP_FORBIDDEN);
        }

        // Sem Origin, vale o IP do cliente — o de $request->ip(), que só segue
        // X-Forwarded-For / CF-Connecting-IP vindos de um proxy listado no
        // TrustProxies. Antes o IP vinha de Device::getClientPublicIp(), que lê
        // esses headers direto de $_SERVER: quem tinha a chave furava a lista de
        // IPs permitidos mandando `X-Forwarded-For: <ip permitido>`.
        $requestOrigin = $request->header('Origin') ?? $request->ip();

        if (! $apiKey->isOriginAllowed($requestOrigin)) {
            logglyWarning()->withContext([
                'ip' => $request->ip(),
                'origin' => $requestOrigin,
                'api_key_id' => $apiKey->id,
                'user_id' => auth()->id(),
                'url' => $request->url(),
                'user_agent' => $request->userAgent(),
            ])->log('API key origin validation failed');

            $request->attributes->set(CheckRequestLimitMiddleware::NOT_BILLABLE, true);

            return response()->json(['message' => 'Unauthorized: Request origin/IP not permitted.'], Response::HTTP_FORBIDDEN);
        }

        $request->attributes->set('api_key_model', $apiKey);

        return $next($request);
    }
}
