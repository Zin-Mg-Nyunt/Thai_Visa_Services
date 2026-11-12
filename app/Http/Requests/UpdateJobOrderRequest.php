<?php

namespace App\Http\Requests;

use App\Concerns\JobOrderValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateJobOrderRequest extends FormRequest
{
    use JobOrderValidationRules;

    /**
     * A closed order is frozen: every field, so the policy refuses the update.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('jobOrder')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->jobOrderRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->jobOrderMessages();
    }
}
