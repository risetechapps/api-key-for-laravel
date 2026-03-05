<?php

namespace RiseTechApps\ApiKey\Rules;

use RiseTechApps\ApiKey\Enums\BillingMethod;
use RiseTechApps\FormRequest\Contracts\RulesContract;

class SignatureRules implements RulesContract
{

    public static function Rules(): array
    {
        return [
            'signature' => [
                'plan' => 'bail|required|uuid|exists:plans,id',
                'method' => 'bail|required|in:' . implode(',', BillingMethod::values()),
                'method_data' => 'bail|required',
                'coupon_code' => 'bail|nullable|string',
            ],
        ];
    }

    public static function Validator(): array
    {
        return [];
    }
}
