<?php

namespace App\Concerns;

use App\Enums\ServiceAvailability;
use App\Models\Service;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ServiceValidationRules
{
    /**
     * Get the validation rules used to validate service and sub-service records.
     *
     * A title is needed in at least one language; the public description is optional. A parent must be an
     * existing main service other than Job Search; Job Search itself and a service that already
     * has sub-services stay main services.
     *
     * @return array<string, array<int, ValidationRule|Closure|array<mixed>|string>>
     */
    protected function serviceRules(?Service $service = null): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255', 'required_without:title_my'],
            'title_my' => ['nullable', 'string', 'max:255', 'required_without:title'],
            'public_description' => ['nullable', 'string', 'max:2000'],
            'public_description_my' => ['nullable', 'string', 'max:2000'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists(Service::class, 'id')->whereNull('parent_id')->whereNull('key')->whereNull('deleted_at'),
                function (string $attribute, mixed $value, Closure $fail) use ($service): void {
                    if ($value === null || $service === null) {
                        return;
                    }

                    if ((int) $value === $service->id) {
                        $fail(__('A service cannot be its own parent.'));
                    } elseif ($service->isJobSearch()) {
                        $fail(__('The Job Search service cannot be a sub-service.'));
                    } elseif ($service->children()->withTrashed()->exists()) {
                        $fail(__('A service that has sub-services cannot become a sub-service.'));
                    }
                },
            ],
            'status' => ['required', Rule::enum(ServiceAvailability::class)],
            'default_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'has_documents' => ['required', 'boolean'],
            'show_on_website' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function serviceMessages(): array
    {
        return [
            'title.required_without' => __('Enter a title in English or Burmese.'),
            'title_my.required_without' => __('Enter a title in English or Burmese.'),
            'parent_id.exists' => __('Choose a main service as the parent.'),
        ];
    }
}
