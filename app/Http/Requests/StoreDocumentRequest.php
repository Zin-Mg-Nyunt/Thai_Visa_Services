<?php

namespace App\Http\Requests;

use App\Actions\Document\IdentifyDocumentFile;
use App\Models\Document;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class StoreDocumentRequest extends FormRequest
{
    /**
     * Whether the user may add a file to this service is decided by the policy in the controller, so a refused
     * upload is a 403 and not a validation error.
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
            // The ceiling is the largest allowed size; the type and the real size limit are checked on the content below.
            'file' => ['bail', 'required', 'file', 'max:'.(IdentifyDocumentFile::PDF_MAX_BYTES / 1024)],
        ];
    }

    /**
     * What the file really is: a PDF up to 5 MB, or a JPG or PNG up to 2 MB, judged by its content and not by its name.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('file');

                if (! $file instanceof UploadedFile || $validator->errors()->has('file')) {
                    return;
                }

                $detected = $this->detected();

                if ($detected === null) {
                    $validator->errors()->add('file', __('The file must be a PDF, JPG or PNG.'));
                } elseif ($file->getSize() > $detected['max_bytes']) {
                    $validator->errors()->add('file', $detected['mime'] === 'application/pdf'
                        ? __('A PDF can be at most 5 MB.')
                        : __('A JPG or PNG can be at most 2 MB.'));
                }
            },
        ];
    }

    /**
     * The real type of the uploaded file. Only valid after the request has passed validation.
     *
     * @return array{mime: string, extension: string, max_bytes: int}|null
     */
    public function detected(): ?array
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? (new IdentifyDocumentFile)($file) : null;
    }
}
