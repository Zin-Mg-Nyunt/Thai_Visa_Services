<?php

use App\Enums\ServiceIcon;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\ServiceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Each service has an icon chosen per service (services.icon, a Lucide name from the ServiceIcon allowlist): set by the seeder for
 * the starting catalogue, chosen by the admin in the service form, shown on the service's landing page card. A missing or unknown
 * value is shown as the fallback icon.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function iconServiceInput(array $overrides = []): array
{
    return [
        'title' => 'Visa Extension',
        'title_my' => 'ဗီဇာ သက်တမ်းတိုး',
        'public_description' => 'Visa extension support.',
        'public_description_my' => null,
        'parent_id' => null,
        'status' => 'active',
        'default_fee' => null,
        'has_documents' => false,
        'show_on_website' => true,
        'icon' => 'plane',
        ...$overrides,
    ];
}

/** @return list<array<string, mixed>> */
function landingServicesWithIcons(): array
{
    return test()->get(route('home'))->assertOk()->viewData('page')['props']['services'];
}

/** The icon names of the browser's allowlist, read from the keys of SERVICE_ICONS in resources/js/lib/serviceIcons.ts. */
function frontendServiceIconNames(): array
{
    $source = file_get_contents(resource_path('js/lib/serviceIcons.ts'));
    preg_match('/export const SERVICE_ICONS = \{(.*?)\} as const/s', $source, $block);
    preg_match_all("/^\s*'?([a-z0-9-]+)'?:\s*[A-Za-z0-9]+,\s*$/m", $block[1] ?? '', $names);

    return $names[1];
}

describe('schema', function () {
    test('the services table migration creates a nullable icon column', function () {
        $column = collect(Schema::getColumns('services'))->firstWhere('name', 'icon');

        expect($column)->not->toBeNull()
            ->and($column['nullable'])->toBeTrue()
            ->and($column['type_name'])->toBeIn(['varchar', 'text']);
    });

    test('no separate migration adds the icon column: it is in the create migration', function () {
        $files = collect(glob(database_path('migrations/*.php')));

        expect($files->filter(fn (string $file) => str_contains(file_get_contents($file), "'icon'"))->map(fn ($file) => basename($file))->values()->all())
            ->toBe(['2026_11_03_105604_create_services_table.php']);
    });
});

describe('seeded catalogue', function () {
    beforeEach(fn () => $this->seed(ServiceSeeder::class));

    test('the six main services get their own icons, set explicitly by the seeder', function () {
        expect(Service::main()->orderBy('id')->pluck('icon', 'title')->all())->toBe([
            'Passport' => 'book-user',
            'CI' => 'file-badge',
            'Pink Card' => 'id-card',
            'Work Permit' => 'file-check',
            '90 Days Report' => 'calendar-clock',
            'Job Search' => 'handshake',
        ]);
    });

    test('every seeded icon is allowed and no two main services share one', function () {
        $icons = Service::main()->pluck('icon');

        expect($icons->every(fn (?string $icon) => ServiceIcon::tryFrom((string) $icon) !== null))->toBeTrue()
            ->and($icons->unique())->toHaveCount(6);
    });

    test('the sub-services have no icon, because they are never shown on the website', function () {
        expect(Service::whereNotNull('parent_id')->pluck('icon')->all())->toBe([null, null]);
    });

    test('running the seeder again creates no duplicate and does not undo an icon the admin changed', function () {
        Service::where('title', 'CI')->update(['icon' => 'plane']);

        $this->seed(ServiceSeeder::class);

        expect(Service::withTrashed()->count())->toBe(8)
            ->and(Service::where('title', 'CI')->value('icon'))->toBe('plane');
    });

    test('the landing page shows each seeded main service with its own icon', function () {
        expect(collect(landingServicesWithIcons())->pluck('icon', 'title')->all())->toBe([
            'Passport' => 'book-user',
            'CI' => 'file-badge',
            'Pink Card' => 'id-card',
            'Work Permit' => 'file-check',
            '90 Days Report' => 'calendar-clock',
            'Job Search' => 'handshake',
        ]);
    });
});

describe('admin create and edit', function () {
    beforeEach(function () {
        $this->admin = User::factory()->create();
    });

    test('a service is created with an approved icon', function () {
        $this->actingAs($this->admin)->post(route('admin.services.store'), iconServiceInput(['icon' => 'plane']))
            ->assertSessionHasNoErrors()->assertRedirect();

        expect(Service::where('title', 'Visa Extension')->value('icon'))->toBe('plane');
    });

    test('the icon of a service is changed, and the edit form gets the saved icon', function () {
        $service = Service::factory()->create(['icon' => 'plane']);

        $this->actingAs($this->admin)->get(route('admin.services.edit', $service))
            ->assertInertia(fn (Assert $page) => $page->component('admin/services/Edit')->where('service.data.icon', 'plane'));

        $this->actingAs($this->admin)->put(route('admin.services.update', $service), iconServiceInput(['icon' => 'landmark']))
            ->assertSessionHasNoErrors()->assertRedirect();

        expect($service->fresh()->icon)->toBe('landmark');
    });

    test('a name that is not on the allowlist is refused on create and on update, and nothing is stored', function (mixed $icon) {
        $service = Service::factory()->create(['icon' => 'plane']);

        $this->actingAs($this->admin)->post(route('admin.services.store'), iconServiceInput(['title' => 'Refused', 'icon' => $icon]))
            ->assertSessionHasErrors(['icon' => __('Choose one of the icons in the list.')]);
        $this->actingAs($this->admin)->put(route('admin.services.update', $service), iconServiceInput(['icon' => $icon]))
            ->assertSessionHasErrors('icon');

        expect(Service::where('title', 'Refused')->exists())->toBeFalse()
            ->and($service->fresh()->icon)->toBe('plane');
    })->with([
        'a Lucide icon not in the list' => ['trash-2'],
        'a component name' => ['BookUser'],
        'markup' => ['<script>alert(1)</script>'],
        'a path' => ['../../app'],
        'an array' => [['plane']],
        'a number' => [42],
    ]);

    test('no icon is allowed and is stored as null; the page then shows the fallback', function () {
        $this->actingAs($this->admin)->post(route('admin.services.store'), iconServiceInput(['icon' => null]))
            ->assertSessionHasNoErrors();

        $service = Service::where('title', 'Visa Extension')->firstOrFail();

        expect($service->icon)->toBeNull()
            ->and(landingServicesWithIcons()[0]['icon'])->toBeNull();
    });

    test('leaving the icon out of an update keeps nothing else from saving', function () {
        $service = Service::factory()->create(['icon' => 'plane']);
        $input = iconServiceInput(['title' => 'Renamed']);
        unset($input['icon']);

        $this->actingAs($this->admin)->put(route('admin.services.update', $service), $input)->assertSessionHasNoErrors();

        expect($service->fresh()->title)->toBe('Renamed')->and($service->fresh()->icon)->toBe('plane');
    });

    test('a changed icon shows on the landing page on the next request', function () {
        $service = Service::factory()->create(['icon' => 'plane']);

        expect(landingServicesWithIcons()[0]['icon'])->toBe('plane');

        $this->actingAs($this->admin)->put(route('admin.services.update', $service), iconServiceInput(['icon' => 'globe']));
        auth()->logout();

        expect(landingServicesWithIcons()[0]['icon'])->toBe('globe');
    });
});

describe('landing page and fallback', function () {
    test('each main service card gets its own icon; sub-services stay off the page whatever icon they have', function () {
        $passport = Service::factory()->create(['title' => 'Passport', 'icon' => 'book-user']);
        Service::factory()->create(['title' => 'Work Permit', 'icon' => 'file-check']);
        $sub = Service::factory()->subServiceOf($passport)->create(['title' => 'MOU', 'icon' => 'stamp']);
        DB::table('services')->where('id', $sub->id)->update(['show_on_website' => true]);

        expect(collect(landingServicesWithIcons())->pluck('icon', 'title')->all())->toBe(['Passport' => 'book-user', 'Work Permit' => 'file-check']);
    });

    test('a value in the database that is not on the allowlist never leaves the server', function () {
        $service = Service::factory()->create(['icon' => 'plane']);
        DB::table('services')->where('id', $service->id)->update(['icon' => '<img src=x onerror=alert(1)>']);

        expect(landingServicesWithIcons()[0]['icon'])->toBeNull();

        $this->actingAs(User::factory()->create())->get(route('admin.services.edit', $service))
            ->assertInertia(fn (Assert $page) => $page->where('service.data.icon', null));
    });

    test('the page renders only components from the allowlist map, with the fallback for anything else', function () {
        $page = file_get_contents(resource_path('js/pages/Welcome.vue'));
        $cards = file_get_contents(resource_path('js/lib/landingSections.ts'));

        expect($page)->toContain(':is="SERVICE_ICONS[service.icon]"')
            ->and($cards)->toContain('icon: serviceIconName(service.icon)');
    });
});

describe('one allowlist', function () {
    test('the browser list is exactly the server allowlist, in the same order', function () {
        expect(frontendServiceIconNames())->toBe(ServiceIcon::values())->toHaveCount(count(ServiceIcon::cases()));
    });

    test('the browser and the server use the same fallback icon', function () {
        expect(file_get_contents(resource_path('js/lib/serviceIcons.ts')))
            ->toContain("FALLBACK_SERVICE_ICON: ServiceIconName = '".ServiceIcon::FALLBACK->value."'");
    });

    test('every allowed name is a real icon of the installed Lucide package', function () {
        $icons = base_path('node_modules/@lucide/vue/dist/esm/icons');

        if (! is_dir($icons)) {
            $this->markTestSkipped('The Lucide package is not installed (run npm install).');
        }

        foreach (ServiceIcon::values() as $name) {
            expect(file_exists("{$icons}/{$name}.mjs"))->toBeTrue("{$name} is not a Lucide icon");
        }
    });

    test('every allowed icon has a name in English and in Burmese for the picker', function () {
        foreach (['en', 'my'] as $language) {
            preg_match('/^        icons: \{(.*?)^        \},/ms', file_get_contents(resource_path("js/locales/{$language}.ts")), $block);
            $translations = $block[1] ?? '';

            foreach (ServiceIcon::values() as $name) {
                $key = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $name))));

                expect(preg_match("/^\s+{$key}:/m", $translations))->toBe(1, "{$language}: no picker name for {$name}");
            }
        }
    });
});
