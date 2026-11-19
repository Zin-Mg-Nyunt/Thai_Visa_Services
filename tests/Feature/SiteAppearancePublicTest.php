<?php

use App\Actions\Site\SiteSettings;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The public pages use the website appearance settings (Section 4.11): the logo and agency name in the header, the favicon and the
 * hero image, each with the built-in one as the fallback. Only public values reach the page.
 */
beforeEach(function () {
    app()->setLocale('en');
    Storage::fake(SiteSetting::DISK);
    Cache::forget(SiteSettings::CACHE_KEY);
});

/** A saved settings row with the given image keys. */
function appearanceRow(array $keys = [], array $state = []): SiteSetting
{
    $row = SiteSetting::factory()->create($state);
    $row->forceFill($keys)->save();

    return $row;
}

test('with nothing set the page sends empty values and shows the built-in logo, favicon and name', function () {
    $this->get(route('home'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('site.agency_name', null)->where('site.agency_name_my', null)->where('site.logo_url', null)
            ->where('site.favicon_url', null)->where('site.hero_image_url', null))
        ->assertSee('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', false);
});

test('the logo, the hero image, the name in both languages and the favicon come from the settings', function () {
    $row = appearanceRow(
        ['logo_key' => 'site/logo.png', 'favicon_key' => 'site/fav.png', 'hero_image_key' => 'site/hero.jpg'],
        ['agency_name' => 'PaingThu', 'agency_name_my' => 'ပိုင်သူ'],
    );
    $version = $row->fresh()->updated_at->getTimestamp();
    $disk = Storage::disk(SiteSetting::DISK);

    $response = $this->get(route('home'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('site.agency_name', 'PaingThu')
        ->where('site.agency_name_my', 'ပိုင်သူ')
        ->where('site.logo_url', $disk->url('site/logo.png').'?v='.$version)
        ->where('site.hero_image_url', $disk->url('site/hero.jpg').'?v='.$version)
        ->where('site.favicon_url', $disk->url('site/fav.png').'?v='.$version));
    $response->assertSee('<link rel="icon" href="'.$disk->url('site/fav.png').'?v='.$version.'" type="image/png">', false)
        ->assertDontSee('/favicon.svg', false);
});

test('an ICO favicon is declared as an icon and an empty name falls back', function () {
    appearanceRow(['favicon_key' => 'site/fav.ico'], ['agency_name' => '   ', 'agency_name_my' => null]);

    $this->get(route('home'))->assertSee('type="image/x-icon"', false)
        ->assertInertia(fn (Assert $page) => $page->where('site.agency_name', null)->where('site.agency_name_my', null));
});

test('the favicon is used on every page, not only the landing page', function () {
    appearanceRow(['favicon_key' => 'site/fav.png']);

    $this->get(route('login'))->assertOk()->assertSee('site/fav.png', false)->assertDontSee('/favicon.svg', false);
});

test('only public values are sent: no storage key, no disk path, no admin', function () {
    appearanceRow(['logo_key' => 'site/secret-key.png', 'hero_image_key' => 'site/hero-key.jpg'], ['agency_name' => 'PaingThu']);
    User::factory()->create();

    $props = $this->get(route('home'))->assertOk()->viewData('page')['props']['site'];

    expect(array_keys($props))->toEqualCanonicalizing(array_keys(app(SiteSettings::class)->forPage()))
        ->and(array_keys($props))->not->toContain('logo_key')->not->toContain('updated_by')->not->toContain('approved_statistics')
        ->not->toContain('is_published')
        ->and(json_encode($props))->not->toContain('logo_key')->not->toContain('updated_by')
        ->not->toContain(storage_path());
});

test('a change saved on the appearance screen shows on the public page at once', function () {
    $admin = User::factory()->create();
    appearanceRow(['logo_key' => 'site/old.png']);
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('site.logo_url', fn ($url) => str_contains($url, 'site/old.png')));

    $path = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    $this->actingAs($admin)->post(route('admin.settings.appearance.update'), ['logo' => new UploadedFile($path, 'new.png', null, null, true), 'agency_name' => 'x'])
        ->assertSessionHasNoErrors();
    auth()->logout();

    $url = $this->get(route('home'))->viewData('page')['props']['site']['logo_url'];

    expect($url)->not->toContain('site/old.png')->toContain('site/');
});

test('if the settings cannot be read the page still works with the built-in values and the error is reported', function () {
    Exceptions::fake();
    Schema::drop('site_settings');

    $this->get(route('home'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('site.logo_url', null)->where('site.hero_image_url', null))
        ->assertSee('/favicon.svg', false);

    Exceptions::assertReported(QueryException::class);
});

test('the layout draws the logo and the name from the settings and keeps the built-in ones as the fallback', function () {
    $layout = file_get_contents(resource_path('js/layouts/PublicLayout.vue'));
    $welcome = file_get_contents(resource_path('js/pages/Welcome.vue'));

    expect($layout)->toContain('v-if="site?.logo_url"')->toContain(':src="site.logo_url"')->toContain('v-else')
        ->toContain('AppLogoIcon')->toContain('localized(site.value?.agency_name, site.value?.agency_name_my)')
        ->and($welcome)->toContain('v-if="site.hero_image_url"')->toContain(':src="site.hero_image_url"')->toContain('<HeroIllustration v-else />');
});
