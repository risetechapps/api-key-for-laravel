<?php

namespace RiseTechApps\ApiKey\Http\Request\Authentication;

use Illuminate\Foundation\Http\FormRequest;
use RiseTechApps\FormRequest\Traits\HasFormValidation\HasFormValidation;
use RiseTechApps\FormRequest\ValidationRuleRepository;

class LoginRequest extends FormRequest
{
    use HasFormValidation;

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
        return $this->result['messages'];
    }


}
