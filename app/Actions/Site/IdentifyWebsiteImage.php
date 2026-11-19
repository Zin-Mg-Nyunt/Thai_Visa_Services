<?php

namespace App\Actions\Site;

use Illuminate\Http\UploadedFile;

/**
 * Tells what an uploaded website image really is from its CONTENT: the detected type and the first bytes. The file name and the
 * type the browser claims are never trusted, so an SVG or an HTML page renamed to `.png` is refused. Only PNG, JPG, WebP and ICO
 * can be recognised; which of them a given image may be (the logo, the favicon, the hero image) is decided by the caller with
 * {@see self::ALLOWED}. SVG is never accepted (HLD Phase 5, D2).
 */
class IdentifyWebsiteImage
{
    /** The largest size of every website image, in bytes (HLD Section 3.2). */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** @var string The folder of the images on the public disk. */
    public const DIRECTORY = 'site';

    /**
     * The types each image may be: the logo PNG, JPG or WebP; the favicon PNG or ICO; the hero image JPG, PNG or WebP.
     */
    public const ALLOWED = [
        'logo' => ['image/png', 'image/jpeg', 'image/webp'],
        'favicon' => ['image/png', 'image/x-icon'],
        'hero_image' => ['image/jpeg', 'image/png', 'image/webp'],
    ];

    /**
     * The detected type and the extension to store it under, or null when the file is not a PNG, JPG, WebP or ICO image
     * (including a file only named like one). An ICO is reported as `image/x-icon`.
     *
     * @return array{mime: string, extension: string}|null
     */
    public function __invoke(UploadedFile $file): ?array
    {
        $path = $file->getRealPath();

        if ($path === false) {
            return null;
        }

        $start = (string) file_get_contents($path, false, null, 0, 12);

        return match ($file->getMimeType()) {
            'image/png' => str_starts_with($start, "\x89PNG\r\n\x1a\n")
                ? ['mime' => 'image/png', 'extension' => 'png']
                : null,
            'image/jpeg' => str_starts_with($start, "\xFF\xD8\xFF")
                ? ['mime' => 'image/jpeg', 'extension' => 'jpg']
                : null,
            'image/webp' => str_starts_with($start, 'RIFF') && substr($start, 8, 4) === 'WEBP'
                ? ['mime' => 'image/webp', 'extension' => 'webp']
                : null,
            'image/vnd.microsoft.icon', 'image/x-icon' => str_starts_with($start, "\x00\x00\x01\x00")
                ? ['mime' => 'image/x-icon', 'extension' => 'ico']
                : null,
            default => null,
        };
    }
}
