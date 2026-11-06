<?php

namespace App\Http\Requests;

use App\Concerns\ClientValidationRules;
use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    use ClientValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Client::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->normalisePhoneInput();
    }

    /**
     * Get the validation rules that apply to the request. A profile photo can be added with the client; it is optional.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->clientRules() + ['photo' => ['nullable', ...$this->photoRules()]];
    }
}
