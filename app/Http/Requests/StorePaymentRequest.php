<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\ClientService;
use App\Models\Payment;
use Closure;
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
        return $this->user()?->can('create', Payment::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     * A payment cannot be more than the balance still to pay, so received money never passes the fee.
     *
     * @return array<string, array<int, ValidationRule|Closure|string>>
     */
    public function rules(): array
    {
        /** @var ClientService $clientService */
        $clientService = $this->route('clientService');

        return [
            'amount' => [
                'required',
                'numeric',
                'gt:0',
                'decimal:0,2',
                function (string $attribute, mixed $value, Closure $fail) use ($clientService): void {
                    if (bccomp(number_format((float) $value, 2, '.', ''), $clientService->remaining(), 2) === 1) {
                        $fail(__('The payment cannot be more than the remaining balance.'));
                    }
                },
            ],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
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
