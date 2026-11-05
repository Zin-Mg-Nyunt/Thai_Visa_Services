<?php

use App\Enums\ServiceAvailability;
use App\Models\Service;
use Database\Seeders\ServiceSeeder;
use Illuminate\Database\QueryException;

function seededService(string $title): Service
{
    return Service::where('title', $title)->firstOrFail();
}

describe('seeded catalogue', function () {
    beforeEach(fn () => $this->seed(ServiceSeeder::class));

    test('creates the six services and the two Passport sub-services', function () {
        expect(Service::whereNull('parent_id')->orderBy('id')->pluck('title')->all())
            ->toBe(['Passport', 'CI', 'Pink Card', 'Work Permit', '90 Days Report', 'Job Search'])
            ->and(seededService('Passport')->children()->orderBy('id')->pluck('title')->all())
            ->toBe(['Bath', 'MOU'])
            ->and(Service::count())->toBe(8);
    });

    test('has the confirmed default fees and leaves the undecided ones empty', function () {
        expect(seededService('Work Permit')->default_fee)->toBe('8000.00')
            ->and(seededService('Passport')->default_fee)->toBe('3500.00')
            ->and(seededService('Pink Card')->default_fee)->toBe('4000.00')
            ->and(seededService('CI')->default_fee)->toBeNull()
            ->and(seededService('90 Days Report')->default_fee)->toBeNull()
            ->and(seededService('Job Search')->default_fee)->toBeNull()
            ->and(seededService('Bath')->default_fee)->toBeNull();
    });

    test('only Work Permit and Job Search hold documents', function () {
        expect(Service::where('has_documents', true)->orderBy('id')->pluck('title')->all())
            ->toBe(['Work Permit', 'Job Search']);
    });

    test('every service is active and has a title in both languages', function () {
        expect(Service::where('status', '!=', ServiceAvailability::Active)->count())->toBe(0)
            ->and(Service::whereNull('title')->orWhereNull('title_my')->count())->toBe(0);
    });

    test('the six top-level services show on the website with a description in both languages', function () {
        $topLevel = Service::whereNull('parent_id')->get();

        expect($topLevel)->toHaveCount(6)
            ->and($topLevel->every(fn (Service $service) => $service->show_on_website
                && filled($service->public_description)
                && filled($service->public_description_my)))->toBeTrue()
            ->and(seededService('Bath')->show_on_website)->toBeFalse();
    });

    test('running it again changes nothing', function () {
        $this->seed(ServiceSeeder::class);

        expect(Service::count())->toBe(8);
    });

    test('running it again does not bring back a deleted service or undo an edit', function () {
        seededService('CI')->delete();
        seededService('Pink Card')->update(['default_fee' => 4500]);

        $this->seed(ServiceSeeder::class);

        expect(Service::count())->toBe(7)
            ->and(Service::withTrashed()->count())->toBe(8)
            ->and(seededService('Pink Card')->default_fee)->toBe('4500.00');
    });
});

describe('job search key', function () {
    test('only the seeded Job Search service carries the job_search key', function () {
        $this->seed(ServiceSeeder::class);

        expect(Service::whereNotNull('key')->pluck('title')->all())->toBe(['Job Search'])
            ->and(Service::where('key', Service::JOB_SEARCH_KEY)->firstOrFail()->isJobSearch())->toBeTrue()
            ->and(seededService('Passport')->isJobSearch())->toBeFalse();
    });

    test('the key cannot be mass-assigned', function () {
        $service = Service::create([
            'key' => Service::JOB_SEARCH_KEY,
            'title' => 'Fake Job Search',
            'public_description' => 'Not the real one.',
        ]);

        expect($service->fresh()->key)->toBeNull()
            ->and($service->isJobSearch())->toBeFalse();
    });

    test('renaming Job Search does not lose it', function () {
        $this->seed(ServiceSeeder::class);

        seededService('Job Search')->update(['title' => 'Overseas Jobs', 'title_my' => null]);

        expect(Service::where('key', Service::JOB_SEARCH_KEY)->first()->title)->toBe('Overseas Jobs');
    });

    test('no second service can take the key, even after Job Search is deleted', function () {
        $this->seed(ServiceSeeder::class);
        seededService('Job Search')->delete();

        expect(fn () => Service::factory()->create(['key' => Service::JOB_SEARCH_KEY]))
            ->toThrow(QueryException::class);
    });

    test('services created without a key have none', function () {
        expect(Service::factory()->create()->key)->toBeNull();
    });
});

describe('sub-services', function () {
    test('a main service can have any number of sub-services', function () {
        $workPermit = Service::factory()->create();
        $children = Service::factory()->count(5)->subServiceOf($workPermit)->create();

        expect($workPermit->children)->toHaveCount(5)
            ->and($children->every(fn (Service $child) => $child->parent->is($workPermit) && $child->isSubService()))->toBeTrue()
            ->and($workPermit->isSubService())->toBeFalse();
    });

    test('a sub-service is hidden from the website by default', function () {
        $child = Service::factory()->subServiceOf(Service::factory()->create())->create();

        expect($child->show_on_website)->toBeFalse();
    });

    test('a sub-service keeps its own default fee', function () {
        $parent = Service::factory()->create(['default_fee' => 3500]);
        $child = Service::factory()->subServiceOf($parent)->create(['default_fee' => 1200]);
        $feeless = Service::factory()->subServiceOf($parent)->create(['default_fee' => null]);

        expect($child->default_fee)->toBe('1200.00')
            ->and($feeless->default_fee)->toBeNull()
            ->and($parent->fresh()->default_fee)->toBe('3500.00');
    });

    test('the main scope returns main services only', function () {
        $this->seed(ServiceSeeder::class);

        expect(Service::main()->count())->toBe(6)
            ->and(Service::main()->pluck('title')->contains('Bath'))->toBeFalse();
    });

    test('deleting a service is a soft delete that keeps its sub-services', function () {
        $parent = Service::factory()->create();
        $child = Service::factory()->subServiceOf($parent)->create();

        $parent->delete();

        expect(Service::find($parent->id))->toBeNull()
            ->and(Service::withTrashed()->find($parent->id))->not->toBeNull()
            ->and(Service::find($child->id))->not->toBeNull();
    });

    test('a sub-service cannot be hard-deleted away from its parent while the parent exists', function () {
        $parent = Service::factory()->create();
        Service::factory()->subServiceOf($parent)->create();

        expect(fn () => $parent->forceDelete())->toThrow(QueryException::class);
    });
});

describe('two-level rule', function () {
    test('a sub-service cannot have sub-services', function () {
        $child = Service::factory()->subServiceOf(Service::factory()->create())->create();

        expect(fn () => Service::factory()->subServiceOf($child)->create())
            ->toThrow(DomainException::class, 'two levels at most');
    });

    test('a service that has sub-services cannot become a sub-service', function () {
        $parent = Service::factory()->create();
        Service::factory()->subServiceOf($parent)->create();
        $other = Service::factory()->create();

        expect(fn () => $parent->update(['parent_id' => $other->id]))
            ->toThrow(DomainException::class, 'cannot become a sub-service');
    });

    test('a service cannot be its own parent', function () {
        $service = Service::factory()->create();

        expect(fn () => $service->update(['parent_id' => $service->id]))->toThrow(DomainException::class);
    });

    test('a sub-service needs an existing parent', function () {
        expect(fn () => Service::factory()->create(['parent_id' => 999999]))
            ->toThrow(DomainException::class, 'does not exist');
    });

    test('Job Search cannot have sub-services and cannot become one', function () {
        $this->seed(ServiceSeeder::class);
        $jobSearch = seededService('Job Search');

        expect(fn () => Service::factory()->subServiceOf($jobSearch)->create())
            ->toThrow(DomainException::class, 'Job Search');

        expect(fn () => $jobSearch->update(['parent_id' => seededService('Passport')->id]))
            ->toThrow(DomainException::class, 'system key');
    });

    test('editing an existing sub-service does not trip the rule', function () {
        $child = Service::factory()->subServiceOf(Service::factory()->create())->create();

        $child->update(['title' => 'Renamed', 'default_fee' => 99]);

        expect($child->fresh()->title)->toBe('Renamed');
    });

    test('a sub-service stays editable after its parent is deleted', function () {
        $parent = Service::factory()->create();
        $child = Service::factory()->subServiceOf($parent)->create();
        $parent->delete();

        $child->update(['title' => 'Still editable']);

        expect($child->fresh()->title)->toBe('Still editable');
    });
});
