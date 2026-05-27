<?php

namespace RiseTechApps\ApiKey\Http\Request\Dashboard\Coupon;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use RiseTechApps\FormRequest\Traits\HasFormValidation\HasFormValidation;
use RiseTechApps\FormRequest\ValidationRuleRepository;

class UpdateCouponRequest extends FormRequest
{
    use HasFormValidation;

    public ValidationRuleRepository $ruleRepository;

    public array $result = [];

    public function __construct(ValidationRuleRepository $ruleRepository,  array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null)
    {
        parent::__construct($query, $request, $attributes, $cookies, $files, $server, $content);

        $this->ruleRepository = $ruleRepository;

        $coupon = request()->route('coupon');
        $couponId = $coupon instanceof Model ? $coupon->getKey() : $coupon;

        $this->result = $this->ruleRepository->getRules('coupon', ['id' => $couponId]);
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
