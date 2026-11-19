<?php

use App\Actions\Landing\LandingStatistics;
use App\Enums\ClientServiceStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrderWorker;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The landing page statistics: Clients Served (unique Person records with at least one service record) and Services Provided (every
 * service record), both lifetime, both from the database, cached for ten minutes. See App\Actions\Landing\LandingStatistics.
 */
beforeEach(function () {
    app()->setLocale('en');
});

/**
 * The two numbers worked out now, not read from the cache.
 *
 * @return array{clients_served: int, services_provided: int}
 */
function freshCounts(): array
{
    Cache::forget(LandingStatistics::CACHE_KEY);

    return app(LandingStatistics::class)->counts();
}

function serviceFor(Client $client, ClientServiceStatus $status = ClientServiceStatus::InProgress, ?Service $service = null): ClientService
{
    $service ??= Service::factory()->create();

    return ClientService::factory()->forService($service)->create(['client_id' => $client->id, 'status' => $status]);
}

describe('Clients Served', function () {
    test('a Person with no service is not counted', function () {
        Client::factory()->count(3)->create();

        expect(freshCounts()['clients_served'])->toBe(0);
    });

    test('a Person with one service is counted once, and with several services still once', function () {
        $one = Client::factory()->create();
        $many = Client::factory()->create();
        serviceFor($one);
        foreach (range(1, 4) as $ignored) {
            serviceFor($many);
        }

        expect(freshCounts()['clients_served'])->toBe(2);
    });

    test('every service status qualifies: In Progress, Complete and Cancelled', function (ClientServiceStatus $status) {
        serviceFor(Client::factory()->create(), $status);

        expect(freshCounts()['clients_served'])->toBe(1);
    })->with([
        'in progress' => [ClientServiceStatus::InProgress],
        'complete' => [ClientServiceStatus::Complete],
        'cancelled' => [ClientServiceStatus::Cancel],
    ]);

    test('a Job Search service qualifies', function () {
        serviceFor(Client::factory()->create(), service: Service::factory()->jobSearch()->create());

        expect(freshCounts()['clients_served'])->toBe(1);
    });

    test('a sub-service qualifies', function () {
        $parent = Service::factory()->create(['title' => 'Passport']);
        $sub = Service::factory()->subServiceOf($parent)->create(['title' => 'BATH']);
        ClientService::factory()->forService($parent, $sub)->create(['client_id' => Client::factory()->create()->id]);

        expect(freshCounts()['clients_served'])->toBe(1);
    });

    test('a soft-deleted Person with a service is still counted, and deleting them does not lower the count', function () {
        $person = Client::factory()->create();
        serviceFor($person);

        expect(freshCounts()['clients_served'])->toBe(1);

        $person->delete();

        expect(Client::find($person->id))->toBeNull()
            ->and(Client::withTrashed()->find($person->id)->trashed())->toBeTrue()
            ->and(freshCounts()['clients_served'])->toBe(1);
    });

    test('two Person records of one returning client are two clients: nothing is merged by name or phone', function () {
        $first = Client::factory()->create(['name' => 'Aung Aung', 'phone' => '+66811111111']);
        serviceFor($first);
        $first->delete();

        // The returning client is registered again, with the same name and phone (the first record is deleted, so it is allowed).
        $again = Client::factory()->create(['name' => 'Aung Aung', 'phone' => '+66811111111']);
        serviceFor($again);

        expect(freshCounts()['clients_served'])->toBe(2);
    });
});

describe('Services Provided', function () {
    test('every status counts, Cancelled included', function (ClientServiceStatus $status) {
        serviceFor(Client::factory()->create(), $status);

        expect(freshCounts()['services_provided'])->toBe(1);
    })->with([
        'in progress' => [ClientServiceStatus::InProgress],
        'complete' => [ClientServiceStatus::Complete],
        'cancelled' => [ClientServiceStatus::Cancel],
    ]);

    test('Job Search, sub-services and the services of a deleted Person count', function () {
        $parent = Service::factory()->create(['title' => 'Passport']);
        $sub = Service::factory()->subServiceOf($parent)->create(['title' => 'BATH']);
        $deleted = Client::factory()->create();

        serviceFor(Client::factory()->create(), service: Service::factory()->jobSearch()->create());
        ClientService::factory()->forService($parent, $sub)->create(['client_id' => Client::factory()->create()->id]);
        serviceFor($deleted);
        $deleted->delete();

        expect(freshCounts()['services_provided'])->toBe(3);
    });

    test('several services of one Person are counted separately', function () {
        $person = Client::factory()->create();
        foreach (range(1, 3) as $ignored) {
            serviceFor($person);
        }

        expect(freshCounts())->toBe(['clients_served' => 1, 'services_provided' => 3]);
    });

    test('completing a service, or cancelling it, never lowers the count', function () {
        $person = Client::factory()->create();
        $first = serviceFor($person);
        $second = serviceFor($person);
        $third = serviceFor($person, ClientServiceStatus::Complete);

        expect(freshCounts()['services_provided'])->toBe(3);

        $first->update(['status' => ClientServiceStatus::Complete]);
        expect(freshCounts()['services_provided'])->toBe(3);

        $second->update(['status' => ClientServiceStatus::Cancel, 'cancelled_at' => now()]);
        expect(freshCounts()['services_provided'])->toBe(3);

        $third->update(['status' => ClientServiceStatus::Cancel, 'cancelled_at' => now()]);
        expect(freshCounts())->toBe(['clients_served' => 1, 'services_provided' => 3]);
    });

    test('cancelling through the real cancel workflow keeps the count', function () {
        $admin = User::factory()->create();
        $person = Client::factory()->create();
        $service = serviceFor($person);

        $this->actingAs($admin)->post(route('admin.clients.services.cancel', [$person, $service]), ['cancel_refund_amount' => null])->assertSessionHasNoErrors();

        expect($service->fresh()->status)->toBe(ClientServiceStatus::Cancel)
            ->and(freshCounts()['services_provided'])->toBe(1);
    });

    test('payments, refunds, applications and catalogue entries are not services', function () {
        $person = Client::factory()->create();
        $record = ClientService::factory()->forService(Service::factory()->jobSearch()->create())->create(['client_id' => $person->id, 'refund_amount' => '500.00', 'cancel_refund_amount' => '200.00']);
        Payment::factory()->count(3)->create(['client_service_id' => $record->id]);
        JobOrderWorker::factory()->count(3)->create(['client_id' => $person->id, 'client_service_id' => $record->id]);
        Service::factory()->count(4)->create();

        expect(freshCounts())->toBe(['clients_served' => 1, 'services_provided' => 1]);
    });
});

describe('the landing page', function () {
    test('with nothing to show the statistics section is hidden', function () {
        Client::factory()->create();

        $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Welcome')->where('statistics', null));
    });

    test('with a service the section is shown with both numbers, and sends only totals', function () {
        serviceFor(Client::factory()->create());
        serviceFor(Client::factory()->create(), ClientServiceStatus::Cancel);

        $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('statistics', ['clients_served' => 2, 'services_provided' => 2]));
    });

    test('one number being zero does not hide it or the other: both are shown', function (int $clients, int $services) {
        Cache::put(LandingStatistics::CACHE_KEY, ['clients_served' => $clients, 'services_provided' => $services], 600);

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
            ->where('statistics.clients_served', $clients)
            ->where('statistics.services_provided', $services));
    })->with([
        'no clients, some services' => [0, 5],
        'some clients, no services' => [3, 0],
    ]);

    test('the page uses the real numbers and the exact labels, and has no mockup numbers left', function () {
        $page = file_get_contents(resource_path('js/pages/Welcome.vue'));
        $english = file_get_contents(resource_path('js/locales/en.ts'));

        expect($page)->toContain('v-if="statistics"')
            ->toContain('props.statistics.clients_served')
            ->toContain('props.statistics.services_provided')
            ->not->toContain('1240')->not->toContain('3860')
            ->and($english)->toContain("clients: 'Clients Served'")->toContain("services: 'Services Provided'");
    });
});

describe('the cache', function () {
    test('the numbers are kept for ten minutes, and are worked out again after that', function () {
        $this->travelTo(now());
        serviceFor(Client::factory()->create());
        Cache::forget(LandingStatistics::CACHE_KEY);

        expect(LandingStatistics::CACHE_SECONDS)->toBe(600)
            ->and(app(LandingStatistics::class)->counts()['services_provided'])->toBe(1);

        // A change that does not go through the admin workflows (no creation event) is only picked up when the numbers expire.
        $other = Client::factory()->create();
        ClientService::withoutEvents(fn () => serviceFor($other));

        // Still the cached numbers just before ten minutes...
        $this->travel(599)->seconds();
        expect(app(LandingStatistics::class)->counts())->toBe(['clients_served' => 1, 'services_provided' => 1]);

        // ...and the new ones once they have expired.
        $this->travel(2)->seconds();
        expect(app(LandingStatistics::class)->counts())->toBe(['clients_served' => 2, 'services_provided' => 2]);
    });

    test('while cached, a visit runs no count query', function () {
        serviceFor(Client::factory()->create());
        $this->get(route('home'))->assertOk();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'client_services')) {
                $queries[] = $query->sql;
            }
        });

        $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('statistics.services_provided', 1));

        expect($queries)->toBe([]);
    });

    test('the first visit runs exactly the two count queries, in the database', function () {
        serviceFor(Client::factory()->create());
        Cache::forget(LandingStatistics::CACHE_KEY);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'client_services')) {
                $queries[] = $query->sql;
            }
        });

        $this->get(route('home'))->assertOk();

        expect($queries)->toHaveCount(2)
            ->and($queries[0])->toContain('count(distinct')
            ->and($queries[1])->toContain('count(*)');
    });
});

describe('when the statistics fail', function () {
    test('a database failure gives a normal page without the statistics section, and the error is reported', function () {
        Exceptions::fake();
        Cache::forget(LandingStatistics::CACHE_KEY);
        Service::factory()->create(['title' => 'Work Permit']);
        Schema::drop('client_services');

        $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('statistics', null)
            ->has('services', 1));

        Exceptions::assertReportedCount(1);
    });

    test('a cache failure gives a normal page without the statistics section, and the error is reported', function () {
        Exceptions::fake();
        Cache::extend('broken', fn () => new Repository(new class extends ArrayStore
        {
            public function get($key)
            {
                throw new RuntimeException('cache is down');
            }
        }));
        config(['cache.stores.broken' => ['driver' => 'broken'], 'cache.default' => 'broken']);
        serviceFor(Client::factory()->create());

        $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Welcome')->where('statistics', null));

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'cache is down');
    });

    test('a failure in counts() itself is caught only on the page path; counts() still throws for its other callers', function () {
        Exceptions::fake();
        $statistics = Mockery::mock(LandingStatistics::class)->makePartial();
        $statistics->shouldReceive('counts')->andThrow(new RuntimeException('boom'));

        expect($statistics->forPage())->toBeNull()
            ->and(fn () => $statistics->counts())->toThrow(RuntimeException::class);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'boom');
    });

    test('when nothing fails the section still shows and nothing is reported', function () {
        Exceptions::fake();
        serviceFor(Client::factory()->create());

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('statistics', ['clients_served' => 1, 'services_provided' => 1]));

        Exceptions::assertNothingReported();
    });
});
