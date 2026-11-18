<?php

use App\Actions\JobSearch\JobOrderMoney;
use App\Enums\ClientServiceStatus;
use App\Enums\WorkerCaseStatus;
use App\Models\ClientService;
use App\Models\JobOrderWorker;
use App\Support\Concurrency\TryAgainException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Mysql\Support\ChildWorkflow;
use Tests\Concurrency\Mysql\Support\JobSearchWorld;

/**
 * The approved Job Search workflows under real MySQL contention. Every workflow is the real action class, run in its own PHP
 * process (its own connection); the test holds a row lock on a peer connection so the workflow really waits, waits until MySQL
 * shows the wait, and then lets go. Rows are committed and removed again by id only. Run with:
 *
 *   php artisan test -c phpunit.mysql.xml --filter=JobSearchConcurrencyMysqlTest
 */
beforeEach(function () {
    app()->setLocale('en');
    $this->world = new JobSearchWorld;
    $this->children = [];
});

afterEach(function () {
    // On a run without MySQL the test is skipped before beforeEach has run, so there is nothing to clean up.
    foreach ($this->children ?? [] as $child) {
        $child->kill();
    }

    $this->closePeers();
    ($this->world ?? null)?->cleanUp();
});

function jsChild(object $test, string $command, array $arguments): ChildWorkflow
{
    return $test->children[] = new ChildWorkflow($command, $arguments);
}

function jsHold(Connection $peer, string $table, int $id): void
{
    $peer->beginTransaction();
    $peer->table($table)->where('id', $id)->lockForUpdate()->first();
}

function jsAddArguments(int $order, int $client): array
{
    return ['order' => $order, 'client' => $client, 'fee' => '15000.00'];
}

function jsMoney(int $orderId): string
{
    return app(JobOrderMoney::class)->forOrder($orderId)['amount'];
}

function jsActiveApplications(int $order, int $client): int
{
    return JobOrderWorker::query()->where('job_order_id', $order)->where('client_id', $client)->whereIn('case_status', WorkerCaseStatus::activeValues())->count();
}

// ---------------------------------------------------------------------------------------------------------------------
// 1. Hired -> Cancelled waits for a lock held by someone else, then completes atomically (every level of the lock order).
// ---------------------------------------------------------------------------------------------------------------------

test('Hired to Cancelled waits for the lock held on the order, person, record or application, then completes atomically', function (string $table) {
    $order = $this->world->order();
    $person = $this->world->person();
    $hired = $this->world->application($order, $person, WorkerCaseStatus::Hired);
    $record = $hired->clientService;

    $target = match ($table) {
        'job_orders' => $order->id,
        'clients' => $person->id,
        'client_services' => $record->id,
        'job_order_workers' => $hired->id,
    };

    $unassignedBefore = app(JobOrderMoney::class)->totals()['unassigned'];
    $orderTouched = DB::table('job_orders')->where('id', $order->id)->first();

    expect(jsMoney($order->id))->toBe('15000.00');

    $peer = $this->peerConnection();
    jsHold($peer, $table, $target);
    $cancel = jsChild($this, 'cancel-hired', ['worker' => $hired->id]);

    ChildWorkflow::waitUntilBlocked();

    // While it waits nothing has changed.
    expect($hired->fresh()->case_status)->toBe(WorkerCaseStatus::Hired)
        ->and($record->fresh()->status)->toBe(ClientServiceStatus::Complete)
        ->and(jsMoney($order->id))->toBe('15000.00')
        ->and($cancel->isRunning())->toBeTrue();

    $peer->commit();
    $result = $cancel->result();

    expect($result)->toMatchArray(['ok' => true, 'attempts' => 1, 'inside' => 5, 'before' => 37, 'after' => 37])
        ->and($hired->fresh()->case_status)->toBe(WorkerCaseStatus::Cancelled)
        ->and($record->fresh()->status)->toBe(ClientServiceStatus::InProgress)
        ->and(jsMoney($order->id))->toBe('0.00')
        ->and(bcsub(app(JobOrderMoney::class)->totals()['unassigned'], $unassignedBefore, 2))->toBe('15000.00')
        ->and($record->payments()->count())->toBe(1)
        ->and($record->fresh()->fee)->toBe('15000.00')
        ->and(DB::table('job_orders')->where('id', $order->id)->first())->toEqual($orderTouched)
        ->and($this->world->inProgressRecords($person))->toBe(1);
})->with([
    'order lock (level 1)' => ['job_orders'],
    'person lock (level 2)' => ['clients'],
    'service record lock (level 3)' => ['client_services'],
    'application lock (level 4)' => ['job_order_workers'],
]);

test('two Hired to Cancelled requests for the same application: exactly one succeeds, the other sees it is no longer Hired', function () {
    $order = $this->world->order();
    $person = $this->world->person();
    $hired = $this->world->application($order, $person, WorkerCaseStatus::Hired);

    $peer = $this->peerConnection();
    jsHold($peer, 'job_orders', $order->id);
    $first = jsChild($this, 'cancel-hired', ['worker' => $hired->id]);
    $second = jsChild($this, 'cancel-hired', ['worker' => $hired->id]);

    ChildWorkflow::waitUntilBlocked(2);
    $peer->commit();

    $results = [$first->result(), $second->result()];
    $ok = collect($results)->where('ok', true);
    $refused = collect($results)->where('ok', false);

    expect($ok)->toHaveCount(1)
        ->and($refused)->toHaveCount(1)
        ->and($refused->first()['error'])->toBe(DomainException::class)
        ->and($refused->first()['message'])->toContain('no longer Hired')
        ->and($hired->fresh()->case_status)->toBe(WorkerCaseStatus::Cancelled)
        ->and($hired->clientService->fresh()->status)->toBe(ClientServiceStatus::InProgress)
        ->and(JobOrderWorker::query()->where('client_id', $person->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------------------------------------------------
// 2. Hired -> Cancelled against Add Worker for the same person: never two In Progress engagements.
// ---------------------------------------------------------------------------------------------------------------------

test('Hired to Cancelled and Add Worker for the same person never leave two In Progress engagements', function (string $heldLock) {
    $orderA = $this->world->order();
    $orderB = $this->world->order();
    $person = $this->world->person();
    $hired = $this->world->application($orderA, $person, WorkerCaseStatus::Hired);
    $oldRecord = $hired->clientService;

    [$table, $id] = match ($heldLock) {
        'order A' => ['job_orders', $orderA->id],
        'order B' => ['job_orders', $orderB->id],
        'person' => ['clients', $person->id],
    };

    $peer = $this->peerConnection();
    jsHold($peer, $table, $id);

    $cancel = jsChild($this, 'cancel-hired', ['worker' => $hired->id]);
    $add = jsChild($this, 'add-worker', jsAddArguments($orderB->id, $person->id));

    if ($heldLock === 'order A') {
        // Cancel waits on the order; Add Worker runs to the end first and commits its new engagement.
        ChildWorkflow::waitUntilBlocked(1);
        $addResult = $add->result();
        $peer->commit();
        $cancelResult = $cancel->result();

        expect($addResult['ok'])->toBeTrue()
            ->and($addResult['record_id'])->not->toBe($oldRecord->id)
            ->and($cancelResult['ok'])->toBeFalse()
            ->and($cancelResult['error'])->toBe(DomainException::class)
            ->and($cancelResult['message'])->toContain('already has another Job Search service in progress')
            ->and($hired->fresh()->case_status)->toBe(WorkerCaseStatus::Hired)
            ->and($oldRecord->fresh()->status)->toBe(ClientServiceStatus::Complete)
            ->and(jsMoney($orderA->id))->toBe('15000.00')
            ->and(jsMoney($orderB->id))->toBe('0.00');
    } elseif ($heldLock === 'order B') {
        // Add Worker waits on its order; Hired -> Cancelled runs to the end first, so the record is reusable afterwards.
        ChildWorkflow::waitUntilBlocked(1);
        $cancelResult = $cancel->result();
        $peer->commit();
        $addResult = $add->result();

        expect($cancelResult['ok'])->toBeTrue()
            ->and($addResult['ok'])->toBeTrue()
            ->and($addResult['record_id'])->toBe($oldRecord->id)
            ->and($hired->fresh()->case_status)->toBe(WorkerCaseStatus::Cancelled)
            ->and(jsMoney($orderA->id))->toBe('0.00')
            ->and(jsMoney($orderB->id))->toBe('15000.00');
    } else {
        // Both wait for the same person: whichever the database lets in first, the result is consistent.
        ChildWorkflow::waitUntilBlocked(2);
        $peer->commit();
        $cancelResult = $cancel->result();
        $addResult = $add->result();

        expect($addResult['ok'])->toBeTrue()
            ->and(jsMoney($orderA->id) === '15000.00' xor jsMoney($orderB->id) === '15000.00')->toBeTrue()
            ->and(bcadd(jsMoney($orderA->id), jsMoney($orderB->id), 2))->toBe('15000.00');

        if (! $cancelResult['ok']) {
            expect($cancelResult['message'])->toContain('already has another Job Search service in progress');
        }
    }

    // In every order: exactly one In Progress Job Search record, and the person's 15,000 is on at most one order.
    expect($this->world->inProgressRecords($person))->toBe(1)
        ->and(bcadd(jsMoney($orderA->id), jsMoney($orderB->id), 2))->toBe('15000.00')
        ->and(ClientService::query()->where('client_id', $person->id)->where('status', ClientServiceStatus::InProgress->value)->count())->toBe(1)
        ->and(ClientService::query()->where('client_id', $person->id)->where('status', ClientServiceStatus::Complete->value)->count())->toBeLessThanOrEqual(1);
})->with([
    'Add Worker commits first (cancel waits on order A)' => ['order A'],
    'cancel commits first (Add Worker waits on order B)' => ['order B'],
    'both wait for the person lock' => ['person'],
]);

// ---------------------------------------------------------------------------------------------------------------------
// 3. Two Add Worker requests for the same person and the same order.
// ---------------------------------------------------------------------------------------------------------------------

test('two Add Worker requests for the same person and order create one active application', function (string $table) {
    $order = $this->world->order();
    $person = $this->world->person();
    $this->world->jobSearch();

    $peer = $this->peerConnection();
    jsHold($peer, $table, $table === 'job_orders' ? $order->id : $person->id);

    $first = jsChild($this, 'add-worker', jsAddArguments($order->id, $person->id));
    $second = jsChild($this, 'add-worker', jsAddArguments($order->id, $person->id));

    ChildWorkflow::waitUntilBlocked(2);
    $peer->commit();

    $results = collect([$first->result(), $second->result()]);

    expect($results->where('ok', true))->toHaveCount(1)
        ->and($results->where('ok', false)->first())->toMatchArray(['error' => DomainException::class, 'message' => 'This person is already on this job order.'])
        ->and(jsActiveApplications($order->id, $person->id))->toBe(1)
        ->and(JobOrderWorker::query()->where('client_id', $person->id)->count())->toBe(1)
        ->and(ClientService::query()->where('client_id', $person->id)->count())->toBe(1);
})->with([
    'held order lock' => ['job_orders'],
    'held person lock' => ['clients'],
]);

// ---------------------------------------------------------------------------------------------------------------------
// 4. Two Add Worker requests for the same person and order after a Rejected application.
// ---------------------------------------------------------------------------------------------------------------------

test('two concurrent re-adds after a Rejected application create exactly one new row and leave the old one untouched', function () {
    $order = $this->world->order();
    $person = $this->world->person();
    $rejected = $this->world->application($order, $person, WorkerCaseStatus::Rejected);
    $recordBefore = DB::table('client_services')->where('id', $rejected->client_service_id)->first();
    $rejectedBefore = DB::table('job_order_workers')->where('id', $rejected->id)->first();

    $peer = $this->peerConnection();
    jsHold($peer, 'job_orders', $order->id);

    $first = jsChild($this, 'add-worker', jsAddArguments($order->id, $person->id));
    $second = jsChild($this, 'add-worker', jsAddArguments($order->id, $person->id));

    ChildWorkflow::waitUntilBlocked(2);
    $peer->commit();

    $results = collect([$first->result(), $second->result()]);
    $winner = $results->where('ok', true)->first();

    expect($results->where('ok', true))->toHaveCount(1)
        ->and($results->where('ok', false)->first())->toMatchArray(['error' => DomainException::class, 'message' => 'This person is already on this job order.'])
        ->and($winner['worker_id'])->toBeGreaterThan($rejected->id)
        ->and($winner['record_id'])->toBe($rejected->client_service_id)
        ->and(DB::table('job_order_workers')->where('id', $rejected->id)->first())->toEqual($rejectedBefore)
        ->and(DB::table('client_services')->where('id', $rejected->client_service_id)->first())->toEqual($recordBefore)
        ->and(JobOrderWorker::query()->where('job_order_id', $order->id)->where('client_id', $person->id)->count())->toBe(2)
        ->and(jsActiveApplications($order->id, $person->id))->toBe(1)
        ->and(JobOrderWorker::query()->findOrFail($winner['worker_id'])->case_status)->toBe(WorkerCaseStatus::Applied)
        ->and($this->world->inProgressRecords($person))->toBe(1);
});

// ---------------------------------------------------------------------------------------------------------------------
// 5. The same person added to two different orders at once.
// ---------------------------------------------------------------------------------------------------------------------

test('the same person added to two different orders at once: one succeeds, one is refused', function (bool $withHistory) {
    $orderA = $this->world->order();
    $orderB = $this->world->order();
    $person = $this->world->person();
    $this->world->jobSearch();
    $expectedKept = '0.00';

    if ($withHistory) {
        // A reusable engagement: In Progress with only a Rejected application elsewhere, with 15,000 kept.
        $this->world->application($this->world->order(), $person, WorkerCaseStatus::Rejected);
        $expectedKept = '15000.00';
    }

    $peer = $this->peerConnection();
    jsHold($peer, 'clients', $person->id);

    $toA = jsChild($this, 'add-worker', jsAddArguments($orderA->id, $person->id));
    $toB = jsChild($this, 'add-worker', jsAddArguments($orderB->id, $person->id));

    // Each passes its own order lock (different rows), then both wait for the one person.
    ChildWorkflow::waitUntilBlocked(2);
    $peer->commit();

    $results = collect([$toA->result(), $toB->result()]);

    expect($results->where('ok', true))->toHaveCount(1)
        ->and($results->where('ok', false)->first()['error'])->toBe(DomainException::class)
        ->and($results->where('ok', false)->first()['message'])->toContain('already has an open application')
        ->and($this->world->inProgressRecords($person))->toBe(1)
        ->and(ClientService::query()->where('client_id', $person->id)->count())->toBe(1)
        ->and(jsActiveApplications($orderA->id, $person->id) + jsActiveApplications($orderB->id, $person->id))->toBe(1)
        // The person's kept money is on exactly one order, never on both.
        ->and(bcadd(jsMoney($orderA->id), jsMoney($orderB->id), 2))->toBe($expectedKept);
})->with([
    'new person' => [false],
    'person with a reusable engagement' => [true],
]);

// ---------------------------------------------------------------------------------------------------------------------
// 6. Money stays consistent while Hired -> Cancelled, Add Worker and payments run.
// ---------------------------------------------------------------------------------------------------------------------

test('Job Order Money totals stay consistent while a person is moved between orders and paid', function () {
    $orderA = $this->world->order();
    $orderB = $this->world->order();
    $person = $this->world->person();
    $hired = $this->world->application($orderA, $person, WorkerCaseStatus::Hired);
    $rounds = 12;

    $money = app(JobOrderMoney::class);
    $writer = jsChild($this, 'churn', [
        'orders' => [$orderA->id, $orderB->id],
        'worker' => $hired->id,
        'record' => $hired->client_service_id,
        'client' => $person->id,
        'fee' => '15000.00',
        'rounds' => $rounds,
    ]);

    $samples = 0;
    $violations = [];

    while ($writer->isRunning()) {
        $totals = $money->totals();
        $samples++;

        $problems = [];

        if (bccomp($totals['unassigned'], '0', 2) < 0) {
            $problems[] = 'unassigned is negative';
        }

        if (bccomp($totals['attributed'], $totals['kept'], 2) > 0) {
            $problems[] = 'attributed is more than kept';
        }

        if (bcadd($totals['attributed'], $totals['unassigned'], 2) !== $totals['kept']) {
            $problems[] = 'attributed + unassigned is not kept';
        }

        if ($problems !== []) {
            $violations[] = json_encode($totals).' => '.implode(', ', $problems);
        }
    }

    $result = $writer->result();

    // Once the writer has finished, the whole is exact: the last application is Hired, so all the person's money is on one order.
    $final = $money->totals();
    $paid = bcadd('15000.00', bcmul('100.00', (string) $rounds, 2), 2);

    expect($result)->toMatchArray(['ok' => true, 'rounds' => $rounds])
        ->and($samples)->toBeGreaterThan(5)
        ->and($violations)->toBe([], "totals() was inconsistent in {$samples} samples: ".implode(' | ', array_slice($violations, 0, 5)))
        ->and(bcadd(jsMoney($orderA->id), jsMoney($orderB->id), 2))->toBe($paid)
        ->and($final['unassigned'])->toBe('0.00')
        ->and($final['kept'])->toBe($final['attributed'])
        ->and($this->world->inProgressRecords($person))->toBe(0)
        ->and(ClientService::query()->where('client_id', $person->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------------------------------------------------
// 7. Retry policy and lock order with the real actions.
// ---------------------------------------------------------------------------------------------------------------------

test('a lock wait that times out is retried: attempt 2 succeeds, the timeout is 5 seconds inside and restored after', function () {
    $order = $this->world->order();
    $person = $this->world->person();
    $this->world->jobSearch();

    $peer = $this->peerConnection();
    jsHold($peer, 'job_orders', $order->id);

    $add = jsChild($this, 'add-worker', jsAddArguments($order->id, $person->id));

    // The first attempt waits 5 seconds and times out (1205); the second begins. Let go only then.
    $add->waitForAttempts(2);
    $peer->commit();
    $result = $add->result();

    expect($result)->toMatchArray(['ok' => true, 'attempts' => 2, 'inside' => 5, 'before' => 37, 'after' => 37])
        ->and(jsActiveApplications($order->id, $person->id))->toBe(1);
});

test('when every attempt times out the admin gets the friendly error after exactly 3 attempts, nothing is written, the timeout is restored', function () {
    $order = $this->world->order();
    $person = $this->world->person();
    $this->world->jobSearch();

    $peer = $this->peerConnection();
    jsHold($peer, 'job_orders', $order->id);

    $add = jsChild($this, 'add-worker', jsAddArguments($order->id, $person->id));
    $result = $add->result(90);
    $peer->rollBack();

    expect($result)->toMatchArray(['ok' => false, 'error' => TryAgainException::class, 'attempts' => 3, 'inside' => 5, 'before' => 37, 'after' => 37])
        ->and(JobOrderWorker::query()->where('client_id', $person->id)->count())->toBe(0)
        ->and(ClientService::query()->where('client_id', $person->id)->count())->toBe(0);
});

test('after an ordinary refusal the session timeout is restored and no retry happens', function () {
    $closed = $this->world->order(closed: true);
    $person = $this->world->person();
    $this->world->jobSearch();

    $result = jsChild($this, 'add-worker', jsAddArguments($closed->id, $person->id))->result();

    expect($result)->toMatchArray(['ok' => false, 'error' => DomainException::class, 'message' => 'This job order is already closed.', 'attempts' => 1, 'before' => 37, 'after' => 37]);
});

test('Add Worker and Hired to Cancelled started in either order never deadlock beyond the retry policy', function (bool $cancelStartsFirst) {
    for ($round = 1; $round <= 3; $round++) {
        $orderA = $this->world->order();
        $orderB = $this->world->order();
        $person = $this->world->person();
        $hired = $this->world->application($orderA, $person, WorkerCaseStatus::Hired);

        $peer = $this->peerConnection("peer_{$round}");
        jsHold($peer, 'clients', $person->id);

        if ($cancelStartsFirst) {
            $cancel = jsChild($this, 'cancel-hired', ['worker' => $hired->id]);
            ChildWorkflow::waitUntilBlocked(1);
            $add = jsChild($this, 'add-worker', jsAddArguments($orderB->id, $person->id));
        } else {
            $add = jsChild($this, 'add-worker', jsAddArguments($orderB->id, $person->id));
            ChildWorkflow::waitUntilBlocked(1);
            $cancel = jsChild($this, 'cancel-hired', ['worker' => $hired->id]);
        }

        ChildWorkflow::waitUntilBlocked(2);
        $peer->commit();

        $results = [$cancel->result(), $add->result()];

        foreach ($results as $result) {
            expect($result['attempts'])->toBeLessThanOrEqual(3, "round {$round}: ".json_encode($result))
                ->and($result['after'])->toBe(37)
                ->and($result['error'] ?? null)->not->toBe(TryAgainException::class, "round {$round}: ".json_encode($result));

            if (! $result['ok']) {
                expect($result['error'])->toBe(DomainException::class);
            }
        }

        expect($results[1]['ok'])->toBeTrue()
            ->and($this->world->inProgressRecords($person))->toBe(1, "round {$round}: ".json_encode($results))
            ->and(bcadd(jsMoney($orderA->id), jsMoney($orderB->id), 2))->toBe('15000.00');
    }
})->with([
    'Hired to Cancelled starts first' => [true],
    'Add Worker starts first' => [false],
]);
