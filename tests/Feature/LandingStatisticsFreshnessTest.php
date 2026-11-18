<?php

use App\Actions\Landing\LandingStatistics;
use App\Enums\ClientServiceStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrder;
use App\Models\Service;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The landing page numbers are cached for ten minutes, and creating a Person or a service refreshes them as soon as the outermost
 * transaction has committed. The cache key holds a recognisable stale value in most tests, so "refreshed" is proven by it being
 * replaced with the real numbers, and "not refreshed" by it still being there.
 */
beforeEach(function () {
    app()->setLocale('en');
    $this->admin = User::factory()->create();
    $this->passport = Service::factory()->create(['title' => 'Passport']);
    $this->sub = Service::factory()->subServiceOf($this->passport)->create(['title' => 'BATH']);
});

const STALE = ['clients_served' => 999, 'services_provided' => 999];

/**
 * A cache store that can be made to fail on demand, for the tests of a failing refresh. Everything else works as in memory.
 */
class FlakyCacheStore extends ArrayStore
{
    public static bool $failLock = false;

    public static bool $failForget = false;

    public function lock($name, $seconds = 0, $owner = null)
    {
        if (self::$failLock) {
            throw new RuntimeException('cache is down');
        }

        return parent::lock($name, $seconds, $owner);
    }

    public function forget($key)
    {
        if (self::$failForget) {
            throw new RuntimeException('cache is still down');
        }

        return parent::forget($key);
    }
}

function useFlakyCache(): void
{
    FlakyCacheStore::$failLock = FlakyCacheStore::$failForget = false;
    Cache::extend('flaky', fn () => Cache::repository(new FlakyCacheStore));
    config(['cache.stores.flaky' => ['driver' => 'flaky'], 'cache.default' => 'flaky']);
    Cache::forgetDriver('flaky');
}

function cachedStatistics(): mixed
{
    return Cache::get(LandingStatistics::CACHE_KEY);
}

function goStale(): void
{
    Cache::put(LandingStatistics::CACHE_KEY, STALE, 600);
}

/**
 * The count statements that run against the service records while a callback runs.
 *
 * @return array<int, string>
 */
function countStatements(Closure $callback): array
{
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        if (str_contains($query->sql, 'client_services') && str_contains($query->sql, 'count(')) {
            $statements[] = $query->sql;
        }
    });

    $callback();

    return $statements;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function postService(object $test, Client $client, Service $service, array $overrides = []): TestResponse
{
    return $test->actingAs($test->admin)->post(route('admin.clients.services.store', $client), [
        'service_id' => $service->id, 'sub_service_id' => null, 'status' => 'in_progress',
        'start_date' => '2026-10-01', 'end_date' => null, 'fee' => '8000.00', 'received_now' => null, 'remark' => null,
        ...$overrides,
    ]);
}

function postClient(object $test, array $overrides = []): TestResponse
{
    return $test->actingAs($test->admin)->post(route('admin.clients.store'), [
        'name' => 'Aung Min', 'phone' => '081 234 5678', 'birthday' => '1995-03-12', 'gender' => 'male', 'remarks' => null, ...$overrides,
    ]);
}

test('the first landing page request works out both numbers, caches them, and later requests run no count query', function () {
    $person = Client::factory()->create();
    ClientService::withoutEvents(fn () => ClientService::factory()->forService($this->passport)->create(['client_id' => $person->id]));
    Cache::forget(LandingStatistics::CACHE_KEY);

    $first = countStatements(fn () => $this->get(route('home'))->assertOk());

    expect($first)->toHaveCount(2)
        ->and(cachedStatistics())->toBe(['clients_served' => 1, 'services_provided' => 1]);

    $later = countStatements(function () {
        $this->get(route('home'))->assertOk();
        $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('statistics.services_provided', 1));
    });

    expect($later)->toBe([]);
});

test('creating a Person without a service leaves Clients Served as it was, and refreshes the cache', function () {
    $existing = Client::factory()->create();
    ClientService::factory()->forService($this->passport)->create(['client_id' => $existing->id]);
    goStale();

    postClient($this)->assertSessionHasNoErrors();

    expect(Client::count())->toBe(2)
        ->and(cachedStatistics())->toBe(['clients_served' => 1, 'services_provided' => 1]);
});

test('creating a Person and then their first service raises Clients Served and Services Provided', function () {
    postClient($this)->assertSessionHasNoErrors();
    $person = Client::query()->latest('id')->firstOrFail();

    expect(cachedStatistics())->toBe(['clients_served' => 0, 'services_provided' => 0]);

    postService($this, $person, $this->passport)->assertSessionHasNoErrors();

    expect(cachedStatistics())->toBe(['clients_served' => 1, 'services_provided' => 1]);
});

test('another service for the same Person raises Services Provided only', function () {
    $person = Client::factory()->create();
    postService($this, $person, $this->passport)->assertSessionHasNoErrors();
    postService($this, $person, $this->passport)->assertSessionHasNoErrors();

    expect(cachedStatistics())->toBe(['clients_served' => 1, 'services_provided' => 2]);
});

test('a sub-service created through the form refreshes the numbers', function () {
    $person = Client::factory()->create();
    goStale();

    postService($this, $person, $this->passport, ['sub_service_id' => $this->sub->id])->assertSessionHasNoErrors();

    expect(ClientService::query()->sole()->sub_service_id)->toBe($this->sub->id)
        ->and(cachedStatistics())->toBe(['clients_served' => 1, 'services_provided' => 1]);
});

test('a Job Search service created by Add Worker, with its new Person, refreshes the numbers once', function () {
    Service::factory()->jobSearch()->create();
    $order = JobOrder::factory()->create();
    goStale();

    $statements = countStatements(function () use ($order) {
        $this->actingAs($this->admin)->post(route('admin.job-search.workers.store', $order), [
            'name' => 'Aung Min', 'phone' => '081 234 5678', 'birthday' => '1995-03-12', 'gender' => 'male', 'fee' => '5000',
        ])->assertSessionHasNoErrors();
    });

    // A new Person and a new Job Search service were created in one transaction: one refresh, so two count statements.
    expect($statements)->toHaveCount(2)
        ->and(cachedStatistics())->toBe(['clients_served' => 1, 'services_provided' => 1]);
});

test('an existing Person added to a job order gets a new Job Search service and refreshes the numbers', function () {
    Service::factory()->jobSearch()->create();
    $order = JobOrder::factory()->create();
    $person = Client::factory()->create();
    goStale();

    $this->actingAs($this->admin)->post(route('admin.job-search.workers.store', $order), ['client_id' => $person->id, 'fee' => '5000'])->assertSessionHasNoErrors();

    expect(cachedStatistics())->toBe(['clients_served' => 1, 'services_provided' => 1]);
});

test('a cancelled service stays in the numbers, and cancelling it neither refreshes nor lowers them', function () {
    $person = Client::factory()->create();
    postService($this, $person, $this->passport)->assertSessionHasNoErrors();
    $service = ClientService::query()->sole();

    $this->actingAs($this->admin)->post(route('admin.clients.services.cancel', [$person, $service]), ['cancel_refund_amount' => null])->assertSessionHasNoErrors();

    expect($service->fresh()->status)->toBe(ClientServiceStatus::Cancel)
        ->and(cachedStatistics())->toBe(['clients_served' => 1, 'services_provided' => 1]);

    // Cancelling creates nothing, so it does not refresh: a marker left in the cache survives it.
    goStale();
    $this->actingAs($this->admin)->post(route('admin.clients.services.cancel', [$person, ClientService::factory()->forService($this->passport)->create(['client_id' => $person->id])]), ['cancel_refund_amount' => null]);
    goStale();
    postService($this, $person, $this->passport);

    expect(cachedStatistics())->toBe(['clients_served' => 1, 'services_provided' => 3]);
});

test('the cache is refreshed only after the transaction commits, never before', function () {
    $person = Client::factory()->create();
    goStale();

    DB::transaction(function () use ($person) {
        ClientService::factory()->forService($this->passport)->create(['client_id' => $person->id]);

        // The service exists inside the transaction, but nothing has been committed yet: the cache is untouched.
        expect(cachedStatistics())->toBe(STALE);
    });

    expect(cachedStatistics())->toBe(['clients_served' => 1, 'services_provided' => 1]);
});

test('a rolled-back transaction leaves the cache alone, and the next creation still refreshes it', function () {
    $person = Client::factory()->create();
    goStale();

    expect(fn () => DB::transaction(function () use ($person) {
        ClientService::factory()->forService($this->passport)->create(['client_id' => $person->id]);

        throw new RuntimeException('the operation failed');
    }))->toThrow(RuntimeException::class);

    expect(ClientService::count())->toBe(0)
        ->and(cachedStatistics())->toBe(STALE);

    // The rolled-back attempt must not leave a refresh "pending" that would swallow the next one.
    postService($this, $person, $this->passport)->assertSessionHasNoErrors();

    expect(cachedStatistics())->toBe(['clients_served' => 1, 'services_provided' => 1]);
});

test('a rolled-back inner transaction does not cancel the refresh of the outer one that commits, and its service is not counted', function () {
    $person = Client::factory()->create();
    goStale();

    DB::transaction(function () use ($person) {
        ClientService::factory()->forService($this->passport)->create(['client_id' => $person->id]);

        try {
            DB::transaction(function () use ($person) {
                ClientService::factory()->forService($this->passport)->create(['client_id' => $person->id]);

                throw new RuntimeException('inner failed');
            });
        } catch (RuntimeException) {
        }
    });

    expect(cachedStatistics())->toBe(['clients_served' => 1, 'services_provided' => 1]);
});

test('failed admin operations leave the cache untouched', function () {
    $person = Client::factory()->create();
    goStale();

    // Validation fails: the fee is not a number. The phone is not valid either.
    postService($this, $person, $this->passport, ['fee' => 'abc'])->assertSessionHasErrors('fee');
    postClient($this, ['phone' => 'not a phone'])->assertSessionHasErrors();
    postClient($this, ['name' => ''])->assertSessionHasErrors();

    expect(ClientService::count())->toBe(0)
        ->and(cachedStatistics())->toBe(STALE);
});

test('several records created in one transaction run one refresh, not one each', function () {
    $statements = countStatements(function () {
        DB::transaction(function () {
            $person = Client::factory()->create();
            foreach (range(1, 3) as $ignored) {
                ClientService::factory()->forService($this->passport)->create(['client_id' => $person->id]);
            }
            // A nested transaction inside is still part of the same outermost one.
            DB::transaction(fn () => ClientService::factory()->forService($this->passport)->create(['client_id' => $person->id]));
        });
    });

    expect($statements)->toHaveCount(2)
        ->and(cachedStatistics())->toBe(['clients_served' => 1, 'services_provided' => 4]);
});

test('without a transaction each creation refreshes at once', function () {
    $person = Client::factory()->create();

    $statements = countStatements(function () use ($person) {
        ClientService::factory()->forService($this->passport)->create(['client_id' => $person->id]);
        ClientService::factory()->forService($this->passport)->create(['client_id' => $person->id]);
    });

    expect($statements)->toHaveCount(4)
        ->and(cachedStatistics())->toBe(['clients_served' => 1, 'services_provided' => 2]);
});

test('a failed refresh does not fail the admin operation: the service is saved, the error is reported, the old numbers are dropped', function () {
    Exceptions::fake();
    useFlakyCache();
    $person = Client::factory()->create();
    goStale();
    FlakyCacheStore::$failLock = true;

    postService($this, $person, $this->passport)->assertSessionHasNoErrors()->assertRedirect(route('admin.clients.show', $person));

    expect(ClientService::count())->toBe(1);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'cache is down');
});

test('after a failed refresh the next visitor recalculates the numbers from the database', function () {
    Exceptions::fake();
    useFlakyCache();
    $person = Client::factory()->create();
    goStale();
    FlakyCacheStore::$failLock = true;

    postService($this, $person, $this->passport)->assertSessionHasNoErrors();

    // The cache works again for the next visitor.
    FlakyCacheStore::$failLock = false;

    // The stale numbers were dropped, so the page shows the real ones.
    expect(cachedStatistics())->toBeNull();

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('statistics', ['clients_served' => 1, 'services_provided' => 1]));
});

test('even when the stale numbers cannot be dropped either, the admin operation still succeeds', function () {
    Exceptions::fake();
    useFlakyCache();
    $person = Client::factory()->create();
    FlakyCacheStore::$failLock = FlakyCacheStore::$failForget = true;

    postService($this, $person, $this->passport)->assertSessionHasNoErrors()->assertRedirect(route('admin.clients.show', $person));

    expect(ClientService::count())->toBe(1);
});

test('the next landing page request shows the new number although the ten minutes have not passed', function () {
    $person = Client::factory()->create();
    postService($this, $person, $this->passport)->assertSessionHasNoErrors();

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('statistics', ['clients_served' => 1, 'services_provided' => 1]));

    // Cached now. A new Person with a service is added by the admin a minute later.
    $this->travel(60)->seconds();
    $another = Client::factory()->create();
    postService($this, $another, $this->passport)->assertSessionHasNoErrors();

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('statistics', ['clients_served' => 2, 'services_provided' => 2]));
});

test('a slow visitor cannot put an older result over a refreshed one', function () {
    $person = Client::factory()->create();
    ClientService::factory()->forService($this->passport)->create(['client_id' => $person->id]);
    Cache::forget(LandingStatistics::CACHE_KEY);

    // A visitor finds the cache empty and starts working the numbers out. Right after their first count (from the data as it
    // was), the admin's refresh lands in the cache with the newer numbers...
    $refreshed = ['clients_served' => 5, 'services_provided' => 5];
    $landed = false;
    DB::listen(function ($query) use (&$landed, $refreshed) {
        if (! $landed && str_contains($query->sql, 'count(distinct')) {
            $landed = true;
            Cache::put(LandingStatistics::CACHE_KEY, $refreshed, 600);
        }
    });

    $visitor = app(LandingStatistics::class)->counts();

    // ...the visitor is still given what they worked out, but it does not replace the fresher numbers.
    expect($landed)->toBeTrue()
        ->and($visitor)->toBe(['clients_served' => 1, 'services_provided' => 1])
        ->and(cachedStatistics())->toBe($refreshed);
});

test('a malformed cached entry is ignored and the numbers are worked out again', function () {
    $person = Client::factory()->create();
    ClientService::factory()->forService($this->passport)->create(['client_id' => $person->id]);

    Cache::put(LandingStatistics::CACHE_KEY, 'garbage', 600);
    expect(app(LandingStatistics::class)->counts())->toBe(['clients_served' => 1, 'services_provided' => 1]);

    Cache::put(LandingStatistics::CACHE_KEY, ['clients_served' => 'many'], 600);
    expect(app(LandingStatistics::class)->counts())->toBe(['clients_served' => 1, 'services_provided' => 1]);
});
