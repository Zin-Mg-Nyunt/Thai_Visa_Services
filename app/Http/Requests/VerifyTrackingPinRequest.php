<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VerifyTrackingPinRequest extends FormRequest
{
    /**
     * Anyone holding the tracking link may try a PIN; the controller rate-limits the attempts.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'digits:4'],
        ];
    }
}
