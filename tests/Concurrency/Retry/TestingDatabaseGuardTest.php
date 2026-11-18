<?php

use Illuminate\Support\Facades\DB;
use Tests\TestingDatabaseGuard;

/**
 * The guard that stands in front of every test that writes outside RefreshDatabase. It is tested by pointing the configuration at
 * an unsafe database and checking that it refuses before any connection is opened or any statement is run, so nothing is ever
 * written to the database that is named.
 */
function pointAt(string $driver, string $database): ArrayObject
{
    $queries = new ArrayObject;

    config(['database.default' => 'unsafe', 'database.connections.unsafe' => [
        'driver' => $driver,
        'host' => '127.0.0.1',
        'database' => $database,
        'username' => 'nobody',
        'password' => 'nothing',
    ]]);
    DB::purge('unsafe');
    DB::listen(fn ($query) => $queries->append($query->sql));

    return $queries;
}

afterEach(function () {
    DB::purge('unsafe');
});

test('a statement listener like the one used below really records a statement (a positive control)', function () {
    $queries = new ArrayObject;
    DB::listen(fn ($query) => $queries->append($query->sql));

    DB::select('select 1');

    expect($queries)->toHaveCount(1);
});

test('the database this run uses is accepted', function () {
    TestingDatabaseGuard::assertSafe();

    expect(true)->toBeTrue();
});

test('the development database is refused before any connection or statement', function (string $driver, string $database) {
    $queries = pointAt($driver, $database);

    expect(fn () => TestingDatabaseGuard::assertSafe())->toThrow(RuntimeException::class, 'not a test database')
        ->and($queries->getArrayCopy())->toBe([]);
})->with([
    'development MySQL' => ['mysql', 'Thai_Visa_Services'],
    'the old lowercase testing name' => ['mysql', 'thai_visa_services_testing'],
    'a testing name with a suffix' => ['mysql', 'Thai_Visa_Services_Testing_2'],
    'another MySQL database' => ['mysql', 'laravel'],
    'a SQLite file' => ['sqlite', '/tmp/not-in-memory.sqlite'],
]);

test('the MySQL-only guard refuses every name but the exact testing database', function (string $database) {
    $queries = pointAt('mysql', $database);

    expect(fn () => TestingDatabaseGuard::assertMysqlTestingDatabase())->toThrow(RuntimeException::class, 'Refusing to run')
        ->and($queries->getArrayCopy())->toBe([]);
})->with(['Thai_Visa_Services', 'thai_visa_services_testing', 'Thai_Visa_Services_Testing ']);

test('the exact testing database name is accepted without opening a connection', function () {
    $queries = pointAt('mysql', 'Thai_Visa_Services_Testing');

    TestingDatabaseGuard::assertSafe();
    TestingDatabaseGuard::assertMysqlTestingDatabase();

    expect($queries->getArrayCopy())->toBe([]);
});
