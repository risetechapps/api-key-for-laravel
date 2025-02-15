<?php

namespace RiseTechApps\ApiKey\Http\Request\Dashboard\Plans;

use Illuminate\Foundation\Http\FormRequest;
use RiseTechApps\FormRequest\Traits\hasFormValidation\hasFormValidation;
use RiseTechApps\FormRequest\ValidationRuleRepository;

class StorePlanRequest extends FormRequest
{

    use hasFormValidation;

    public ValidationRuleRepository $ruleRepository;

    public array $result = [];

    public function __construct(ValidationRuleRepository $ruleRepository,  array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null)
    {
        parent::__construct($query, $request, $attributes, $cookies, $files, $server, $content);

        $this->ruleRepository = $ruleRepository;

        $this->result = $this->ruleRepository->getRules('plan');
    }

    public function rules(): array
    {
        return $this->result['rules'];
    }

    public function authorize(): bool
    {
        return auth()->check();
    }

    public function messages(): array
    {
//        return $this->result['messages'];

        return [
            'name.required' => 'Necessário digitar o nome do plano',
            'name.min' => 'Nome do plano deve ter ao menos 5 caracteres',
            'price.required' => 'Necessário digitar o valor do plano',
            'price.numeric' => 'Valor do plano inválido',
            'price.min' => 'Necessário que o limite seja igual ou maior que 1',
            'request_limit.required' => 'Necessário digitar o limite do plano',
            'request_limit.numeric' => 'Limite do plano inválido',
            'request_limit.min' => 'Necessário que o limite seja igual ou maior que zero',
            'duration_days.required' => 'Necessário digitar a duração do plano',
            'duration_days.min' => 'Necessário que a duração seja maior que 1 dia',
            'duration_days.integer' => 'Necessário que a duração seja maior que 1 dia'
        ];
    }
}
