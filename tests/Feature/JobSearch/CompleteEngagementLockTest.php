<?php

use App\Enums\ClientServiceStatus;
use App\Enums\WorkerCaseStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\ClientServiceRemark;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The Client page edit form and a Job Search service (business rules, Section 7). Its status never changes by editing the record:
 * the only ways are an application becoming Hired (In Progress -> Complete), Hired -> Cancelled (Complete -> In Progress) and
 * whole-service cancellation. A Complete one also has a locked fee and takes no new remark. Everything else, such as the dates,
 * stays editable, and other services are not touched by any of this.
 */
beforeEach(function () {
    app()->setLocale('en');
    $this->admin = User::factory()->create();
    $this->jobSearch = Service::factory()->jobSearch()->create();
    $this->client = Client::factory()->create();
});

function jobSearchRecord(object $test, ClientServiceStatus $status, string $fee = '15000.00'): ClientService
{
    $record = ClientService::factory()->forService($test->jobSearch)->create([
        'client_id' => $test->client->id, 'status' => $status, 'fee' => $fee,
    ]);
    Payment::factory()->create(['client_service_id' => $record->id, 'amount' => '5000.00', 'paid_at' => today()->toDateString()]);

    return $record;
}

/**
 * The edit form as the page sends it for a Job Search service: no status.
 *
 * @param  array<string, mixed>  $overrides
 */
function editJobSearch(object $test, ClientService $record, array $overrides = []): TestResponse
{
    return $test->actingAs($test->admin)->put(
        route('admin.clients.services.update', [$record->client_id, $record]),
        array_merge(['sub_service_id' => null, 'start_date' => null, 'end_date' => null, 'fee' => $record->fee, 'remark' => null], $overrides),
    );
}

test('an In Progress Job Search service can still change its fee, dates and remark', function () {
    $record = jobSearchRecord($this, ClientServiceStatus::InProgress);

    editJobSearch($this, $record, ['fee' => '16000.00', 'start_date' => '2026-12-01', 'remark' => 'Agreed a new price'])
        ->assertSessionHasNoErrors();

    expect($record->fresh())->status->toBe(ClientServiceStatus::InProgress)
        ->fee->toBe('16000.00')
        ->and($record->fresh()->start_date->toDateString())->toBe('2026-12-01')
        ->and($record->remarks()->where('body', 'Agreed a new price')->exists())->toBeTrue();
});

test('a status sent for a Job Search service is refused in both directions and nothing is written', function (ClientServiceStatus $from, string $sent) {
    $record = jobSearchRecord($this, $from);

    editJobSearch($this, $record, ['status' => $sent, 'remark' => 'sneaky'])
        ->assertSessionHasErrors(['status' => 'The status of a Job Search service changes only through its applications.']);

    expect($record->fresh()->status)->toBe($from)
        ->and($record->remarks()->count())->toBe(0);
})->with([
    'In Progress to Complete' => [ClientServiceStatus::InProgress, 'complete'],
    'Complete back to In Progress' => [ClientServiceStatus::Complete, 'in_progress'],
    'the same status sent again' => [ClientServiceStatus::InProgress, 'in_progress'],
]);

test('a Complete Job Search service keeps its fee: a change is refused, the same fee is accepted, the dates stay editable', function () {
    $record = jobSearchRecord($this, ClientServiceStatus::Complete);

    editJobSearch($this, $record, ['fee' => '9000.00'])
        ->assertSessionHasErrors(['fee' => 'The fee of a Job Search service that is Complete is locked.']);

    expect($record->fresh()->fee)->toBe('15000.00')
        ->and($record->fresh()->refund_amount)->toBe('0.00')
        ->and(ClientServiceRemark::query()->where('client_service_id', $record->id)->count())->toBe(0);

    editJobSearch($this, $record, ['fee' => '15000.00', 'start_date' => '2026-12-05', 'end_date' => '2026-12-20'])
        ->assertSessionHasNoErrors();

    expect($record->fresh()->status)->toBe(ClientServiceStatus::Complete)
        ->and($record->fresh()->start_date->toDateString())->toBe('2026-12-05')
        ->and($record->fresh()->end_date->toDateString())->toBe('2026-12-20');
});

test('a Complete Job Search service takes no new remark, and a refused edit saves nothing at all', function () {
    $record = jobSearchRecord($this, ClientServiceStatus::Complete);
    $startBefore = $record->fresh()->start_date?->toDateString();

    editJobSearch($this, $record, ['remark' => 'one more note', 'start_date' => '2026-12-05'])
        ->assertSessionHasErrors(['remark' => 'A Job Search service that is Complete takes no new remark.']);

    expect($record->remarks()->count())->toBe(0)
        ->and($record->fresh()->start_date?->toDateString())->toBe($startBefore);
});

test('after Hired to Cancelled the service is In Progress again and its fee and remarks open up', function () {
    $record = jobSearchRecord($this, ClientServiceStatus::Complete);
    $order = JobOrder::factory()->create();
    $hired = JobOrderWorker::factory()->create([
        'job_order_id' => $order->id, 'client_id' => $this->client->id, 'client_service_id' => $record->id, 'case_status' => WorkerCaseStatus::Hired,
    ]);

    $this->actingAs($this->admin)->patch(route('admin.job-search.workers.cancel-hired', [$order, $hired]), ['confirm_cancel_hired' => true]);

    editJobSearch($this, $record, ['fee' => '15500.00', 'remark' => 'Looking again'])->assertSessionHasNoErrors();

    expect($record->fresh())->status->toBe(ClientServiceStatus::InProgress)->fee->toBe('15500.00');
});

test('a whole-service Cancelled Job Search service still takes only a remark, and a status sent is refused', function () {
    $record = jobSearchRecord($this, ClientServiceStatus::Cancel);

    editJobSearch($this, $record, ['remark' => 'Refund sent', 'fee' => '1.00'])->assertSessionHasNoErrors();

    expect($record->fresh())->status->toBe(ClientServiceStatus::Cancel)->fee->toBe('15000.00')
        ->and($record->remarks()->where('body', 'Refund sent')->exists())->toBeTrue();
});

test('a service that is not Job Search is untouched by these rules: its status, fee and remarks stay editable when Complete', function () {
    $passport = Service::factory()->create(['title' => 'Passport']);
    $record = ClientService::factory()->forService($passport)->create(['client_id' => $this->client->id, 'status' => ClientServiceStatus::Complete, 'fee' => '10000.00']);

    $this->actingAs($this->admin)->put(route('admin.clients.services.update', [$this->client, $record]), [
        'sub_service_id' => null, 'status' => 'in_progress', 'start_date' => null, 'end_date' => null, 'fee' => '12000.00', 'remark' => 'Reopened',
    ])->assertSessionHasNoErrors();

    expect($record->fresh())->status->toBe(ClientServiceStatus::InProgress)->fee->toBe('12000.00')
        ->and($record->remarks()->where('body', 'Reopened')->exists())->toBeTrue();
});

test('the edit page tells the form which service is Job Search, and a normal one is not', function () {
    $record = jobSearchRecord($this, ClientServiceStatus::Complete);
    $passport = Service::factory()->create(['title' => 'Passport']);
    $other = ClientService::factory()->forService($passport)->create(['client_id' => $this->client->id]);

    $this->actingAs($this->admin)->get(route('admin.clients.services.edit', [$this->client, $record]))
        ->assertInertia(fn (Assert $page) => $page->where('clientService.data.is_job_search', true)->where('clientService.data.status', 'complete'));
    $this->actingAs($this->admin)->get(route('admin.clients.services.edit', [$this->client, $other]))
        ->assertInertia(fn (Assert $page) => $page->where('clientService.data.is_job_search', false));
});

// A form opened before another admin's action: the request is checked against the row as it was when the page loaded, so the
// controller checks again on the locked row. The row is changed behind the request's back right after it is first loaded.
function changeRowAfterFirstLoad(int $id, array $attributes): void
{
    $loads = 0;
    ClientService::retrieved(function (ClientService $loaded) use (&$loads, $id, $attributes): void {
        if ($loaded->id === $id && ++$loads === 1) {
            DB::table('client_services')->where('id', $id)->update($attributes);
        }
    });
}

test('a form opened before the service became Complete cannot change the fee any more', function () {
    $record = jobSearchRecord($this, ClientServiceStatus::InProgress);
    changeRowAfterFirstLoad($record->id, ['status' => ClientServiceStatus::Complete->value]);

    editJobSearch($this, $record, ['fee' => '9000.00'])->assertSessionHasErrors(['fee']);

    ClientService::flushEventListeners();

    expect($record->fresh()->fee)->toBe('15000.00')->and($record->fresh()->status)->toBe(ClientServiceStatus::Complete);
});

test('a form opened before the service was cancelled writes nothing but a remark', function () {
    $record = jobSearchRecord($this, ClientServiceStatus::InProgress);
    $startBefore = $record->fresh()->start_date?->toDateString();
    changeRowAfterFirstLoad($record->id, ['status' => ClientServiceStatus::Cancel->value]);

    editJobSearch($this, $record, ['fee' => '9000.00', 'start_date' => '2026-12-01', 'remark' => 'late note'])->assertSessionHasNoErrors();

    ClientService::flushEventListeners();

    expect($record->fresh())->fee->toBe('15000.00')->status->toBe(ClientServiceStatus::Cancel)
        ->and($record->fresh()->start_date?->toDateString())->toBe($startBefore)
        ->and($record->fresh()->refund_amount)->toBe('0.00')
        ->and($record->remarks()->where('body', 'late note')->exists())->toBeTrue();
});
