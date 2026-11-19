<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\SchemaFingerprint;

/**
 * The integrity audit of the MySQL testing database (Thai_Visa_Services_Testing), deferred from Phase 4. It is READ-ONLY: it runs only
 * SELECT statements on MySQL and changes nothing in it.
 *
 *   1. The schema really in MySQL equals the schema the migration files create (read from a throw-away in-memory SQLite copy that is
 *      migrated for the comparison, never from MySQL): no missing or unexpected table, column, foreign key, unique key or index.
 *      A leftover index from an experiment shows up as "unexpected"; a foreign key whose own index was dropped shows up as "missing".
 *   2. No row points at a parent that does not exist (every single-column foreign key is checked with a LEFT JOIN).
 *   3. A fingerprint of the schema and of the rows (count and highest id per table) is printed. Run the audit before and after another
 *      MySQL run: if the two fingerprints are equal, that run left nothing behind.
 *
 * Run it (the user and password come from the local .env; phpunit.mysql.xml forces the database name, and the guards refuse any other):
 *   php artisan test -c phpunit.mysql.xml --filter=TestingDatabaseIntegrityAuditMysqlTest
 */
function migrationSchema(): array
{
    config(['database.connections.migration_copy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    $before = DB::getDefaultConnection();

    // --database makes every migration run on the SQLite copy only, and the default connection is put back afterwards.
    Artisan::call('migrate', ['--database' => 'migration_copy', '--force' => true]);

    expect(DB::getDefaultConnection())->toBe($before)->and($before)->toBe('mysql');

    return SchemaFingerprint::take('migration_copy');
}

test('the testing database is the dedicated one, and this audit runs no statement that changes it', function () {
    expect(DB::selectOne('select database() as name')->name)->toBe('Thai_Visa_Services_Testing')
        ->and(file_get_contents(__FILE__))->not->toMatch('/DB::(statement|unprepared|insert|update|delete)\(|->(insert|update|delete|truncate)\(/');
});

test('the schema in MySQL equals the schema the migrations create', function () {
    $differences = SchemaFingerprint::differences(migrationSchema(), SchemaFingerprint::take('mysql'));

    expect($differences)->toBe([]);
});

test('the foreign key indexes that job_order_workers relies on are still there', function () {
    $actual = SchemaFingerprint::take('mysql')['tables']['job_order_workers'];

    // A composite index that starts with a foreign key column can make MySQL drop that foreign key's own index silently, so the
    // foreign keys and an index that leads with each of their columns are checked by name.
    expect($actual['foreign_keys'])->toContain('job_order_id -> job_orders(id) on delete restrict')
        ->toContain('client_id -> clients(id) on delete restrict')->toContain('client_service_id -> client_services(id) on delete restrict');

    $leading = collect(DB::select("select distinct column_name as name from information_schema.statistics where table_schema = database() and table_name = 'job_order_workers' and seq_in_index = 1"))->pluck('name');

    expect($leading->all())->toContain('job_order_id')->toContain('client_id')->toContain('client_service_id');
});

test('no row points at a parent that does not exist', function () {
    $orphans = [];

    foreach (SchemaFingerprint::take('mysql')['tables'] as $table => $definition) {
        foreach ($definition['foreign_keys'] as $foreignKey) {
            if (! preg_match('/^(\w+) -> (\w+)\((\w+)\) on delete/', $foreignKey, $match)) {
                continue; // a key over several columns: none in this schema
            }

            [, $column, $parent, $parentColumn] = $match;

            $count = DB::table("{$table} as child")->leftJoin("{$parent} as parent", "child.{$column}", '=', "parent.{$parentColumn}")
                ->whereNotNull("child.{$column}")->whereNull("parent.{$parentColumn}")->count();

            if ($count > 0) {
                $orphans[] = "{$table}.{$column} -> {$parent}.{$parentColumn}: {$count} row(s)";
            }
        }
    }

    expect($orphans)->toBe([]);
});

test('the fingerprint of the schema and of the rows is printed, to compare before and after another run', function () {
    $schema = SchemaFingerprint::take('mysql');
    $rows = [];

    foreach (array_keys($schema['tables']) as $table) {
        $hasId = array_key_exists('id', $schema['tables'][$table]['columns']);
        $rows[$table] = ['count' => DB::table($table)->count(), 'max_id' => $hasId ? DB::table($table)->max('id') : null];
    }

    $report = sprintf("\nINTEGRITY AUDIT FINGERPRINT  schema=%s  rows=%s\n", SchemaFingerprint::hash($schema), SchemaFingerprint::hash(['rows' => $rows]));
    $report .= "Tables with rows:\n";

    foreach ($rows as $table => $figure) {
        if ($figure['count'] > 0) {
            $report .= sprintf("  %-28s %7d rows, highest id %s\n", $table, $figure['count'], $figure['max_id'] ?? '-');
        }
    }

    fwrite(STDERR, $report.($report === '' ? '' : "(none other)\n"));

    expect($rows)->not->toBeEmpty();
});
