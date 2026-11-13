<?php

namespace App\Http\Requests;

use App\Concerns\ClientValidationRules;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The phone number typed in the Add worker dialog, looked up before a person is picked or created.
 */
class LookupJobOrderWorkerRequest extends FormRequest
{
    use ClientValidationRules;

    /**
     * A closed order takes no new worker, so there is nothing to look up for it.
     */
    public function authorize(): bool
    {
        /** @var JobOrder $jobOrder */
        $jobOrder = $this->route('jobOrder');

        return $this->user()?->can('create', [JobOrderWorker::class, $jobOrder]) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->normalisePhoneInput();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/']];
    }
}
