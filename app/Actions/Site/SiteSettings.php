<?php

namespace App\Actions\Site;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

    public static function forgetAfterCommit(): void
    {
        DB::afterCommit(fn () => Cache::forget(self::CACHE_KEY));
    }
}
