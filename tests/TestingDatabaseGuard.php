<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Refuses to let a test touch any database but a test one. It reads only the configured driver and database name, never
 * opens a connection and never runs a statement, so it is safe to call before any write.
 *
 * The only databases allowed are the in-memory SQLite database of the normal suite and the MySQL database
 * Thai_Visa_Services_Testing. The name must match exactly: MySQL database names are case-sensitive on Linux. PHPUnit
 * configuration alone is not trusted, because an exported shell variable can override it.
 */
final class TestingDatabaseGuard
{
    public const MYSQL_DATABASE = 'Thai_Visa_Services_Testing';

    /**
     * For tests that can run on either test database.
     */
    public static function assertSafe(): void
    {
        $driver = DB::getDriverName();
        $database = (string) DB::connection()->getDatabaseName();

        if ($driver === 'sqlite' && $database === ':memory:') {
            return;
        }

        if ($driver === 'mysql' && $database === self::MYSQL_DATABASE) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run: the [%s] database [%s] is not a test database (only in-memory SQLite or MySQL [%s]).',
            $driver,
            $database,
            self::MYSQL_DATABASE,
        ));
    }

    /**
     * For tests that need MySQL: it must be the testing database.
     */
    public static function assertMysqlTestingDatabase(): void
    {
        $database = (string) DB::connection()->getDatabaseName();

        if ($database !== self::MYSQL_DATABASE) {
            throw new RuntimeException("Refusing to run: the database is [{$database}], not [".self::MYSQL_DATABASE.'].');
        }
    }
}
