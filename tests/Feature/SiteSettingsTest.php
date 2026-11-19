<?php

use App\Actions\Site\SiteSettings;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * The website settings foundation: the single-row table, the model, the cached reader and the manage-site-settings permission.
 * See App\Models\SiteSetting and App\Actions\Site\SiteSettings.
 */
beforeEach(function () {
    app()->setLocale('en');
    Cache::forget(SiteSettings::CACHE_KEY);
});

function siteSettings(): SiteSetting
{
    return app(SiteSettings::class)->get();
}

describe('the permission', function () {
    test('a superadmin may manage the site settings, a guest may not', function () {
        $admin = User::factory()->create();

        expect(Gate::forUser($admin)->allows('manage-site-settings'))->toBeTrue()
            ->and(Gate::allows('manage-site-settings'))->toBeFalse();
    });
});

describe('the table', function () {
    test('it has every column the HLD lists, with both languages for the texts', function () {
        $columns = Schema::getColumnListing('site_settings');

        expect($columns)->toContain(
            'id', 'agency_name', 'agency_name_my', 'hero_heading', 'hero_heading_my', 'hero_description', 'hero_description_my',
            'overview', 'overview_my', 'experience', 'experience_my', 'mission', 'mission_my', 'how_we_help', 'how_we_help_my',
            'logo_key', 'favicon_key', 'hero_image_key', 'phone', 'email', 'address', 'address_my', 'messaging_link',
            'social_links', 'approved_statistics', 'is_published', 'updated_by', 'created_at', 'updated_at',
        );
    });

    test('everything but the id and the publish switch can be empty, and a new row is not published', function () {
        DB::table('site_settings')->insert(['id' => SiteSetting::ID]);

        $row = SiteSetting::query()->findOrFail(SiteSetting::ID);

        expect($row->is_published)->toBeFalse()
            ->and($row->logo_key)->toBeNull()->and($row->favicon_key)->toBeNull()->and($row->hero_image_key)->toBeNull()
            ->and($row->social_links)->toBeNull()->and($row->approved_statistics)->toBeNull();
    });

    test('the social links and the approved statistics are stored as lists and read back as arrays', function () {
        SiteSetting::factory()->create(['social_links' => ['facebook' => 'https://example.test/f'], 'approved_statistics' => ['clients_served']]);

        $row = SiteSetting::query()->findOrFail(SiteSetting::ID);

        expect($row->social_links)->toBe(['facebook' => 'https://example.test/f'])
            ->and($row->approved_statistics)->toBe(['clients_served']);
    });

    test('deleting the admin who last saved it keeps the settings and clears updated_by', function () {
        $admin = User::factory()->create();
        $row = SiteSetting::factory()->create();
        $row->updated_by = $admin->id;
        $row->save();

        $admin->delete();

        expect(SiteSetting::query()->findOrFail(SiteSetting::ID)->updated_by)->toBeNull();
    });
});

describe('the model', function () {
    test('the image keys, updated_by and the id cannot be mass assigned, the content can', function () {
        $row = new SiteSetting;
        $row->fill([
            'id' => 9, 'logo_key' => 'site/x.png', 'favicon_key' => 'site/y.png', 'hero_image_key' => 'site/z.png', 'updated_by' => 5,
            'agency_name' => 'PaingThu', 'mission_my' => 'ရည်မှန်းချက်', 'is_published' => true,
        ]);

        expect($row->logo_key)->toBeNull()->and($row->favicon_key)->toBeNull()->and($row->hero_image_key)->toBeNull()
            ->and($row->updated_by)->toBeNull()->and($row->id)->toBeNull()
            ->and($row->agency_name)->toBe('PaingThu')->and($row->mission_my)->toBe('ရည်မှန်းချက်')->and($row->is_published)->toBeTrue();
    });

    test('a text falls back to the other language, and is null when both are empty', function () {
        $row = new SiteSetting(['mission' => 'Our mission', 'mission_my' => null, 'overview' => '  ', 'overview_my' => 'အကျဉ်းချုပ်']);

        expect($row->text('mission', 'en'))->toBe('Our mission')
            ->and($row->text('mission', 'my'))->toBe('Our mission')
            ->and($row->text('overview', 'en'))->toBe('အကျဉ်းချုပ်')
            ->and($row->text('overview', 'my'))->toBe('အကျဉ်းချုပ်')
            ->and($row->text('experience', 'en'))->toBeNull()
            ->and($row->text('experience', 'my'))->toBeNull();

        app()->setLocale('my');
        $both = new SiteSetting(['mission' => 'English', 'mission_my' => 'မြန်မာ']);

        expect($both->text('mission'))->toBe('မြန်မာ');
    });
});

describe('the cached reader', function () {
    test('with no row it returns empty defaults and creates nothing', function () {
        $settings = siteSettings();

        expect($settings->exists)->toBeFalse()
            ->and($settings->is_published)->toBeFalse()
            ->and($settings->agency_name)->toBeNull()->and($settings->logo_key)->toBeNull()
            ->and($settings->text('mission'))->toBeNull()
            ->and(SiteSetting::query()->count())->toBe(0);
    });

    test('it reads the row, and a second read does not query the database', function () {
        SiteSetting::factory()->create(['agency_name' => 'PaingThu']);

        expect(siteSettings()->agency_name)->toBe('PaingThu');

        DB::enableQueryLog();
        $again = siteSettings();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($again->agency_name)->toBe('PaingThu')->and($again->exists)->toBeTrue()->and($queries)->toBe([]);
    });

    test('a read before the row exists does not hide it once it is created', function () {
        expect(siteSettings()->exists)->toBeFalse();

        SiteSetting::factory()->create(['agency_name' => 'Created later']);

        expect(siteSettings()->agency_name)->toBe('Created later');
    });

    test('saving clears the cache so the next read shows the change', function () {
        $row = SiteSetting::factory()->create(['agency_name' => 'Before']);
        siteSettings();

        $row->update(['agency_name' => 'After']);

        expect(Cache::has(SiteSettings::CACHE_KEY))->toBeFalse()
            ->and(siteSettings()->agency_name)->toBe('After');
    });

    test('inside a transaction the cache is cleared only when it commits', function () {
        $row = SiteSetting::factory()->create(['agency_name' => 'Before']);
        siteSettings();

        DB::transaction(function () use ($row) {
            $row->update(['agency_name' => 'After']);

            expect(Cache::has(SiteSettings::CACHE_KEY))->toBeTrue();
        });

        expect(Cache::has(SiteSettings::CACHE_KEY))->toBeFalse()
            ->and(siteSettings()->agency_name)->toBe('After');
    });

    test('a rolled-back save keeps the cached copy', function () {
        $row = SiteSetting::factory()->create(['agency_name' => 'Before']);
        siteSettings();

        try {
            DB::transaction(function () use ($row) {
                $row->update(['agency_name' => 'After']);

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        expect(Cache::has(SiteSettings::CACHE_KEY))->toBeTrue()
            ->and(siteSettings()->agency_name)->toBe('Before')
            ->and(SiteSetting::query()->findOrFail(SiteSetting::ID)->agency_name)->toBe('Before');
    });

    test('deleting the row clears the cache', function () {
        $row = SiteSetting::factory()->create();
        siteSettings();

        $row->delete();

        expect(Cache::has(SiteSettings::CACHE_KEY))->toBeFalse()->and(siteSettings()->exists)->toBeFalse();
    });
});
