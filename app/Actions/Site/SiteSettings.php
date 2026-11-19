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

    /**
     * What the public pages need from the settings: the agency name in both languages and the public addresses of the logo, the
     * favicon and the hero image. Nothing else leaves the server: no storage key, no disk path, no admin.
     *
     * It never throws: if the settings cannot be read (the cache or the database failed) the error is reported and every value is
     * null, so the public pages keep working with the built-in logo, favicon and name.
     *
     * @return array{agency_name: string|null, agency_name_my: string|null, logo_url: string|null, favicon_url: string|null, hero_image_url: string|null}
     */
    public function forPage(): array
    {
        try {
            $setting = $this->get();

            return [
                'agency_name' => $this->filled($setting->agency_name),
                'agency_name_my' => $this->filled($setting->agency_name_my),
                'logo_url' => $setting->imageUrl($setting->logo_key),
                'favicon_url' => $setting->imageUrl($setting->favicon_key),
                'hero_image_url' => $setting->imageUrl($setting->hero_image_key),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['agency_name' => null, 'agency_name_my' => null, 'logo_url' => null, 'favicon_url' => null, 'hero_image_url' => null];
        }
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
