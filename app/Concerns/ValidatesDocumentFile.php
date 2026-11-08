<?php

namespace App\Concerns;

use App\Actions\Document\IdentifyDocumentFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * The checks every document file goes through, for a new upload and for a replacement alike: it must be a real PDF, JPG or
 * PNG judged by its content (never its name), a PDF up to 5 MB or a JPG or PNG up to 2 MB, with clear messages.
 */
trait ValidatesDocumentFile
{
    /**
     * @return array<int, string>
     */
    protected function fileRules(): array
    {
        // The ceiling is the largest allowed size; the type and the real size limit are checked on the content in after().
        return ['bail', 'required', 'file', 'max:'.(IdentifyDocumentFile::PDF_MAX_BYTES / 1024)];
    }

    /**
     * Clear messages in the selected language. The file rules would otherwise show their general texts, such as
     * "must not be greater than 5120 kilobytes".
     *
     * @return array<string, string>
     */
    protected function fileMessages(): array
    {
        return [
            'file.required' => __('Choose a PDF, JPG or PNG file.'),
            'file.file' => __('Choose a PDF, JPG or PNG file.'),
            'file.max' => __('The file is too large. A PDF can be at most 5 MB and a JPG or PNG at most 2 MB.'),
            'file.uploaded' => __('The server did not accept this file. It may be larger than the upload limit of the server (a PDF can be at most 5 MB, a JPG or PNG at most 2 MB).'),
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
