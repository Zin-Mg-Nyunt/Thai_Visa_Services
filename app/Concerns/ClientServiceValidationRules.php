<?php

namespace App\Concerns;

use App\Enums\ClientServiceStatus;
use App\Enums\ServiceAvailability;
use App\Models\ClientService;
use App\Models\Service;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ClientServiceValidationRules
{
    /**
     * Get the validation rules for the details of a client's service.
     *
     * The service itself is chosen only when it is added (see serviceChoiceRules); an existing record keeps its service.
     * A sub-service is optional: it must be an active, not deleted sub-service of the record's service, or the one
     * the record already has, even if it was made inactive or deleted since. Cancel is not offered here; cancelling a service has its own rules and refund field.
     * The remark is optional and is added to the service's list of remarks; earlier remarks never change.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function clientServiceRules(?ClientService $clientService, int $serviceId): array
    {
        return [
            'sub_service_id' => [
                'nullable',
                'integer',
                Rule::exists(Service::class, 'id')
                    ->where('parent_id', $serviceId)
                    ->where(function ($query) use ($clientService): void {
                        $query->where(fn ($available) => $available
                            ->where('status', ServiceAvailability::Active->value)
                            ->whereNull('deleted_at'));

                        if ($clientService?->sub_service_id !== null) {
                            $query->orWhere('id', $clientService->sub_service_id);
                        }
                    }),
            ],
            'status' => ['required', Rule::in([ClientServiceStatus::InProgress->value, ClientServiceStatus::Complete->value])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'fee' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The service chosen when adding: an active, available main service other than Job Search.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function serviceChoiceRules(): array
    {
        return [
            'service_id' => [
                'required',
                'integer',
                Rule::exists(Service::class, 'id')
                    ->whereNull('parent_id')
                    ->whereNull('key')
                    ->whereNull('deleted_at')
                    ->where('status', ServiceAvailability::Active->value),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function clientServiceMessages(): array
    {
        return [
            'service_id.exists' => __('Choose an available service.'),
            'sub_service_id.exists' => __('Choose a sub-service of the selected service.'),
            'end_date.after_or_equal' => __('The end date cannot be before the start date.'),
        ];
    }
}
