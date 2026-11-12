<?php

namespace App\Http\Requests;

use App\Enums\JobOrderStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CloseJobOrderRequest extends FormRequest
{
    /**
     * Only an open order can be closed: a closed order is final.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('close', $this->route('jobOrder')) ?? false;
    }

    /**
     * An order closes as done or cancelled, never as open.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([JobOrderStatus::Done->value, JobOrderStatus::Cancelled->value])],
            // Sent by the edit page: a closed order's edit page is refused, so the admin goes back to the list.
            'from' => ['nullable', Rule::in(['edit'])],
        ];
    }
}
