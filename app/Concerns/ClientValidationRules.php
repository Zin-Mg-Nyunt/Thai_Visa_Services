<?php

namespace App\Concerns;

use App\Enums\Gender;
use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ClientValidationRules
{
    /**
     * Get the validation rules used to validate client records.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function clientRules(?Client $client = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'regex:/^\+[1-9]\d{7,14}$/',
                $client === null
                    ? Rule::unique(Client::class)->withoutTrashed()
                    : Rule::unique(Client::class)->withoutTrashed()->ignore($client),
            ],
            'birthday' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Put the phone number into international format before it is validated,
     * so a duplicate is found whatever way it was typed.
     */
    protected function normalisePhoneInput(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => Client::normalisePhone($this->input('phone'))]);
        }
    }
}
