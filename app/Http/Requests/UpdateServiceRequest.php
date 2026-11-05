<?php

namespace App\Http\Requests;

use App\Concerns\ServiceValidationRules;
use App\Models\Service;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceRequest extends FormRequest
{
    use ServiceValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('service')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Service $service */
        $service = $this->route('service');

        return $this->serviceRules($service);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->serviceMessages();
    }
}
