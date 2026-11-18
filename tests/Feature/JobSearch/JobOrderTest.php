<?php

use App\Enums\JobOrderStatus;
use App\Enums\WorkerCaseStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

test('a job order starts open with what the company asked for and no profit stored', function () {
    $order = JobOrder::factory()->create(['manpower' => 5, 'male_count' => 3, 'female_count' => 2]);

    expect($order->status)->toBe(JobOrderStatus::Open)
        ->and($order->isOpen())->toBeTrue()
        ->and($order->isClosed())->toBeFalse()
        ->and($order->closed_at)->toBeNull()
        ->and(array_keys($order->getAttributes()))->not->toContain('profit');
});

test('done and cancelled orders are both closed', function (string $state, JobOrderStatus $status) {
    $order = JobOrder::factory()->{$state}()->create();

    expect($order->status)->toBe($status)
        ->and($order->isClosed())->toBeTrue()
        ->and($order->closed_at)->not->toBeNull();
})->with([
    'done' => ['done', JobOrderStatus::Done],
    'cancelled' => ['cancelled', JobOrderStatus::Cancelled],
]);

test('a worker links a person to a job order through a Job Search record', function () {
    $order = JobOrder::factory()->create();
    $worker = JobOrderWorker::factory()->create(['job_order_id' => $order->id]);

    expect($worker->case_status)->toBe(WorkerCaseStatus::Applied)
        ->and($worker->jobOrder->is($order))->toBeTrue()
        ->and($order->workers)->toHaveCount(1)
        ->and($worker->client->is($worker->clientService->client))->toBeTrue()
        ->and($worker->clientService->service->isJobSearch())->toBeTrue()
        ->and($worker->clientService->sub_service_id)->toBeNull();
});

test('the same person can be a normal client and a worker', function () {
    $client = Client::factory()->create();
    ClientService::factory()->create(['client_id' => $client->id]);
    $jobSearch = Service::factory()->jobSearch()->create();
    $record = ClientService::factory()->forService($jobSearch)->create(['client_id' => $client->id]);

    $worker = JobOrderWorker::factory()->create([
        'client_id' => $client->id,
        'client_service_id' => $record->id,
    ]);

    expect($worker->client->is($client))->toBeTrue()
        ->and($client->clientServices)->toHaveCount(2);
});

test('a worker record must belong to the same person', function () {
    $jobSearch = Service::factory()->jobSearch()->create();
    $record = ClientService::factory()->forService($jobSearch)->create();
    $other = Client::factory()->create();

    expect(fn () => JobOrderWorker::factory()->create([
        'client_id' => $other->id,
        'client_service_id' => $record->id,
    ]))->toThrow(DomainException::class);
});

test('a worker record must be a Job Search record', function () {
    $client = Client::factory()->create();
    $record = ClientService::factory()->create(['client_id' => $client->id]);

    expect(fn () => JobOrderWorker::factory()->create([
        'client_id' => $client->id,
        'client_service_id' => $record->id,
    ]))->toThrow(DomainException::class);
});

test('one service record can hold several applications, and a person can have several on one order over time', function () {
    $worker = JobOrderWorker::factory()->create();

    // Round 10: (job_order_id, client_id) is a plain index, so a second row of the same person on the same order is accepted by the
    // database. Keeping two ACTIVE ones apart is Add Worker's job, under its locks.
    $again = JobOrderWorker::factory()->create([
        'job_order_id' => $worker->job_order_id,
        'client_id' => $worker->client_id,
        'client_service_id' => $worker->client_service_id,
    ]);

    expect($again->id)->not->toBe($worker->id);

    // An engagement (one service record) is reused across job orders: a second application on another order is allowed.
    $another = JobOrderWorker::factory()->create([
        'client_id' => $worker->client_id,
        'client_service_id' => $worker->client_service_id,
    ]);

    expect($another->client_service_id)->toBe($worker->client_service_id)
        ->and($another->job_order_id)->not->toBe($worker->job_order_id);
});

test('the application statuses are applied, interview, hired, rejected and cancelled', function () {
    expect(array_column(WorkerCaseStatus::cases(), 'value'))
        ->toBe(['applied', 'interview', 'hired', 'rejected', 'cancelled']);
});

test('the database keeps history: an order with workers, or a worker with a record, cannot be hard deleted', function () {
    $worker = JobOrderWorker::factory()->create();

    expect(fn () => $worker->jobOrder->delete())->toThrow(QueryException::class)
        ->and(fn () => $worker->clientService->delete())->toThrow(QueryException::class);
});

test('only the signed-in superadmin manages job orders, and a closed order is final', function () {
    $admin = User::factory()->create();
    $open = JobOrder::factory()->create();
    $closed = JobOrder::factory()->done()->create();

    expect(Gate::forUser($admin)->allows('viewAny', JobOrder::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', JobOrder::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $open))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('close', $open))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $closed))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('close', $closed))->toBeFalse()
        ->and(Gate::allows('viewAny', JobOrder::class))->toBeFalse()
        ->and(Gate::allows('view', $open))->toBeFalse();
});

test('a closed order takes no new worker and its application statuses are frozen', function () {
    $admin = User::factory()->create();
    $closed = JobOrder::factory()->cancelled()->create();
    $open = JobOrder::factory()->create();

    expect(Gate::forUser($admin)->allows('create', [JobOrderWorker::class, $closed]))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('create', [JobOrderWorker::class, $open]))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('changeStatus', [JobOrderWorker::class, $open]))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('changeStatus', [JobOrderWorker::class, $closed]))->toBeFalse()
        ->and(Gate::allows('changeStatus', [JobOrderWorker::class, $open]))->toBeFalse();
});

test('closing an order sets its status and closing time together', function (JobOrderStatus $status) {
    $this->travelTo('2026-11-12 10:00:00');
    $order = JobOrder::factory()->create();

    $order->close($status);

    expect($order->status)->toBe($status)
        ->and($order->closed_at->toDateTimeString())->toBe('2026-11-12 10:00:00')
        ->and($order->fresh()->status)->toBe($status)
        ->and($order->fresh()->closed_at->toDateTimeString())->toBe('2026-11-12 10:00:00');
})->with([
    'done' => [JobOrderStatus::Done],
    'cancelled' => [JobOrderStatus::Cancelled],
]);

test('a closed order is final: it cannot be closed again, and an order is never closed as open', function () {
    $closed = JobOrder::factory()->done()->create();
    $closedAt = $closed->closed_at->toDateTimeString();

    expect(fn () => $closed->close(JobOrderStatus::Cancelled))->toThrow(DomainException::class)
        ->and(fn () => JobOrder::factory()->create()->close(JobOrderStatus::Open))->toThrow(InvalidArgumentException::class)
        ->and($closed->fresh()->status)->toBe(JobOrderStatus::Done)
        ->and($closed->fresh()->closed_at->toDateTimeString())->toBe($closedAt);
});

test('an order closed elsewhere since it was loaded cannot be closed again', function () {
    $order = JobOrder::factory()->create();
    JobOrder::query()->whereKey($order->id)->first()->close(JobOrderStatus::Cancelled);

    expect(fn () => $order->close(JobOrderStatus::Done))->toThrow(DomainException::class)
        ->and($order->fresh()->status)->toBe(JobOrderStatus::Cancelled);
});

test('the status and closing time cannot be mass assigned, so a closed order is never reopened that way', function () {
    $order = JobOrder::create([
        'company_name' => 'Acme',
        'status' => JobOrderStatus::Done->value,
        'closed_at' => now(),
    ])->fresh();

    $closed = JobOrder::factory()->done()->create();
    $closed->update(['status' => JobOrderStatus::Open->value, 'closed_at' => null]);

    expect($order->status)->toBe(JobOrderStatus::Open)
        ->and($order->closed_at)->toBeNull()
        ->and($closed->fresh()->status)->toBe(JobOrderStatus::Done)
        ->and($closed->fresh()->closed_at)->not->toBeNull();
});

test('changing only the case status or notes does not look up the service record again', function () {
    $worker = JobOrderWorker::factory()->create();
    $lookups = 0;
    DB::listen(function ($query) use (&$lookups) {
        if (str_contains($query->sql, 'client_services')) {
            $lookups++;
        }
    });

    $worker->update(['case_status' => WorkerCaseStatus::Interview, 'notes' => 'Called on Monday']);

    expect($lookups)->toBe(0)
        ->and($worker->fresh()->case_status)->toBe(WorkerCaseStatus::Interview);
});

test('moving a worker to another person\'s record is still refused', function () {
    $worker = JobOrderWorker::factory()->create();
    $someoneElse = JobOrderWorker::factory()->create();

    expect(fn () => $worker->update(['client_service_id' => $someoneElse->client_service_id]))->toThrow(DomainException::class)
        ->and($worker->fresh()->client_service_id)->not->toBe($someoneElse->client_service_id);
});

test('the worker factory makes the person and the Job Search record only when they are not given', function () {
    $jobSearch = Service::factory()->jobSearch()->create();
    $client = Client::factory()->create();
    $record = ClientService::factory()->forService($jobSearch)->create(['client_id' => $client->id]);
    $order = JobOrder::factory()->create();

    JobOrderWorker::factory()->create(['job_order_id' => $order->id, 'client_id' => $client->id, 'client_service_id' => $record->id]);

    expect(Client::count())->toBe(1)
        ->and(ClientService::count())->toBe(1);

    $givenPerson = JobOrderWorker::factory()->create(['client_id' => $client->id]);

    expect(Client::count())->toBe(1)
        ->and($givenPerson->clientService->client_id)->toBe($client->id)
        ->and($givenPerson->clientService->service->isJobSearch())->toBeTrue();
});

test('a new order is open straight after it is created, before it is read again', function () {
    $order = JobOrder::create(['company_name' => 'Acme']);

    expect($order->status)->toBe(JobOrderStatus::Open)
        ->and($order->isOpen())->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('update', $order))->toBeTrue();
});
