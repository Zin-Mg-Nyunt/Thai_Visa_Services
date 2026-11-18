<?php

namespace App\Http\Requests;

use App\Models\ClientService;
use App\Models\Document;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('document')) ?? false;
    }

    /**
     * The title is changed by renaming; the release flag is changed on its own by the release switch.
     * The file itself can never be replaced; delete the document and upload a new one.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        $clientService = $this->route('clientService');
        $isJobSearch = $clientService instanceof ClientService && $clientService->service->isJobSearch();

        return [
            'title' => ['sometimes', 'required', 'string', 'max:150'],
            // A Job Search document is never shown to the client, so it has nothing to release.
            'client_can_download' => $isJobSearch
                ? ['prohibited']
                : ['sometimes', 'required', 'boolean'],
        ];
    }
}
