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
//        return $this->result['messages'];
        return [
            'name.required' => 'Necessário digitar o nome',
            'name.min' => 'O nome deve ter pelo menos 5 caracteres',
            'email.required' => 'Necessário digitar o email',
            'email.email' => 'Email digitado é inválido',
            'password.required' => 'Necessário digitar a senha',
            'password.min' => 'A senha deve ter pelo menos 8 caracteres',
            'password_confirmation.required' => 'Necessário confirmar a senha',
            'password_confirmation.min' => 'A senha deve ter pelo menos 8 caracteres',
            'password_confirmation.same' => 'As senhas devem ser iguais',
        ];
    }
}
