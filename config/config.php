<?php

use RiseTechApps\ApiKey\Notifications\EmailVerifyNotification;
use RiseTechApps\ApiKey\Notifications\GracePeriodStartedNotification;
use RiseTechApps\ApiKey\Notifications\PaymentRejectedNotification;
use RiseTechApps\ApiKey\Notifications\PlanActivatedNotification;
use RiseTechApps\ApiKey\Notifications\PlanCancelledNotification;
use RiseTechApps\ApiKey\Notifications\PlanExpiredNotification;
use RiseTechApps\ApiKey\Notifications\PlanRefundedNotification;
use RiseTechApps\ApiKey\Notifications\RequestLimitReachedNotification;
use RiseTechApps\ApiKey\Notifications\ResetPasswordNotification;
use RiseTechApps\ApiKey\Notifications\UsageThresholdNotification;

/*
 * You can place your custom package configuration in here.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Grace Period Configuration
    |--------------------------------------------------------------------------
    |
    | The number of days after a plan expires that the user can still
    | access the service. During this period, the plan is considered
    | "expired" but functional. Set to 0 to disable.
    |
    */
    'grace_period_days' => env('API_KEY_GRACE_PERIOD_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Request Limit Configuration
    |--------------------------------------------------------------------------
    |
    | Default settings for request limiting.
    |
    */
    'rate_limit' => [
        'cache_ttl' => env('API_KEY_RATE_LIMIT_CACHE_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Limit Warning Threshold
    |--------------------------------------------------------------------------
    |
    | Percentual de uso do limite do plano que dispara o e-mail de aviso
    | (ex.: 80 = avisa quando o usuário atingir 80% das requisições do ciclo).
    | O e-mail é enviado no máximo uma vez por período do plano. Use 0 para
    | desabilitar o aviso prévio (o aviso de limite atingido em 100% continua).
    |
    */
    'request_limit' => [
        'warning_threshold' => env('API_KEY_USAGE_WARNING_THRESHOLD', 80),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reembolso no cancelamento
    |--------------------------------------------------------------------------
    |
    | Janela de arrependimento. Quando o assinante cancela dentro de
    | `window_days` da PRIMEIRA vez que contratou aquele plano, e desde que
    | tenha consumido no máximo `max_usage_percent` das requisições do ciclo,
    | o pacote estorna o valor pago e encerra o acesso na hora.
    |
    | `window_days` = 0 DESLIGA o estorno automático (padrão). Mover dinheiro
    | sozinho não pode ser comportamento herdado por quem só atualizou o
    | pacote: quem quiser a política liga explicitamente.
    |
    | A janela conta da primeira assinatura do plano, não de cada renovação —
    | senão todo ciclo renovado abriria uma janela nova e daria para assinar,
    | usar abaixo do teto e cancelar no último dia, todo mês.
    |
    | O teto de consumo não se aplica a plano ilimitado (`request_limit` = 0):
    | não existe percentual de um limite que não existe.
    |
    */
    'refund' => [
        'window_days' => env('API_KEY_REFUND_WINDOW_DAYS', 0),
        'max_usage_percent' => env('API_KEY_REFUND_MAX_USAGE_PERCENT', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliação com o gateway
    |--------------------------------------------------------------------------
    |
    | Rotinas que fecham o que o Mercado Pago deveria ter avisado e não avisou.
    | Dependem do `schedule:run` da aplicação estar ativo.
    |
    | `payments_enabled` agenda `api-key:reconcile-payments` a cada quinze
    | minutos: pergunta ao gateway o desfecho de compras que ficaram em análise
    | e cujo webhook não chegou. Sem ela, um pagamento pendente sem webhook fica
    | pendente para sempre — dinheiro cobrado sem assinatura entregue.
    |
    | `validation_refunds_enabled` agenda `api-key:retry-validation-refunds` de
    | hora em hora: reprocessa o estorno da cobrança de validação de cartão que
    | falhou no cadastro.
    |
    */
    'reconciliation' => [
        'payments_enabled' => env('API_KEY_RECONCILE_PAYMENTS_ENABLED', true),
        'validation_refunds_enabled' => env('API_KEY_RETRY_VALIDATION_REFUNDS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Mapa das classes de notificação usadas pelo pacote. Para personalizar
    | qualquer notificação, aponte a chave para a SUA classe (ela deve manter
    | a mesma assinatura de construtor da classe original — pode até estender
    | a do pacote e sobrescrever apenas o toMail()).
    |
    */
    'notifications' => [
        'email_verify' => EmailVerifyNotification::class,
        'reset_password' => ResetPasswordNotification::class,
        'plan_activated' => PlanActivatedNotification::class,
        'usage_threshold' => UsageThresholdNotification::class,
        'limit_reached' => RequestLimitReachedNotification::class,
        'grace_period' => GracePeriodStartedNotification::class,
        'plan_expired' => PlanExpiredNotification::class,
        'plan_cancelled' => PlanCancelledNotification::class,
        'plan_refunded' => PlanRefundedNotification::class,
        'payment_rejected' => PaymentRejectedNotification::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for API key caching.
    |
    */
    'cache' => [
        'enabled' => env('API_KEY_CACHE_ENABLED', true),
        'ttl' => env('API_KEY_CACHE_TTL', 300), // 5 minutes - general cache
        'prefix' => 'api_key_',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache TTL Configuration
    |--------------------------------------------------------------------------
    |
    | Fine-grained cache TTL settings for specific operations.
    | These override the general cache TTL when set.
    | All values are in seconds.
    |
    */
    'cache_ttl' => [
        'validation' => env('API_KEY_CACHE_TTL_VALIDATION', 300),    // API key validation
        'origin' => env('API_KEY_CACHE_TTL_ORIGIN', 60),              // Origin validation
        'invalid' => env('API_KEY_CACHE_TTL_INVALID', 30),            // Negative cache for rejected keys
        'stats' => env('API_KEY_CACHE_TTL_STATS', 30),                // Dashboard stats endpoint
    ],

    /*
    |--------------------------------------------------------------------------
    | API Key Lookup Secret
    |--------------------------------------------------------------------------
    |
    | Secret used to derive the deterministic `lookup_hash` that lets an API key
    | be found with a single indexed query instead of a bcrypt scan over every
    | active key. Defaults to the application key.
    |
    | Changing this value invalidates every stored lookup_hash: keys keep working
    | (validation falls back to the legacy scan and re-backfills), but the first
    | request for each key after the change is slow. Rotate only when necessary.
    |
    */
    'lookup_secret' => env('API_KEY_LOOKUP_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Request Log Retention
    |--------------------------------------------------------------------------
    |
    | The `request_logs` table grows by one row per authenticated API request and
    | is read on every dashboard visit. `api-key:prune-logs` deletes entries older
    | than the retention window; it is scheduled automatically when enabled.
    | Set days to 0 to keep logs forever (not recommended).
    |
    */
    'request_log' => [
        'retention_days' => env('API_KEY_LOG_RETENTION_DAYS', 90),
        'prune_enabled' => env('API_KEY_LOG_PRUNE_ENABLED', true),
        'prune_chunk' => env('API_KEY_LOG_PRUNE_CHUNK', 5000),

        // Fila usada para gravar o log de cada requisição fora do worker web.
        //
        // ATENÇÃO: o padrão é a fila 'logs', então O PACOTE EXIGE UM WORKER
        // consumindo essa fila desde a instalação. Sem worker, os jobs se
        // acumulam e NENHUMA linha é gravada — e a falha é silenciosa, porque a
        // contagem de cota é síncrona e continua correta. O sintoma é o
        // dashboard mostrando o consumo certo com o histórico vazio.
        //
        //   php artisan queue:work redis --queue=logs
        //
        // Deixe NULA para gravar no próprio processo após a resposta
        // (afterResponse), sem depender de fila. É o caminho para quem não tem
        // worker; o custo é um INSERT no worker web, fora da rota crítica da
        // resposta.
        'queue' => env('API_KEY_LOG_QUEUE', 'logs'),

        // Conexão de fila do job de log. O Horizon só observa a conexão `redis`;
        // como o QUEUE_CONNECTION padrão da app é `database`, sem isto o job iria
        // para a fila do banco e o Horizon nunca o processaria. null = usa a
        // conexão default do app.
        'connection' => env('API_KEY_LOG_CONNECTION', 'redis'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy Key Scan
    |--------------------------------------------------------------------------
    |
    | Orçamento de tempo (em segundos) para o fallback que valida API keys
    | criadas antes da coluna `lookup_hash`. Cada verificação é um bcrypt, então
    | um backlog grande de keys não migradas poderia transformar uma única
    | requisição de autenticação em um scan de minutos. Ao esgotar o orçamento
    | sem encontrar a key, a requisição é tratada como não autenticada e um aviso
    | é registrado. Keys migram sozinhas no primeiro uso (backfill); para um
    | backlog grande, rotacione as keys legadas. Use 0 para desabilitar o limite.
    |
    */
    'legacy_scan' => [
        'max_seconds' => env('API_KEY_LEGACY_SCAN_MAX_SECONDS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue (listeners e notificações)
    |--------------------------------------------------------------------------
    |
    | Conexão/fila usadas pelos listeners enfileirados (aviso de uso, limite
    | atingido, grace period, plano expirado/ativado) e pelas notificações
    | enviadas diretamente (verificação de e-mail e reset de senha). Mesmo motivo
    | do log: o Horizon só observa a conexão `redis`, mas o QUEUE_CONNECTION
    | default da app é `database` — sem forçar a conexão, esses jobs iriam para o
    | banco e nunca seriam processados. null = usa a conexão/fila default do app.
    |
    */
    'queue' => [
        'connection' => env('API_KEY_QUEUE_CONNECTION', 'redis'),
        'name' => env('API_KEY_QUEUE_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Web Middleware Configuration
    |--------------------------------------------------------------------------
    |
    | Control whether the DisableRouteWebMiddleware is automatically
    | pushed to the 'web' middleware group. When enabled, this middleware
    | will prevent access to web routes when using API key authentication.
    | Set to false if you need to use both web and API routes simultaneously.
    |
    */
    'disable_web_middleware' => [
        'enabled' => env('API_KEY_DISABLE_WEB_MIDDLEWARE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Throttle Configuration
    |--------------------------------------------------------------------------
    |
    | Rate limiting settings for authentication endpoints (login/register).
    | Format: 'attempts,decay_minutes'
    |
    */
    'auth_throttle' => [
        'enabled' => env('API_KEY_AUTH_THROTTLE_ENABLED', true),
        'attempts' => env('API_KEY_AUTH_THROTTLE_ATTEMPTS', 5),
        'decay_minutes' => env('API_KEY_AUTH_THROTTLE_DECAY', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Key Header Configuration
    |--------------------------------------------------------------------------
    |
    | The HTTP header name used to pass the API key in requests.
    | Default: X-API-KEY
    |
    */
    'header_name' => env('API_KEY_HEADER_NAME', 'X-API-KEY'),

    /*
    |--------------------------------------------------------------------------
    | Override the Auth User Model
    |--------------------------------------------------------------------------
    |
    | The package points auth.providers.users.model at its own Authentication
    | model, which its routes, middlewares and notifications rely on. Set this to
    | false if the host application manages its own user model and only wants the
    | API key primitives.
    |
    */
    'override_auth_provider' => env('API_KEY_OVERRIDE_AUTH_PROVIDER', true),

    /*
    |--------------------------------------------------------------------------
    | Default Language Configuration
    |--------------------------------------------------------------------------
    |
    | The default language/locale to use when the request doesn't specify
    | a preferred language or when the preferred language is not supported.
    |
    */
    'default_language' => env('API_KEY_DEFAULT_LANGUAGE', 'pt'),

    /*
    |--------------------------------------------------------------------------
    | Routes Configuration
    |--------------------------------------------------------------------------
    |
    | Control whether the package routes are automatically loaded.
    | Set to false if you want to define your own routes.
    |
    */
    'routes' => [
        'enabled' => env('API_KEY_ROUTES_ENABLED', true),
        'prefix' => env('API_KEY_ROUTES_PREFIX', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware Group Configuration
    |--------------------------------------------------------------------------
    |
    | Customize the middlewares included in the 'plan' middleware group.
    | You can reorder, add or remove middlewares as needed.
    |
    */
    'middleware_group' => [
        'plan' => [
            'api.key',
            'check.active.plan',
            'check.limit.plan',
            'api.key.origin',
            'language',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mercado Pago Configuration
    |--------------------------------------------------------------------------
    |
    | Credentials for Mercado Pago payment gateway integration.
    |
    */
    'mercadopago' => [
        'public_key' => env('MP_PUBLIC_KEY'),
        'access_token' => env('MP_ACCESS_TOKEN'),
        'webhook_secret' => env('MP_WEBHOOK_SECRET'),

        /*
        | URL absoluta do webhook, enviada em `notification_url` a cada
        | pagamento. A revisão de qualidade da integração do Mercado Pago cobra
        | esse campo no corpo da requisição — cadastrar a URL apenas no painel
        | não satisfaz a checagem.
        |
        | Deixe NULA em desenvolvimento. O Mercado Pago valida a URL e recusa a
        | criação do pagamento se ela não for HTTPS acessível publicamente, então
        | preencher com localhost derruba todo o checkout local. O campo só é
        | enviado quando definido.
        |
        | Aponte para a rota de webhook do pacote, por padrão:
        | https://seu-dominio/api/v1/dashboard/checkout/webhook
        */
        'notification_url' => env('MP_NOTIFICATION_URL'),

        /*
        | Aceitar as notificações IPN, o formato legado que o Mercado Pago envia
        | para a `notification_url`: query `?topic=payment&id=…` e corpo
        | `{resource, topic}`, sem assinatura nenhuma.
        |
        | Deixe LIGADO se você preencheu `notification_url`, senão essas
        | notificações são recusadas com 400 e o gateway reentrega sem parar.
        |
        | O IPN não tem HMAC a conferir — quem verifica é o pacote, indo buscar
        | o pagamento na API com o access token, de modo que só pagamentos da
        | sua própria conta produzem efeito. Ainda assim é verificação mais
        | fraca que a do webhook assinado: desligue se você recebe apenas
        | webhooks configurados no painel.
        */
        'accept_ipn' => env('MP_ACCEPT_IPN', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | Queue used by `billing:process-renewals`. Each due subscription is charged
    | in its own job so workers can process them in parallel. Leave null to use
    | the default queue; a dedicated queue is recommended so a billing backlog
    | does not delay other work.
    |
    */
    'billing' => [
        'queue' => env('API_KEY_BILLING_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo User ID
    |--------------------------------------------------------------------------
    |
    | ID (authentication_id) of the user used by the public demo endpoint.
    | Create a dedicated user + active plan and set their ID here.
    | Find it with: SELECT id FROM authentications WHERE email = 'demo@seu.dominio';
    |
    */
    'demo_user_id' => env('API_KEY_DEMO_USER_ID'),

    /*
    |--------------------------------------------------------------------------
    | Internal Token
    |--------------------------------------------------------------------------
    |
    | Secret token used for server-to-server calls (dashboard test feature).
    | Generate with: php artisan tinker --execute="echo Str::random(64);"
    |
    */
    'internal_token' => env('API_INTERNAL_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | SPA (Single Page Application) Configuration
    |--------------------------------------------------------------------------
    |
    | When enabled, the package registers a catch-all web route that serves
    | the Vue SPA entry point (app.blade.php) for all non-API requests.
    | This also disables the DisableRouteWebMiddleware automatically so
    | browsers can access the frontend.
    |
    */
    'spa' => [
        'enabled' => env('API_KEY_SPA_ENABLED', false),
    ],
];
