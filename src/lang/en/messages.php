<?php

return [
    // Authentication
    'registration_success' => 'Registration successful. Please verify your email.',
    'registration_failed' => 'We were unable to register at this time, please try again later.',
    'user_not_found' => 'User not found',
    'account_not_verified' => 'Account not verified, please check your email inbox.',
    'incorrect_credentials' => 'Incorrect username or password',
    'unauthorized' => 'Unauthorized',
    'forbidden' => 'Forbidden',

    // API Key
    'api_key_not_active' => 'Your API key is not active',
    'api_key_expired' => 'Your API key has expired',
    'origin_not_allowed' => 'Unauthorized: Request origin/IP not permitted',

    // Plan & Subscription
    'plan_expired_or_inactive' => 'Your plan has expired or is not active',
    'plan_expired_grace_ended' => 'Your plan has expired. The grace period has also ended.',
    'request_limit_reached' => 'Request limit reached',
    'grace_period_warning' => 'grace-period',
    'grace_period_days_remaining' => 'days remaining in grace period',

    // Error Messages
    'error_loading_plans' => 'Error loading plan list',
    'error_creating_plan' => 'Error registering this plan, please try again later',
    'error_loading_plan' => 'Error loading plan data to be viewed',
    'error_updating_plan' => 'We couldn\'t update the plan at the moment, please try again later',
    'error_deleting_plan' => 'We couldn\'t delete this plan at the moment, please try again later',

    // Validation
    'validation_failed' => 'Validation failed',

    // Coupon Messages
    'error_loading_coupons' => 'Error loading coupons',
    'error_creating_coupon' => 'We were unable to register this coupon at this time, please try again later',
    'error_loading_coupon' => 'Unable to load coupon details at this time, please try again later',
    'error_updating_coupon' => 'We couldn\'t update this coupon at this time, please try again later',
    'error_deleting_coupon' => 'We couldn\'t delete this coupon at the moment, please try again later',

    // Profile Messages
    'error_loading_profile' => 'Error loading data from your profile',
    'error_updating_profile' => 'It is not possible to update your profile at this time',
    'error_loading_allowed_origins' => 'Error loading allowed access data',
    'error_updating_allowed_origins' => 'Error updating user-allowed access data',

    // Signature Messages
    'error_creating_signature' => 'Unable to complete the subscription at this time, please try again later',
    'error_loading_signature_history' => 'Unable to load subscribed plan history',
    'error_loading_request_log' => 'Unable to load request history',

    // Generic
    'success' => 'Success',
    'error' => 'Error',
    'please_try_again' => 'Please try again later',
];
