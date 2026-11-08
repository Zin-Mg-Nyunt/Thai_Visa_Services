<?php

namespace App\Http\Requests;

use App\Concerns\ValidatesDocumentFile;
use App\Models\Document;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    use ValidatesDocumentFile;

    /**
     * Whether the user may add a file to this service is decided by the policy, so a refused upload is a 403 and not a
     * validation error.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Document::class, $this->route('clientService')]) ?? false;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'file' => $this->fileRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => __('Enter a title for the document.'),
            'title.max' => __('The title can have at most 150 characters.'),
            ...$this->fileMessages(),
        ];
    }
}
