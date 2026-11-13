<?php

namespace App\Http\Requests;

use App\Concerns\ClientValidationRules;
use App\Models\Client;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adds a person to a job order: an existing person (client_id) or a new one (name, phone, birthday, gender), plus the fee.
 * The phone number of a new person must not belong to an active client: an existing person is picked, not created twice.
 */
class StoreJobOrderWorkerRequest extends FormRequest
{
    use ClientValidationRules;

    /**
     * A closed order takes no new worker.
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
        /** @var JobOrder $jobOrder */
        $jobOrder = $this->route('jobOrder');

        $person = $this->filled('client_id')
            ? []
            : array_intersect_key($this->clientRules(), array_flip(['name', 'phone', 'birthday', 'gender']));

        return [
            'client_id' => [
                'nullable',
                'integer',
                Rule::exists(Client::class, 'id')->whereNull('deleted_at'),
                Rule::unique(JobOrderWorker::class, 'client_id')->where('job_order_id', $jobOrder->id),
            ],
            'fee' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
        ] + $person;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_id.unique' => __('This person is already on this job order.'),
            'client_id.exists' => __('Choose a person from the search result.'),
        ];
    }
}
