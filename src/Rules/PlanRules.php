<?php

namespace RiseTechApps\ApiKey\Rules;

use RiseTechApps\ApiKey\Enums\BillingCycle;
use RiseTechApps\FormRequest\Contracts\RulesContract;

class PlanRules implements RulesContract
{

    public static function Rules(): array
    {
        return [

            'plan' => [
                'name' => 'bail|required|min:5|max:255|unique:plans,name',
                'description' => 'bail|nullable',
                'request_limit' => 'bail|required|integer|min:0',
                'price' => 'bail|required|numeric|min:0.01',
                'billing_cycle' => 'bail|required|in:' . implode(',', BillingCycle::values()),
                'is_active' => 'bail|required|boolean',
                'features' => 'bail|required|array',
            ],
        ];
    }

    public static function Validator(): array
    {
        return [];
    }
}
