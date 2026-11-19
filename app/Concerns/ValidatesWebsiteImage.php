<?php

namespace App\Concerns;

use App\Actions\Site\IdentifyWebsiteImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * The checks every website image (logo, favicon, hero image) goes through: it must be a real image of an allowed type judged by its
 * content, never its name, and at most 2 MB. SVG is never accepted. Each image is optional here, because a save may change only
 * some of them; the screen decides what is required.
 */
trait ValidatesWebsiteImage
{
    /**
     * The upload fields of the request, in the order they are checked.
     *
     * @return array<int, string>
     */
    protected function imageFields(): array
    {
        return array_keys(IdentifyWebsiteImage::ALLOWED);
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function imageRules(): array
    {
        // The ceiling is the size limit; the real type is checked on the content in after().
        return array_fill_keys($this->imageFields(), ['bail', 'nullable', 'file', 'max:'.(IdentifyWebsiteImage::MAX_BYTES / 1024)]);
    }

    /**
     * Clear messages in the selected language, instead of the general texts such as "must not be greater than 2048 kilobytes".
     *
     * @return array<string, string>
     */
    protected function imageMessages(): array
    {
        $messages = [];

        foreach ($this->imageFields() as $field) {
            $messages["{$field}.file"] = $this->imageTypeMessage($field);
            $messages["{$field}.max"] = __('The image can be at most 2 MB.');
            $messages["{$field}.uploaded"] = __('The server did not accept this file. It may be larger than the upload limit of the server (an image can be at most 2 MB).');
        }

        return $messages;
    }

    private function imageTypeMessage(string $field): string
    {
        return match ($field) {
            'logo' => __('The logo must be a PNG, JPG or WebP image.'),
            'favicon' => __('The favicon must be a PNG or ICO file.'),
            default => __('The hero image must be a JPG, PNG or WebP image.'),
        };
    }

    /**
     * What each uploaded image really is: an allowed type, judged by its content.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->imageFields() as $field) {
                    $file = $this->file($field);

                    if (! $file instanceof UploadedFile || $validator->errors()->has($field)) {
                        continue;
                    }

                    $detected = (new IdentifyWebsiteImage)($file);

                    if ($detected === null || ! in_array($detected['mime'], IdentifyWebsiteImage::ALLOWED[$field], true)) {
                        $validator->errors()->add($field, $this->imageTypeMessage($field));
                    }
                }
            },
        ];
    }

    /**
     * The real type of an uploaded image. Only valid after the request has passed validation.
     *
     * @return array{mime: string, extension: string}|null
     */
    public function detectedImage(string $field): ?array
    {
        $file = $this->file($field);

        return $file instanceof UploadedFile ? (new IdentifyWebsiteImage)($file) : null;
    }

    /**
     * A new random storage key for an uploaded image, such as `site/3f2b...-....png`. It never uses the file's own name, so a
     * replacement always gets a new key and the browser fetches the new image. The extension comes from the detected type.
     * Only valid after the request has passed validation; null when the field has no file.
     */
    public function newImageKey(string $field): ?string
    {
        $detected = $this->detectedImage($field);

        return $detected === null ? null : IdentifyWebsiteImage::DIRECTORY.'/'.Str::uuid().'.'.$detected['extension'];
    }
}
