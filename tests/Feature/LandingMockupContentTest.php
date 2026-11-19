<?php

use App\Models\Client;
use App\Models\ClientService;
use App\Models\Service;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Invented and mockup facts are not shown on the landing page (HLD Phase 5, D4): the mockup phone, email and address, the
 * "10+ years" claim. The statistics, the services and the Admin Login button stay.
 */
function landingSource(): string
{
    return file_get_contents(resource_path('js/pages/Welcome.vue'))
        .file_get_contents(resource_path('js/locales/en.ts'))
        .file_get_contents(resource_path('js/locales/my.ts'))
        .file_get_contents(resource_path('js/layouts/PublicLayout.vue'));
}

test('the mockup contact details and the invented experience claim are gone from the page and the translations', function () {
    $source = landingSource();

    expect($source)
        ->not->toContain('+66 2 123 4567')->not->toContain('66212')
        ->not->toContain('info@example.com')
        ->not->toContain('Bangkok, Thailand')
        ->not->toContain('10+ years')
        ->not->toContain('experienceTitle')->not->toContain('experienceText')
        ->not->toContain('data-test="contact-section"')
        ->not->toContain('tel:')->not->toContain('mailto:')
        ->not->toContain('href="#contact"')
        ->not->toContain('landing.contact');
});

test('the About section no longer has an Experience card', function () {
    $page = file_get_contents(resource_path('js/pages/Welcome.vue'));

    expect($page)->not->toContain("key: 'experience'")
        ->toContain("key: 'mission'")->toContain("key: 'help'");
});

test('the statistics and the services are not affected, and the page is served to guests', function () {
    $service = Service::factory()->create(['title' => 'Work Permit']);
    ClientService::factory()->forService($service)->create(['client_id' => Client::factory()->create()->id]);

    $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Welcome')
        ->where('statistics.clients_served', 1)
        ->where('statistics.services_provided', 1)
        ->has('services', 1)
        ->where('services.0.title', 'Work Permit'));
});

test('Admin Login stays visible in the header and still points to the admin login', function () {
    $layout = file_get_contents(resource_path('js/layouts/PublicLayout.vue'));

    expect($layout)->toContain("t('common.adminLogin')")->toContain(':href="login()"');
    $this->get(route('login'))->assertOk();
});
