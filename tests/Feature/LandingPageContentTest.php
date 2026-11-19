<?php

use App\Actions\Landing\LandingStatistics;
use App\Actions\Site\SiteSettings;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The landing page follows the website settings (Section 4.10): the admin's texts and contact details show only once the website is
 * published, sections without content are hidden, links are built only from filled and valid fields, and only the statistics the
 * admin approved are shown. See App\Actions\Site\SiteSettings and App\Http\Controllers\WelcomeController.
 */
beforeEach(function () {
    app()->setLocale('en');
    Cache::forget(SiteSettings::CACHE_KEY);
});

/** @param  array<string, mixed>  $state */
function contentSettings(array $state = [], bool $published = true): SiteSetting
{
    return SiteSetting::factory()->create([
        'agency_name' => 'PaingThu', 'hero_heading' => 'Real heading', 'hero_heading_my' => 'ခေါင်းစဉ်', 'hero_description' => 'Real description',
        'overview' => 'Overview text', 'experience' => 'Experience text', 'mission' => 'Mission text', 'mission_my' => 'ရည်မှန်းချက်',
        'how_we_help' => 'Help text', 'address' => '1 Road, Bangkok', 'address_my' => 'လိပ်စာ', 'phone' => '+66 2 123 4567',
        'email' => 'hello@example.test', 'messaging_link' => 'https://line.me/ti/p/abc', 'social_links' => ['facebook' => 'https://facebook.com/x'],
        'is_published' => $published, ...$state,
    ]);
}

/** @return array<string, mixed> */
function landingSite(): array
{
    return test()->get(route('home'))->assertOk()->viewData('page')['props']['site'];
}

describe('before the website is published', function () {
    test('none of the admin\'s texts or contact details reach the page, but the name, logo and hero image do', function () {
        contentSettings(['logo_key' => 'site/logo.png', 'hero_image_key' => 'site/hero.jpg'], published: false);

        $site = landingSite();

        expect($site['agency_name'])->toBe('PaingThu')->and($site['logo_url'])->toContain('site/logo.png')->and($site['hero_image_url'])->toContain('site/hero.jpg');

        foreach (['hero_heading', 'hero_heading_my', 'hero_description', 'overview', 'experience', 'mission', 'mission_my', 'how_we_help', 'address', 'address_my', 'phone', 'phone_tel', 'email', 'messaging_link'] as $field) {
            expect($site[$field])->toBeNull();
        }

        expect($site['social_links'])->toBe([])->and(json_encode($site))->not->toContain('Real heading')->not->toContain('4567')->not->toContain('hello@example.test');
    });

    test('the services, the approved statistics and the Admin Login button still show', function () {
        contentSettings(['approved_statistics' => ['clients_served']], published: false);
        ClientService::factory()->forService(Service::factory()->create(['title' => 'Work Permit']))->create(['client_id' => Client::factory()->create()->id]);

        $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('statistics', ['clients_served' => 1])->has('services', 1)->where('services.0.title', 'Work Permit'));
        expect(file_get_contents(resource_path('js/layouts/PublicLayout.vue')))->toContain("t('common.adminLogin')");
    });
});

describe('once it is published', function () {
    test('the texts, the phone number, the email, the address and the social links are sent, in both languages', function () {
        contentSettings();

        $site = landingSite();

        expect($site)->toMatchArray([
            'hero_heading' => 'Real heading', 'hero_heading_my' => 'ခေါင်းစဉ်', 'hero_description' => 'Real description', 'hero_description_my' => null,
            'overview' => 'Overview text', 'experience' => 'Experience text', 'mission' => 'Mission text', 'mission_my' => 'ရည်မှန်းချက်',
            'how_we_help' => 'Help text', 'how_we_help_my' => null, 'address' => '1 Road, Bangkok', 'address_my' => 'လိပ်စာ',
            'phone' => '+66 2 123 4567', 'phone_tel' => '+6621234567', 'email' => 'hello@example.test', 'messaging_link' => 'https://line.me/ti/p/abc',
            'social_links' => ['facebook' => 'https://facebook.com/x'],
        ]);
    });

    test('a field that is empty is sent as empty, so its section is hidden', function () {
        contentSettings(['experience' => '  ', 'phone' => null, 'email' => null, 'address' => null, 'address_my' => null, 'social_links' => null]);

        $site = landingSite();

        expect($site['experience'])->toBeNull()->and($site['phone'])->toBeNull()->and($site['phone_tel'])->toBeNull()
            ->and($site['email'])->toBeNull()->and($site['address'])->toBeNull()->and($site['social_links'])->toBe([]);
    });

    test('the messaging link is sent only when it is a full http or https address', function (?string $link, ?string $expected) {
        contentSettings(['messaging_link' => $link]);

        expect(landingSite()['messaging_link'])->toBe($expected);
    })->with([
        'https' => ['https://line.me/ti/p/abc', 'https://line.me/ti/p/abc'],
        'javascript' => ['javascript:alert(1)', null],
        'no scheme' => ['line.me/abc', null],
        'empty' => [null, null],
    ]);

    test('a phone number is cut down to digits and a leading plus for the tel link', function (string $phone, ?string $tel) {
        contentSettings(['phone' => $phone]);

        expect(landingSite()['phone_tel'])->toBe($tel);
    })->with([
        'international' => ['+66 (0) 2-123.4567', '+66021234567'],
        'local' => ['081 234 5678', '0812345678'],
        'no digit' => ['call us', null],
    ]);

    test('even if bad data is in the database, an invalid email and a link that is not http or https are never sent', function () {
        contentSettings(['email' => 'not an email', 'messaging_link' => 'javascript:alert(1)', 'social_links' => ['facebook' => 'https://facebook.com/x', 'instagram' => 'javascript:alert(1)', 'tiktok' => 'data:text/html,x', 'youtube' => '//evil.test']]);

        $site = landingSite();

        expect($site['email'])->toBeNull()->and($site['messaging_link'])->toBeNull()->and($site['social_links'])->toBe(['facebook' => 'https://facebook.com/x']);
    });

    test('publishing on the Website content screen shows the texts on the very next visit', function () {
        $admin = User::factory()->create();
        $row = contentSettings(published: false);
        $row->forceFill(['logo_key' => 'site/logo.png', 'favicon_key' => 'site/fav.png'])->save();
        expect(landingSite()['hero_heading'])->toBeNull();

        $this->actingAs($admin)->postJson(route('admin.settings.content.publish'), ['published' => true])->assertRedirect();
        auth()->logout();

        expect(landingSite()['hero_heading'])->toBe('Real heading');
    });
});

describe('the statistics', function () {
    function twoServices(): void
    {
        ClientService::factory()->forService(Service::factory()->create())->create(['client_id' => Client::factory()->create()->id]);
    }

    test('only the ticked ones are shown', function (array $approved, ?array $expected) {
        contentSettings(['approved_statistics' => $approved]);
        twoServices();

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('statistics', $expected));
    })->with([
        'none ticked' => [[], null],
        'only clients' => [['clients_served'], ['clients_served' => 1]],
        'only services' => [['services_provided'], ['services_provided' => 1]],
        'both' => [['clients_served', 'services_provided'], ['clients_served' => 1, 'services_provided' => 1]],
        'an unknown name is ignored' => [['profit', 'clients_served'], ['clients_served' => 1]],
    ]);

    test('with nothing saved yet no statistic is shown', function () {
        twoServices();

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('statistics', null));
    });

    test('a ticked statistic whose number is zero is not shown', function () {
        contentSettings(['approved_statistics' => ['clients_served', 'services_provided']]);

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('statistics', null));
    });

    test('a ticked statistic whose number is zero is hidden while a ticked one that is not zero stays', function () {
        contentSettings(['approved_statistics' => ['clients_served', 'services_provided']]);
        Cache::put(LandingStatistics::CACHE_KEY, ['clients_served' => 0, 'services_provided' => 5], 600);

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('statistics', ['services_provided' => 5]));
    });

    test('approving a statistic does not let the admin type a number: the value always comes from the database', function () {
        contentSettings(['approved_statistics' => ['clients_served']]);
        twoServices();
        twoServices();

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('statistics.clients_served', 2));
    });
});

describe('when the settings cannot be read', function () {
    test('the page still works with the built-in texts, no contact section and no statistics, and the error is reported', function () {
        Exceptions::fake();
        twoServices();
        Schema::drop('site_settings');

        $site = $this->get(route('home'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('statistics', null)->has('services', 1))
            ->viewData('page')['props']['site'];

        expect($site['hero_heading'])->toBeNull()->and($site['phone_tel'])->toBeNull()->and($site['social_links'])->toBe([]);
        Exceptions::assertReported(QueryException::class);
    });
});

describe('the page', function () {
    test('every contact link is built from a filled field only, and an outside link opens safely', function () {
        $page = file_get_contents(resource_path('js/pages/Welcome.vue'));

        expect($page)->toContain('v-if="hasContact"')->toContain('v-if="site.phone_tel"')->toContain('`tel:${site.phone_tel}`')
            ->toContain('props.site.email')->toContain('`mailto:${props.site.email}`')
            ->toContain('v-if="site.messaging_link"')->toContain(':href="site.messaging_link"')
            ->toContain('target="_blank"')->toContain('rel="noopener noreferrer"')
            ->toContain('v-if="serviceCards.length > 0"')
            ->not->toContain('v-html')->not->toContain('+66 2 123 4567')->not->toContain('info@example.com');
    });

    test('"Message us" uses the messaging link, not the email, and is hidden when there is no link', function () {
        $page = file_get_contents(resource_path('js/pages/Welcome.vue'));
        $messageUs = substr($page, strpos($page, 'v-if="site.messaging_link"'), 420);

        expect($messageUs)->toContain('data-test="message-us"')->not->toContain('mailto:');
    });

    test('the contact details are in one line from the medium screens up, with the phone and the email as links', function () {
        $page = file_get_contents(resource_path('js/pages/Welcome.vue'));

        expect($page)->toContain('md:flex-row')->toContain('data-test="contact-details"')->toContain('aria-hidden="true"')
            ->toContain("key: 'phone'")->toContain("key: 'email'")->toContain("key: 'address'");
    });

    test('the statistic labels are in sentence case', function () {
        $english = file_get_contents(resource_path('js/locales/en.ts'));

        expect($english)->toContain("clients: 'Clients served'")->toContain("services: 'Services provided'")
            ->not->toContain('Clients Served')->not->toContain('Services Provided');
    });

    test('the navigation bar links to the sections that are on the page, on large screens and as a row on small ones', function () {
        $layout = file_get_contents(resource_path('js/layouts/PublicLayout.vue'));
        $lib = file_get_contents(resource_path('js/lib/landingSections.ts'));
        $page = file_get_contents(resource_path('js/pages/Welcome.vue'));

        expect($layout)->toContain('data-test="site-nav"')->toContain('data-test="site-nav-mobile"')->toContain('navSections(')
            ->toContain('`#${section.id}`')->toContain("t('common.adminLogin')")
            ->and($lib)->toContain("id: 'about'")->toContain("id: 'services'")->toContain("id: 'contact'")
            ->toContain('shown: serviceCardsOf(services).length > 0')->toContain('shown: hasContactDetails(site)')
            // Every link points at an id that is on the page.
            ->and($page)->toContain('id="about"')->toContain('id="services"')->toContain('id="contact"');
    });

    test('where the admin wrote nothing the built-in text is the fallback, except for Experience', function () {
        $page = file_get_contents(resource_path('js/pages/Welcome.vue'));

        expect($page)->toContain("t('landing.hero.description')")->toContain("t('landing.about.missionText')")->toContain("t('landing.about.helpText')")
            ->toContain('<template v-if="heroHeading">')->toContain('<HeroIllustration v-else />')
            ->and($page)->not->toContain("t('landing.about.experienceText')");
    });

    test('Admin Login is always in the header, whatever the settings are', function () {
        $layout = file_get_contents(resource_path('js/layouts/PublicLayout.vue'));

        expect($layout)->toContain("t('common.adminLogin')")->not->toContain('is_published')->not->toContain('v-if="site?.is_published"');
        contentSettings(published: false);
        $this->get(route('home'))->assertOk();
    });
});
