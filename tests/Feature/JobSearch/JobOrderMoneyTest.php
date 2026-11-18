<?php

use App\Actions\Dashboard\BusinessReport;
use App\Actions\JobSearch\JobOrderMoney;
use App\Enums\ClientServiceStatus;
use App\Enums\WorkerCaseStatus as S;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Job Order Money and Unassigned Kept Amount: both are worked out live from the records, applications and payments. Nothing
 * is stored and no payment or refund is created to move money between orders.
 */
beforeEach(function () {
    app()->setLocale('en');
    $this->jobSearch = Service::factory()->jobSearch()->create();
    $this->admin = User::factory()->create();
    $this->money = app(JobOrderMoney::class);
});

/**
 * One Job Search record of a person with an application on the order, and the money received on it.
 *
 * @param  array<string, mixed>  $record
 */
function applicationOn(JobOrder $order, S $status, ClientServiceStatus $service, string $paid, array $record = [], ?Client $client = null): JobOrderWorker
{
    $client ??= Client::factory()->create();
    $engagement = ClientService::factory()->forService(Service::query()->where('key', Service::JOB_SEARCH_KEY)->first())
        ->create(array_merge(['client_id' => $client->id, 'fee' => '20000.00', 'status' => $service], $record));

    if (bccomp($paid, '0', 2) === 1) {
        Payment::factory()->create(['client_service_id' => $engagement->id, 'amount' => $paid, 'paid_at' => now()->toDateString()]);
    }

    return JobOrderWorker::factory()->create(['job_order_id' => $order->id, 'client_id' => $client->id, 'client_service_id' => $engagement->id, 'case_status' => $status]);
}

test('the Kept Amount of a record is the Money of the order of its Applied, Interview or Hired application', function (S $status, ClientServiceStatus $service) {
    $order = JobOrder::factory()->create();
    applicationOn($order, $status, $service, '7000.00');

    expect($this->money->forOrder($order->id))->toBe(['amount' => '7000.00', 'includes_deleted' => false])
        ->and($this->money->totals()['unassigned'])->toBe('0.00');
})->with([
    'applied' => [S::Applied, ClientServiceStatus::InProgress],
    'interview' => [S::Interview, ClientServiceStatus::InProgress],
    'hired' => [S::Hired, ClientServiceStatus::Complete],
]);

test('a Rejected or Cancelled application owns no money: it is Unassigned and still part of the record\'s own Kept Amount', function (S $status) {
    $order = JobOrder::factory()->create();
    $worker = applicationOn($order, $status, ClientServiceStatus::InProgress, '7000.00');

    expect($this->money->forOrder($order->id)['amount'])->toBe('0.00')
        ->and($this->money->totals())->toBe(['kept' => '7000.00', 'attributed' => '0.00', 'unassigned' => '7000.00'])
        ->and($worker->clientService->kept())->toBe('7000.00');
})->with([S::Rejected, S::Cancelled]);

test('a whole-service Cancelled record\'s Kept Amount, after its cancel refund, is Unassigned', function () {
    $order = JobOrder::factory()->create();
    applicationOn($order, S::Cancelled, ClientServiceStatus::Cancel, '9000.00', ['cancel_refund_amount' => '2000.00', 'cancelled_at' => now()]);

    expect($this->money->forOrder($order->id)['amount'])->toBe('0.00')
        ->and($this->money->totals()['unassigned'])->toBe('7000.00');
});

test('refunds are subtracted once: the Money is received minus the fee-change refund', function () {
    $order = JobOrder::factory()->create();
    applicationOn($order, S::Applied, ClientServiceStatus::InProgress, '9000.00', ['refund_amount' => '1500.00']);

    expect($this->money->forOrder($order->id)['amount'])->toBe('7500.00');
});

test('Example A: a payment after the order closed raises that closed order\'s Money, and Outstanding falls', function () {
    $order = JobOrder::factory()->done()->create();
    $worker = applicationOn($order, S::Hired, ClientServiceStatus::Complete, '10000.00', ['fee' => '15000.00']);

    expect($this->money->forOrder($order->id)['amount'])->toBe('10000.00');

    Payment::factory()->create(['client_service_id' => $worker->client_service_id, 'amount' => '5000.00', 'paid_at' => now()->toDateString()]);

    expect($this->money->forOrder($order->id)['amount'])->toBe('15000.00')
        ->and($worker->clientService->fresh()->remaining())->toBe('0.00')
        ->and($order->fresh()->isOpen())->toBeFalse();
});

test('a payment while the money is Unassigned raises the Unassigned amount, and a new application takes it all', function () {
    $orderA = JobOrder::factory()->done()->create();
    $worker = applicationOn($orderA, S::Cancelled, ClientServiceStatus::InProgress, '4000.00');

    Payment::factory()->create(['client_service_id' => $worker->client_service_id, 'amount' => '1000.00', 'paid_at' => now()->toDateString()]);

    expect($this->money->totals()['unassigned'])->toBe('5000.00');

    $orderB = JobOrder::factory()->create();
    JobOrderWorker::factory()->create(['job_order_id' => $orderB->id, 'client_id' => $worker->client_id, 'client_service_id' => $worker->client_service_id, 'case_status' => S::Applied]);

    expect($this->money->forOrder($orderB->id)['amount'])->toBe('5000.00')
        ->and($this->money->forOrder($orderA->id)['amount'])->toBe('0.00')
        ->and($this->money->totals()['unassigned'])->toBe('0.00');
});

test('the same Kept Amount is never in two orders, even when a record abnormally has two qualifying applications', function () {
    $orderA = JobOrder::factory()->create();
    $orderB = JobOrder::factory()->create();
    $first = applicationOn($orderA, S::Interview, ClientServiceStatus::InProgress, '6000.00');
    $second = JobOrderWorker::factory()->create(['job_order_id' => $orderB->id, 'client_id' => $first->client_id, 'client_service_id' => $first->client_service_id, 'case_status' => S::Applied]);

    $total = bcadd($this->money->forOrder($orderA->id)['amount'], $this->money->forOrder($orderB->id)['amount'], 2);

    expect($total)->toBe('6000.00')
        ->and($this->money->forOrder($orderB->id)['amount'])->toBe('6000.00')
        ->and($second->id)->toBeGreaterThan($first->id);
});

test('all orders\' Money plus the Unassigned amount is the Job Search Kept Amount', function () {
    $open = JobOrder::factory()->create();
    $closed = JobOrder::factory()->done()->create();
    applicationOn($open, S::Applied, ClientServiceStatus::InProgress, '3000.00');
    applicationOn($closed, S::Hired, ClientServiceStatus::Complete, '12000.00');
    applicationOn($closed, S::Rejected, ClientServiceStatus::InProgress, '800.00');
    applicationOn($open, S::Cancelled, ClientServiceStatus::Cancel, '5000.00', ['cancel_refund_amount' => '1000.00', 'cancelled_at' => now()]);
    // Another service's money is not Job Search money.
    Payment::factory()->create(['client_service_id' => ClientService::factory()->create()->id, 'amount' => '999.00', 'paid_at' => now()->toDateString()]);

    $totals = $this->money->totals();
    $orders = bcadd($this->money->forOrder($open->id)['amount'], $this->money->forOrder($closed->id)['amount'], 2);

    expect($totals['kept'])->toBe('19800.00')
        ->and(bcadd($orders, $totals['unassigned'], 2))->toBe($totals['kept'])
        ->and($totals['attributed'])->toBe('15000.00')
        ->and($totals['unassigned'])->toBe('4800.00');
});

test('a deleted person\'s Hired application stays attributed, and the order says so', function () {
    $order = JobOrder::factory()->done()->create();
    $worker = applicationOn($order, S::Hired, ClientServiceStatus::Complete, '10000.00');
    $worker->client->delete();

    expect($this->money->forOrder($order->id))->toBe(['amount' => '10000.00', 'includes_deleted' => true]);
});

test('closing an order that holds a deleted person\'s open application removes it from the order and makes it Unassigned, Kept unchanged', function () {
    $order = JobOrder::factory()->create();
    $worker = applicationOn($order, S::Interview, ClientServiceStatus::InProgress, '10000.00');
    $worker->client->delete();

    expect($this->money->forOrder($order->id)['amount'])->toBe('10000.00');

    $this->actingAs($this->admin)->post(route('admin.job-search.close', $order), [
        'status' => 'done', 'resolutions' => [], 'confirm_hired' => false,
        'expected' => ['open' => [], 'deleted_open' => [$worker->id]],
    ]);

    expect($order->fresh()->isClosed())->toBeTrue()
        ->and($this->money->forOrder($order->id)['amount'])->toBe('0.00')
        ->and($this->money->totals()['unassigned'])->toBe('10000.00')
        ->and($worker->clientService->fresh()->kept())->toBe('10000.00')
        ->and(Payment::count())->toBe(1);
});

test('the Job Order page sends its live Money', function () {
    $order = JobOrder::factory()->done()->create();
    applicationOn($order, S::Hired, ClientServiceStatus::Complete, '4200.00');

    $this->actingAs($this->admin)->get(route('admin.job-search.show', $order))
        ->assertInertia(fn ($page) => $page->where('money.amount', '4200.00')->where('money.includes_deleted', false));
});

test('Client Detail shows each Job Search service\'s kept money and where it is attributed, or that it is Unassigned', function () {
    $order = JobOrder::factory()->create(['company_name' => 'Acme Foods']);
    $worker = applicationOn($order, S::Interview, ClientServiceStatus::InProgress, '2500.00');

    $this->actingAs($this->admin)->get(route('admin.clients.show', $worker->client_id))
        ->assertInertia(fn ($page) => $page
            ->where("jobSearchAttribution.{$worker->client_service_id}.company_name", 'Acme Foods')
            ->where('clientServices.data.0.kept', '2500.00'));

    $worker->update(['case_status' => S::Cancelled]);

    $this->actingAs($this->admin)->get(route('admin.clients.show', $worker->client_id))
        ->assertInertia(fn ($page) => $page
            ->where("jobSearchAttribution.{$worker->client_service_id}", null)
            ->where('clientServices.data.0.kept', '2500.00'));
});

test('a service that is not Job Search has no attribution entry', function () {
    $client = Client::factory()->create();
    ClientService::factory()->create(['client_id' => $client->id]);

    $this->actingAs($this->admin)->get(route('admin.clients.show', $client))
        ->assertInertia(fn ($page) => $page->where('jobSearchAttribution', []));
});

test('Business Dashboard Total Received already includes the Unassigned amount once, and moving attribution never changes it', function () {
    $order = JobOrder::factory()->done()->create();
    $worker = applicationOn($order, S::Hired, ClientServiceStatus::Complete, '10000.00');
    $report = fn () => app(BusinessReport::class)->build(CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth());

    $before = $report();

    expect($before['received'])->toBe('10000.00')->and($this->money->totals()['unassigned'])->toBe('0.00');

    // Hired to Cancelled: the money stays received and kept, and now sits Unassigned.
    $this->actingAs($this->admin)->patch(route('admin.job-search.workers.cancel-hired', [$order, $worker]), ['confirm_cancel_hired' => true]);
    $after = $report();

    expect($after['received'])->toBe('10000.00')
        ->and($this->money->totals()['unassigned'])->toBe('10000.00')
        ->and($after['outstanding'])->toBe($before['outstanding'])
        ->and($this->money->forOrder($order->id)['amount'])->toBe('0.00');
});

test('the dashboard page sends no separate Unassigned figure: the money is part of Total Received', function () {
    $order = JobOrder::factory()->create();
    applicationOn($order, S::Cancelled, ClientServiceStatus::InProgress, '1234.00', ['status' => ClientServiceStatus::InProgress]);

    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('report.received', '1234.00')->missing('report.unassigned_kept'));
});
