<?php

use App\Enums\ServiceAvailability;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function newServiceInput(array $overrides = []): array
{
    return [
        'title' => 'Visa Extension',
        'title_my' => 'ဗီဇာ သက်တမ်းတိုး',
        'public_description' => 'Visa extension support.',
        'public_description_my' => 'ဗီဇာ သက်တမ်းတိုး ကူညီခြင်း။',
        'parent_id' => null,
        'status' => 'active',
        'default_fee' => '1900.50',
        'has_documents' => false,
        'show_on_website' => true,
        ...$overrides,
    ];
}

function jobSearchService(): Service
{
    return Service::factory()->create(['title' => 'Job Search', 'key' => Service::JOB_SEARCH_KEY]);
}

beforeEach(function () {
    $this->admin = User::factory()->create();
});

test('guests are redirected to the login page', function (string $method, string $routeName) {
    $service = Service::factory()->create();

    $this->{$method}(route($routeName, str_contains($routeName, '.index') || str_contains($routeName, 'create') || str_contains($routeName, 'store') ? [] : $service))
        ->assertRedirect(route('login'));
})->with([
    'index' => ['get', 'admin.services.index'],
    'create' => ['get', 'admin.services.create'],
    'store' => ['post', 'admin.services.store'],
    'show' => ['get', 'admin.services.show'],
    'edit' => ['get', 'admin.services.edit'],
    'update' => ['put', 'admin.services.update'],
    'destroy' => ['delete', 'admin.services.destroy'],
]);

test('users without the superadmin role are refused', function () {
    $service = Service::factory()->create();
    DB::table('users')->where('id', $this->admin->id)->update(['role' => 'staff']);
    $user = $this->admin->fresh();

    $this->actingAs($user)->get(route('admin.services.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.services.store'), newServiceInput())->assertForbidden();
    $this->actingAs($user)->put(route('admin.services.update', $service), newServiceInput())->assertForbidden();
    $this->actingAs($user)->delete(route('admin.services.destroy', $service))->assertForbidden();

    expect(Service::count())->toBe(1);
});

describe('list and detail', function () {
    test('the list shows main services only, with their sub-service counts', function () {
        $passport = Service::factory()->create(['title' => 'Passport']);
        Service::factory()->subServiceOf($passport)->count(2)->create();
        Service::factory()->create(['title' => 'CI']);

        $this->actingAs($this->admin)
            ->get(route('admin.services.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/services/Index')
                ->has('services.data', 2)
                ->where('services.data.0.title', 'Passport')
                ->where('services.data.0.sub_services_count', 2)
                ->where('services.data.1.sub_services_count', 0)
                ->missing('services.data.0.key'));
    });

    test('a main service page lists its sub-services', function () {
        $passport = Service::factory()->create(['default_fee' => '1900.50']);
        Service::factory()->subServiceOf($passport)->create(['title' => 'Bath']);

        $this->actingAs($this->admin)
            ->get(route('admin.services.show', $passport))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/services/Show')
                ->where('service.data.id', $passport->id)
                ->where('service.data.default_fee', '1900.50')
                ->has('subServices.data', 1)
                ->where('subServices.data.0.title', 'Bath')
                ->where('canHaveSubServices', true)
                ->where('canDelete', true));
    });

    test('a sub-service page names its parent and cannot hold sub-services', function () {
        $passport = Service::factory()->create(['title' => 'Passport']);
        $bath = Service::factory()->subServiceOf($passport)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.services.show', $bath))
            ->assertInertia(fn (Assert $page) => $page
                ->where('service.data.parent.title', 'Passport')
                ->has('subServices.data', 0)
                ->where('canHaveSubServices', false));
    });

    test('the Job Search page cannot add sub-services or be deleted', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.services.show', jobSearchService()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('service.data.is_job_search', true)
                ->where('canHaveSubServices', false)
                ->where('canDelete', false));
    });

    test('a deleted service is not found', function () {
        $service = Service::factory()->create();
        $service->delete();

        $this->actingAs($this->admin)->get(route('admin.services.show', $service->id))->assertNotFound();
    });
});

describe('create', function () {
    test('the form offers main services as parents, never Job Search', function () {
        $passport = Service::factory()->create();
        Service::factory()->subServiceOf($passport)->create();
        jobSearchService();

        $this->actingAs($this->admin)
            ->get(route('admin.services.create', ['parent' => $passport->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/services/Create')
                ->has('parentOptions', 1)
                ->where('parentOptions.0.id', $passport->id)
                ->where('defaultParentId', $passport->id));
    });

    test('an unusable ?parent= is ignored', function () {
        $jobSearch = jobSearchService();

        $this->actingAs($this->admin)
            ->get(route('admin.services.create', ['parent' => $jobSearch->id]))
            ->assertInertia(fn (Assert $page) => $page->where('defaultParentId', null));
    });

    test('creates a main service', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.services.store'), newServiceInput())
            ->assertRedirect();

        $service = Service::firstOrFail();

        expect($service->title)->toBe('Visa Extension')
            ->and($service->title_my)->toBe('ဗီဇာ သက်တမ်းတိုး')
            ->and($service->parent_id)->toBeNull()
            ->and($service->key)->toBeNull()
            ->and($service->default_fee)->toBe('1900.50')
            ->and($service->show_on_website)->toBeTrue()
            ->and($service->status)->toBe(ServiceAvailability::Active);
    });

    test('creates a sub-service that never shows on the website', function () {
        $passport = Service::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.services.store'), newServiceInput(['parent_id' => $passport->id, 'show_on_website' => true]))
            ->assertRedirect();

        $sub = Service::where('parent_id', $passport->id)->firstOrFail();

        expect($sub->show_on_website)->toBeFalse();
    });

    test('there is no limit on the number of sub-services', function () {
        $passport = Service::factory()->create();

        foreach (range(1, 6) as $number) {
            $this->actingAs($this->admin)
                ->post(route('admin.services.store'), newServiceInput(['title' => "Sub {$number}", 'title_my' => null, 'parent_id' => $passport->id]))
                ->assertSessionHasNoErrors();
        }

        expect($passport->children()->count())->toBe(6);
    });

    test('a title in one language is enough and the description is optional', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.services.store'), newServiceInput([
                'title' => null,
                'public_description' => null,
                'public_description_my' => null,
            ]))
            ->assertSessionHasNoErrors();

        expect(Service::count())->toBe(1)
            ->and(Service::first()->public_description)->toBeNull();
    });

    test('a service needs a title in at least one language', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.services.store'), newServiceInput([
                'title' => null,
                'title_my' => null,
            ]))
            ->assertSessionHasErrors(['title', 'title_my'])
            ->assertSessionDoesntHaveErrors(['public_description', 'public_description_my']);

        expect(Service::count())->toBe(0);
    });

    test('rejects a parent that is a sub-service, Job Search, deleted or missing', function (Closure $makeParent) {
        $parentId = $makeParent();

        $this->actingAs($this->admin)
            ->post(route('admin.services.store'), newServiceInput(['parent_id' => $parentId]))
            ->assertSessionHasErrors('parent_id');

        expect(Service::where('title', 'Visa Extension')->exists())->toBeFalse();
    })->with([
        'a sub-service' => [fn () => Service::factory()->subServiceOf(Service::factory()->create())->create()->id],
        'Job Search' => [fn () => jobSearchService()->id],
        'a deleted service' => [function () {
            $service = Service::factory()->create();
            $service->delete();

            return $service->id;
        }],
        'a missing service' => [fn () => 9999],
    ]);

    test('rejects a bad status, fee or flag', function (string $field, mixed $value) {
        $this->actingAs($this->admin)
            ->post(route('admin.services.store'), newServiceInput([$field => $value]))
            ->assertSessionHasErrors($field);
    })->with([
        'status' => ['status', 'archived'],
        'negative fee' => ['default_fee', '-1'],
        'fee with three decimals' => ['default_fee', '10.123'],
        'fee that is not a number' => ['default_fee', 'abc'],
        'documents flag' => ['has_documents', 'maybe'],
    ]);

    test('an empty fee is allowed and the system key cannot be set from the form', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.services.store'), newServiceInput(['default_fee' => null, 'key' => Service::JOB_SEARCH_KEY]))
            ->assertSessionHasNoErrors();

        $service = Service::firstOrFail();

        expect($service->default_fee)->toBeNull()
            ->and($service->key)->toBeNull();
    });
});

describe('edit and update', function () {
    test('the edit form lists the other main services as parents', function () {
        $passport = Service::factory()->create();
        $ci = Service::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.services.edit', $ci))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/services/Edit')
                ->has('parentOptions', 1)
                ->where('parentOptions.0.id', $passport->id));
    });

    test('a service with sub-services and Job Search get no parent options', function () {
        $passport = Service::factory()->create();
        Service::factory()->subServiceOf($passport)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.services.edit', $passport))
            ->assertInertia(fn (Assert $page) => $page->has('parentOptions', 0));

        $this->actingAs($this->admin)
            ->get(route('admin.services.edit', jobSearchService()))
            ->assertInertia(fn (Assert $page) => $page->has('parentOptions', 0));
    });

    test('updates a service and can deactivate it', function () {
        $service = Service::factory()->create(['default_fee' => 100]);

        $this->actingAs($this->admin)
            ->put(route('admin.services.update', $service), newServiceInput(['status' => 'inactive', 'default_fee' => '250']))
            ->assertRedirect(route('admin.services.show', $service));

        $service->refresh();

        expect($service->status)->toBe(ServiceAvailability::Inactive)
            ->and($service->default_fee)->toBe('250.00')
            ->and($service->title)->toBe('Visa Extension');
    });

    test('moves a main service without sub-services under another service', function () {
        $passport = Service::factory()->create();
        $service = Service::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('admin.services.update', $service), newServiceInput(['parent_id' => $passport->id]))
            ->assertSessionHasNoErrors();

        expect($service->refresh()->parent_id)->toBe($passport->id)
            ->and($service->show_on_website)->toBeFalse();
    });

    test('editing a sub-service never puts it on the website', function () {
        $passport = Service::factory()->create();
        $sub = Service::factory()->subServiceOf($passport)->create();

        $this->actingAs($this->admin)
            ->put(route('admin.services.update', $sub), newServiceInput(['parent_id' => $passport->id, 'show_on_website' => true]))
            ->assertSessionHasNoErrors();

        expect($sub->refresh()->show_on_website)->toBeFalse()
            ->and($sub->title)->toBe('Visa Extension');
    });

    test('the fee is sent to the page as a decimal string, never a float', function () {
        $service = Service::factory()->create(['default_fee' => '8000.00']);
        $feeless = Service::factory()->create(['default_fee' => null]);

        $this->actingAs($this->admin)
            ->get(route('admin.services.show', $service))
            ->assertInertia(fn (Assert $page) => $page->where('service.data.default_fee', '8000.00'));

        $this->get(route('admin.services.show', $feeless))
            ->assertInertia(fn (Assert $page) => $page->where('service.data.default_fee', null));
    });

    test('a sub-service can become a main service again', function () {
        $passport = Service::factory()->create();
        $sub = Service::factory()->subServiceOf($passport)->create();

        $this->actingAs($this->admin)
            ->put(route('admin.services.update', $sub), newServiceInput(['parent_id' => null]))
            ->assertSessionHasNoErrors();

        expect($sub->refresh()->parent_id)->toBeNull();
    });

    test('rejects an invalid parent when editing', function (Closure $makeCase) {
        [$service, $parentId] = $makeCase();

        $this->actingAs($this->admin)
            ->put(route('admin.services.update', $service), newServiceInput(['parent_id' => $parentId]))
            ->assertSessionHasErrors('parent_id');

        expect($service->refresh()->parent_id)->toBeNull();
    })->with([
        'itself' => [function () {
            $service = Service::factory()->create();

            return [$service, $service->id];
        }],
        'a service that has sub-services under another service' => [function () {
            $passport = Service::factory()->create();
            Service::factory()->subServiceOf($passport)->create();

            return [$passport, Service::factory()->create()->id];
        }],
        'Job Search under another service' => [fn () => [jobSearchService(), Service::factory()->create()->id]],
    ]);

    test('Job Search keeps its system key whatever the form sends', function () {
        $jobSearch = jobSearchService();

        $this->actingAs($this->admin)
            ->put(route('admin.services.update', $jobSearch), newServiceInput(['title' => 'Jobs', 'key' => 'other']))
            ->assertSessionHasNoErrors();

        expect($jobSearch->refresh()->key)->toBe(Service::JOB_SEARCH_KEY)
            ->and($jobSearch->title)->toBe('Jobs');
    });
});

describe('delete', function () {
    test('soft-deletes a main service and leaves its sub-services in the database', function () {
        $passport = Service::factory()->create();
        $bath = Service::factory()->subServiceOf($passport)->create();

        $this->actingAs($this->admin)
            ->delete(route('admin.services.destroy', $passport))
            ->assertRedirect(route('admin.services.index'));

        expect(Service::find($passport->id))->toBeNull()
            ->and(Service::withTrashed()->find($passport->id))->not->toBeNull()
            ->and(Service::find($bath->id))->not->toBeNull()
            ->and($bath->refresh()->deleted_at)->toBeNull();
    });

    test('deleting a sub-service returns to its main service and keeps the row', function () {
        $passport = Service::factory()->create();
        $bath = Service::factory()->subServiceOf($passport)->create();

        $this->actingAs($this->admin)
            ->delete(route('admin.services.destroy', $bath))
            ->assertRedirect(route('admin.services.show', $passport));

        expect(Service::withTrashed()->find($bath->id)?->deleted_at)->not->toBeNull()
            ->and($passport->children()->count())->toBe(0);
    });

    test('the Job Search service cannot be deleted', function () {
        $jobSearch = jobSearchService();

        $this->actingAs($this->admin)
            ->delete(route('admin.services.destroy', $jobSearch))
            ->assertForbidden();

        expect(Service::find($jobSearch->id))->not->toBeNull();
    });
});
