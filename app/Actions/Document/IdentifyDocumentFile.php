<?php

namespace App\Actions\Document;

use Illuminate\Http\UploadedFile;

/**
 * Tells what an uploaded file really is from its CONTENT: the detected type and the first bytes. The file name and the
 * type the browser claims are never trusted. Only PDF (up to 5 MB), JPG and PNG (up to 2 MB) are accepted.
 */
class IdentifyDocumentFile
{
    /**
     * Largest sizes in bytes, by what the file really is.
     */
    public const PDF_MAX_BYTES = 5 * 1024 * 1024;

    public const IMAGE_MAX_BYTES = 2 * 1024 * 1024;

    /**
     * The detected type, the extension to store it under and the size limit, or null when the file is not a
     * PDF, JPG or PNG (including a file only named like one).
     *
     * @return array{mime: string, extension: string, max_bytes: int}|null
     */
    public function __invoke(UploadedFile $file): ?array
    {
        $path = $file->getRealPath();

        if ($path === false) {
            return null;
        }

        $start = (string) file_get_contents($path, false, null, 0, 8);

        return match ($file->getMimeType()) {
            'application/pdf' => str_starts_with($start, '%PDF-')
                ? ['mime' => 'application/pdf', 'extension' => 'pdf', 'max_bytes' => self::PDF_MAX_BYTES]
                : null,
            'image/jpeg' => str_starts_with($start, "\xFF\xD8\xFF")
                ? ['mime' => 'image/jpeg', 'extension' => 'jpg', 'max_bytes' => self::IMAGE_MAX_BYTES]
                : null,
            'image/png' => str_starts_with($start, "\x89PNG\r\n\x1a\n")
                ? ['mime' => 'image/png', 'extension' => 'png', 'max_bytes' => self::IMAGE_MAX_BYTES]
                : null,
            default => null,
        };
    }
}
