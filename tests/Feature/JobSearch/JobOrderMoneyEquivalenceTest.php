<?php

use App\Actions\Dashboard\BusinessReport;
use App\Actions\JobSearch\AddWorker;
use App\Actions\JobSearch\CancelHiredApplication;
use App\Actions\JobSearch\CancelJobSearchService;
use App\Actions\JobSearch\CloseJobOrder;
use App\Actions\JobSearch\JobOrderMoney;
use App\Actions\JobSearch\UpdateApplicationStatuses;
use App\Enums\JobOrderStatus;
use App\Enums\WorkerCaseStatus as S;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\Support\JobOrderMoneyComparison;
use Tests\Support\JobOrderMoneyWorld;

/**
 * The Job Order Money queries were optimised (review finding P1): each aggregate now reads only the records that can matter,
 * instead of the whole job_order_workers and payments tables. The money rules did not change, so the optimised class must give
 * exactly the answer of the original queries (Tests\Support\LegacyJobOrderMoney) in every state: on seeded synthetic data sets
 * that include anomalies, and after every step of the real workflows that move money between orders and Unassigned.
 */
beforeEach(function () {
    app()->setLocale('en');
    $this->jobSearch = Service::query()->where('key', Service::JOB_SEARCH_KEY)->first() ?? Service::factory()->jobSearch()->create();
    $this->admin = User::factory()->create();
});

test('the optimised queries give the original answers on seeded data sets with every state and anomaly', function (int $seed) {
    (new JobOrderMoneyWorld($seed))->build(people: 60, orders: 7, paymentsPerRecord: 3, otherServicePeople: 15);

    JobOrderMoneyComparison::assertMatchesOriginal();
})->with(range(1, 12));

test('a record with two qualifying applications on two orders is counted once, on the order of the newest one', function () {
    [$older, $newer] = JobOrder::factory()->count(2)->create();
    $person = Client::factory()->create();
    $record = ClientService::factory()->forService($this->jobSearch)->create(['client_id' => $person->id, 'fee' => '10000.00']);
    Payment::factory()->create(['client_service_id' => $record->id, 'amount' => '4000.00', 'paid_at' => now()->toDateString()]);

    // An anomaly the workflows cannot make: written straight to the table, so no model check stops it.
    DB::table('job_order_workers')->insert([
        ['job_order_id' => $older->id, 'client_id' => $person->id, 'client_service_id' => $record->id, 'case_status' => 'applied', 'created_at' => now(), 'updated_at' => now()],
        ['job_order_id' => $newer->id, 'client_id' => $person->id, 'client_service_id' => $record->id, 'case_status' => 'interview', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $money = app(JobOrderMoney::class);

    expect($money->forOrder($older->id)['amount'])->toBe('0.00')
        ->and($money->forOrder($newer->id)['amount'])->toBe('4000.00')
        ->and($money->ordersOf([$record->id]))->toBe([$record->id => $newer->id]);

    JobOrderMoneyComparison::assertMatchesOriginal();
});

test('payments of other services never reach Job Order Money', function () {
    $order = JobOrder::factory()->create();
    $person = Client::factory()->create();
    $worker = app(AddWorker::class)->handle($order, $person->id, [], '10000.00', $this->admin->id);
    Payment::factory()->create(['client_service_id' => $worker->client_service_id, 'amount' => '1000.00', 'paid_at' => now()->toDateString()]);

    $passport = ClientService::factory()->forService(Service::factory()->create())->create(['client_id' => $person->id]);
    Payment::factory()->create(['client_service_id' => $passport->id, 'amount' => '777.00', 'paid_at' => now()->toDateString()]);

    expect(app(JobOrderMoney::class)->forOrder($order->id))->toBe(['amount' => '1000.00', 'includes_deleted' => false]);

    JobOrderMoneyComparison::assertMatchesOriginal();
});

test('every workflow step that moves money gives the original answers, and Total Received never moves with the attribution', function () {
    [$orderA, $orderB, $orderC] = JobOrder::factory()->count(3)->create();
    $people = Client::factory()->count(4)->create();
    $add = app(AddWorker::class);
    $statuses = app(UpdateApplicationStatuses::class);
    $pay = fn (int $recordId, string $amount) => Payment::factory()->create(['client_service_id' => $recordId, 'amount' => $amount, 'paid_at' => '2026-11-02']);
    $report = fn () => Arr::only(app(BusinessReport::class)->build(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30')), ['received']);

    // Applied on A, with payments: attributed to A.
    $first = $add->handle($orderA, $people[0]->id, [], '20000.00', $this->admin->id);
    $pay($first->client_service_id, '5000.00');
    $second = $add->handle($orderA, $people[1]->id, [], '15000.00', $this->admin->id);
    $pay($second->client_service_id, '3000.00');
    $third = $add->handle($orderB, $people[2]->id, [], '12000.00', $this->admin->id);
    $pay($third->client_service_id, '2500.00');
    JobOrderMoneyComparison::assertMatchesOriginal();
    $received = $report();

    // Hired: the record is Complete and still attributed to A.
    $statuses->handle($orderA, [$first->id => S::Hired, $second->id => S::Rejected]);
    JobOrderMoneyComparison::assertMatchesOriginal();

    // A payment after the order closed raises the closed order's Money (Example A).
    app(CloseJobOrder::class)->handle($orderA, JobOrderStatus::Done, [], [], []);
    $pay($first->client_service_id, '1000.00');
    JobOrderMoneyComparison::assertMatchesOriginal();
    expect(app(JobOrderMoney::class)->forOrder($orderA->id)['amount'])->toBe('6000.00');
    $received = $report();

    // Hired -> Cancelled: the money leaves A and is Unassigned. Total Received does not move.
    app(CancelHiredApplication::class)->handle($first->fresh());
    JobOrderMoneyComparison::assertMatchesOriginal();
    expect(app(JobOrderMoney::class)->forOrder($orderA->id)['amount'])->toBe('0.00')
        ->and($report())->toBe($received);

    // Reassignment: a new application row on C takes the money. The rejected person comes back on B too.
    $add->handle($orderC, $people[0]->id, [], '20000.00', $this->admin->id);
    $add->handle($orderB, $people[1]->id, [], '15000.00', $this->admin->id);
    JobOrderMoneyComparison::assertMatchesOriginal();
    expect(app(JobOrderMoney::class)->forOrder($orderC->id)['amount'])->toBe('6000.00')
        ->and($report())->toBe($received);

    // A fee-change refund on reuse, then a whole-service cancellation with a cancel refund.
    $refunded = $add->handle(JobOrder::factory()->create(), $people[3]->id, [], '9000.00', $this->admin->id);
    $pay($refunded->client_service_id, '9000.00');
    app(CancelJobSearchService::class)->handle(ClientService::query()->findOrFail($refunded->client_service_id), '4000.00', $this->admin->id);
    JobOrderMoneyComparison::assertMatchesOriginal();

    // Person deletion moves nothing; the close then cancels the deleted person's open application and releases its money.
    DB::transaction(fn () => Client::query()->lockForUpdate()->findOrFail($people[2]->id)->delete());
    JobOrderMoneyComparison::assertMatchesOriginal();
    expect(app(JobOrderMoney::class)->forOrder($orderB->id))->toBe(['amount' => '5500.00', 'includes_deleted' => true]);

    $open = JobOrder::query()->findOrFail($orderB->id)->workers()->open()->with('client')->get();
    $live = $open->reject(fn ($worker) => $worker->client->trashed());
    app(CloseJobOrder::class)->handle(
        $orderB,
        JobOrderStatus::Cancelled,
        $live->mapWithKeys(fn ($worker) => [$worker->id => S::Rejected])->all(),
        $live->mapWithKeys(fn ($worker) => [$worker->id => $worker->case_status])->all(),
        $open->filter(fn ($worker) => $worker->client->trashed())->pluck('id')->all(),
    );
    JobOrderMoneyComparison::assertMatchesOriginal();
    expect(app(JobOrderMoney::class)->forOrder($orderB->id))->toBe(['amount' => '0.00', 'includes_deleted' => false]);
});

test('the Money of one order is worked out in one query, and the records of a client page in one query', function () {
    (new JobOrderMoneyWorld(7))->build(people: 30, orders: 4);
    $order = JobOrder::query()->firstOrFail();
    $money = app(JobOrderMoney::class);
    $records = ClientService::query()->limit(5)->pluck('id')->all();

    DB::enableQueryLog();
    $money->forOrder($order->id);
    $forOrder = count(DB::getQueryLog());
    DB::flushQueryLog();
    $money->ordersOf($records);
    $ordersOf = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($forOrder)->toBe(1)->and($ordersOf)->toBe(1);
});
