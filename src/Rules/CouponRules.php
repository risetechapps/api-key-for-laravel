<?php

namespace RiseTechApps\ApiKey\Rules;

use RiseTechApps\FormRequest\Contracts\RulesContract;

class CouponRules implements RulesContract
{

    public static function Rules(): array
    {
        return [
            'coupon' => [
                'code' => 'bail|required|string|unique:coupons,code',
                'type' => 'bail|required|in:percentage,fixed',
                'value' => 'required|numeric|min:0|max:100|decimal:0,2',
                'max_uses' => 'bail|required|numeric|min:1',
                'expires_at' => 'bail|required|date_format:Y-m-d',
            ]
        ];
    }

    public static function Validator(): array
    {
        return [];
    }
}
