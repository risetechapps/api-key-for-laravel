<?php

namespace RiseTechApps\ApiKey\Enums;

/**
 * Recusas dos middlewares que protegem a API do cliente (grupo `plan` e
 * `feature`). Quem consome é o sistema do cliente, não a SPA: mensagem sempre
 * em inglês, independente do locale, e `error_code` estável para o código do
 * cliente decidir sem depender do texto.
 */
enum ApiError: string
{
    case MISSING_API_KEY = 'MISSING_API_KEY';
    case INVALID_API_KEY = 'INVALID_API_KEY';
    case ACCOUNT_DISABLED = 'ACCOUNT_DISABLED';
    case UNAUTHENTICATED = 'UNAUTHENTICATED';
    case RATE_LIMITED = 'RATE_LIMITED';
    case PLAN_INACTIVE = 'PLAN_INACTIVE';
    case PLAN_EXPIRED = 'PLAN_EXPIRED';
    case REQUEST_LIMIT_REACHED = 'REQUEST_LIMIT_REACHED';
    case ORIGIN_NOT_ALLOWED = 'ORIGIN_NOT_ALLOWED';
    case API_KEY_SCOPE_DENIED = 'API_KEY_SCOPE_DENIED';
    case FEATURE_NOT_IN_PLAN = 'FEATURE_NOT_IN_PLAN';

    public function status(): int
    {
        return match ($this) {
            self::MISSING_API_KEY, self::INVALID_API_KEY, self::UNAUTHENTICATED => 401,
            self::FEATURE_NOT_IN_PLAN => 402,
            self::ACCOUNT_DISABLED, self::PLAN_INACTIVE, self::PLAN_EXPIRED,
            self::ORIGIN_NOT_ALLOWED, self::API_KEY_SCOPE_DENIED => 403,
            self::RATE_LIMITED, self::REQUEST_LIMIT_REACHED => 429,
        };
    }

    /**
     * Texto em `lang/en/api.php`. O app pode trocar publicando
     * `lang/vendor/api-key/en/api.php`.
     */
    public function message(array $replace = []): string
    {
        return trans('api-key::api.'.strtolower($this->value), $replace, 'en');
    }
}
