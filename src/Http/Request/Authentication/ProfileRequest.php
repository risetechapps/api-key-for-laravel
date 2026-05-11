<?php

namespace RiseTechApps\ApiKey\Http\Request\Authentication;

use Illuminate\Foundation\Http\FormRequest;
use RiseTechApps\FormRequest\Traits\HasFormValidation\HasFormValidation;
use RiseTechApps\FormRequest\ValidationRuleRepository;

class ProfileRequest extends FormRequest
{
    use HasFormValidation;

    protected ValidationRuleRepository $repository;

    public function __construct(ValidationRuleRepository $ruleRepository, array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null)
    {
        parent::__construct($query, $request, $attributes, $cookies, $files, $server, $content);

        $this->repository = $ruleRepository;
    }

    public function rules(): array
    {
        $userId = optional($this->user())->getKey();

        return $this->repository->getRules('profile', ['id' => $userId])['rules'];
    }

    public function authorize(): bool
    {
        return auth()->check();
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O campo nome é obrigatório.',
            'name.min' => 'O nome deve ter pelo menos :min caracteres.',
            'name.string' => 'O nome deve ser uma string válida.',
            'cpf.required' => 'O campo CPF é obrigatório.',
            'cpf.min' => 'O CPF deve ter pelo menos :min caracteres.',
            'cpf.cpf' => 'O CPF informado é inválido.',
            'cpf.unique' => 'Este CPF já está registrado.',
            'rg.min' => 'O RG deve ter pelo menos :min caracteres.',
            'birth_date.required' => 'O campo data de nascimento é obrigatório.',
            'birth_date.date' => 'A data de nascimento informada é inválida.',
            'cellphone.required' => 'O campo celular é obrigatório.',
            'cellphone.min' => 'O celular deve ter pelo menos :min caracteres.',
            'cellphone.regex' => 'O formato do celular é inválido.',
            'telephone.min' => 'O telefone deve ter pelo menos :min caracteres.',
            'telephone.regex' => 'O formato do telefone é inválido.',
            'email.required' => 'O campo e-mail é obrigatório.',
            'email.email' => 'O e-mail deve ser um endereço válido.',
            'email.unique' => 'Este e-mail já está registrado.',
            'address.country.required' => 'O campo país é obrigatório.',
            'address.country.min' => 'O país deve ter pelo menos :min caracteres.',
            'address.state.required' => 'O campo estado é obrigatório.',
            'address.state.min' => 'O estado deve ter pelo menos :min caracteres.',
            'address.city.required' => 'O campo cidade é obrigatório.',
            'address.city.min' => 'A cidade deve ter pelo menos :min caracteres.',
            'address.zip_code.required' => 'O campo CEP é obrigatório.',
            'address.district.required' => 'O campo bairro é obrigatório.',
            'address.district.min' => 'O bairro deve ter pelo menos :min caracteres.',
            'address.address.required' => 'O campo endereço é obrigatório.',
            'address.address.min' => 'O endereço deve ter pelo menos :min caracteres.',
            'address.number.required' => 'O campo número é obrigatório.',
        ];
    }
}
