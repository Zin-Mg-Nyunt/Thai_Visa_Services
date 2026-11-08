<?php

use App\Models\Client;
use App\Models\ClientService;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'Asia/Bangkok'));

    $this->admin = User::factory()->create();
    $this->passport = Service::factory()->create(['title' => 'Passport']);
    $this->bath = Service::factory()->subServiceOf($this->passport)->create(['title' => 'BATH']);
    $this->pinkCard = Service::factory()->create(['title' => 'Pink Card']);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * @param  array<int, array{0: string, 1: string}>  $payments  [paid_at, amount]
 * @param  array<string, mixed>  $state
 */
function takenService(Service $service, ?Service $sub, array $payments, array $state = [], ?Client $client = null): ClientService
{
    $clientService = ClientService::factory()->forService($service, $sub)->create([
        'client_id' => ($client ?? Client::factory()->create())->id,
        'fee' => '10000.00',
        ...$state,
    ]);

    foreach ($payments as [$paidAt, $amount]) {
        Payment::factory()->create(['client_service_id' => $clientService->id, 'paid_at' => $paidAt, 'amount' => $amount]);
    }

    return $clientService;
}

function visitDashboard(mixed $test, array $query = []): Assert|TestResponse
{
    return $test->actingAs($test->admin)->get(route('dashboard', $query));
}

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('the default range is this month and only payments in it are counted', function () {
    takenService($this->passport, null, [['2026-11-02', '4000.10'], ['2026-11-14', '1000.20'], ['2026-10-31', '9999.00'], ['2026-12-01', '8888.00']]);

    visitDashboard($this)->assertInertia(fn (Assert $page) => $page
        ->where('range.key', 'this_month')
        ->where('range.from', '2026-11-01')
        ->where('range.to', '2026-11-15')
        ->where('report.received', '5000.30')
        ->where('report.refunded', '0.00'));
});

test('last month, this year and a custom range pick the right payments', function (array $query, string $from, string $to, string $received) {
    takenService($this->passport, null, [['2026-01-10', '100.00'], ['2026-10-05', '200.00'], ['2026-11-05', '400.00']]);

    visitDashboard($this, $query)->assertInertia(fn (Assert $page) => $page
        ->where('range.from', $from)
        ->where('range.to', $to)
        ->where('report.received', $received));
})->with([
    'last month' => [['range' => 'last_month'], '2026-10-01', '2026-10-31', '200.00'],
    'this year' => [['range' => 'this_year'], '2026-01-01', '2026-11-15', '700.00'],
    'custom' => [['range' => 'custom', 'from' => '2026-10-05', 'to' => '2026-11-05'], '2026-10-05', '2026-11-05', '600.00'],
]);

test('a custom range that is not valid stays on the dashboard with its error and the dates as typed', function (array $query, string $field) {
    takenService($this->passport, null, [['2026-11-02', '700.00'], ['2026-10-02', '900.00']]);

    visitDashboard($this, $query)
        ->assertOk()
        ->assertSessionHasNoErrors()
        ->assertInertia(fn (Assert $page) => $page
            ->where('range.key', 'custom')
            ->where('customInput.from', $query['from'] ?? '')
            ->where('customInput.to', $query['to'] ?? '')
            ->has("rangeErrors.{$field}")
            // Until the range is fixed, the figures are this month's.
            ->where('range.from', '2026-11-01')
            ->where('range.to', '2026-11-15')
            ->where('report.received', '700.00'));
})->with([
    'no dates' => [['range' => 'custom'], 'from'],
    'end before start' => [['range' => 'custom', 'from' => '2026-11-05', 'to' => '2026-11-01'], 'to'],
    'bad date' => [['range' => 'custom', 'from' => 'soon', 'to' => '2026-11-01'], 'from'],
]);

test('a bad range name falls back to this month without an error', function () {
    visitDashboard($this, ['range' => 'forever'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('range.key', 'this_month')
            ->where('rangeErrors', [])
            ->where('range.from', '2026-11-01'));
});

test('a preset range ignores dates that are sent with it, even bad ones', function () {
    takenService($this->passport, null, [['2026-10-05', '200.00']]);

    visitDashboard($this, ['range' => 'last_month', 'from' => 'soon', 'to' => '1999-01-01'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('range.key', 'last_month')
            ->where('rangeErrors', [])
            ->where('customInput.from', '2026-10-01')
            ->where('customInput.to', '2026-10-31')
            ->where('report.received', '200.00'));
});

test('a valid custom range sends its dates back to the form', function () {
    visitDashboard($this, ['range' => 'custom', 'from' => '2026-10-05', 'to' => '2026-11-05'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('customInput.from', '2026-10-05')
            ->where('customInput.to', '2026-11-05')
            ->where('rangeErrors', []));
});

test('only a superadmin may view the dashboard', function () {
    $staff = User::factory()->create();
    DB::table('users')->where('id', $staff->id)->update(['role' => 'staff']);

    expect(Gate::forUser($this->admin)->allows('viewDashboard'))->toBeTrue()
        ->and(Gate::allows('viewDashboard'))->toBeFalse();

    $this->actingAs($staff->fresh())->get(route('dashboard'))->assertForbidden();
});

test('a cancelled service refund is taken off on the cancel day', function () {
    takenService($this->passport, null, [['2026-10-20', '4000.00']], ['status' => 'cancel', 'cancelled_at' => Carbon::parse('2026-11-10 09:00:00', 'Asia/Bangkok'), 'cancel_refund_amount' => '1500.25']);

    visitDashboard($this)->assertInertia(fn (Assert $page) => $page
        ->where('report.received', '-1500.25')
        ->where('report.refunded', '1500.25'));

    visitDashboard($this, ['range' => 'last_month'])->assertInertia(fn (Assert $page) => $page
        ->where('report.received', '4000.00')
        ->where('report.refunded', '0.00'));
});

test('a refund that no refund remark explains still counts, on the last payment day', function () {
    // Only possible by editing the database by hand: the app always writes a refund remark with the refund.
    takenService($this->passport, null, [['2026-10-01', '3000.00'], ['2026-11-03', '2000.00']], ['refund_amount' => '1000.00']);

    visitDashboard($this)->assertInertia(fn (Assert $page) => $page
        ->where('report.received', '1000.00')
        ->where('report.refunded', '1000.00'));
});

test('outstanding is the balance owed today whatever the range, and a cancelled service owes nothing', function () {
    takenService($this->passport, null, [['2026-01-05', '4000.00']]);
    takenService($this->pinkCard, null, [['2026-01-05', '1000.00']], ['status' => 'cancel', 'cancelled_at' => now()]);

    foreach (['this_month', 'last_month', 'this_year'] as $range) {
        visitDashboard($this, ['range' => $range])->assertInertia(fn (Assert $page) => $page->where('report.outstanding', '6000.00'));
    }
});

test('active clients are those with a service in progress, and a deleted client is not active', function () {
    $busy = Client::factory()->create();
    takenService($this->passport, null, [], client: $busy);
    takenService($this->pinkCard, null, [], client: $busy);
    takenService($this->passport, null, [], ['status' => 'complete']);
    $gone = Client::factory()->create();
    takenService($this->passport, null, [['2026-11-02', '700.00']], client: $gone);
    $gone->delete();

    visitDashboard($this)->assertInertia(fn (Assert $page) => $page
        ->where('report.active_clients', 1)
        ->where('report.received', '700.00'));
});

test('the by-service rows hold sub-service rows that add up to the service and show deleted services as inactive', function () {
    takenService($this->passport, $this->bath, [['2026-11-02', '22000.10']]);
    takenService($this->passport, null, [['2026-11-03', '8000.00']]);
    takenService($this->pinkCard, null, [['2026-11-04', '4000.00']]);
    $this->pinkCard->delete();

    visitDashboard($this)->assertInertia(function (Assert $page) {
        $page->has('report.by_service', 2)
            ->where('report.by_service.0.service.id', $this->passport->id)
            ->where('report.by_service.0.received', '30000.10')
            ->where('report.by_service.0.share_percent', 100)
            ->where('report.by_service.0.sub_services.0.sub_service.id', $this->bath->id)
            ->where('report.by_service.0.sub_services.0.received', '22000.10')
            ->where('report.by_service.0.sub_services.1.sub_service', null)
            ->where('report.by_service.0.sub_services.1.received', '8000.00')
            ->where('report.by_service.1.service.is_inactive', true)
            ->where('report.by_service.1.received', '4000.00');

        $props = $page->toArray()['props']['report'];
        $total = collect($props['by_service'])->reduce(fn ($sum, $row) => bcadd($sum, $row['received'], 2), '0.00');

        expect($total)->toBe($props['received']);
    });
});

test('a service nobody paid for in the range is left out of the by-service list', function () {
    takenService($this->passport, null, [['2026-05-02', '500.00']]);

    visitDashboard($this)->assertInertia(fn (Assert $page) => $page->has('report.by_service', 0));
});

test('the month chart covers twelve months ending with the range end month', function () {
    takenService($this->passport, null, [['2026-11-02', '1000.00'], ['2026-10-02', '500.00'], ['2025-12-02', '250.00'], ['2025-11-30', '9999.00']]);

    visitDashboard($this)->assertInertia(fn (Assert $page) => $page
        ->has('report.months', 12)
        ->where('report.months.0.month', '2025-12')
        ->where('report.months.0.received', '250.00')
        ->where('report.months.10.month', '2026-10')
        ->where('report.months.10.received', '500.00')
        ->where('report.months.11.month', '2026-11')
        ->where('report.months.11.received', '1000.00')
        ->where('report.months.11.height_percent', 100)
        ->where('report.months.10.height_percent', 50));
});

test('the dashboard sends no profit figure, only the money received after refunds', function () {
    takenService($this->passport, null, [['2026-11-02', '1000.00']]);

    visitDashboard($this)->assertInertia(fn (Assert $page) => $page
        ->where('report.received', '1000.00')
        ->missing('report.sample_profit')
        ->missing('report.profit')
        ->missing('sampleProfitPercent'));
});

test('an empty system shows zeros', function () {
    visitDashboard($this)->assertInertia(fn (Assert $page) => $page
        ->where('report.received', '0.00')
        ->where('report.outstanding', '0.00')
        ->where('report.active_clients', 0)
        ->has('report.by_service', 0));
});

/**
 * Changes a service's fee through the edit form at a moment in Bangkok time, as an admin would.
 */
function changeFeeAt(mixed $test, string $moment, ClientService $record, string $fee, ?string $remark = null): void
{
    Carbon::setTestNow(Carbon::parse($moment, 'Asia/Bangkok'));

    $test->actingAs($test->admin)
        ->put(route('admin.clients.services.update', [$record->client_id, $record]), [
            'sub_service_id' => $record->sub_service_id, 'status' => 'in_progress',
            'start_date' => null, 'end_date' => null, 'fee' => $fee, 'remark' => $remark,
        ])
        ->assertSessionHasNoErrors();
}

/**
 * Cancels a service through the cancel dialog at a moment in Bangkok time.
 */
function cancelAt(mixed $test, string $moment, ClientService $record, ?string $cancelRefund = null): void
{
    Carbon::setTestNow(Carbon::parse($moment, 'Asia/Bangkok'));

    $test->actingAs($test->admin)
        ->post(route('admin.clients.services.cancel', [$record->client_id, $record]), ['cancel_refund_amount' => $cancelRefund])
        ->assertSessionHasNoErrors();
}

function backToToday(): void
{
    Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'Asia/Bangkok'));
}

/**
 * @return array{received: string, refunded: string}
 */
function receivedIn(mixed $test, string $from, string $to): array
{
    $report = null;

    visitDashboard($test, ['range' => 'custom', 'from' => $from, 'to' => $to])
        ->assertInertia(function (Assert $page) use (&$report) {
            $report = $page->toArray()['props']['report'];
        });

    return ['received' => $report['received'], 'refunded' => $report['refunded']];
}

describe('refunds keep to the day they happened', function () {
    test('an October fee refund stays in October when the service is cancelled in November', function () {
        $record = takenService($this->passport, null, [['2026-10-05', '5000.00']], ['fee' => '5000.00']);
        // The admin's own note in the same save is never read as a refund.
        changeFeeAt($this, '2026-10-10 10:00', $record, '3000', 'Client asked for a discount');
        backToToday();

        $october = receivedIn($this, '2026-10-01', '2026-10-31');
        expect($october)->toBe(['received' => '3000.00', 'refunded' => '2000.00']);

        // The cancel remark repeats the refund in its details; it must not count it again.
        cancelAt($this, '2026-11-10 10:00', $record);
        backToToday();

        expect(receivedIn($this, '2026-10-01', '2026-10-31'))->toBe($october)
            ->and(receivedIn($this, '2026-11-01', '2026-11-15'))->toBe(['received' => '0.00', 'refunded' => '0.00']);

        visitDashboard($this)->assertInertia(fn (Assert $page) => $page
            ->where('report.months.10.month', '2026-10')
            ->where('report.months.10.received', '3000.00')
            ->where('report.months.11.received', '0.00'));
    });

    test('a cancel refund counts in the cancel month, on top of an earlier fee refund', function () {
        $record = takenService($this->passport, null, [['2026-10-05', '5000.00']], ['fee' => '5000.00']);
        changeFeeAt($this, '2026-10-10 10:00', $record, '3000');
        cancelAt($this, '2026-11-10 10:00', $record, '1000');
        backToToday();

        expect(receivedIn($this, '2026-10-01', '2026-10-31'))->toBe(['received' => '3000.00', 'refunded' => '2000.00'])
            ->and(receivedIn($this, '2026-11-01', '2026-11-15'))->toBe(['received' => '-1000.00', 'refunded' => '1000.00'])
            ->and(receivedIn($this, '2026-01-01', '2026-11-15')['received'])->toBe($record->fresh()->kept())->toBe('2000.00');
    });

    test('each fee change counts on its own day, and raising the fee back adds the money back', function () {
        $record = takenService($this->passport, null, [['2026-08-05', '5000.00']], ['fee' => '8000.00']);
        changeFeeAt($this, '2026-08-20 10:00', $record, '4000');
        changeFeeAt($this, '2026-09-10 10:00', $record, '3000');
        changeFeeAt($this, '2026-10-03 10:00', $record, '6000');
        backToToday();

        expect(receivedIn($this, '2026-08-01', '2026-08-31'))->toBe(['received' => '4000.00', 'refunded' => '1000.00'])
            ->and(receivedIn($this, '2026-09-01', '2026-09-30'))->toBe(['received' => '-1000.00', 'refunded' => '1000.00'])
            ->and(receivedIn($this, '2026-10-01', '2026-10-31'))->toBe(['received' => '2000.00', 'refunded' => '-2000.00'])
            ->and(receivedIn($this, '2026-01-01', '2026-11-15'))->toBe(['received' => '5000.00', 'refunded' => '0.00'])
            ->and($record->fresh()->kept())->toBe('5000.00');
    });

    test('fee changes saved in the same second are taken in the order they were saved', function () {
        $record = takenService($this->passport, null, [['2026-11-02', '5000.00']], ['fee' => '5000.00']);
        changeFeeAt($this, '2026-11-03 10:00:00', $record, '4000');
        changeFeeAt($this, '2026-11-03 10:00:00', $record, '3000');
        backToToday();

        expect(receivedIn($this, '2026-11-01', '2026-11-15'))->toBe(['received' => '3000.00', 'refunded' => '2000.00']);
    });
});

test('a month below zero keeps a bar sized by its amount', function () {
    takenService($this->passport, null, [['2026-10-20', '4000.00']], ['status' => 'cancel', 'cancelled_at' => Carbon::parse('2026-11-10 09:00:00', 'Asia/Bangkok'), 'cancel_refund_amount' => '1500.25']);

    visitDashboard($this)->assertInertia(fn (Assert $page) => $page
        ->where('report.months.10.received', '4000.00')
        ->where('report.months.10.height_percent', 100)
        ->where('report.months.11.received', '-1500.25')
        ->where('report.months.11.height_percent', 37));
});

test('the database sums are exact to the satang', function () {
    takenService($this->passport, $this->bath, [['2026-11-02', '0.10'], ['2026-11-02', '0.20']]);
    takenService($this->pinkCard, null, [['2026-11-03', '9999999999.99'], ['2026-11-04', '9999999999.99']], ['fee' => '9999999999.99']);

    visitDashboard($this)->assertInertia(fn (Assert $page) => $page
        ->where('report.received', '20000000000.28')
        ->where('report.months.11.received', '20000000000.28')
        ->where('report.by_service.1.received', '0.30')
        ->where('report.by_service.1.sub_services.0.received', '0.30')
        ->where('report.outstanding', '9999.70'));
});
