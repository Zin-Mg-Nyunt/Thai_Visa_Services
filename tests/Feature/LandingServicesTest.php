<?php

use App\Actions\Landing\LandingServices;
use App\Enums\ServiceAvailability;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The services on the public landing page come from the services catalogue: main services that are active and marked "show on
 * website". See App\Actions\Landing\LandingServices.
 */
beforeEach(function () {
    app()->setLocale('en');
});

/** @return list<array<string, mixed>> */
function landingServices(): array
{
    return test()->get(route('home'))->assertOk()->viewData('page')['props']['services'];
}

test('visible, active main services are shown in the order they were created, with both languages', function () {
    $first = Service::factory()->create(['title' => 'Work Permit', 'title_my' => 'အလုပ်လုပ်ခွင့်', 'public_description' => 'Permits.', 'public_description_my' => 'ခွင့်ပြုချက်']);
    $second = Service::factory()->create(['title' => 'Passport', 'title_my' => null, 'public_description' => null]);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->component('Welcome')
        ->has('services', 2)
        ->where('services.0.id', $first->id)
        ->where('services.0.title', 'Work Permit')
        ->where('services.0.title_my', 'အလုပ်လုပ်ခွင့်')
        ->where('services.0.public_description', 'Permits.')
        ->where('services.0.public_description_my', 'ခွင့်ပြုချက်')
        ->where('services.1.id', $second->id)
        ->where('services.1.title_my', null)
        ->where('services.1.public_description', null));
});

test('hidden, inactive, deleted and sub-services are not shown', function () {
    $main = Service::factory()->create(['title' => 'Shown']);
    Service::factory()->create(['title' => 'Hidden', 'show_on_website' => false]);
    Service::factory()->create(['title' => 'Inactive', 'status' => ServiceAvailability::Inactive]);
    Service::factory()->create(['title' => 'Deleted'])->delete();
    $parent = Service::factory()->create(['title' => 'Parent hidden', 'show_on_website' => false]);
    $sub = Service::factory()->subServiceOf($parent)->create(['title' => 'Sub']);
    // Even if the flag is forced in the database, a sub-service never appears.
    DB::table('services')->where('id', $sub->id)->update(['show_on_website' => true]);

    expect(array_column(landingServices(), 'title'))->toBe(['Shown']);
    expect(landingServices()[0]['id'])->toBe($main->id);
});

test('Job Search appears only when it is marked show on website', function () {
    $jobSearch = Service::factory()->jobSearch()->create(['show_on_website' => false]);

    expect(landingServices())->toBe([]);

    $jobSearch->update(['show_on_website' => true]);

    expect(array_column(landingServices(), 'title'))->toBe(['Job Search']);
});

test('an admin edit shows on the very next request', function () {
    $service = Service::factory()->create(['title' => 'Before']);

    expect(landingServices()[0]['title'])->toBe('Before');

    $service->update(['title' => 'After']);

    expect(landingServices()[0]['title'])->toBe('After');

    $service->update(['show_on_website' => false]);

    expect(landingServices())->toBe([]);
});

test('only the two titles and two public descriptions leave the server', function () {
    Service::factory()->create(['default_fee' => '8000.00', 'has_documents' => true]);

    expect(array_keys(landingServices()[0]))->toBe(['id', 'title', 'title_my', 'public_description', 'public_description_my']);
});

test('with no service to show the list is empty and the section is hidden', function () {
    Service::factory()->create(['show_on_website' => false]);

    expect(landingServices())->toBe([]);
    expect(file_get_contents(resource_path('js/pages/Welcome.vue')))->toContain('v-if="serviceCards.length > 0"');
});

test('the services cost exactly one query', function () {
    Service::factory()->count(3)->create();

    DB::enableQueryLog();
    app(LandingServices::class)->forPage();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);
});

test('no hard-coded service is left in the page or the translations, and the language fallback is used', function () {
    $page = file_get_contents(resource_path('js/pages/Welcome.vue'));

    expect($page)->not->toContain('workPermit')->not->toContain('pinkCard')->not->toContain('jobSearch')
        ->toContain('serviceCardsOf(props.services)')
        ->toContain('localized(');

    expect(file_get_contents(resource_path('js/lib/landingSections.ts')))->toContain('localized(service.title, service.title_my)')->toContain('service.public_description_my');

    foreach (['en', 'my'] as $language) {
        $translations = file_get_contents(resource_path("js/locales/{$language}.ts"));

        expect($translations)->not->toContain('workPermitTitle')->not->toContain('jobSearchText')->not->toContain('pinkCardTitle');
    }
});
