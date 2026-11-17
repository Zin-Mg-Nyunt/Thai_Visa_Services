<?php

namespace App\Support\Concurrency;

use Closure;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs one workflow as a database transaction that is retried after a deadlock or a lock-wait timeout.
 *
 * Only the workflows approved for it use this helper: Add Worker, the application status updates, and the Job Order close.
 * Whole-service cancellation, Person deletion, payments, standalone fee changes and document or file operations do not.
 *
 * - 3 attempts, run straight after each other with no delay. Laravel decides what is retried with its default
 *   ConcurrencyErrorDetector: only deadlocks and lock-wait timeouts. Any other error fails at once.
 * - On MySQL the session's innodb_lock_wait_timeout is set to 5 seconds. The value it had is read first and put back on
 *   every way out: success, an exception, and used-up attempts.
 * - When the attempts are used up, the admin gets a TryAgainException (a friendly message). The original database error is
 *   reported to the log and kept as the previous exception.
 *
 * The callback may run up to three times, so it must be repeatable. It must not touch files, write success logs or reports,
 * flash messages, or change variables outside itself. Do that after run() returns.
 *
 * This must be the OUTERMOST transaction. Laravel does not retry inside a nested transaction: it throws a
 * DeadlockException instead.
 */
class RetriedTransaction
{
    public const ATTEMPTS = 3;

    public const LOCK_WAIT_TIMEOUT_SECONDS = 5;

    /**
     * @template TReturn
     *
     * @param  Closure(Connection): TReturn  $callback
     * @return TReturn
     *
     * @throws TryAgainException when deadlocks or lock-wait timeouts used up every attempt
     * @throws Throwable anything else the callback throws, unchanged and not retried
     */
    public static function run(Closure $callback, ?string $connection = null): mixed
    {
        /** @var Connection $db */
        $db = DB::connection($connection);
        $original = self::readTimeout($db);

        try {
            if ($original !== null) {
                $db->statement('SET SESSION innodb_lock_wait_timeout = '.self::LOCK_WAIT_TIMEOUT_SECONDS);
            }

            return $db->transaction($callback, self::ATTEMPTS);
        } catch (Throwable $exception) {
            if ((new ConcurrencyErrorDetector)->causedByConcurrencyError($exception)) {
                report($exception);

                throw new TryAgainException($exception);
            }

            throw $exception;
        } finally {
            self::restoreTimeout($db, $original);
        }
    }

    /**
     * The session timeout in seconds, or null when the connection is not MySQL (the setting does not exist there).
     */
    private static function readTimeout(Connection $db): ?int
    {
        if ($db->getDriverName() !== 'mysql') {
            return null;
        }

        return (int) $db->selectOne('select @@session.innodb_lock_wait_timeout as seconds')->seconds;
    }

    /**
     * Put the session's own timeout back. A failure here never hides the workflow's own result or error: if the connection
     * is gone, the session setting is gone with it.
     */
    private static function restoreTimeout(Connection $db, ?int $original): void
    {
        if ($original === null) {
            return;
        }

        try {
            $db->statement('SET SESSION innodb_lock_wait_timeout = '.$original);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
