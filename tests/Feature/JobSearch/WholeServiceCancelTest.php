<?php

use App\Actions\JobSearch\AddWorker;
use App\Actions\JobSearch\CancelJobSearchService;
use App\Actions\JobSearch\JobOrderMoney;
use App\Enums\ClientServiceStatus;
use App\Enums\RemarkKind;
use App\Enums\WorkerCaseStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\ClientServiceRemark;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Whole-service cancellation of a Job Search service (business rules, Section 5): the service, its refund and every application of
 * it are cancelled together, on closed orders as well, and nothing is ever deleted.
 */
beforeEach(function () {
    app()->setLocale('en');
    $this->admin = User::factory()->create();
    $this->jobSearch = Service::factory()->jobSearch()->create();
    $this->client = Client::factory()->create();
    $this->record = ClientService::factory()->forService($this->jobSearch)->create([
        'client_id' => $this->client->id, 'status' => ClientServiceStatus::InProgress, 'fee' => '15000.00',
    ]);
    Payment::factory()->create(['client_service_id' => $this->record->id, 'amount' => '10000.00', 'paid_at' => today()->toDateString()]);
});

function rowOn(JobOrder $order, WorkerCaseStatus $status, ClientService $record): JobOrderWorker
{
    return JobOrderWorker::factory()->create([
        'job_order_id' => $order->id, 'client_id' => $record->client_id, 'client_service_id' => $record->id, 'case_status' => $status,
    ]);
}

function cancelService(object $test, ClientService $record, ?string $refund = '2000.00'): TestResponse
{
    return $test->actingAs($test->admin)->post(
        route('admin.clients.services.cancel', [$record->client_id, $record]),
        ['cancel_refund_amount' => $refund],
    );
}

test('cancelling a Job Search service cancels every application of it, whatever its status and order, and deletes none', function () {
    $open = JobOrder::factory()->create();
    $done = JobOrder::factory()->done()->create();
    $closed = JobOrder::factory()->cancelled()->create();
    $rows = [
        'applied' => rowOn($open, WorkerCaseStatus::Applied, $this->record),
        'interview' => rowOn($done, WorkerCaseStatus::Interview, $this->record),
        'rejected' => rowOn($closed, WorkerCaseStatus::Rejected, $this->record),
        'hired' => rowOn($open, WorkerCaseStatus::Hired, $this->record), // abnormal on an In Progress service
        'cancelled' => rowOn($done, WorkerCaseStatus::Cancelled, $this->record),
    ];
    $alreadyCancelledStamp = $rows['cancelled']->fresh()->updated_at;
    $this->travel(2)->hours();

    cancelService($this, $this->record)->assertSessionHasNoErrors();

    expect($this->record->fresh())->status->toBe(ClientServiceStatus::Cancel)->cancel_refund_amount->toBe('2000.00')
        ->and($this->record->fresh()->cancelled_at)->not->toBeNull()
        ->and(JobOrderWorker::query()->where('client_service_id', $this->record->id)->count())->toBe(5)
        ->and(JobOrderWorker::query()->where('client_service_id', $this->record->id)->where('case_status', '!=', 'cancelled')->count())->toBe(0)
        // A row that was already Cancelled is kept as it is.
        ->and($rows['cancelled']->fresh()->updated_at->equalTo($alreadyCancelledStamp))->toBeTrue()
        // The closed orders themselves are not touched.
        ->and($done->fresh()->status->value)->toBe('done')
        ->and($closed->fresh()->status->value)->toBe('cancelled');
});

test('the refund is settled, a cancel remark records the numbers, and the money is Unassigned, still part of what was kept', function () {
    $order = JobOrder::factory()->create();
    rowOn($order, WorkerCaseStatus::Applied, $this->record);
    $money = app(JobOrderMoney::class);

    expect($money->forOrder($order->id)['amount'])->toBe('10000.00');

    cancelService($this, $this->record, '2000.00');

    $remark = ClientServiceRemark::query()->where('client_service_id', $this->record->id)->where('kind', RemarkKind::Cancel->value)->sole();

    expect($remark->details)->toMatchArray(['received' => '10000.00', 'cancel_refund' => '2000.00', 'kept' => '8000.00'])
        ->and($this->record->payments()->count())->toBe(1)
        ->and($money->forOrder($order->id)['amount'])->toBe('0.00')
        ->and($money->totals()['unassigned'])->toBe('8000.00');
});

test('a refund above the money kept is refused and nothing is cancelled, not even the applications', function () {
    $row = rowOn(JobOrder::factory()->create(), WorkerCaseStatus::Applied, $this->record);

    cancelService($this, $this->record, '10000.01')->assertSessionHasErrors(['cancel_refund_amount']);

    expect($this->record->fresh()->status)->toBe(ClientServiceStatus::InProgress)
        ->and($row->fresh()->case_status)->toBe(WorkerCaseStatus::Applied);
});

test('a Complete Job Search service cannot be cancelled: its Hired application stays', function () {
    $this->record->update(['status' => ClientServiceStatus::Complete]);
    $row = rowOn(JobOrder::factory()->create(), WorkerCaseStatus::Hired, $this->record);

    cancelService($this, $this->record)->assertSessionHasErrors(['status' => 'Only a service that is in progress can be cancelled.']);

    expect($this->record->fresh()->status)->toBe(ClientServiceStatus::Complete)
        ->and($row->fresh()->case_status)->toBe(WorkerCaseStatus::Hired);
});

test('a service that is already cancelled cannot be cancelled again', function () {
    rowOn(JobOrder::factory()->create(), WorkerCaseStatus::Applied, $this->record);
    cancelService($this, $this->record)->assertSessionHasNoErrors();
    $cancelledAt = $this->record->fresh()->cancelled_at;

    cancelService($this, $this->record)->assertSessionHasErrors(['status']);

    expect($this->record->fresh()->cancelled_at->equalTo($cancelledAt))->toBeTrue()
        ->and(ClientServiceRemark::query()->where('client_service_id', $this->record->id)->where('kind', RemarkKind::Cancel->value)->count())->toBe(1);
});

test('all or nothing: when the cancel remark cannot be written, the service and every application stay as they were', function () {
    $applied = rowOn(JobOrder::factory()->create(), WorkerCaseStatus::Applied, $this->record);
    $interview = rowOn(JobOrder::factory()->done()->create(), WorkerCaseStatus::Interview, $this->record);

    ClientServiceRemark::creating(fn () => throw new RuntimeException('the remark failed'));

    try {
        expect(fn () => app(CancelJobSearchService::class)->handle($this->record, '2000.00', $this->admin->id))->toThrow(RuntimeException::class, 'the remark failed');
    } finally {
        ClientServiceRemark::flushEventListeners();
    }

    expect($this->record->fresh())->status->toBe(ClientServiceStatus::InProgress)->cancel_refund_amount->toBe('0.00')
        ->and($this->record->fresh()->cancelled_at)->toBeNull()
        ->and($applied->fresh()->case_status)->toBe(WorkerCaseStatus::Applied)
        ->and($interview->fresh()->case_status)->toBe(WorkerCaseStatus::Interview);
});

test('only this service is cancelled: another person\'s applications and the same person\'s other services are untouched', function () {
    $mine = rowOn(JobOrder::factory()->create(), WorkerCaseStatus::Applied, $this->record);
    $stranger = JobOrderWorker::factory()->create(['job_order_id' => $mine->job_order_id, 'case_status' => WorkerCaseStatus::Applied]);
    $passport = Service::factory()->create(['title' => 'Passport']);
    $other = ClientService::factory()->forService($passport)->create(['client_id' => $this->client->id, 'status' => ClientServiceStatus::InProgress]);

    cancelService($this, $this->record);

    expect($mine->fresh()->case_status)->toBe(WorkerCaseStatus::Cancelled)
        ->and($stranger->fresh()->case_status)->toBe(WorkerCaseStatus::Applied)
        ->and($stranger->clientService->fresh()->status)->toBe(ClientServiceStatus::InProgress)
        ->and($other->fresh()->status)->toBe(ClientServiceStatus::InProgress);
});

test('cancelling an ordinary service does not touch the same person\'s Job Search applications', function () {
    $row = rowOn(JobOrder::factory()->create(), WorkerCaseStatus::Applied, $this->record);
    $passport = Service::factory()->create(['title' => 'Passport']);
    $other = ClientService::factory()->forService($passport)->create(['client_id' => $this->client->id, 'status' => ClientServiceStatus::InProgress]);

    cancelService($this, $other, null)->assertSessionHasNoErrors();

    expect($other->fresh()->status)->toBe(ClientServiceStatus::Cancel)
        ->and($row->fresh()->case_status)->toBe(WorkerCaseStatus::Applied)
        ->and($this->record->fresh()->status)->toBe(ClientServiceStatus::InProgress);
});

test('a person with a cancelled service can be added to a job order again as a new engagement', function () {
    rowOn(JobOrder::factory()->create(), WorkerCaseStatus::Applied, $this->record);
    cancelService($this, $this->record);
    $order = JobOrder::factory()->create();

    $added = app(AddWorker::class)->handle($order, $this->client->id, [], '12000.00');

    expect($added->client_service_id)->not->toBe($this->record->id)
        ->and($added->clientService->status)->toBe(ClientServiceStatus::InProgress)
        ->and($this->record->fresh()->status)->toBe(ClientServiceStatus::Cancel);
});
