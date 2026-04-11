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
        'ttl' => env('API_KEY_CACHE_TTL', 300), // 5 minutes
        'prefix' => 'api_key_',
    ],
];
