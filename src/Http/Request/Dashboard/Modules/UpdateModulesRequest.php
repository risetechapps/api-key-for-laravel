<?php

namespace RiseTechApps\ApiKey\Http\Request\Dashboard\Modules;

use Illuminate\Foundation\Http\FormRequest;
use RiseTechApps\FormRequest\Traits\hasFormValidation\hasFormValidation;
use RiseTechApps\FormRequest\ValidationRuleRepository;

class UpdateModulesRequest extends FormRequest
{
    use hasFormValidation;

    public ValidationRuleRepository $ruleRepository;

    public array $result = [];

    public function __construct(ValidationRuleRepository $ruleRepository,  array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null)
    {
        parent::__construct($query, $request, $attributes, $cookies, $files, $server, $content);

        $this->ruleRepository = $ruleRepository;

        $this->result = $this->ruleRepository->getRules('module', ['id' => request()->module->getKey()]);
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
        ];
    }
}
