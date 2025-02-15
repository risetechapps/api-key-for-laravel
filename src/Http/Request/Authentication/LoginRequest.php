<?php

namespace RiseTechApps\ApiKey\Http\Request\Authentication;

use Illuminate\Foundation\Http\FormRequest;
use RiseTechApps\FormRequest\Traits\hasFormValidation\hasFormValidation;
use RiseTechApps\FormRequest\ValidationRuleRepository;

class LoginRequest extends FormRequest
{
    use hasFormValidation;

    protected ValidationRuleRepository $repository;

    protected array $result = [];

    public function __construct(ValidationRuleRepository $ruleRepository, array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null)
    {
        parent::__construct($query, $request, $attributes, $cookies, $files, $server, $content);

        $this->repository = $ruleRepository;

        $this->result = $this->repository->getRules('login');
    }

    public function rules(): array
    {
        return $this->result['rules'];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
//                return $this->result['messages'];
        return [
            'email.required' => 'Digite seu e-mail',
            'email.max' => 'Email digitado é invalido',
            'email.exists' => 'Email não cadastrado',
            'password.required' => 'Digite sua senha',
            'password.min' => 'Senha deve ter pelo menos 8 caracteres',
        ];
    }


}
