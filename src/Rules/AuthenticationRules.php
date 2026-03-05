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

            'login' => [
                'email' => 'bail|required|email|max:255|exists:authentications,email',
                'password' => 'bail|required|min:8',
            ],

            'profile' => [
                'name' => 'bail|required|string|min:5',
                'cpf' => 'bail|required|min:11|cpf|unique:authentications,cpf',
                'rg' => 'bail|min:5',
                'birth_date' => 'bail|required|date',
                'cellphone' => 'bail|required|min:11|cellphone',
                'telephone' => 'bail',
                'genre' => 'bail',
                'nationality' => 'bail',
                'naturalness' => 'bail',
                'marital_status' => 'bail',
                'email' => 'bail|required|email|unique:authentications,email',
                'address.country' => 'bail|required|string|min:2',
                'address.state' => 'bail|required|string|min:2',
                'address.city' => 'bail|required|string|min:2',
                'address.zip_code' => 'bail|required',
                'address.district' => 'bail|required|min:5',
                'address.address' => 'bail|required|min:5',
                'address.number' => 'bail|required',
            ],
        ];
    }

    public static function Validator(): array
    {
        return [];
    }
}
