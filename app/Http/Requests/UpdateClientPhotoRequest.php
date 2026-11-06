<?php

namespace App\Http\Requests;

use App\Concerns\ClientValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClientPhotoRequest extends FormRequest
{
    use ClientValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('client')) ?? false;
    }

    /**
     * Only JPG, at most 2 MB. The type is read from the file's content, not its name.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', ...$this->photoRules()],
        ];
    }
}
