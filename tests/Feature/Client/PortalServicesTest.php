<?php

use App\Models\Client;
use App\Models\ClientService;
use App\Models\ClientServiceRemark;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->client = Client::factory()->create(['phone' => '+66812345678']);
    $this->passport = Service::factory()->create(['title' => 'Passport', 'title_my' => 'ပတ်စပို့']);
    $this->bath = Service::factory()->subServiceOf($this->passport)->create(['title' => 'BATH']);
});

function signedInPortal(Client $client): void
{
    test()->post(route('track.verify', $client->unique_token), ['pin' => substr($client->phone, -4)]);
}

/**
 * @param  array<int, string>  $payments
 * @param  array<string, mixed>  $state
 */
function portalService(Client $client, Service $service, ?Service $sub = null, array $payments = [], array $state = []): ClientService
{
    $record = ClientService::factory()->forService($service, $sub)->create(['client_id' => $client->id, 'fee' => '8000.00', ...$state]);

    foreach ($payments as $amount) {
        Payment::factory()->create(['client_service_id' => $record->id, 'amount' => $amount]);
    }

    return $record;
}

function portalPage(Client $client): TestResponse
{
    return test()->get(route('client.profile', $client->unique_token));
}

test('a client sees their own services with the service and sub-service titles', function () {
    portalService($this->client, $this->passport, $this->bath, ['3000.00']);
    portalService($this->client, $this->passport);
    signedInPortal($this->client);

    portalPage($this->client)->assertInertia(fn (Assert $page) => $page
        ->component('client/Profile')
        ->has('services.data', 2)
        ->where('services.data.0.service.title', 'Passport')
        ->where('services.data.0.sub_service', null)
        ->where('services.data.1.service.title_my', 'ပတ်စပို့')
        ->where('services.data.1.sub_service.title', 'BATH'));
});

test('a service in progress shows the fee, the money received, the balance and the payment status', function (array $payments, string $received, string $remaining, string $status) {
    portalService($this->client, $this->passport, null, $payments);
    signedInPortal($this->client);

    portalPage($this->client)->assertInertia(fn (Assert $page) => $page
        ->where('services.data.0.status', 'in_progress')
        ->where('services.data.0.fee', '8000.00')
        ->where('services.data.0.received', $received)
        ->where('services.data.0.remaining', $remaining)
        ->where('services.data.0.payment_status', $status));
})->with([
    'unpaid' => [[], '0.00', '8000.00', 'unpaid'],
    'a deposit' => [['3000.50'], '3000.50', '4999.50', 'deposit'],
    'fully paid in parts' => [['5000.00', '3000.00'], '8000.00', '0.00', 'fully_paid'],
]);

test('a completed service shows its status and money', function () {
    portalService($this->client, $this->passport, null, ['8000.00'], ['status' => 'complete']);
    signedInPortal($this->client);

    portalPage($this->client)->assertInertia(fn (Assert $page) => $page
        ->where('services.data.0.status', 'complete')
        ->where('services.data.0.payment_status', 'fully_paid'));
});

test('a cancelled service shows its status only, with no money at all', function () {
    portalService($this->client, $this->passport, null, ['5000.00'], [
        'status' => 'cancel', 'cancelled_at' => now(), 'refund_amount' => '500.00', 'cancel_refund_amount' => '1500.00',
    ]);
    signedInPortal($this->client);

    portalPage($this->client)->assertInertia(fn (Assert $page) => $page
        ->where('services.data.0.status', 'cancel')
        ->where('services.data.0', fn ($service) => collect($service)->keys()->sort()->values()->all() === ['id', 'service', 'status', 'sub_service']));
});

test('only the fields a client may see are sent, never refunds, kept money, dates, remarks, payments or documents', function () {
    $record = portalService($this->client, $this->passport, $this->bath, ['3000.00'], [
        'refund_amount' => '0.00', 'start_date' => '2026-11-01', 'end_date' => '2026-12-01',
    ]);
    ClientServiceRemark::factory()->create(['client_service_id' => $record->id, 'body' => 'Agency note']);
    Document::factory()->create(['client_service_id' => $record->id, 'title' => 'Secret file', 'storage_key' => 'documents/secret.pdf']);
    signedInPortal($this->client);

    portalPage($this->client)->assertInertia(fn (Assert $page) => $page
        ->where('services.data.0', fn ($service) => collect($service)->keys()->sort()->values()->all() === [
            'fee', 'id', 'payment_status', 'received', 'remaining', 'service', 'status', 'sub_service',
        ])
        ->where('services.data.0.service', fn ($service) => collect($service)->keys()->sort()->values()->all() === ['title', 'title_my'])
        ->where('services.data.0.sub_service', fn ($service) => collect($service)->keys()->sort()->values()->all() === ['title', 'title_my']));

    $json = json_encode(portalPage($this->client)->viewData('page')['props'] ?? []);

    expect($json)->not->toContain('Agency note')
        ->and($json)->not->toContain('Secret file')
        ->and($json)->not->toContain('secret.pdf')
        ->and($json)->not->toContain('refund')
        ->and($json)->not->toContain('kept')
        ->and($json)->not->toContain('client_id');
});

test('a client never sees another client\'s services', function () {
    $other = Client::factory()->create(['phone' => '+66899990000']);
    portalService($other, $this->passport, $this->bath, ['8000.00']);
    portalService($this->client, $this->passport);
    signedInPortal($this->client);

    portalPage($this->client)->assertInertia(fn (Assert $page) => $page
        ->has('services.data', 1)
        ->where('services.data.0.sub_service', null)
        ->where('services.data.0.received', '0.00'));
});

test('another client\'s page cannot be opened with this client\'s session', function () {
    $other = Client::factory()->create(['phone' => '+66899990000']);
    portalService($other, $this->passport);
    signedInPortal($this->client);

    portalPage($other)->assertRedirect(route('track.show', $other->unique_token));
});

test('a client with no services sees an empty list', function () {
    signedInPortal($this->client);

    portalPage($this->client)->assertInertia(fn (Assert $page) => $page->has('services.data', 0));
});

test('a deleted service still shows its name on an existing record', function () {
    portalService($this->client, $this->passport);
    $this->passport->delete();
    signedInPortal($this->client);

    portalPage($this->client)->assertInertia(fn (Assert $page) => $page->where('services.data.0.service.title', 'Passport'));
});

test('the number of queries does not grow with the number of services', function () {
    // The first page already has a sub-service, so both relations are loaded in both counts.
    portalService($this->client, $this->passport, $this->bath, ['1000.00']);
    signedInPortal($this->client);

    DB::enableQueryLog();
    portalPage($this->client)->assertOk();
    $one = count(DB::getQueryLog());

    foreach (range(1, 4) as $unused) {
        portalService($this->client, $this->passport, $this->bath, ['500.00', '250.00']);
    }

    DB::flushQueryLog();
    portalPage($this->client)->assertOk();

    expect(count(DB::getQueryLog()))->toBe($one);
});
