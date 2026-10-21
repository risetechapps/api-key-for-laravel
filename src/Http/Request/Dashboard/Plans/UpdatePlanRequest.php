<?php

namespace RiseTechApps\ApiKey\Http\Request\Dashboard\Plans;

use Illuminate\Foundation\Http\FormRequest;
use RiseTechApps\ApiKey\Http\Request\Concerns\ReplacesUniqueIgnoringCase;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Rules\PlanRules;
use RiseTechApps\FormRequest\Traits\HasFormValidation\HasFormValidation;
use RiseTechApps\FormRequest\ValidationRuleRepository;

class UpdatePlanRequest extends FormRequest
{
    use HasFormValidation, ReplacesUniqueIgnoringCase;

    public array $result = [];

    public function __construct(public ValidationRuleRepository $ruleRepository, array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null)
    {
        parent::__construct($query, $request, $attributes, $cookies, $files, $server, $content);

        $this->result = $this->ruleRepository->getRules('plan', ['id' => request()->route('plan')]);
    }

    public function rules(): array
    {
        return $this->uniqueIgnoringCase(
            $this->result['rules'],
            'name',
            Plan::class,
            // A própria linha em edição não conta como conflito.
            $this->route('plan'),
            __('api-key::messages.plan_name_taken'),
        );
    }

    public function authorize(): bool
    {
        return auth()->check();
    }

    #[\Override]
    public function messages(): array
    {
        return array_merge($this->result['messages'] ?? [], PlanRules::messages());
    }
}
