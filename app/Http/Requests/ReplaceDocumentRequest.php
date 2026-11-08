<?php

namespace App\Http\Requests;

use App\Concerns\ValidatesDocumentFile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceDocumentRequest extends FormRequest
{
    use ValidatesDocumentFile;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('document')) ?? false;
    }

    /**
     * Only the file is sent. The title and the release setting stay as they are.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'file' => $this->fileRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->fileMessages();
    }
}
