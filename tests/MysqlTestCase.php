<?php

namespace Tests;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Base for tests that need a real MySQL server: locking, deadlocks and timeouts cannot be proven on SQLite.
 *
 * It refuses to run against anything but Thai_Visa_Services_Testing, so these tests cannot touch the development or
 * production data by accident. Run them with: php artisan test -c phpunit.mysql.xml
 *
 * There is no override: the database name must match exactly (MySQL database names are case-sensitive on this machine's
 * Linux file system, so the case matters too).
 */
abstract class MysqlTestCase extends TestCase
{
    public const TESTING_DATABASE = 'Thai_Visa_Services_Testing';

    /** @var array<int, string> */
    private array $peerNames = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped(sprintf(
                'These tests need MySQL, but this run uses the [%s] driver (database [%s], config [%s]). Run them with: php artisan test -c phpunit.mysql.xml',
                DB::getDriverName(),
                DB::connection()->getDatabaseName(),
                app()->configurationIsCached() ? 'cached' : (string) env('APP_ENV'),
            ));
        }

        $this->guardTestingDatabase();
    }

    protected function tearDown(): void
    {
        $this->closePeers();

        parent::tearDown();
    }

    /**
     * Roll back and close every peer connection. A peer with an open transaction holds table metadata locks, and a DROP TABLE
     * would wait for them, so a failed test must never leave one open.
     */
    protected function closePeers(): void
    {
        foreach ($this->peerNames as $name) {
            if (DB::connection($name)->transactionLevel() > 0) {
                DB::connection($name)->rollBack();
            }

            DB::purge($name);
        }

        $this->peerNames = [];
    }

    /**
     * Runs before a migrate:fresh too, so a schema wipe can never reach the wrong database.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $this->guardTestingDatabase();
    }

    protected function guardTestingDatabase(): void
    {
        $database = (string) DB::connection()->getDatabaseName();

        if ($database !== self::TESTING_DATABASE) {
            throw new RuntimeException("Refusing to run: the database is [{$database}], not [".self::TESTING_DATABASE.'].');
        }
    }

    /**
     * A second, independent MySQL connection to the same database, as another admin's request would have. Its transactions
     * and locks are separate from the default connection's.
     */
    protected function peerConnection(string $name = 'mysql_peer'): Connection
    {
        config(["database.connections.{$name}" => config('database.connections.mysql')]);
        DB::purge($name);
        $this->peerNames[] = $name;

        /** @var Connection */
        return DB::connection($name);
    }
}
