<?php

namespace RiseTechApps\ApiKey\Http\Request\Authentication;

use Illuminate\Foundation\Http\FormRequest;
use RiseTechApps\FormRequest\Traits\HasFormValidation\HasFormValidation;
use RiseTechApps\FormRequest\ValidationRuleRepository;

class RegisterRequest extends FormRequest
{
    use HasFormValidation;

    protected ValidationRuleRepository $ruleRepository;

    protected array $result = [];

    public function __construct(ValidationRuleRepository $ruleRepository,  array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null)
    {
        parent::__construct($query, $request, $attributes, $cookies, $files, $server, $content);

        $this->ruleRepository = $ruleRepository;

        $this->result = $this->ruleRepository->getRules('register');
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
        return [
            'name.required' => 'O campo nome é obrigatório.',
            'name.min' => 'O nome deve ter pelo menos :min caracteres.',
            'name.max' => 'O nome não pode ter mais que :max caracteres.',
            'email.required' => 'O campo e-mail é obrigatório.',
            'email.email' => 'O e-mail deve ser um endereço válido.',
            'email.unique' => 'Este e-mail já está registrado.',
            'email.max' => 'O e-mail não pode ter mais que :max caracteres.',
            'password.required' => 'O campo senha é obrigatório.',
            'password.min' => 'A senha deve ter pelo menos :min caracteres.',
            'password_confirmation.required' => 'O campo confirmação de senha é obrigatório.',
            'password_confirmation.min' => 'A confirmação de senha deve ter pelo menos :min caracteres.',
            'password_confirmation.same' => 'A confirmação de senha não corresponde à senha.',
        ];
    }
}
