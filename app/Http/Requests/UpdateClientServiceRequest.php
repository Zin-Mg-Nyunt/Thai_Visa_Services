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

        return $this->clientServiceRules($clientService, $clientService->service_id);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->clientServiceMessages();
    }
}
