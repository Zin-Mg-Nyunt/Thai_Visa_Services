<?php

namespace App\Http\Requests;

use App\Concerns\ClientServiceValidationRules;
use App\Models\ClientService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClientServiceRequest extends FormRequest
{
    use ClientServiceValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('clientService')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var ClientService $clientService */
        $clientService = $this->route('clientService');

        // A cancelled service is history: only a new remark can still be added.
        if ($clientService->isCancelled()) {
            return ['remark' => $this->clientServiceRules($clientService, $clientService->service_id)['remark']];
        }

        $rules = $this->clientServiceRules($clientService, $clientService->service_id);

        // A Job Search service moves between In Progress, Complete and Cancelled only through its applications (Hired, Hired ->
        // Cancelled, whole-service cancel), never by editing the record. The form does not send a status for it.
        if ($clientService->service()->first()->isJobSearch()) {
            $rules['status'] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->clientServiceMessages(),
            'status.prohibited' => __('The status of a Job Search service changes only through its applications.'),
        ];
    }
}
