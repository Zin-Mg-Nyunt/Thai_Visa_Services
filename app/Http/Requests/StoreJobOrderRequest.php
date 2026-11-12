<?php

namespace App\Http\Requests;

use App\Concerns\JobOrderValidationRules;
use App\Models\JobOrder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreJobOrderRequest extends FormRequest
{
    use JobOrderValidationRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', JobOrder::class) ?? false;
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
