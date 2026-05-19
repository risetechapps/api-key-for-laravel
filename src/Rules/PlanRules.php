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
                'features_description' => 'bail|required|array',
            ],
        ];
    }

    public static function Validator(): array
    {
        return [];
    }

    public static function messages(): array
    {
        return [
            'name.required'              => 'O nome do plano é obrigatório.',
            'name.min'                   => 'O nome deve ter pelo menos 5 caracteres.',
            'name.max'                   => 'O nome deve ter no máximo 255 caracteres.',
            'name.unique'                => 'Já existe um plano com este nome.',
            'description.required'       => 'A descrição é obrigatória.',
            'request_limit.required'     => 'O limite de requisições é obrigatório.',
            'request_limit.integer'      => 'O limite de requisições deve ser um número inteiro.',
            'request_limit.min'          => 'O limite de requisições não pode ser negativo.',
            'price.required'             => 'O preço é obrigatório.',
            'price.numeric'              => 'O preço deve ser um valor numérico.',
            'price.min'                  => 'O preço deve ser maior que zero.',
            'billing_cycle.required'     => 'O ciclo de cobrança é obrigatório.',
            'billing_cycle.in'           => 'Ciclo de cobrança inválido.',
            'is_active.required'         => 'O status do plano é obrigatório.',
            'is_active.boolean'          => 'O status do plano deve ser verdadeiro ou falso.',
            'features.required'          => 'Selecione ao menos uma feature.',
            'features.array'             => 'As features devem ser uma lista.',
            'features_description.required' => 'Adicione ao menos uma descrição de feature.',
            'features_description.array'    => 'As descrições de features devem ser uma lista.',
        ];
    }
}
