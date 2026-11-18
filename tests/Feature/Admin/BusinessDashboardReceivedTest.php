<?php

use App\Actions\Dashboard\BusinessReport;
use App\Actions\JobSearch\AddWorker;
use App\Actions\JobSearch\CancelHiredApplication;
use App\Actions\JobSearch\JobOrderMoney;
use App\Enums\ClientServiceStatus;
use App\Enums\WorkerCaseStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Total Received on the Business Dashboard is the net money kept across ALL services: payments minus refunds and cancel refunds.
 * Job Search money that no job order owns yet (Unassigned) is only a job order attribution label. It is already inside Total
 * Received, it is not shown as a separate figure, and moving it to a job order never changes the total.
 */
beforeEach(function () {
    app()->setLocale('en');
    Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'Asia/Bangkok'));

    $this->admin = User::factory()->create();
    $this->passport = Service::factory()->create(['title' => 'Passport']);
    $this->jobSearch = Service::factory()->jobSearch()->create();
    $this->orderA = JobOrder::factory()->create();
    $this->orderB = JobOrder::factory()->create();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Every money figure of the report. Active clients is a head count that legitimately follows a service going back to In
 * Progress, so it is not part of what must stay the same when money moves between job orders.
 */
function receivedReport(): array
{
    return Arr::except(receivedFullReport(), ['active_clients']);
}

function receivedFullReport(): array
{
    return app(BusinessReport::class)->build(
        CarbonImmutable::parse('2026-11-01', 'Asia/Bangkok'),
        CarbonImmutable::parse('2026-11-15', 'Asia/Bangkok'),
    );
}

/**
 * @param  array<string, mixed>  $state
 */
function receivedRecord(Service $service, array $state, string $paid, string $paidAt = '2026-11-02'): ClientService
{
    $record = ClientService::factory()->forService($service)->create(['client_id' => Client::factory()->create()->id, ...$state]);
    Payment::factory()->create(['client_service_id' => $record->id, 'amount' => $paid, 'paid_at' => $paidAt]);

    return $record;
}

/**
 * The same figure worked out from the records alone: the Kept Amount of every service, added up once.
 */
function keptOfAllServices(): string
{
    return ClientService::query()->with('payments')->get()->reduce(fn (string $sum, ClientService $record) => bcadd($sum, $record->kept(), 2), '0.00');
}

/**
 * An ordinary service (10,000 kept), a Job Search service attributed to order A (15,000 kept) and one that is Unassigned (8,000).
 *
 * @return array{ordinary: ClientService, attributed: ClientService, unassigned: ClientService, worker: JobOrderWorker}
 */
function receivedWorld(object $test): array
{
    $ordinary = receivedRecord($test->passport, ['fee' => '10000.00'], '10000.00');

    $attributed = receivedRecord($test->jobSearch, ['fee' => '15000.00', 'status' => ClientServiceStatus::Complete], '15000.00', '2026-11-03');
    $worker = JobOrderWorker::factory()->create([
        'job_order_id' => $test->orderA->id, 'client_id' => $attributed->client_id,
        'client_service_id' => $attributed->id, 'case_status' => WorkerCaseStatus::Hired,
    ]);

    $unassigned = receivedRecord($test->jobSearch, ['fee' => '8000.00', 'status' => ClientServiceStatus::InProgress], '8000.00', '2026-11-04');
    JobOrderWorker::factory()->create([
        'job_order_id' => $test->orderA->id, 'client_id' => $unassigned->client_id,
        'client_service_id' => $unassigned->id, 'case_status' => WorkerCaseStatus::Rejected,
    ]);

    return compact('ordinary', 'attributed', 'unassigned', 'worker');
}

test('Total Received includes ordinary services, Job Search money on an order, and Job Search money that is Unassigned', function () {
    receivedWorld($this);
    $money = app(JobOrderMoney::class);

    $totals = $money->totals();

    expect(receivedReport()['received'])->toBe('33000.00')
        // The three parts, each counted once: ordinary + the order's Money + the Unassigned amount.
        ->and($money->forOrder($this->orderA->id)['amount'])->toBe('15000.00')
        ->and($totals['unassigned'])->toBe('8000.00')
        ->and($totals['kept'])->toBe('23000.00')
        ->and(bcadd('10000.00', bcadd($money->forOrder($this->orderA->id)['amount'], $totals['unassigned'], 2), 2))->toBe('33000.00');
});

test('Total Received is exactly the Kept Amount of all services, so nothing is counted twice or left out', function () {
    receivedWorld($this);

    expect(receivedReport()['received'])->toBe(keptOfAllServices())
        // Adding the Unassigned amount on top would be the double count this rule forbids.
        ->and(receivedReport()['received'])->not->toBe(bcadd(keptOfAllServices(), app(JobOrderMoney::class)->totals()['unassigned'], 2));
});

test('refunds and cancel refunds reduce Total Received, for ordinary and Job Search services', function () {
    $world = receivedWorld($this);

    // A fee-change refund on the ordinary service, and a Job Search service cancelled with a cancel refund.
    $world['ordinary']->update(['refund_amount' => '1500.00']);
    $cancelled = receivedRecord($this->jobSearch, ['fee' => '6000.00', 'status' => ClientServiceStatus::Cancel, 'cancelled_at' => '2026-11-10 10:00:00', 'cancel_refund_amount' => '2000.00'], '6000.00', '2026-11-05');

    $report = receivedReport();

    // 10,000 - 1,500 + 15,000 + 8,000 + (6,000 - 2,000)
    expect($report['received'])->toBe('35500.00')
        ->and($report['refunded'])->toBe('3500.00')
        ->and($report['received'])->toBe(keptOfAllServices())
        ->and($cancelled->fresh()->kept())->toBe('4000.00')
        // The cancelled service's kept money is Unassigned: 8,000 + 4,000, and still inside the total.
        ->and(app(JobOrderMoney::class)->totals()['unassigned'])->toBe('12000.00');
});

test('moving Unassigned money onto a job order, and back, never changes Total Received', function () {
    $world = receivedWorld($this);
    $money = app(JobOrderMoney::class);
    $before = receivedReport();

    // Hired -> Cancelled: order A loses 15,000, which becomes Unassigned.
    app(CancelHiredApplication::class)->handle($world['worker']);
    $released = receivedReport();

    expect($money->forOrder($this->orderA->id)['amount'])->toBe('0.00')
        ->and($money->totals()['unassigned'])->toBe('23000.00')
        ->and($released)->toBe($before);

    // The person is assigned to order B: the Unassigned money becomes order B's Money.
    app(AddWorker::class)->handle($this->orderB, $world['attributed']->client_id, [], '15000.00');
    $assigned = receivedReport();

    expect($money->forOrder($this->orderB->id)['amount'])->toBe('15000.00')
        ->and($money->totals()['unassigned'])->toBe('8000.00')
        // Every money figure (received, refunded, outstanding, months, by-service rows) is identical, not only the headline one.
        ->and($assigned)->toBe($before)
        ->and($assigned['received'])->toBe('33000.00');
});

test('the dashboard payload has no Unassigned figure at all', function () {
    receivedWorld($this);

    expect(array_key_exists('unassigned_kept', receivedFullReport()))->toBeFalse();

    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('report.received', '33000.00')->missing('report.unassigned_kept'));
});

test('the dashboard page has no Unassigned card, text or field', function () {
    $page = file_get_contents(resource_path('js/pages/Dashboard.vue'));
    $english = file_get_contents(resource_path('js/locales/en.ts'));
    $types = file_get_contents(resource_path('js/types/dashboard.ts'));

    expect($page)->not->toContain('card-unassigned')
        ->not->toContain('unassigned_kept')
        ->not->toContain('cards.unassigned')
        ->and($types)->not->toContain('unassigned_kept')
        // The word stays where it belongs, the Client page, but is gone from the dashboard cards.
        ->and($english)->toContain("unassigned: 'Unassigned'");
});
