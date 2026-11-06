<?php

namespace App\Http\Requests;

use App\Concerns\HandlesMoney;
use App\Enums\ClientServiceStatus;
use App\Models\ClientService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CancelClientServiceRequest extends FormRequest
{
    use HandlesMoney;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('clientService')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     * The cancel refund is optional (empty means none) and cannot be more than the money kept: received minus
     * any refund from fee changes, so kept never goes below zero.
     *
     * @return array<string, array<int, ValidationRule|Closure|string>>
     */
    public function rules(): array
    {
        /** @var ClientService $clientService */
        $clientService = $this->route('clientService');

        return [
            // bail: the check below only runs on a valid amount, so bcmath never sees text like "abc".
            'cancel_refund_amount' => [
                'bail',
                'nullable',
                'numeric',
                'min:0',
                'decimal:0,2',
                function (string $attribute, mixed $value, Closure $fail) use ($clientService): void {
                    if (bccomp($this->money($value), $clientService->kept(), 2) === 1) {
                        $fail(__('The cancel refund cannot be more than the money kept.'));
                    }
                },
            ],
        ];
    }

    /**
     * Only a service that is In Progress can be cancelled.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var ClientService $clientService */
                $clientService = $this->route('clientService');

                if ($clientService->status !== ClientServiceStatus::InProgress) {
                    $validator->errors()->add('status', __('Only a service that is in progress can be cancelled.'));
                }
            },
        ];
    }
}
