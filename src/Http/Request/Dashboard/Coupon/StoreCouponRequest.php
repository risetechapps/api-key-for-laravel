<?php

namespace RiseTechApps\ApiKey\Http\Request\Dashboard\Coupon;

use Illuminate\Foundation\Http\FormRequest;
use RiseTechApps\ApiKey\Http\Request\Concerns\ReplacesUniqueIgnoringCase;
use RiseTechApps\ApiKey\Models\Coupon\Coupon;
use RiseTechApps\FormRequest\Traits\HasFormValidation\HasFormValidation;
use RiseTechApps\FormRequest\ValidationRuleRepository;

class StoreCouponRequest extends FormRequest
{
    use HasFormValidation, ReplacesUniqueIgnoringCase;

    public array $result = [];

    public function __construct(public ValidationRuleRepository $ruleRepository, array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null)
    {
        parent::__construct($query, $request, $attributes, $cookies, $files, $server, $content);

        $this->result = $this->ruleRepository->getRules('coupon');
    }

    public function rules(): array
    {
        return $this->uniqueIgnoringCase(
            $this->result['rules'],
            'code',
            Coupon::class,
            message: __('api-key::messages.coupon_code_taken'),
        );
    }

    public function authorize(): bool
    {
        return auth()->check();
    }

    #[\Override]
    public function messages(): array
    {
        return $this->result['messages'];
    }
}
