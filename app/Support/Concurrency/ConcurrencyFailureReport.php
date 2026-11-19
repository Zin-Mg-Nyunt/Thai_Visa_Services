<?php

namespace App\Support\Concurrency;

use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * What is written to the log when a retried workflow runs out of attempts: enough to diagnose the conflict, and nothing a person
 * typed. Laravel's own QueryException message puts the query's values in its SQL (a new worker's name, phone number and birthday,
 * for example), so that exception is never reported as it is.
 *
 * Kept: the workflow that gave up, the number of attempts, the class of the original error, its SQLSTATE and driver error number,
 * and the SQL with its "?" placeholders (column and table names only). Left out: every bound value, the driver's free-text
 * message, and the original exception itself, which is not chained as "previous" because a log formatter would print its message.
 * The original stays attached to the TryAgainException the caller receives, which is never reported.
 */
final class ConcurrencyFailureReport extends RuntimeException
{
    public static function from(Throwable $exception, int $attempts, ?string $operation): self
    {
        $parts = [
            'operation' => $operation ?? 'unknown',
            'attempts' => (string) $attempts,
            'error' => $exception::class,
            'sqlstate' => (string) $exception->getCode(),
        ];

        if ($exception instanceof PDOException && isset($exception->errorInfo[1])) {
            $parts['driver_code'] = (string) $exception->errorInfo[1];
        }

        if ($exception instanceof QueryException) {
            $parts['connection'] = $exception->getConnectionName();
            // The SQL as it was prepared: placeholders, never the values bound to them.
            $parts['sql'] = $exception->getSql();
        }

        $details = implode(', ', array_map(fn (string $key, string $value) => "{$key}: {$value}", array_keys($parts), $parts));

        return new self("A retried transaction gave up after deadlocks or lock-wait timeouts ({$details}).");
    }
}
