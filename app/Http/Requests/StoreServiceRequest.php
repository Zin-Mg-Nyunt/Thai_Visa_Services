<?php

namespace App\Http\Requests;

use App\Concerns\ServiceValidationRules;
use App\Models\Service;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreServiceRequest extends FormRequest
{
    use ServiceValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Service::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->serviceRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->serviceMessages();
    }
}
