<?php

namespace RiseTechApps\ApiKey\Http\Request\Dashboard\Signature;

use Illuminate\Foundation\Http\FormRequest;
use RiseTechApps\FormRequest\Traits\HasFormValidation\HasFormValidation;
use RiseTechApps\FormRequest\ValidationRuleRepository;

class SignatureRequest extends FormRequest
{
    use HasFormValidation;

    public ValidationRuleRepository $ruleRepository;

    public array $result = [];

    public function __construct(ValidationRuleRepository $ruleRepository, array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null)
    {
        parent::__construct($query, $request, $attributes, $cookies, $files, $server, $content);

        $this->ruleRepository = $ruleRepository;
        $this->result = $this->ruleRepository->getRules('signature');
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
        return $this->result['messages'];
    }
}
