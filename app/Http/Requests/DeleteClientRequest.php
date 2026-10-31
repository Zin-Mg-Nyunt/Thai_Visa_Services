<?php

namespace App\Http\Requests;

use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteClientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('delete', $this->route('client')) ?? false;
    }

    /**
     * The admin confirms by typing the client's phone number; it is compared in international format.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('confirmation'))) {
            $this->merge(['confirmation' => Client::normalisePhone($this->input('confirmation'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Client $client */
        $client = $this->route('client');

        return [
            'confirmation' => ['required', 'string', Rule::in([$client->phone])],
        ];
    }
}
