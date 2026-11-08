<?php

use App\Models\Client;
use App\Models\ClientService;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->passport = Service::factory()->create(['title' => 'Passport']);
    $this->bath = Service::factory()->subServiceOf($this->passport)->create(['title' => 'BATH']);
});

/**
 * A client with one service for the given fee and payments.
 *
 * @param  array<int, string>  $payments
 * @param  array<string, mixed>  $state
 */
function clientPaying(string $name, string $fee, array $payments, array $state = [], ?Service $service = null, ?Service $sub = null): Client
{
    $client = Client::factory()->create(['name' => $name]);
    $record = ClientService::factory()->forService($service ?? test()->passport, $sub)->create(['client_id' => $client->id, 'fee' => $fee, ...$state]);

    foreach ($payments as $amount) {
        Payment::factory()->create(['client_service_id' => $record->id, 'amount' => $amount]);
    }

    return $client;
}

/**
 * @param  array<string, mixed>  $query
 * @return array<int, string>
 */
function clientNames(array $query): array
{
    $names = [];

    test()->actingAs(test()->admin)
        ->get(route('admin.clients.index', $query))
        ->assertInertia(function (Assert $page) use (&$names) {
            $names = collect($page->toArray()['props']['clients']['data'])->pluck('name')->sort()->values()->all();
        });

    return $names;
}

test('the fully paid filter lists clients whose service is paid in full, in progress or complete', function () {
    clientPaying('Paid in progress', '5000', ['5000']);
    clientPaying('Paid complete', '5000', ['2000', '3000'], ['status' => 'complete']);
    clientPaying('Deposit', '5000', ['1000']);
    clientPaying('Unpaid', '5000', []);
    clientPaying('Paid then cancelled', '5000', ['5000'], ['status' => 'cancel', 'cancelled_at' => now()]);

    expect(clientNames(['payment' => 'fully_paid']))->toBe(['Paid complete', 'Paid in progress']);
});

test('the remaining filter lists clients with a balance, unpaid or deposit, in progress or complete', function () {
    clientPaying('Deposit in progress', '5000', ['1000']);
    clientPaying('Unpaid in progress', '5000', []);
    clientPaying('Deposit complete', '5000', ['4999.99'], ['status' => 'complete']);
    clientPaying('Unpaid complete', '5000', [], ['status' => 'complete']);
    clientPaying('Paid', '5000', ['5000']);
    clientPaying('Cancelled with a balance', '5000', ['1000'], ['status' => 'cancel', 'cancelled_at' => now()]);

    expect(clientNames(['payment' => 'remaining']))->toBe(['Deposit complete', 'Deposit in progress', 'Unpaid complete', 'Unpaid in progress']);
});

test('the money is compared exactly to the satang', function () {
    clientPaying('Exact parts', '0.30', ['0.10', '0.20']);
    clientPaying('One satang short', '8000', ['7999.99']);
    clientPaying('Big fee paid', '9999999999.99', ['9999999999.99']);
    clientPaying('Big fee one short', '9999999999.99', ['9999999999.98']);

    expect(clientNames(['payment' => 'fully_paid']))->toBe(['Big fee paid', 'Exact parts'])
        ->and(clientNames(['payment' => 'remaining']))->toBe(['Big fee one short', 'One satang short']);
});

test('a service with no fee and nothing paid is in neither filter', function () {
    clientPaying('Free', '0', []);

    expect(clientNames(['payment' => 'fully_paid']))->toBe([])
        ->and(clientNames(['payment' => 'remaining']))->toBe([]);
});

test('the payment filter works together with the service, sub-service and status filters on one service', function () {
    clientPaying('Bath paid', '5000', ['5000'], [], $this->passport, $this->bath);
    clientPaying('Bath deposit complete', '5000', ['1000'], ['status' => 'complete'], $this->passport, $this->bath);
    clientPaying('Parent paid', '5000', ['5000']);
    clientPaying('Other service paid', '5000', ['5000'], [], Service::factory()->create());

    expect(clientNames(['payment' => 'fully_paid', 'service' => $this->passport->id]))->toBe(['Bath paid', 'Parent paid'])
        ->and(clientNames(['payment' => 'fully_paid', 'service' => $this->passport->id, 'sub_service' => $this->bath->id]))->toBe(['Bath paid'])
        ->and(clientNames(['payment' => 'fully_paid', 'service' => $this->passport->id, 'sub_service' => 'none']))->toBe(['Parent paid'])
        ->and(clientNames(['payment' => 'remaining', 'status' => 'complete']))->toBe(['Bath deposit complete'])
        ->and(clientNames(['payment' => 'remaining', 'status' => 'in_progress']))->toBe([]);
});

test('all filters must match one service of the client, not different ones', function () {
    $client = Client::factory()->create(['name' => 'Mixed']);
    $paidBath = ClientService::factory()->forService($this->passport, $this->bath)->create(['client_id' => $client->id, 'fee' => '5000']);
    Payment::factory()->create(['client_service_id' => $paidBath->id, 'amount' => '5000']);
    ClientService::factory()->forService($this->passport)->create(['client_id' => $client->id, 'fee' => '5000']);

    expect(clientNames(['payment' => 'fully_paid', 'service' => $this->passport->id, 'sub_service' => $this->bath->id]))->toBe(['Mixed'])
        ->and(clientNames(['payment' => 'fully_paid', 'service' => $this->passport->id, 'sub_service' => 'none']))->toBe([])
        ->and(clientNames(['payment' => 'remaining', 'service' => $this->passport->id, 'sub_service' => 'none']))->toBe(['Mixed'])
        ->and(clientNames(['payment' => 'remaining', 'service' => $this->passport->id, 'sub_service' => $this->bath->id]))->toBe([]);
});

test('a client is listed once even with several matching services', function () {
    $client = Client::factory()->create(['name' => 'Twice']);
    foreach (range(1, 3) as $unused) {
        ClientService::factory()->forService($this->passport)->create(['client_id' => $client->id, 'fee' => '5000']);
    }

    expect(clientNames(['payment' => 'remaining']))->toBe(['Twice']);
});

test('an unknown payment value is ignored and the page echoes the chosen filter', function () {
    clientPaying('Anyone', '5000', []);

    expect(clientNames(['payment' => 'nonsense']))->toBe(['Anyone']);

    $this->actingAs($this->admin)
        ->get(route('admin.clients.index', ['payment' => 'remaining']))
        ->assertInertia(fn (Assert $page) => $page->where('filters.payment', 'remaining'));

    $this->actingAs($this->admin)
        ->get(route('admin.clients.index', ['payment' => 'nonsense']))
        ->assertInertia(fn (Assert $page) => $page->missing('filters.payment'));
});

test('the payment filter works together with the name search', function () {
    clientPaying('Aung Paid', '5000', ['5000']);
    clientPaying('Aung Deposit', '5000', ['1000']);
    clientPaying('Zaw Paid', '5000', ['5000']);

    expect(clientNames(['payment' => 'fully_paid', 'q' => 'Aung']))->toBe(['Aung Paid']);
});
