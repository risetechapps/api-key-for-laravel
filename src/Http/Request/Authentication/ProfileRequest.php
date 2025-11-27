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
//                return $this->result['messages'];
        return [
            'name.required' => 'Necessário digitar o nome',
            'name.min' => 'Necessário que o nome tenha no mínimo 5 caracteres',
            'cpf.required' => 'Necessário digitar o CPF',
            'cpf.min' => 'Necessário que o CPF tenha no mínimo 11 caracteres',
            'cpf.cpf' => 'Necessário digitar um CPF válido',
            'cpf.unique' => 'Já existe um cadastro com esse CPF',
            'rg.min' => 'Necessário que o rg tenha no mínimo 5 caracteres',
            'birth_date.required' => 'Necessário digitar a data de nascimento',
            'birth_date.date' => 'Necessário digitar uma data de nascimento válida',
            'cellphone.required' => 'Necessário digitar um celular válido',
            'cellphone.min' => 'Necessário que o celular tenha 11 caracteres',
            'cellphone.cellphone' => 'Necessário digitar um celular válido',
            'email.required' => 'Necessário digitar o email',
            'email.email' => 'Necessário digitar um endereço de email válido',
            'email.unique' => 'Já existe um cadastro com esse email',
            'address.country.required' => 'Necessário digitar o País',
            'address.country.min' => 'Necessário que o País tenha 2 caracteres',
            'address.state.required' => 'Necessário digitar o Estado',
            'address.state.min' => 'Necessário que o Estado tenha 2 caracteres',
            'address.city.required' => 'Necessário digitar a Cidade',
            'address.city.min' => 'Necessário que a Cidade tenha 2 caracteres',
            'address.zip_code.required' => 'Necessário digitar o CEP',
            'address.district.required' => 'Necessário digitar o Bairro',
            'address.district.min' => 'Necessário que o Bairro tenha 2 caracteres',
            'address.number.required' => 'Necessário digitar o número',
        ];
    }
}
