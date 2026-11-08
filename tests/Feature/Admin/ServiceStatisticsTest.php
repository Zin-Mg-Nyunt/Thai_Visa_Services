<?php

use App\Models\Client;
use App\Models\ClientService;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->passport = Service::factory()->create(['title' => 'Passport']);
    $this->bath = Service::factory()->subServiceOf($this->passport)->create(['title' => 'BATH']);
    $this->mou = Service::factory()->subServiceOf($this->passport)->create(['title' => 'MOU']);
});

/**
 * @param  array<string, mixed>  $state
 */
function takeService(Service $service, ?Service $sub, string $received, string $refund = '0.00', array $state = [], ?Client $client = null): ClientService
{
    $clientService = ClientService::factory()
        ->forService($service, $sub)
        ->create([
            'client_id' => ($client ?? Client::factory()->create())->id,
            'fee' => '10000.00',
            'refund_amount' => $refund,
            ...$state,
        ]);

    if (bccomp($received, '0', 2) === 1) {
        Payment::factory()->create(['client_service_id' => $clientService->id, 'amount' => $received]);
    }

    return $clientService;
}

test('the main service totals add up the client services and the money kept', function () {
    takeService($this->passport, $this->bath, '5000.10');
    takeService($this->passport, $this->mou, '4000.20', state: ['status' => 'complete']);
    takeService($this->passport, null, '3000.00', '1000.30', ['status' => 'cancel', 'cancelled_at' => now()]);

    $this->actingAs($this->admin)
        ->get(route('admin.services.show', $this->passport))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_clients', 3)
            ->where('stats.in_progress', 1)
            ->where('stats.complete', 1)
            ->where('stats.total_received', '11000.00'));
});

test('a client with two services is counted once', function () {
    $client = Client::factory()->create();
    takeService($this->passport, $this->bath, '0.00', client: $client);
    takeService($this->passport, $this->mou, '0.00', client: $client);

    $this->actingAs($this->admin)
        ->get(route('admin.services.show', $this->passport))
        ->assertInertia(fn (Assert $page) => $page->where('stats.total_clients', 1)->where('stats.in_progress', 2));
});

test('a service nobody has taken shows zero', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.services.show', $this->passport))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_clients', 0)
            ->where('stats.total_received', '0.00'));
});

test('a sub-service page counts only its own client services', function () {
    takeService($this->passport, $this->bath, '5000.00');
    takeService($this->passport, $this->mou, '4000.00');
    takeService($this->passport, null, '3000.00');

    $this->actingAs($this->admin)
        ->get(route('admin.services.show', $this->bath))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_clients', 1)
            ->where('stats.total_received', '5000.00')
            ->where('breakdown', []));
});

test('the sub-service breakdown adds up to the main service total', function () {
    takeService($this->passport, $this->bath, '22000.10');
    takeService($this->passport, $this->mou, '15000.20', state: ['status' => 'complete']);
    takeService($this->passport, null, '9000.00', '1000.00', ['status' => 'cancel', 'cancelled_at' => now()]);

    $this->actingAs($this->admin)
        ->get(route('admin.services.show', $this->passport))
        ->assertInertia(function (Assert $page) {
            $page->has('breakdown', 3)
                ->where('breakdown.0.sub_service.id', $this->bath->id)
                ->where('breakdown.0.stats.total_received', '22000.10')
                ->where('breakdown.1.sub_service.id', $this->mou->id)
                ->where('breakdown.1.stats.total_received', '15000.20')
                ->where('breakdown.2.sub_service', null)
                ->where('breakdown.2.stats.total_received', '8000.00');

            $props = $page->toArray()['props'];
            $money = collect($props['breakdown'])->reduce(fn ($total, $row) => bcadd($total, $row['stats']['total_received'], 2), '0.00');

            expect($money)->toBe($props['stats']['total_received'])->toBe('45000.30');
            expect(collect($props['breakdown'])->sum('stats.in_progress'))->toBe($props['stats']['in_progress']);
            expect(collect($props['breakdown'])->sum('stats.complete'))->toBe($props['stats']['complete']);
        });
});

test('a deleted sub-service is listed only while it still has client services', function () {
    takeService($this->passport, $this->mou, '1000.00');
    $this->bath->delete();
    $this->mou->delete();

    $this->actingAs($this->admin)
        ->get(route('admin.services.show', $this->passport))
        ->assertInertia(fn (Assert $page) => $page
            ->has('breakdown', 2)
            ->where('breakdown.0.sub_service.id', $this->mou->id)
            ->where('breakdown.1.sub_service', null));
});

test('the services list gives the stats of every service and leaves out those nobody has taken', function () {
    takeService($this->passport, null, '2500.50');

    $this->actingAs($this->admin)
        ->get(route('admin.services.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where("stats.{$this->passport->id}.total_received", '2500.50')
            ->where("stats.{$this->passport->id}.total_clients", 1)
            ->missing("stats.{$this->bath->id}"));
});

test('a refund lowers the service total', function () {
    takeService($this->passport, null, '4000.00', '1000.00', ['status' => 'cancel', 'cancelled_at' => now()]);

    $this->actingAs($this->admin)
        ->get(route('admin.services.show', $this->passport))
        ->assertInertia(fn (Assert $page) => $page->where('stats.total_received', '3000.00'));
});

test('a cancel refund lowers the service total as well', function () {
    takeService($this->passport, null, '4000.00', '500.00', ['status' => 'cancel', 'cancelled_at' => now(), 'cancel_refund_amount' => '1000.25']);

    $this->actingAs($this->admin)
        ->get(route('admin.services.show', $this->passport))
        ->assertInertia(fn (Assert $page) => $page->where('stats.total_received', '2499.75'));
});

test('the clients list filters by service, sub-service, main service only and status', function (array $query, array $names) {
    $make = fn (string $name) => Client::factory()->create(['name' => $name]);

    takeService($this->passport, $this->bath, '0.00', client: $make('Bath Client'));
    takeService($this->passport, $this->mou, '0.00', state: ['status' => 'complete'], client: $make('Mou Client'));
    takeService($this->passport, null, '0.00', client: $make('Parent Client'));
    $other = Service::factory()->create();
    takeService($other, null, '0.00', state: ['status' => 'cancel', 'cancelled_at' => now()], client: $make('Other Client'));
    $make('No Service Client');

    $query = array_map(fn ($value) => $value instanceof Closure ? $value($this) : $value, $query);

    $this->actingAs($this->admin)
        ->get(route('admin.clients.index', $query))
        ->assertInertia(fn (Assert $page) => $page
            ->where('clients.data', fn ($clients) => collect($clients)->pluck('name')->sort()->values()->all() === collect($names)->sort()->values()->all()));
})->with([
    'a main service' => [['service' => fn ($t) => $t->passport->id], ['Bath Client', 'Mou Client', 'Parent Client']],
    'a sub-service' => [['service' => fn ($t) => $t->passport->id, 'sub_service' => fn ($t) => $t->bath->id], ['Bath Client']],
    'main service only' => [['service' => fn ($t) => $t->passport->id, 'sub_service' => 'none'], ['Parent Client']],
    'a status' => [['status' => 'complete'], ['Mou Client']],
    'a cancelled status' => [['status' => 'cancel'], ['Other Client']],
    'service and status together' => [['service' => fn ($t) => $t->passport->id, 'status' => 'in_progress'], ['Bath Client', 'Parent Client']],
    'a bad status is ignored' => [['status' => 'nonsense', 'service' => fn ($t) => $t->passport->id], ['Bath Client', 'Mou Client', 'Parent Client']],
]);

test('the filters must all match one service of the client', function () {
    $client = Client::factory()->create(['name' => 'Mixed']);
    takeService($this->passport, $this->bath, '0.00', state: ['status' => 'complete'], client: $client);
    takeService($this->passport, $this->mou, '0.00', client: $client);

    $this->actingAs($this->admin)
        ->get(route('admin.clients.index', ['service' => $this->passport->id, 'sub_service' => $this->bath->id, 'status' => 'in_progress']))
        ->assertInertia(fn (Assert $page) => $page->has('clients.data', 0));
});

test('a sub-service filter counts only with its own main service', function (Closure $query, int $clients) {
    takeService($this->passport, $this->bath, '0.00');
    takeService($this->passport, $this->mou, '0.00');
    $this->other = Service::factory()->create();

    // The sub-service is dropped, so only the other filters (if any) are applied.
    $this->actingAs($this->admin)
        ->get(route('admin.clients.index', $query($this)))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('filters.sub_service')
            ->has('clients.data', $clients));
})->with([
    'without a service' => [fn ($t) => ['sub_service' => $t->bath->id], 2],
    'main service only without a service' => [fn ($t) => ['sub_service' => 'none'], 2],
    'with another service' => [fn ($t) => ['service' => $t->other->id, 'sub_service' => $t->bath->id], 0],
]);

test('a cancel refund lowers the service total', function () {
    takeService($this->passport, null, '3000.00', '500.00', [
        'status' => 'cancel', 'cancelled_at' => now(), 'cancel_refund_amount' => '1000.00',
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.services.show', $this->passport))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_received', '1500.00')
            ->where('breakdown.2.stats.total_received', '1500.00'));

    $this->actingAs($this->admin)
        ->get(route('admin.services.index'))
        ->assertInertia(fn (Assert $page) => $page->where("stats.{$this->passport->id}.total_received", '1500.00'));
});

test('the clients list sends the filters and the service options to the page', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.clients.index', ['service' => $this->passport->id, 'sub_service' => 'none', 'status' => 'complete']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.service', $this->passport->id)
            ->where('filters.sub_service', 'none')
            ->where('filters.status', 'complete')
            ->where('serviceOptions.0.id', $this->passport->id)
            ->has('serviceOptions.0.sub_services', 2));
});

test('a deleted client still counts in the clients, the statuses and the money', function () {
    $gone = Client::factory()->create();
    takeService($this->passport, $this->bath, '3000.00', client: $gone);
    takeService($this->passport, null, '1000.00', state: ['status' => 'complete'], client: $gone);
    takeService($this->passport, $this->mou, '500.00');
    $gone->delete();

    $this->actingAs($this->admin)
        ->get(route('admin.services.show', $this->passport))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_clients', 2)
            ->where('stats.in_progress', 2)
            ->where('stats.complete', 1)
            ->where('stats.total_received', '4500.00')
            ->where('breakdown.0.stats.total_received', '3000.00')
            ->where('breakdown.2.stats.total_received', '1000.00'));

    $this->actingAs($this->admin)
        ->get(route('admin.services.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where("stats.{$this->passport->id}.total_clients", 2)
            ->where("stats.{$this->passport->id}.total_received", '4500.00'));
});

test('the totals equal the money kept of every client service, with fee refunds and cancel refunds', function () {
    $rows = [
        takeService($this->passport, $this->bath, '5000.10', '1000.05'),
        takeService($this->passport, $this->bath, '2500.00', state: ['status' => 'complete']),
        takeService($this->passport, $this->mou, '3000.33', '500.11', ['status' => 'cancel', 'cancelled_at' => now(), 'cancel_refund_amount' => '1250.22']),
        takeService($this->passport, null, '0.00'),
        takeService($this->passport, null, '999.99', state: ['status' => 'cancel', 'cancelled_at' => now(), 'cancel_refund_amount' => '999.99']),
    ];
    Payment::factory()->create(['client_service_id' => $rows[0]->id, 'amount' => '0.01']);

    $kept = fn (array $records) => collect($records)->reduce(fn ($sum, ClientService $row) => bcadd($sum, $row->fresh()->kept(), 2), '0.00');

    $this->actingAs($this->admin)
        ->get(route('admin.services.show', $this->passport))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_received', $kept($rows))
            ->where('stats.total_received', '7750.06')
            ->where('breakdown.0.stats.total_received', $kept([$rows[0], $rows[1]]))
            ->where('breakdown.1.stats.total_received', $kept([$rows[2]]))
            ->where('breakdown.2.stats.total_received', $kept([$rows[3], $rows[4]])));

    $this->actingAs($this->admin)
        ->get(route('admin.services.show', $this->mou))
        ->assertInertia(fn (Assert $page) => $page->where('stats.total_received', $kept([$rows[2]])));
});

test('the money is exact to the satang', function () {
    $record = takeService($this->passport, $this->bath, '0.10');
    Payment::factory()->create(['client_service_id' => $record->id, 'amount' => '0.20']);
    takeService($this->passport, null, '9999999999.99');

    $this->actingAs($this->admin)
        ->get(route('admin.services.show', $this->passport))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_received', '10000000000.29')
            ->where('breakdown.0.stats.total_received', '0.30'));
});

test('the service pages add up in the database and never load payments into PHP', function () {
    takeService($this->passport, $this->bath, '5000.00');
    takeService($this->passport, null, '3000.00', '500.00');

    $loaded = 0;
    Event::listen('eloquent.retrieved: '.Payment::class, function () use (&$loaded) {
        $loaded++;
    });

    $this->actingAs($this->admin)->get(route('admin.services.index'))->assertOk();
    $this->actingAs($this->admin)->get(route('admin.services.show', $this->passport))->assertOk();
    $this->actingAs($this->admin)->get(route('admin.services.show', $this->bath))->assertOk();

    expect($loaded)->toBe(0);
});
