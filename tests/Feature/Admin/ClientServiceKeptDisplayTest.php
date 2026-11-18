<?php

use App\Enums\ClientServiceStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The Client Detail page shows "Kept" for a service only when the service has a refund (a fee-change refund) or has been cancelled
 * (resources/js/pages/admin/clients/Show.vue, baseRows). A payment alone never shows it. Job Search follows the same rule as every
 * other service. The page decides from these three fields, so this pins what it is given: the refund fields and the Kept Amount,
 * which is always payments minus both refunds, counted once.
 */
beforeEach(function () {
    app()->setLocale('en');
    $this->admin = User::factory()->create();
    $this->client = Client::factory()->create();
});

/**
 * @param  array<string, mixed>  $state
 */
function keptPayload(object $test, bool $jobSearch, array $state, ?string $paid): array
{
    $service = $jobSearch ? Service::factory()->jobSearch()->create() : Service::factory()->create(['title' => 'Passport']);
    $record = ClientService::factory()->forService($service)->create(['client_id' => $test->client->id, 'fee' => '10000.00', ...$state]);

    if ($paid !== null) {
        Payment::factory()->create(['client_service_id' => $record->id, 'amount' => $paid, 'paid_at' => today()->toDateString()]);
    }

    $payload = [];
    $test->actingAs($test->admin)->get(route('admin.clients.show', $test->client))
        ->assertInertia(function (Assert $page) use (&$payload) {
            $payload = $page->toArray()['props']['clientServices']['data'][0];
        });

    return collect($payload)->only(['status', 'refund_amount', 'cancel_refund_amount', 'received', 'kept'])->all();
}

dataset('kept cases', [
    'a deposit and no refund: Kept is not shown' => [
        ['status' => ClientServiceStatus::InProgress], '5000.00',
        ['status' => 'in_progress', 'refund_amount' => '0.00', 'cancel_refund_amount' => '0.00', 'received' => '5000.00', 'kept' => '5000.00'],
    ],
    'a deposit and a partial refund: Kept is shown after the refund' => [
        ['status' => ClientServiceStatus::InProgress, 'refund_amount' => '1000.00'], '5000.00',
        ['status' => 'in_progress', 'refund_amount' => '1000.00', 'cancel_refund_amount' => '0.00', 'received' => '5000.00', 'kept' => '4000.00'],
    ],
    'a cancellation refund: Kept is shown after it' => [
        ['status' => ClientServiceStatus::Cancel, 'cancelled_at' => now(), 'cancel_refund_amount' => '2000.00'], '5000.00',
        ['status' => 'cancel', 'refund_amount' => '0.00', 'cancel_refund_amount' => '2000.00', 'received' => '5000.00', 'kept' => '3000.00'],
    ],
    'both refunds: each is taken off once' => [
        ['status' => ClientServiceStatus::Cancel, 'cancelled_at' => now(), 'refund_amount' => '1000.00', 'cancel_refund_amount' => '1500.00'], '5000.00',
        ['status' => 'cancel', 'refund_amount' => '1000.00', 'cancel_refund_amount' => '1500.00', 'received' => '5000.00', 'kept' => '2500.00'],
    ],
    'no payment and no refund: Kept is not shown' => [
        ['status' => ClientServiceStatus::InProgress], null,
        ['status' => 'in_progress', 'refund_amount' => '0.00', 'cancel_refund_amount' => '0.00', 'received' => '0.00', 'kept' => '0.00'],
    ],
]);

test('Job Search gets the refund fields and the Kept Amount the page decides from', function (array $state, ?string $paid, array $expected) {
    expect(keptPayload($this, true, $state, $paid))->toBe($expected);
})->with('kept cases');

test('any other service gets exactly the same fields for the same money, so both follow one rule', function (array $state, ?string $paid, array $expected) {
    expect(keptPayload($this, false, $state, $paid))->toBe($expected);
})->with('kept cases');
