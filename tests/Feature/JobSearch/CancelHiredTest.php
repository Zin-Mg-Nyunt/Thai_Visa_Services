<?php

use App\Actions\JobSearch\CancelHiredApplication;
use App\Actions\JobSearch\JobOrderMoney;
use App\Enums\ClientServiceStatus;
use App\Enums\JobOrderStatus;
use App\Enums\WorkerCaseStatus as S;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

/**
 * Hired to Cancelled: the one way out of Hired. Individual only, confirmed, also on a closed order, and atomic.
 */
beforeEach(function () {
    app()->setLocale('en');
    $this->jobSearch = Service::factory()->jobSearch()->create(['default_fee' => '10000.00']);
    $this->admin = User::factory()->create();
});

/**
 * A person Hired on the given order: their Job Search record is Complete and they paid the given amount.
 */
function hiredOn(JobOrder $order, string $paid = '10000.00', ?Client $client = null): JobOrderWorker
{
    $client ??= Client::factory()->create();
    $record = ClientService::factory()->forService(Service::query()->where('key', Service::JOB_SEARCH_KEY)->first())
        ->complete()->create(['client_id' => $client->id, 'fee' => '10000.00']);

    Payment::factory()->create(['client_service_id' => $record->id, 'amount' => $paid, 'paid_at' => now()->toDateString()]);

    return JobOrderWorker::factory()->create([
        'job_order_id' => $order->id,
        'client_id' => $client->id,
        'client_service_id' => $record->id,
        'case_status' => S::Hired,
    ]);
}

function cancelHiredRequest(mixed $test, JobOrderWorker $worker, bool $confirm = true, ?JobOrder $order = null): TestResponse
{
    return $test->actingAs($test->admin)->patch(
        route('admin.job-search.workers.cancel-hired', [$order ?? $worker->jobOrder, $worker]),
        ['confirm_cancel_hired' => $confirm],
    );
}

function chToast(): ?string
{
    return session('inertia.flash_data.toast.message') ?? session('toast.message');
}

test('an admin can cancel a Hired application after confirming: the application is Cancelled and the record is In Progress again', function () {
    $worker = hiredOn(JobOrder::factory()->create());

    cancelHiredRequest($this, $worker)->assertSessionHasNoErrors();

    expect($worker->fresh()->case_status)->toBe(S::Cancelled)
        ->and($worker->clientService->fresh()->status)->toBe(ClientServiceStatus::InProgress)
        ->and(chToast())->toContain('In Progress again');
});

test('it changes nothing else: no new record, no row deleted, fee, payments, refunds and remarks stay', function () {
    $worker = hiredOn(JobOrder::factory()->create());
    $record = $worker->clientService;
    $before = $record->fresh()->only(['fee', 'refund_amount', 'cancel_refund_amount', 'cancelled_at']);

    cancelHiredRequest($this, $worker);

    expect(JobOrderWorker::count())->toBe(1)
        ->and(ClientService::count())->toBe(1)
        ->and(Payment::count())->toBe(1)
        ->and($record->remarks()->count())->toBe(0)
        ->and($record->fresh()->only(['fee', 'refund_amount', 'cancel_refund_amount', 'cancelled_at']))->toBe($before);
});

test('the confirmation is required, and without it nothing changes', function () {
    $worker = hiredOn(JobOrder::factory()->create());

    cancelHiredRequest($this, $worker, confirm: false)->assertSessionHasErrors('confirm_cancel_hired');

    expect($worker->fresh()->case_status)->toBe(S::Hired)
        ->and($worker->clientService->fresh()->status)->toBe(ClientServiceStatus::Complete);
});

test('it works on a Done order and on a Cancelled order, and the order stays exactly as it was', function (string $state) {
    $order = JobOrder::factory()->{$state}()->create();
    $worker = hiredOn($order);
    $raw = fn () => Arr::only($order->fresh()->getAttributes(), ['status', 'closed_at', 'company_name', 'manpower', 'updated_at']);
    $before = $raw();

    cancelHiredRequest($this, $worker)->assertSessionHasNoErrors();

    expect($worker->fresh()->case_status)->toBe(S::Cancelled)
        ->and($worker->clientService->fresh()->status)->toBe(ClientServiceStatus::InProgress)
        ->and($raw())->toBe($before)
        ->and($order->fresh()->status)->not->toBe(JobOrderStatus::Open);
})->with(['done', 'cancelled']);

test('the order stays frozen after it: no new worker, no edit, and other status changes are still refused', function () {
    $order = JobOrder::factory()->done()->create();
    $worker = hiredOn($order);
    cancelHiredRequest($this, $worker);

    $other = Client::factory()->create();
    $this->actingAs($this->admin)->post(route('admin.job-search.workers.store', $order), ['client_id' => $other->id, 'fee' => '1000'])->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.job-search.edit', $order))->assertForbidden();

    $row = JobOrderWorker::factory()->create(['job_order_id' => $order->id, 'case_status' => S::Applied]);
    $this->actingAs($this->admin)->patch(route('admin.job-search.workers.status', $order), [
        'changes' => [['worker_id' => $row->id, 'status' => 'interview']],
    ])->assertForbidden();

    expect($row->fresh()->case_status)->toBe(S::Applied);
});

test('every other way out of Hired is refused and changes nothing', function (string $status) {
    $order = JobOrder::factory()->create();
    $worker = hiredOn($order);

    $response = $this->actingAs($this->admin)->patch(route('admin.job-search.workers.status', $order), [
        'changes' => [['worker_id' => $worker->id, 'status' => $status]],
        'confirm_hired' => true,
    ]);

    expect($worker->fresh()->case_status)->toBe(S::Hired)
        ->and($worker->clientService->fresh()->status)->toBe(ClientServiceStatus::Complete);

    $response->assertSessionHasNoErrors();
    expect(chToast())->toContain('cannot change');
})->with(['applied', 'interview', 'rejected', 'cancelled']);

test('a bulk update cannot perform Hired to Cancelled, and refuses the whole batch', function () {
    $order = JobOrder::factory()->create();
    $hired = hiredOn($order);
    $open = JobOrderWorker::factory()->create(['job_order_id' => $order->id, 'case_status' => S::Applied]);

    $this->actingAs($this->admin)->patch(route('admin.job-search.workers.status', $order), [
        'changes' => [['worker_id' => $hired->id, 'status' => 'cancelled'], ['worker_id' => $open->id, 'status' => 'cancelled']],
    ]);

    expect($hired->fresh()->case_status)->toBe(S::Hired)
        ->and($open->fresh()->case_status)->toBe(S::Applied)
        ->and($hired->clientService->fresh()->status)->toBe(ClientServiceStatus::Complete);
});

test('the job order close cannot perform Hired to Cancelled either', function () {
    $order = JobOrder::factory()->create();
    $hired = hiredOn($order);
    $open = JobOrderWorker::factory()->create(['job_order_id' => $order->id, 'case_status' => S::Applied]);

    $response = $this->actingAs($this->admin)->post(route('admin.job-search.close', $order), [
        'status' => 'done',
        'resolutions' => [['worker_id' => $open->id, 'status' => 'rejected'], ['worker_id' => $hired->id, 'status' => 'cancelled']],
        'confirm_hired' => false,
        'expected' => ['open' => [['worker_id' => $open->id, 'status' => 'applied']], 'deleted_open' => []],
    ]);

    expect($order->fresh()->status)->toBe(JobOrderStatus::Open)
        ->and($hired->fresh()->case_status)->toBe(S::Hired)
        ->and($open->fresh()->case_status)->toBe(S::Applied);
});

test('a Cancelled application is never reactivated', function () {
    $order = JobOrder::factory()->create();
    $worker = hiredOn($order);
    cancelHiredRequest($this, $worker);

    $this->actingAs($this->admin)->patch(route('admin.job-search.workers.status', $order), [
        'changes' => [['worker_id' => $worker->id, 'status' => 'applied']],
    ]);

    expect($worker->fresh()->case_status)->toBe(S::Cancelled);

    // And cancelling it again is refused: it is no longer Hired.
    cancelHiredRequest($this, $worker);
    expect(chToast())->toContain('no longer Hired');
});

test('a soft-deleted person cannot use it: the Hired application and its record stay as they are', function () {
    $worker = hiredOn(JobOrder::factory()->done()->create());
    $worker->client->delete();

    cancelHiredRequest($this, $worker);

    expect(chToast())->toContain('was deleted')
        ->and($worker->fresh()->case_status)->toBe(S::Hired)
        ->and($worker->clientService->fresh()->status)->toBe(ClientServiceStatus::Complete)
        ->and(app(JobOrderMoney::class)->forOrder($worker->job_order_id)['amount'])->toBe('10000.00');
});

test('another In Progress Job Search record of the person blocks it, and that record is not touched', function () {
    $order = JobOrder::factory()->done()->create();
    $worker = hiredOn($order);

    // The person moved on: a newer record, In Progress, with an open application on another order.
    $newer = ClientService::factory()->forService($this->jobSearch)->create(['client_id' => $worker->client_id]);
    $open = JobOrderWorker::factory()->create(['client_id' => $worker->client_id, 'client_service_id' => $newer->id, 'case_status' => S::Interview]);

    cancelHiredRequest($this, $worker);

    expect(chToast())->toContain('another Job Search service in progress')
        ->and($worker->fresh()->case_status)->toBe(S::Hired)
        ->and($worker->clientService->fresh()->status)->toBe(ClientServiceStatus::Complete)
        ->and($newer->fresh()->status)->toBe(ClientServiceStatus::InProgress)
        ->and($open->fresh()->case_status)->toBe(S::Interview);
});

test('a Complete record of another service, or an old Cancelled Job Search record, does not block it', function () {
    $worker = hiredOn(JobOrder::factory()->create());
    ClientService::factory()->forService($this->jobSearch)->cancelled()->create(['client_id' => $worker->client_id]);
    ClientService::factory()->create(['client_id' => $worker->client_id]); // some other service, In Progress

    cancelHiredRequest($this, $worker)->assertSessionHasNoErrors();

    expect($worker->fresh()->case_status)->toBe(S::Cancelled);
});

test('a Hired application on a record that is not Complete is refused and logged, never repaired', function () {
    Log::spy();
    $worker = hiredOn(JobOrder::factory()->create());
    $worker->clientService->update(['status' => ClientServiceStatus::InProgress]);

    cancelHiredRequest($this, $worker);

    Log::shouldHaveReceived('warning')->once();
    expect($worker->fresh()->case_status)->toBe(S::Hired)
        ->and($worker->clientService->fresh()->status)->toBe(ClientServiceStatus::InProgress);
});

test('if the record update fails, the application is not changed either: everything rolls back', function () {
    $worker = hiredOn(JobOrder::factory()->done()->create());
    ClientService::updating(fn () => throw new RuntimeException('forced failure'));

    try {
        expect(fn () => app(CancelHiredApplication::class)->handle($worker))->toThrow(RuntimeException::class, 'forced failure');
    } finally {
        ClientService::flushEventListeners();
    }

    expect($worker->fresh()->case_status)->toBe(S::Hired)
        ->and($worker->clientService->fresh()->status)->toBe(ClientServiceStatus::Complete)
        ->and(app(JobOrderMoney::class)->forOrder($worker->job_order_id)['amount'])->toBe('10000.00');
});

test('an application of another order is not found under this order', function () {
    $worker = hiredOn(JobOrder::factory()->create());
    $other = JobOrder::factory()->create();

    cancelHiredRequest($this, $worker, order: $other)->assertNotFound();

    expect($worker->fresh()->case_status)->toBe(S::Hired);
});

test('the worker list offers the cancel only for a Hired application on a Complete record, also on a closed order', function () {
    $order = JobOrder::factory()->done()->create();
    $hired = hiredOn($order);
    JobOrderWorker::factory()->create(['job_order_id' => $order->id, 'case_status' => S::Rejected]);

    $this->actingAs($this->admin)->get(route('admin.job-search.show', $order))
        ->assertInertia(fn ($page) => $page
            ->where('workers.data', fn ($rows) => collect($rows)->where('can_cancel_hired', true)->pluck('id')->all() === [$hired->id]));
});

test('the old order loses the money, it is Unassigned, and a new order receives it without double counting', function () {
    $orderA = JobOrder::factory()->done()->create();
    $orderB = JobOrder::factory()->create();
    $worker = hiredOn($orderA, '15000.00');
    $money = app(JobOrderMoney::class);

    expect($money->forOrder($orderA->id)['amount'])->toBe('15000.00')
        ->and($money->totals()['unassigned'])->toBe('0.00');

    cancelHiredRequest($this, $worker);

    expect($money->forOrder($orderA->id)['amount'])->toBe('0.00')
        ->and($money->totals()['unassigned'])->toBe('15000.00')
        ->and(Payment::count())->toBe(1);

    // Added to order B: the same engagement, a new application.
    $this->actingAs($this->admin)->post(route('admin.job-search.workers.store', $orderB), ['client_id' => $worker->client_id, 'fee' => '10000'])
        ->assertSessionHasNoErrors();

    expect($money->forOrder($orderA->id)['amount'])->toBe('0.00')
        ->and($money->forOrder($orderB->id)['amount'])->toBe('15000.00')
        ->and($money->totals()['unassigned'])->toBe('0.00')
        ->and($worker->fresh()->case_status)->toBe(S::Cancelled)
        ->and(ClientService::count())->toBe(1);
});
