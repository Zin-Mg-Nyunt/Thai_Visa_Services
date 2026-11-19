<?php

use App\Actions\Landing\LandingSeo;
use App\Actions\Site\SiteSettings;
use App\Models\Client;
use App\Models\SiteSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * What search engines and link previews read from the landing page (Section 4.10): the title, a meta description and the preview
 * tags, built from the website settings and written into the page's HTML on the server. See App\Actions\Landing\LandingSeo.
 */
beforeEach(function () {
    app()->setLocale('en');
    config(['app.name' => 'PaingThu']);
    Storage::fake(SiteSetting::DISK);
    Cache::forget(SiteSettings::CACHE_KEY);
});

/** The page's HTML head. */
function landingHtml(?string $locale = null): string
{
    return ($locale === null ? test() : test()->withUnencryptedCookie('locale', $locale))->get(route('home'))->assertOk()->getContent();
}

function titleOf(string $html): string
{
    preg_match('#<title[^>]*>(.*?)</title>#s', $html, $match);

    return html_entity_decode(trim($match[1] ?? ''));
}

/** The content of a meta tag, or null when there is none. */
function metaOf(string $html, string $attribute, string $name): ?string
{
    return preg_match('#<meta '.$attribute.'="'.preg_quote($name, '#').'" content="([^"]*)">#', $html, $match) === 1 ? html_entity_decode($match[1]) : null;
}

describe('the title', function () {
    test('with no agency name it is the app name, once', function () {
        $html = landingHtml();

        expect(titleOf($html))->toBe('PaingThu')->and(substr_count($html, '<title'))->toBe(1);
    });

    test('with an agency name that already has the app name in it, it is not repeated', function () {
        SiteSetting::factory()->create(['agency_name' => 'PaingThu Visa Services']);

        expect(titleOf(landingHtml()))->toBe('PaingThu Visa Services');
    });

    test('with another agency name, the app name follows it', function () {
        SiteSetting::factory()->create(['agency_name' => 'Acme Agency']);

        expect(titleOf(landingHtml()))->toBe('Acme Agency - PaingThu');
    });

    test('the Burmese name is used in Burmese, the English one when there is none', function () {
        SiteSetting::factory()->create(['agency_name' => 'Acme Agency', 'agency_name_my' => 'အက်မီ']);

        expect(titleOf(landingHtml('my')))->toBe('အက်မီ - PaingThu')->and(titleOf(landingHtml('en')))->toBe('Acme Agency - PaingThu');

        SiteSetting::query()->update(['agency_name_my' => null]);
        Cache::forget(SiteSettings::CACHE_KEY);

        expect(titleOf(landingHtml('my')))->toBe('Acme Agency - PaingThu');
    });

    test('the title helper never repeats the app name, and the browser title uses the same rule', function () {
        expect(LandingSeo::title('PaingThu', 'PaingThu'))->toBe('PaingThu')->and(LandingSeo::title('paingthu visas', 'PaingThu'))->toBe('paingthu visas')
            ->and(LandingSeo::title('Acme', 'PaingThu'))->toBe('Acme - PaingThu');

        expect(file_get_contents(resource_path('js/app.ts')))->toContain('documentTitle(title, appName)');
        expect(file_get_contents(resource_path('js/lib/documentTitle.ts')))->toContain('.includes(appName.toLowerCase())');
        expect(file_get_contents(resource_path('js/pages/Welcome.vue')))->toContain('<Head :title="name" />')->toContain('agencyName(props.site');
    });
});

describe('the meta description', function () {
    test('before the website is published there is none, even if the admin wrote a text', function () {
        SiteSetting::factory()->create(['hero_description' => 'A secret draft', 'is_published' => false]);

        $html = landingHtml();

        expect(metaOf($html, 'name', 'description'))->toBeNull()->and(metaOf($html, 'property', 'og:description'))->toBeNull()
            ->and($html)->not->toContain('A secret draft');
    });

    test('once published it is the hero description, in the visitor\'s language', function () {
        SiteSetting::factory()->create(['hero_description' => 'We help with visas.', 'hero_description_my' => 'ဗီဇာ ကူညီသည်', 'is_published' => true]);

        $english = landingHtml('en');
        $burmese = landingHtml('my');

        expect(metaOf($english, 'name', 'description'))->toBe('We help with visas.')->and(metaOf($english, 'property', 'og:description'))->toBe('We help with visas.')
            ->and(metaOf($english, 'name', 'twitter:description'))->toBe('We help with visas.')
            ->and(metaOf($burmese, 'name', 'description'))->toBe('ဗီဇာ ကူညီသည်');
    });

    test('without a hero description the overview is used, and with neither there is no description', function () {
        $row = SiteSetting::factory()->create(['overview' => 'About us.', 'is_published' => true]);

        expect(metaOf(landingHtml(), 'name', 'description'))->toBe('About us.');

        $row->update(['overview' => null]);

        expect(metaOf(landingHtml(), 'name', 'description'))->toBeNull();
    });

    test('it is one tidy line of at most 160 characters', function () {
        SiteSetting::factory()->create(['hero_description' => "  Line one.\n\n   Line two   ".str_repeat(' word', 80), 'is_published' => true]);

        $description = metaOf(landingHtml(), 'name', 'description');

        expect($description)->not->toContain("\n")->not->toContain('  ')->and(mb_strlen($description))->toBeLessThanOrEqual(163)->and($description)->toStartWith('Line one. Line two word');
    });

    test('nothing is invented: with no setting at all there is no description and no invented text', function () {
        $html = landingHtml();

        expect(metaOf($html, 'name', 'description'))->toBeNull()->and($html)->not->toContain('og:description')->not->toContain('years of experience');
    });

    test('text from the admin is escaped, so it can not break out of the tag', function () {
        SiteSetting::factory()->create(['agency_name' => 'A "quoted" <b>Agency</b>', 'hero_description' => '"><script>alert(1)</script>', 'is_published' => true]);

        $html = landingHtml();

        expect($html)->not->toContain('<script>alert(1)</script>')->not->toContain('<b>Agency</b>')
            ->and(metaOf($html, 'name', 'description'))->toBe('"><script>alert(1)</script>')
            ->and(titleOf($html))->toBe('A "quoted" <b>Agency</b> - PaingThu');
    });
});

describe('the link preview', function () {
    test('it has the title, site name, address, type, locale and a card, and the canonical address', function () {
        SiteSetting::factory()->create(['agency_name' => 'Acme Agency']);

        $html = landingHtml();

        expect(metaOf($html, 'property', 'og:title'))->toBe('Acme Agency - PaingThu')->and(metaOf($html, 'property', 'og:site_name'))->toBe('Acme Agency')
            ->and(metaOf($html, 'property', 'og:type'))->toBe('website')->and(metaOf($html, 'property', 'og:url'))->toBe(route('home'))
            ->and(metaOf($html, 'property', 'og:locale'))->toBe('en_US')->and(metaOf($html, 'property', 'og:locale:alternate'))->toBe('my_MM')
            ->and(metaOf($html, 'name', 'twitter:title'))->toBe('Acme Agency - PaingThu')
            ->and($html)->toContain('<link rel="canonical" href="'.route('home').'">');
    });

    test('the locale follows the language', function () {
        $html = landingHtml('my');

        expect(metaOf($html, 'property', 'og:locale'))->toBe('my_MM')->and(metaOf($html, 'property', 'og:locale:alternate'))->toBe('en_US');
    });

    test('the image is the hero image, else the logo, as a full address, and the card is large only with an image', function () {
        $row = SiteSetting::factory()->create();
        expect(metaOf(landingHtml(), 'property', 'og:image'))->toBeNull()->and(metaOf(landingHtml(), 'name', 'twitter:card'))->toBe('summary');

        $row->forceFill(['logo_key' => 'site/logo.png'])->save();
        $logo = metaOf(landingHtml(), 'property', 'og:image');
        expect($logo)->toStartWith('http')->toContain('site/logo.png')->and(metaOf(landingHtml(), 'name', 'twitter:card'))->toBe('summary_large_image');

        $row->fresh()->forceFill(['hero_image_key' => 'site/hero.jpg'])->save();
        $html = landingHtml();
        expect(metaOf($html, 'property', 'og:image'))->toStartWith('http')->toContain('site/hero.jpg')->and(metaOf($html, 'name', 'twitter:image'))->toContain('site/hero.jpg');
    });

    test('an image address on this site is made a full address', function () {
        config(['filesystems.disks.public_assets.url' => '/storage']);
        SiteSetting::factory()->create()->forceFill(['hero_image_key' => 'site/hero.jpg'])->save();

        expect(metaOf(landingHtml(), 'property', 'og:image'))->toStartWith(rtrim(url('/'), '/').'/storage/site/hero.jpg');
    });

    test('an unpublished website still has the name, image and title, only no description', function () {
        SiteSetting::factory()->create(['agency_name' => 'Acme Agency', 'hero_description' => 'Draft', 'is_published' => false])->forceFill(['hero_image_key' => 'site/hero.jpg'])->save();

        $html = landingHtml();

        expect(metaOf($html, 'property', 'og:title'))->toBe('Acme Agency - PaingThu')->and(metaOf($html, 'property', 'og:image'))->toContain('site/hero.jpg')
            ->and(metaOf($html, 'property', 'og:description'))->toBeNull();
    });
});

describe('only the landing page', function () {
    test('the tracking page, the login page and the portal have no description or preview tags', function () {
        SiteSetting::factory()->create(['agency_name' => 'Acme Agency', 'hero_description' => 'Public text', 'is_published' => true]);
        $client = Client::factory()->create();

        foreach ([route('login'), route('track.show', $client->unique_token)] as $url) {
            $html = test()->get($url)->assertOk()->getContent();

            expect($html)->not->toContain('og:title')->not->toContain('name="description"')->not->toContain('rel="canonical"')->not->toContain('Public text');
        }
    });
});

describe('when the settings cannot be read', function () {
    test('the page still has the app name as its title, no description, and the error is reported', function () {
        Exceptions::fake();
        Schema::drop('site_settings');

        $html = landingHtml();

        expect(titleOf($html))->toBe('PaingThu')->and(metaOf($html, 'name', 'description'))->toBeNull()->and(metaOf($html, 'property', 'og:title'))->toBe('PaingThu');
        Exceptions::assertReported(QueryException::class);
    });
});
