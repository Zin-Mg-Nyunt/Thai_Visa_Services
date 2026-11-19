<?php

namespace App\Actions\Landing;

use App\Actions\Site\SiteSettings;
use App\Models\SiteSetting;
use Illuminate\Support\Str;
use Throwable;

/**
 * What search engines and link previews (Facebook, LINE, ...) read from the public landing page: the title, a short description and
 * a preview image, built from the website settings (Section 4.10).
 *
 * - Title: the agency name in the visitor's language, or the app name when there is none; the app name is added after it only when
 *   it is not already part of it, so the title never repeats itself.
 * - Description: the hero description, or else the overview, in the visitor's language, but only once the website is published
 *   (an unpublished text is not shown on the page, so it is not given to search engines either). Nothing is invented: with no text,
 *   there is no description.
 * - Image: the hero image, or else the logo, as a full web address.
 *
 * The tags are written into the page's HTML on the server (resources/views/app.blade.php), so they are there for crawlers that do
 * not run JavaScript, which is how link previews are read. It never throws: if the settings cannot be read, the error is reported
 * and only the app name is used.
 */
class LandingSeo
{
    /** The longest meta description, in characters (search engines cut longer ones). */
    public const DESCRIPTION_MAX = 160;

    public function __construct(private readonly SiteSettings $settings) {}

    /**
     * @return array{title: string, description: string|null, image: string|null, url: string, site_name: string, locale: string, alternate_locale: string}
     */
    public function tags(): array
    {
        $appName = (string) config('app.name');
        $locale = app()->getLocale();
        $name = $appName;
        $description = null;
        $image = null;

        try {
            $setting = $this->settings->get();

            $name = $setting->text('agency_name') ?? $appName;
            $description = $setting->is_published ? $this->describe($setting) : null;
            $image = $this->absolute($setting->imageUrl($setting->hero_image_key ?? $setting->logo_key));
        } catch (Throwable $exception) {
            report($exception);
        }

        return [
            'title' => self::title($name, $appName),
            'description' => $description,
            'image' => $image,
            'url' => route('home'),
            'site_name' => $name,
            'locale' => $this->openGraphLocale($locale),
            'alternate_locale' => $this->openGraphLocale($locale === 'my' ? 'en' : 'my'),
        ];
    }

    /** The title of the page: the agency name, followed by the app name unless the name already contains it. */
    public static function title(string $name, string $appName): string
    {
        return $appName === '' || Str::contains($name, $appName, ignoreCase: true) ? $name : $name.' - '.$appName;
    }

    private function describe(SiteSetting $setting): ?string
    {
        $text = $setting->text('hero_description') ?? $setting->text('overview');

        if ($text === null) {
            return null;
        }

        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $text)), self::DESCRIPTION_MAX);
    }

    /** A full web address: a link preview needs the whole address, not a path on this site. */
    private function absolute(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return str_starts_with($url, '/') ? rtrim(url('/'), '/').$url : $url;
    }

    private function openGraphLocale(string $locale): string
    {
        return $locale === 'my' ? 'my_MM' : 'en_US';
    }
}
