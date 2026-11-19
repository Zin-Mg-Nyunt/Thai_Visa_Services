<?php

use App\Actions\JobSearch\AddWorker;
use App\Models\Client;
use App\Models\JobOrder;
use App\Models\Service;
use App\Models\User;
use App\Support\Concurrency\ConcurrencyFailureReport;
use App\Support\Concurrency\RetriedTransaction;
use App\Support\Concurrency\TryAgainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestingDatabaseGuard;

/**
 * Review finding S1: when a retried workflow runs out of attempts, the log must not carry the values of the failed query (a new
 * worker's name, phone number or birthday). Laravel's QueryException puts them in its message, so it is never reported as it is:
 * a ConcurrencyFailureReport with the error codes and the SQL placeholders is reported instead. The retry rules and the
 * TryAgainException the admin gets are unchanged.
 *
 * Outside RefreshDatabase on purpose, as the other retry tests: Laravel does not retry inside a nested transaction.
 */
beforeEach(function () {
    TestingDatabaseGuard::assertSafe();
    app()->setLocale('en');
});

const PRIVATE_NAME = 'Daw Private Person';
const PRIVATE_PHONE = '+66899990001';
const PRIVATE_BIRTHDAY = '1991-02-03';

/**
 * A lock-wait timeout as MySQL reports it, on an insert that carries a person's details.
 */
function lockTimeoutOnPersonalInsert(): QueryException
{
    $driver = new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction');
    $driver->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];
    (new ReflectionProperty(Exception::class, 'code'))->setValue($driver, 'HY000');

    return new QueryException(
        'mysql',
        'insert into `clients` (`name`, `phone`, `birthday`) values (?, ?, ?)',
        [PRIVATE_NAME, PRIVATE_PHONE, PRIVATE_BIRTHDAY],
        $driver,
    );
}

/**
 * Send every log line of the test to a file of its own, so the text the real formatter writes can be read back.
 */
function logToProbeFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'retry-log-');
    config([
        'logging.channels.privacy_probe' => ['driver' => 'single', 'path' => $path, 'level' => 'debug'],
        'logging.default' => 'privacy_probe',
    ]);

    return $path;
}

function assertNoPersonalData(string $text): void
{
    expect($text)->not->toContain(PRIVATE_NAME)
        ->not->toContain(PRIVATE_PHONE)
        ->not->toContain(PRIVATE_BIRTHDAY);
}

test('the original error is not reported; the report has the codes and the SQL placeholders, never the values', function () {
    Exceptions::fake();
    $runs = 0;

    $caller = new class
    {
        public function handle(int &$runs): void
        {
            RetriedTransaction::run(function () use (&$runs) {
                $runs++;

                throw lockTimeoutOnPersonalInsert();
            });
        }
    };

    try {
        $caller->handle($runs);
        $this->fail('Expected a TryAgainException.');
    } catch (TryAgainException $exception) {
        // Unchanged: three attempts, the friendly message, and the original kept for the code that caught it.
        expect($runs)->toBe(3)
            ->and($exception->getMessage())->toBe('The system is busy. Please try again.')
            ->and($exception->getPrevious())->toBeInstanceOf(QueryException::class);
    }

    Exceptions::assertNotReported(QueryException::class);
    Exceptions::assertReported(function (ConcurrencyFailureReport $report) {
        assertNoPersonalData($report->getMessage());

        expect($report->getMessage())
            ->toContain('attempts: 3')
            ->toContain('error: Illuminate\Database\QueryException')
            ->toContain('sqlstate: HY000')
            ->toContain('driver_code: 1205')
            ->toContain('connection: mysql')
            ->toContain('sql: insert into `clients` (`name`, `phone`, `birthday`) values (?, ?, ?)')
            ->toContain('::handle')
            ->and($report->getPrevious())->toBeNull();

        return true;
    });
});

test('the log file itself never holds the values, also through the exception chain', function () {
    $path = logToProbeFile();

    try {
        RetriedTransaction::run(fn () => throw lockTimeoutOnPersonalInsert());
    } catch (TryAgainException) {
        // expected
    }

    $log = (string) file_get_contents($path);
    unlink($path);

    expect($log)->toContain('A retried transaction gave up')->toContain('driver_code: 1205');
    assertNoPersonalData($log);
});

test('a real Add Worker for a new person that keeps hitting a lock leaves no personal data in the log', function () {
    // This test migrates and writes rows outside any rolled-back transaction, so it runs only on the throwaway in-memory SQLite
    // database. On MySQL the real lock errors are proven by tests/Concurrency/Mysql, which clean up after themselves.
    if (DB::getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
        $this->markTestSkipped('Simulated errors with committed rows: in-memory SQLite only.');
    }

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    Service::factory()->jobSearch()->create();
    $order = JobOrder::factory()->create();
    $admin = User::factory()->create();
    $path = logToProbeFile();

    // Every insert into clients fails as a lock-wait timeout would, with the real SQL and the person's real values.
    DB::connection()->beforeExecuting(function (string $sql, array $bindings, $connection): void {
        if (preg_match('/^insert into [`"]clients[`"]/', $sql) === 1) {
            $driver = new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction');
            $driver->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

            throw new QueryException($connection->getName(), $sql, $bindings, $driver);
        }
    });

    expect(fn () => app(AddWorker::class)->handle(
        $order,
        null,
        ['name' => PRIVATE_NAME, 'phone' => PRIVATE_PHONE, 'birthday' => PRIVATE_BIRTHDAY, 'gender' => 'female'],
        '3000.00',
        $admin->id,
    ))->toThrow(TryAgainException::class);

    $log = (string) file_get_contents($path);
    unlink($path);

    expect($log)->toContain('operation: App\Actions\JobSearch\AddWorker::handle')
        ->toMatch('/insert into [`"]clients[`"]/')
        ->and(Client::query()->count())->toBe(0);
    assertNoPersonalData($log);
});
