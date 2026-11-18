<?php

use App\Support\Concurrency\RetriedTransaction;
use App\Support\Concurrency\TryAgainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestingDatabaseGuard;

/**
 * The retry rules, checked with simulated database errors (the real MySQL errors are in tests/Concurrency/Mysql).
 * These tests run outside RefreshDatabase on purpose: Laravel does not retry a nested transaction.
 */
// The guard runs first, before anything is written: this file creates and drops a table, so it must never reach a database that
// is not a test database, whatever the PHPUnit configuration or the shell says.
beforeEach(function () {
    TestingDatabaseGuard::assertSafe();
    app()->setLocale('en');
});

function databaseError(string $message): QueryException
{
    return new QueryException('sqlite', 'update probe set n = 1', [], new PDOException($message));
}

const LOCK_WAIT_TIMEOUT = 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction';
const DEADLOCK = 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction';

test('it runs the callback once and returns its result', function () {
    $runs = 0;

    $result = RetriedTransaction::run(function () use (&$runs) {
        $runs++;

        return 'done';
    });

    expect($result)->toBe('done')->and($runs)->toBe(1);
});

test('a lock-wait timeout is retried, with no more than 3 attempts in all', function () {
    $runs = 0;

    $result = RetriedTransaction::run(function () use (&$runs) {
        $runs++;

        if ($runs < 3) {
            throw databaseError(LOCK_WAIT_TIMEOUT);
        }

        return 'third time lucky';
    });

    expect($result)->toBe('third time lucky')->and($runs)->toBe(3);
});

test('a deadlock is retried', function () {
    $runs = 0;

    $result = RetriedTransaction::run(function () use (&$runs) {
        $runs++;

        if ($runs === 1) {
            throw databaseError(DEADLOCK);
        }

        return 'ok';
    });

    expect($result)->toBe('ok')->and($runs)->toBe(2);
});

test('when the attempts are used up the admin gets a friendly error, never the SQL', function () {
    $runs = 0;

    try {
        RetriedTransaction::run(function () use (&$runs) {
            $runs++;

            throw databaseError(LOCK_WAIT_TIMEOUT);
        });

        $this->fail('Expected a TryAgainException.');
    } catch (TryAgainException $exception) {
        expect($runs)->toBe(3)
            ->and($exception->getMessage())->toBe('The system is busy. Please try again.')
            ->and($exception->getMessage())->not->toContain('SQL')
            ->and($exception->getMessage())->not->toContain('1205')
            // The original error stays attached for the log.
            ->and($exception->getPrevious())->toBeInstanceOf(QueryException::class);
    }
});

test('any other error fails at once and is not retried or hidden', function () {
    $runs = 0;

    try {
        RetriedTransaction::run(function () use (&$runs) {
            $runs++;

            throw new RuntimeException('a real bug');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('a real bug');
    }

    expect($runs)->toBe(1);

    $runs = 0;

    try {
        RetriedTransaction::run(function () use (&$runs) {
            $runs++;

            throw databaseError('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        });
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('Duplicate entry');
    }

    expect($runs)->toBe(1);
});

test('a failed attempt leaves nothing behind, so a retry cannot write twice', function () {
    Schema::create('retry_probe', function ($table) {
        $table->id();
        $table->string('note');
    });

    $runs = 0;

    RetriedTransaction::run(function () use (&$runs) {
        $runs++;
        DB::table('retry_probe')->insert(['note' => "attempt {$runs}"]);

        if ($runs === 1) {
            throw databaseError(DEADLOCK);
        }
    });

    expect(DB::table('retry_probe')->pluck('note')->all())->toBe(['attempt 2']);

    Schema::drop('retry_probe');
});

test('the session timeout is only touched on MySQL', function () {
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = $query->sql;
    });

    RetriedTransaction::run(fn () => 'ok');

    $touched = collect($statements)->contains(fn (string $sql) => str_contains($sql, 'innodb_lock_wait_timeout'));

    expect($touched)->toBe(DB::getDriverName() === 'mysql');
});

test('the friendly message has a Burmese translation', function () {
    app()->setLocale('my');

    expect(__('The system is busy. Please try again.'))->not->toBe('The system is busy. Please try again.');

    app()->setLocale('en');
});

test('the friendly error is shown as a toast, or as a short JSON message for a JSON request', function () {
    $exception = new TryAgainException(databaseError(LOCK_WAIT_TIMEOUT));

    $json = $exception->render(Request::create('/x', 'POST', server: ['HTTP_ACCEPT' => 'application/json']));
    expect($json->getStatusCode())->toBe(503)
        ->and($json->getData(true))->toBe(['message' => 'The system is busy. Please try again.']);

    $redirect = $exception->render(Request::create('/x', 'POST'));
    expect($redirect)->toBeInstanceOf(RedirectResponse::class)
        ->and($exception->report())->toBeFalse();
});
