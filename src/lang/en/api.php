<?php

// Mensagens da API do cliente (ver Enums\ApiError). Só existem em inglês.
return [
    'missing_api_key' => 'API key is missing. Send it in the :header header.',
    'invalid_api_key' => 'Invalid API key.',
    'account_disabled' => 'This account is suspended. Please contact support.',
    'unauthenticated' => 'Unauthenticated.',
    'rate_limited' => 'Too many requests per minute. Please wait and try again.',
    'plan_inactive' => 'Your plan has expired or is not active.',
    'plan_expired' => 'Your plan has expired and the grace period has ended.',
    'request_limit_reached' => 'Request limit reached for the current billing period.',
    'origin_not_allowed' => 'Request origin or IP is not allowed for this API key.',
    'api_key_scope_denied' => 'This API key is not allowed to access this resource.',
    'feature_not_in_plan' => 'Your plan does not include any of these features: :features.',
];
