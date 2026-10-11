<?php

namespace RiseTechApps\ApiKey\Rules;

use RiseTechApps\FormRequest\Contracts\RulesContract;

class AuthenticationRules implements RulesContract
{
    public static function Rules(): array
    {
        return [
            'register' => [
                'name' => 'bail|required|min:5',
                'email' => 'bail|required|email|unique:authentications,email',
                'password' => 'bail|required|min:8',
                'password_confirmation' => 'bail|required|min:8|same:password',
            ],

            // Sem `exists` no e-mail: a mensagem "não está registrado" dizia a
            // qualquer um quais e-mails têm conta. O login responde igual para
            // e-mail inexistente e senha errada.
            'login' => [
                'email' => 'bail|required|email|max:255',
                'password' => 'bail|required|min:8',
            ],

            'profile' => [
                'name' => 'bail|required|string|min:5',
                'cpf' => 'bail|required|min:11|cpf|unique:authentications,cpf',
                'rg' => 'bail|nullable|min:5',
                'birth_date' => 'bail|required|date',
                'cellphone' => 'bail|nullable|min:10|regex:/^[0-9]{10,11}$/',
                'telephone' => 'bail|nullable|min:10|regex:/^[0-9]{10,11}$/',
                'genre' => 'bail|nullable|string',
                'nationality' => 'bail|nullable|string',
                'naturalness' => 'bail|nullable|string',
                'marital_status' => 'bail|nullable|string',
                'address' => 'bail|nullable|array',
                'address.zip_code' => 'bail|nullable|string|max:20',
                'address.country' => 'bail|nullable|string|max:100',
                'address.state' => 'bail|nullable|string|max:2',
                'address.city' => 'bail|nullable|string|max:100',
                'address.district' => 'bail|nullable|string|max:100',
                'address.address' => 'bail|nullable|string|max:255',
                'address.number' => 'bail|nullable|string|max:20',
                'address.complement' => 'bail|nullable|string|max:100',
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
