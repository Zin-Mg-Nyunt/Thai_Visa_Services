<?php

/**
 * Runs ONE real Job Search workflow in its own PHP process, so it has its own MySQL connection and can really wait for a lock
 * held by the test. Used only by the MySQL concurrency tests (see ChildWorkflow).
 *
 * Usage: php run-workflow.php <command> <json arguments>
 *
 * It refuses to run on anything but the dedicated testing database. It prints "ATTEMPT n" each time a transaction attempt begins,
 * and one final "RESULT:{json}" line: the outcome, how many attempts were made, and the session innodb_lock_wait_timeout before,
 * inside (seen as the first transaction attempt begins) and after the workflow.
 */

use App\Actions\JobSearch\AddWorker;
use App\Actions\JobSearch\CancelHiredApplication;
use App\Actions\JobSearch\CancelJobSearchService;
use App\Actions\JobSearch\UpdateApplicationStatuses;
use App\Enums\PaymentMethod;
use App\Enums\WorkerCaseStatus;
use App\Models\ClientService;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Payment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\MysqlTestCase;

require __DIR__.'/../../../../vendor/autoload.php';

$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== MysqlTestCase::TESTING_DATABASE) {
    fwrite(STDERR, 'Refusing to run: not the '.MysqlTestCase::TESTING_DATABASE." MySQL database.\n");
    exit(3);
}

app()->setLocale('en');

$timeout = fn (): int => (int) DB::selectOne('select @@session.innodb_lock_wait_timeout as seconds')->seconds;

// A value that is neither MySQL's default (50) nor the helper's (5), so a restore cannot pass by accident.
DB::statement('SET SESSION innodb_lock_wait_timeout = 37');

$before = $timeout();
$attempts = 0;
$inside = null;

Event::listen(TransactionBeginning::class, function () use (&$attempts, &$inside, $timeout): void {
    $attempts++;
    // Seen as the first attempt begins, so it is known even when every locking read then times out.
    $inside ??= $timeout();
    fwrite(STDOUT, "ATTEMPT {$attempts}\n");
    fflush(STDOUT);
});

$command = $argv[1] ?? '';
$a = json_decode($argv[2] ?? '[]', true);

try {
    $outcome = match ($command) {
        'add-worker' => (function () use ($a): array {
            $worker = app(AddWorker::class)->handle(JobOrder::query()->findOrFail($a['order']), $a['client'], [], $a['fee']);

            return ['worker_id' => $worker->id, 'record_id' => $worker->client_service_id];
        })(),
        'cancel-hired' => (function () use ($a): array {
            $worker = app(CancelHiredApplication::class)->handle(JobOrderWorker::query()->findOrFail($a['worker']));

            return ['worker_id' => $worker->id];
        })(),
        'cancel-service' => (function () use ($a): array {
            app(CancelJobSearchService::class)->handle(ClientService::query()->findOrFail($a['record']), $a['refund'], $a['user']);

            return ['record_id' => $a['record']];
        })(),
        'hire' => (function () use ($a): array {
            app(UpdateApplicationStatuses::class)->handle(JobOrder::query()->findOrFail($a['order']), [$a['worker'] => WorkerCaseStatus::Hired]);

            return ['worker_id' => $a['worker']];
        })(),
        // Money consistency: valid writes in a loop. A payment, then Hired -> Cancelled, then Add Worker on the other order, then
        // Hired there; the next round cancels that Hired application, and so on.
        'churn' => (function () use ($a): array {
            [$orderA, $orderB] = [$a['orders'][0], $a['orders'][1]];
            $worker = $a['worker'];
            $current = $orderA;
            $rounds = 0;

            for ($i = 0; $i < $a['rounds']; $i++) {
                Payment::query()->create([
                    'client_service_id' => $a['record'],
                    'amount' => '100.00',
                    'paid_at' => date('Y-m-d'),
                    'method' => PaymentMethod::Cash,
                ]);

                app(CancelHiredApplication::class)->handle(JobOrderWorker::query()->findOrFail($worker));

                $next = $current === $orderA ? $orderB : $orderA;
                $added = app(AddWorker::class)->handle(JobOrder::query()->findOrFail($next), $a['client'], [], $a['fee']);
                app(UpdateApplicationStatuses::class)->handle(JobOrder::query()->findOrFail($next), [$added->id => WorkerCaseStatus::Hired]);

                [$worker, $current] = [$added->id, $next];
                $rounds++;
            }

            return ['rounds' => $rounds, 'worker_id' => $worker];
        })(),
        default => throw new InvalidArgumentException("Unknown command [{$command}]"),
    };

    $result = ['ok' => true] + $outcome;
} catch (Throwable $exception) {
    $result = ['ok' => false, 'error' => $exception::class, 'message' => $exception->getMessage()];
}

$result += ['attempts' => $attempts, 'before' => $before, 'inside' => $inside, 'after' => $timeout()];

fwrite(STDOUT, 'RESULT:'.json_encode($result)."\n");
