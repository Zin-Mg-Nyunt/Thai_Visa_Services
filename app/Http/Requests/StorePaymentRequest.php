<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Payment::class, $this->route('clientService')]) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The amount must be a decimal with at most two places. That it fits the remaining balance is checked
     * by the controller while the service row is locked. "Today" is the agency's date (Bangkok), not UTC.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['bail', 'required', 'numeric', 'gt:0', 'decimal:0,2'],
            'paid_at' => ['required', 'date', 'before_or_equal:'.today(config('app.display_timezone'))->toDateString()],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paid_at.before_or_equal' => __('The payment date cannot be in the future.'),
        ];
    }
}
