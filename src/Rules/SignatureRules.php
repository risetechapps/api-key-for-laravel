<?php

namespace RiseTechApps\ApiKey\Rules;

use RiseTechApps\FormRequest\Contracts\RulesContract;

class SignatureRules implements RulesContract
{

    /**
     * `method` e `method_data` saíram daqui: o controller nunca os leu, e o
     * endpoint hoje só ativa plano de preço zero — exigir forma de pagamento
     * para uma assinatura que não cobra nada obrigava o cliente a inventar um
     * valor para passar na validação. Cobrança de verdade é o
     * /dashboard/checkout/process, que tem as suas próprias regras.
     */
    public static function Rules(): array
    {
        return [
            'signature' => [
                'plan' => 'bail|required|uuid|exists:plans,id',
                'coupon_code' => 'bail|nullable|string',
            ],
        ];
    }

    public static function Validator(): array
    {
        return [];
    }

    public static function messages(): array
    {
        return [];
    }
}
