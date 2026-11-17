<?php

use App\Actions\JobSearch\AddWorker;
use App\Enums\ClientServiceStatus;
use App\Enums\RemarkKind;
use App\Enums\WorkerCaseStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

/**
 * Adding a person to a job order when they already have a Job Search record (engagement): reuse, block, or refuse.
 */
beforeEach(function () {
    app()->setLocale('en');
    $this->jobSearch = Service::factory()->jobSearch()->create(['default_fee' => '3000.00']);
    $this->admin = User::factory()->create();
    $this->order = JobOrder::factory()->create();
    $this->client = Client::factory()->create();
});

/**
 * A person's Job Search record with one application per given status, each on an order of its own.
 *
 * @param  array<int, WorkerCaseStatus>  $statuses
 */
function engagementWith(Client $client, array $statuses, string $fee = '3000.00', ClientServiceStatus $status = ClientServiceStatus::InProgress): ClientService
{
    $record = ClientService::factory()->forService(Service::query()->where('key', Service::JOB_SEARCH_KEY)->first())
        ->create(['client_id' => $client->id, 'fee' => $fee, 'status' => $status]);

    foreach ($statuses as $caseStatus) {
        JobOrderWorker::factory()->create([
            'client_id' => $client->id,
            'client_service_id' => $record->id,
            'case_status' => $caseStatus,
        ]);
    }

    return $record;
}

function addToOrder(mixed $test, JobOrder $order, Client $client, string $fee = '3000'): TestResponse
{
    return $test->actingAs($test->admin)->post(
        route('admin.job-search.workers.store', $order),
        ['client_id' => $client->id, 'fee' => $fee],
    );
}

function lastToast(): ?string
{
    return session('inertia.flash_data.toast.message') ?? session('toast.message');
}

test('a person with no Job Search record gets a new one and a first application', function () {
    addToOrder($this, $this->order, $this->client)->assertSessionHasNoErrors();

    $worker = JobOrderWorker::query()->sole();

    expect($worker->case_status)->toBe(WorkerCaseStatus::Applied)
        ->and($worker->clientService->status)->toBe(ClientServiceStatus::InProgress)
        ->and(ClientService::count())->toBe(1);
});

test('old Complete and Cancelled records are history: a new record is created next to them', function (ClientServiceStatus $status) {
    $old = engagementWith($this->client, [WorkerCaseStatus::Hired], status: $status);

    addToOrder($this, $this->order, $this->client)->assertSessionHasNoErrors();

    $new = JobOrderWorker::query()->where('job_order_id', $this->order->id)->sole();

    expect($new->client_service_id)->not->toBe($old->id)
        ->and(ClientService::count())->toBe(2)
        ->and($old->fresh()->status)->toBe($status);
})->with([ClientServiceStatus::Complete, ClientServiceStatus::Cancel]);

test('an In Progress record whose applications are all Rejected or Cancelled is reused with a new application row', function () {
    $record = engagementWith($this->client, [WorkerCaseStatus::Rejected, WorkerCaseStatus::Cancelled]);
    $oldRows = JobOrderWorker::query()->where('client_service_id', $record->id)->pluck('case_status', 'id')->all();

    addToOrder($this, $this->order, $this->client, '3000')->assertSessionHasNoErrors();

    $new = JobOrderWorker::query()->where('job_order_id', $this->order->id)->sole();

    expect($new->client_service_id)->toBe($record->id)
        ->and($new->case_status)->toBe(WorkerCaseStatus::Applied)
        ->and(ClientService::count())->toBe(1)
        ->and(JobOrderWorker::count())->toBe(3)
        // The old rows are never reactivated or changed.
        ->and(JobOrderWorker::query()->whereIn('id', array_keys($oldRows))->pluck('case_status', 'id')->all())->toBe($oldRows)
        ->and($record->fresh()->fee)->toBe('3000.00')
        ->and($record->remarks()->count())->toBe(0);
});

test('the fee can be kept or changed when the record is reused', function () {
    $record = engagementWith($this->client, [WorkerCaseStatus::Rejected], fee: '3000.00');

    addToOrder($this, $this->order, $this->client, '4500')->assertSessionHasNoErrors();

    expect($record->fresh()->fee)->toBe('4500.00')
        ->and($record->fresh()->refund_amount)->toBe('0.00')
        // Raising the fee leaves nothing to refund, so there is no refund remark.
        ->and($record->remarks()->count())->toBe(0);
});

test('a fee lowered below the money already received becomes a refund, with a refund remark', function () {
    $record = engagementWith($this->client, [WorkerCaseStatus::Rejected], fee: '8000.00');
    Payment::factory()->create(['client_service_id' => $record->id, 'amount' => '5000.00']);

    addToOrder($this, $this->order, $this->client, '4000')->assertSessionHasNoErrors();

    $remark = $record->remarks()->sole();

    expect($record->fresh()->fee)->toBe('4000.00')
        ->and($record->fresh()->refund_amount)->toBe('1000.00')
        ->and($record->fresh()->kept())->toBe('4000.00')
        ->and($remark->kind)->toBe(RemarkKind::Refund)
        ->and($remark->created_by)->toBe($this->admin->id)
        ->and($remark->details)->toMatchArray(['from_fee' => '8000.00', 'to_fee' => '4000.00', 'received' => '5000.00', 'refund' => '1000.00'])
        // The money itself is untouched: no payment is added or removed.
        ->and(Payment::count())->toBe(1);
});

test('an application that is still Applied or Interview blocks another order, and nothing changes', function (WorkerCaseStatus $open) {
    $record = engagementWith($this->client, [$open]);

    addToOrder($this, $this->order, $this->client, '9999')->assertRedirect();

    expect(lastToast())->toBe('This person already has an open application (Applied or Interview). Reject or cancel it before adding them to another job order.')
        ->and(JobOrderWorker::count())->toBe(1)
        ->and(ClientService::count())->toBe(1)
        ->and($record->fresh()->fee)->toBe('3000.00');
})->with([WorkerCaseStatus::Applied, WorkerCaseStatus::Interview]);

test('every application is checked, not only the latest: one open row among rejected ones still blocks', function () {
    $record = engagementWith($this->client, [WorkerCaseStatus::Interview, WorkerCaseStatus::Rejected, WorkerCaseStatus::Cancelled]);

    addToOrder($this, $this->order, $this->client)->assertRedirect();

    expect(JobOrderWorker::count())->toBe(3)->and($record->fresh()->fee)->toBe('3000.00');
});

test('an In Progress record with no application at all is refused and logged', function () {
    Log::spy();
    $record = engagementWith($this->client, []);

    addToOrder($this, $this->order, $this->client)->assertRedirect();

    expect(lastToast())->toBe("This person's Job Search record is in an unexpected state, so nothing was changed. Please check it first.")
        ->and(JobOrderWorker::count())->toBe(0)
        ->and(ClientService::count())->toBe(1);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $context['client_service_id'] === $record->id
        && $context['reason'] === 'In Progress Job Search record with no application');
});

test('a Hired application on an In Progress record is refused and logged', function () {
    Log::spy();
    engagementWith($this->client, [WorkerCaseStatus::Hired]);

    addToOrder($this, $this->order, $this->client)->assertRedirect();

    expect(JobOrderWorker::count())->toBe(1)->and(ClientService::count())->toBe(1);

    Log::shouldHaveReceived('warning')->once();
});

test('more than one In Progress Job Search record is refused and logged', function () {
    Log::spy();
    engagementWith($this->client, [WorkerCaseStatus::Rejected]);
    engagementWith($this->client, [WorkerCaseStatus::Rejected]);

    addToOrder($this, $this->order, $this->client)->assertRedirect();

    expect(JobOrderWorker::count())->toBe(2)->and(ClientService::count())->toBe(2);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => count($context['client_service_ids']) === 2);
});

test('a person is on an order once: a rejected row on this order is never reactivated or duplicated', function () {
    $record = engagementWith($this->client, []);
    JobOrderWorker::factory()->create([
        'job_order_id' => $this->order->id,
        'client_id' => $this->client->id,
        'client_service_id' => $record->id,
        'case_status' => WorkerCaseStatus::Rejected,
    ]);

    addToOrder($this, $this->order, $this->client)->assertSessionHasErrors('client_id');

    expect(JobOrderWorker::count())->toBe(1)
        ->and(JobOrderWorker::query()->sole()->case_status)->toBe(WorkerCaseStatus::Rejected);
});

test('the phone number taken by another admin a moment ago becomes a normal validation error, not a server error', function () {
    Client::factory()->create(['phone' => '+66812345678']);

    $person = ['name' => 'Aung Min', 'phone' => '+66812345678', 'birthday' => '1995-03-12', 'gender' => 'male'];

    expect(fn () => app(AddWorker::class)->handle($this->order, null, $person, '3000.00'))
        ->toThrow(ValidationException::class);

    // The failed attempt left nothing behind.
    expect(JobOrderWorker::count())->toBe(0)->and(ClientService::count())->toBe(0);
});

test('the phone lookup tells the dialog what adding the person would do', function () {
    $lookup = fn () => $this->actingAs($this->admin)
        ->postJson(route('admin.job-search.workers.lookup', $this->order), ['phone' => $this->client->phone])
        ->assertOk()
        ->json('engagement');

    // No Job Search record yet: a new one is created.
    expect($lookup())->toBeNull();

    $record = engagementWith($this->client, [WorkerCaseStatus::Rejected], fee: '3500.00');
    Payment::factory()->create(['client_service_id' => $record->id, 'amount' => '1000.00']);

    expect($lookup())->toBe(['state' => 'reusable', 'fee' => '3500.00', 'received' => '1000.00']);

    JobOrderWorker::query()->where('client_service_id', $record->id)->update(['case_status' => WorkerCaseStatus::Interview]);

    expect($lookup())->toBe(['state' => 'open_application']);

    JobOrderWorker::query()->where('client_service_id', $record->id)->delete();

    expect($lookup())->toBe(['state' => 'inconsistent']);
});

test('the new messages have Burmese translations', function (string $message) {
    app()->setLocale('my');

    expect(__($message))->not->toBe($message);

    app()->setLocale('en');
})->with([
    'open application' => ['This person already has an open application (Applied or Interview). Reject or cancel it before adding them to another job order.'],
    'inconsistent' => ["This person's Job Search record is in an unexpected state, so nothing was changed. Please check it first."],
]);
