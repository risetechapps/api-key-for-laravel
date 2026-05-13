<?php

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
    | Default Language Configuration
    |--------------------------------------------------------------------------
    |
    | The default language/locale to use when the request doesn't specify
    | a preferred language or when the preferred language is not supported.
    |
    */
    'default_language' => env('API_KEY_DEFAULT_LANGUAGE', 'en'),

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
        'public_key'     => env('MP_PUBLIC_KEY'),
        'access_token'   => env('MP_ACCESS_TOKEN'),
        'webhook_secret' => env('MP_WEBHOOK_SECRET'),
    ],
];
