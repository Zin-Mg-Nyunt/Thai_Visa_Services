<?php

namespace App\Actions\Document;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hands a document file to someone who has already passed every check. Locally the app streams the file itself, so the
 * browser never gets a link to it. In production (Spaces) the browser is sent to a signed link that expires within minutes
 * (HLD option B); a link like that works for anyone who holds it until it expires, which is why it is short.
 */
class DeliverDocument
{
    /**
     * Whether the file is still on the disk. A record whose file is gone cannot be delivered.
     */
    public function hasFile(Document $document): bool
    {
        return Storage::disk(Document::DISK)->exists($document->storage_key);
    }

    public function response(Document $document): Response
    {
        $disk = Storage::disk(Document::DISK);
        $name = $this->downloadName($document);
        $headers = [
            'Content-Type' => $document->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($this->usesSignedUrls()) {
            $url = $disk->temporaryUrl($document->storage_key, now()->addMinutes(Document::SIGNED_URL_MINUTES), [
                'ResponseContentType' => $document->mime,
                'ResponseContentDisposition' => 'attachment; filename="'.Str::ascii($name).'"; filename*=UTF-8\'\''.rawurlencode($name),
            ]);

            return redirect()->away($url)->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        return $disk->download($document->storage_key, $name, $headers);
    }

    /**
     * Spaces (S3) hands out signed links; the local disk is streamed by the app and never linked.
     */
    private function usesSignedUrls(): bool
    {
        return config('filesystems.disks.'.Document::DISK.'.driver') === 's3';
    }

    /**
     * The name the file is saved as: the document's title, made safe for a header and a file name, with the real
     * extension. The title is only a name here; it is never part of the storage path.
     */
    private function downloadName(Document $document): string
    {
        $title = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\":*?<>|]+/u', ' ', $document->title));
        $title = trim(Str::limit($title, 100, ''));

        return ($title === '' ? 'document' : $title).'.'.pathinfo($document->storage_key, PATHINFO_EXTENSION);
    }
}
