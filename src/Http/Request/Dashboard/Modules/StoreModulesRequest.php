<?php

namespace RiseTechApps\ApiKey\Http\Request\Dashboard\Modules;

use Illuminate\Foundation\Http\FormRequest;
use RiseTechApps\FormRequest\Traits\hasFormValidation\hasFormValidation;
use RiseTechApps\FormRequest\ValidationRuleRepository;

class StoreModulesRequest extends FormRequest
{
    use hasFormValidation;

    public function __construct(protected ValidationRuleRepository $ruleRepository, array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null)
    {
        parent::__construct($query, $request, $attributes, $cookies, $files, $server, $content);
    }

    public function rules(): array
    {
        return $this->ruleRepository->getRules('module')['rules'];
    }

    public function authorize(): bool
    {
        return auth()->check();
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Necessário digitar o nome do módulo',
            'name.min' => 'Nome do módulo deve ter ao menos 5 caracteres',
        ];
    }
}
