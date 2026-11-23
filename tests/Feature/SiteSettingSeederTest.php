<?php

use App\Actions\Site\SiteSettings;
use App\Http\Requests\UpdateAppearanceRequest;
use App\Http\Requests\UpdateWebsiteContentRequest;
use App\Models\Client;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ServiceSeeder;
use Database\Seeders\SiteSettingSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The development-only sample website content (SiteSettingSeeder): seeded only when APP_ENV=local, published so the landing page
 * shows every static section, editable as usual, never overwriting what an admin saved, and never touching the dynamic parts.
 */
beforeEach(function () {
    Storage::fake(SiteSetting::DISK);
    Cache::forget(SiteSettings::CACHE_KEY);
});

/** Run a seeder as in local development, then go back to the testing environment (whose requests skip the CSRF check). */
function seedAsLocal(string $seeder): void
{
    app()->detectEnvironment(fn () => 'local');

    try {
        test()->seed($seeder);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
}

/** @return array<string, mixed> the `site` prop of the landing page */
function seededLandingSite(): array
{
    return test()->get(route('home'))->assertOk()->viewData('page')['props']['site'];
}

describe('where it runs', function () {
    test('outside local development it seeds nothing, through DatabaseSeeder or on its own', function () {
        expect(app()->environment())->toBe('testing');

        $this->seed(DatabaseSeeder::class);
        $this->seed(SiteSettingSeeder::class);

        expect(SiteSetting::query()->exists())->toBeFalse()
            ->and(Storage::disk(SiteSetting::DISK)->allFiles())->toBe([]);
    });

    test('in local development DatabaseSeeder seeds the sample website with the services', function () {
        seedAsLocal(DatabaseSeeder::class);

        expect(SiteSetting::query()->sole()->is_published)->toBeTrue()
            ->and(Service::query()->count())->toBe(8);
    });
});

describe('what it seeds (local)', function () {
    beforeEach(function () {
        seedAsLocal(SiteSettingSeeder::class);
        $this->setting = SiteSetting::query()->sole();
    });

    test('every admin-written text in English and Burmese, published, with a stored logo and favicon', function () {
        foreach (SiteSettings::PUBLISHED_TEXTS as $field) {
            expect($this->setting->{$field})->toBeString()->not->toBe('')
                ->and($this->setting->{$field.'_my'})->toBeString()->not->toBe('');
        }

        expect($this->setting->is_published)->toBeTrue()
            ->and($this->setting->logo_key)->toMatch('#^site/[0-9a-f-]{36}\.png$#')
            ->and($this->setting->favicon_key)->toMatch('#^site/[0-9a-f-]{36}\.png$#')
            ->and($this->setting->phone)->toBe('+66 2 000 0000')
            ->and($this->setting->email)->toBe('info@example.com');

        Storage::disk(SiteSetting::DISK)->assertExists([$this->setting->logo_key, $this->setting->favicon_key]);
    });

    test('the content passes the admin forms\' own rules, so the admin can save it again unchanged', function () {
        $content = SiteSettingSeeder::content();

        expect(Validator::make($content, (new UpdateWebsiteContentRequest)->rules())->errors()->all())->toBe([])
            ->and(Validator::make($content, (new UpdateAppearanceRequest)->rules())->errors()->all())->toBe([]);
    });

    test('it invents no agency name and approves no statistic, and adds no hero image', function () {
        expect($this->setting->agency_name)->toBeNull()
            ->and($this->setting->agency_name_my)->toBeNull()
            ->and($this->setting->approved_statistics)->toBeNull()
            ->and($this->setting->hero_image_key)->toBeNull();
    });

    test('the contact details are clearly fictional', function () {
        $links = [$this->setting->messaging_link, ...array_values($this->setting->social_links)];

        expect($this->setting->address)->toStartWith('Sample address')
            ->and($this->setting->address_my)->toStartWith('နမူနာ')
            ->and(collect($links)->every(fn (string $link) => str_starts_with($link, 'https://example.com/')))->toBeTrue();
    });

    test('the landing page gets the seeded texts and contact details', function () {
        $site = seededLandingSite();

        foreach (SiteSettings::PUBLISHED_TEXTS as $field) {
            expect($site[$field])->toBe($this->setting->{$field})
                ->and($site[$field.'_my'])->toBe($this->setting->{$field.'_my'});
        }

        expect($site['phone_tel'])->toBe('+6620000000')
            ->and($site['email'])->toBe('info@example.com')
            ->and($site['messaging_link'])->toBe('https://example.com/message')
            ->and($site['social_links'])->toBe(['facebook' => 'https://example.com/facebook', 'youtube' => 'https://example.com/youtube'])
            ->and($site['logo_url'])->toContain($this->setting->logo_key);
    });

    test('the dynamic sections still come from the records: no statistics and no services without them', function () {
        Client::factory()->create();

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
            ->where('statistics', null)
            ->where('services', []));

        $this->seed(ServiceSeeder::class);

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('services', 6));
    });

    test('running it again changes nothing and never overwrites what the admin saved', function () {
        $files = Storage::disk(SiteSetting::DISK)->allFiles();
        $this->setting->forceFill(['mission' => 'The admin\'s own mission.', 'is_published' => false])->save();

        seedAsLocal(SiteSettingSeeder::class);

        expect(SiteSetting::query()->count())->toBe(1)
            ->and(SiteSetting::query()->sole()->mission)->toBe('The admin\'s own mission.')
            ->and(SiteSetting::query()->sole()->is_published)->toBeFalse()
            ->and(Storage::disk(SiteSetting::DISK)->allFiles())->toBe($files);
    });

    test('the admin can still edit the seeded content in Settings', function () {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.settings.content.update'), [...SiteSettingSeeder::content(), 'mission' => 'Edited by the admin.'])
            ->assertSessionHasNoErrors();

        expect(SiteSetting::query()->sole()->mission)->toBe('Edited by the admin.')
            ->and(seededLandingSite()['mission'])->toBe('Edited by the admin.');
    });
});
