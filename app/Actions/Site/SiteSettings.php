<?php

namespace App\Actions\Site;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads the public website's settings (the single {@see SiteSetting} row), cached so a public visit does not query the database.
 *
 * When the row does not exist yet, an empty, unsaved model is returned (every text empty, not published), so callers never need
 * a null check and nothing is created by reading.
 *
 * The cache is cleared by {@see forgetAfterCommit()}, which the model calls whenever the row is saved or deleted: it waits for the
 * outermost transaction to commit (at once when there is none) and does nothing at all when that transaction rolls back, so a
 * rolled-back save never drops a good cached copy. The one-hour lifetime is only a safety net.
 */
class SiteSettings
{
    public const CACHE_KEY = 'site.settings';

    public const CACHE_SECONDS = 3600;

    public function get(): SiteSetting
    {
        /** @var array<string, mixed>|null $attributes */
        $attributes = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_SECONDS,
            fn (): ?array => SiteSetting::query()->find(SiteSetting::ID)?->getAttributes(),
        );

        return $attributes === null ? new SiteSetting : (new SiteSetting)->newFromBuilder($attributes);
    }

    /** The texts of the website that the admin writes, English and Burmese. They are shown only once the website is published. */
    public const PUBLISHED_TEXTS = ['hero_heading', 'hero_description', 'overview', 'experience', 'mission', 'how_we_help', 'address'];

    /** What the public pages get when nothing can be read: the built-in logo, favicon, name and texts. */
    private const EMPTY = [
        'agency_name' => null, 'agency_name_my' => null, 'logo_url' => null, 'favicon_url' => null, 'hero_image_url' => null,
        'hero_heading' => null, 'hero_heading_my' => null, 'hero_description' => null, 'hero_description_my' => null,
        'overview' => null, 'overview_my' => null, 'experience' => null, 'experience_my' => null, 'mission' => null, 'mission_my' => null,
        'how_we_help' => null, 'how_we_help_my' => null, 'address' => null, 'address_my' => null,
        'phone' => null, 'phone_tel' => null, 'email' => null, 'messaging_link' => null, 'social_links' => [],
    ];

    /**
     * What the public pages need from the settings. Nothing else leaves the server: no storage key, no disk path, no admin, no
     * unpublished text.
     *
     * - Always: the agency name in both languages and the public addresses of the logo, the favicon and the hero image. They are
     *   the look of the website, not claims about the agency.
     * - Only when the website is published: the texts the admin wrote (hero heading and description, overview, the three About
     *   cards, address), the phone number, the email address, the messaging link and the social links. Until then they are all empty, so the page falls
     *   back to its built-in texts and hides the contact section. A text that is empty stays null, and a section with nothing in it
     *   is hidden by the page.
     * - `phone_tel` is the phone number cut down to digits and a leading plus, ready for a `tel:` link; `email` is sent only when
     *   it is a valid address, and the messaging link and a social link only when they are http or https addresses, so a link can never be a script.
     *
     * It never throws: if the settings cannot be read (the cache or the database failed) the error is reported and every value is
     * empty, so the public pages keep working with the built-in logo, favicon, name and texts.
     *
     * @return array<string, mixed>
     */
    public function forPage(): array
    {
        try {
            $setting = $this->get();

            $page = [
                ...self::EMPTY,
                'agency_name' => $this->filled($setting->agency_name),
                'agency_name_my' => $this->filled($setting->agency_name_my),
                'logo_url' => $setting->imageUrl($setting->logo_key),
                'favicon_url' => $setting->imageUrl($setting->favicon_key),
                'hero_image_url' => $setting->imageUrl($setting->hero_image_key),
            ];

            if (! $setting->is_published) {
                return $page;
            }

            foreach (self::PUBLISHED_TEXTS as $field) {
                $page[$field] = $this->filled($setting->{$field});
                $page[$field.'_my'] = $this->filled($setting->{$field.'_my'});
            }

            $page['phone'] = $this->filled($setting->phone);
            $page['phone_tel'] = $this->telephone($setting->phone);
            $page['email'] = filter_var($setting->email, FILTER_VALIDATE_EMAIL) !== false ? $setting->email : null;
            $page['messaging_link'] = $this->webAddress($setting->messaging_link);
            $page['social_links'] = collect($setting->social_links ?? [])
                ->filter(fn ($link): bool => $this->webAddress($link) !== null)
                ->all();

            return $page;
        } catch (Throwable $exception) {
            report($exception);

            return self::EMPTY;
        }
    }

    /**
     * The statistics the admin approved for the landing page (Section 4.10). Empty when none is approved or when the settings
     * cannot be read: the statistics section is then hidden.
     *
     * @return array<int, string>
     */
    public function approvedStatistics(): array
    {
        try {
            return array_values(array_filter($this->get()->approved_statistics ?? [], 'is_string'));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /** The address when it is a full http or https address, otherwise null: a link can never be a script or another kind of address. */
    private function webAddress(mixed $link): ?string
    {
        return is_string($link) && preg_match('#^https?://#i', $link) === 1 ? $link : null;
    }

    /** A phone number cut down to digits and a leading plus for a `tel:` link, or null when it has no digit. */
    private function telephone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return $digits === '' ? null : (str_starts_with(trim((string) $phone), '+') ? '+' : '').$digits;
    }

    private function filled(?string $text): ?string
    {
        return $text === null || trim($text) === '' ? null : $text;
    }

    public static function forgetAfterCommit(): void
    {
        DB::afterCommit(fn () => Cache::forget(self::CACHE_KEY));
    }
}
