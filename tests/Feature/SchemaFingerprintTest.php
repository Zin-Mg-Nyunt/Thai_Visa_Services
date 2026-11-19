<?php

use Tests\Support\SchemaFingerprint;

/**
 * The comparison behind the MySQL integrity audit (tests/Concurrency/Mysql/TestingDatabaseIntegrityAuditMysqlTest.php), proven here on
 * SQLite and on made-up schemas, because the audit itself needs the MySQL testing database.
 */
function fingerprintOf(array $tables): array
{
    return ['tables' => $tables];
}

/** A small table with an id, a name column and a foreign key column. */
function sampleTable(array $override = []): array
{
    return [
        'columns' => ['client_id' => false, 'id' => false, 'name' => true],
        'indexes' => ['index:name'],
        'foreign_keys' => ['client_id -> clients(id) on delete restrict'],
        ...$override,
    ];
}

test('a snapshot of the real schema has the tables, the foreign keys and the indexes the app relies on', function () {
    $snapshot = SchemaFingerprint::take(config('database.default'));

    expect(array_keys($snapshot['tables']))->toContain('clients', 'client_services', 'job_order_workers', 'documents', 'site_settings')
        ->not->toContain('migrations')
        ->and($snapshot['tables']['job_order_workers']['foreign_keys'])->toContain('job_order_id -> job_orders(id) on delete restrict')
        ->toContain('client_id -> clients(id) on delete restrict')->toContain('client_service_id -> client_services(id) on delete restrict')
        ->and($snapshot['tables']['documents']['foreign_keys'])->toContain('uploaded_by -> users(id) on delete set null')
        ->and($snapshot['tables']['clients']['columns'])->toHaveKeys(['name', 'phone', 'unique_token']);
});

test('a database compared with itself has no difference, and the fingerprint is stable', function () {
    $snapshot = SchemaFingerprint::take(config('database.default'));

    expect(SchemaFingerprint::differences($snapshot, $snapshot))->toBe([])
        ->and(SchemaFingerprint::hash($snapshot))->toBe(SchemaFingerprint::hash(SchemaFingerprint::take(config('database.default'))))->toHaveLength(16);
});

test('every kind of drift is named', function (Closure $change, string $expected) {
    $want = fingerprintOf(['jobs' => sampleTable(), 'clients' => sampleTable(['foreign_keys' => []])]);
    $have = $change($want);

    // A changed delete rule is reported twice: the rule that should be there is missing, and the one that is there is unexpected.
    expect(SchemaFingerprint::differences($want, $have))->toContain($expected)->not->toBeEmpty();
})->with([
    'missing table' => [function (array $t) {
        unset($t['tables']['jobs']);

        return $t;
    }, 'missing table: jobs'],
    'unexpected table' => [function (array $t) {
        $t['tables']['extra'] = sampleTable();

        return $t;
    }, 'unexpected table: extra'],
    'missing column' => [function (array $t) {
        unset($t['tables']['jobs']['columns']['name']);

        return $t;
    }, 'jobs: missing column name'],
    'unexpected column' => [function (array $t) {
        $t['tables']['jobs']['columns']['note'] = true;

        return $t;
    }, 'jobs: unexpected column note'],
    'nullability changed' => [function (array $t) {
        $t['tables']['jobs']['columns']['name'] = false;

        return $t;
    }, 'jobs: column name is not nullable but should be nullable'],
    'missing index' => [function (array $t) {
        $t['tables']['jobs']['indexes'] = [];

        return $t;
    }, 'jobs: missing index:name'],
    'a leftover index' => [function (array $t) {
        $t['tables']['jobs']['indexes'][] = 'index:name,id';

        return $t;
    }, 'jobs: unexpected index:name,id'],
    'missing foreign key' => [function (array $t) {
        $t['tables']['jobs']['foreign_keys'] = [];

        return $t;
    }, 'jobs: missing foreign key client_id -> clients(id) on delete restrict'],
    'another delete rule' => [function (array $t) {
        $t['tables']['jobs']['foreign_keys'] = ['client_id -> clients(id) on delete cascade'];

        return $t;
    }, 'jobs: unexpected foreign key client_id -> clients(id) on delete cascade'],
]);

test('the index MySQL adds behind a foreign key is allowed, any other extra index is not', function () {
    $want = fingerprintOf(['jobs' => sampleTable()]);
    $withImplied = fingerprintOf(['jobs' => sampleTable(['indexes' => ['index:client_id', 'index:name']])]);
    $withLeftover = fingerprintOf(['jobs' => sampleTable(['indexes' => ['index:client_id', 'index:client_id,name', 'index:name']])]);

    expect(SchemaFingerprint::differences($want, $withImplied))->toBe([])
        ->and(SchemaFingerprint::differences($want, $withLeftover))->toBe(['jobs: unexpected index:client_id,name']);
});

test('the foreign key rule is worded the same in both engines', function (?string $reported, string $expected) {
    expect(SchemaFingerprint::action($reported))->toBe($expected);
})->with([
    'no rule' => [null, 'restrict'], 'empty' => ['', 'restrict'], 'NO ACTION' => ['NO ACTION', 'restrict'], 'RESTRICT' => ['RESTRICT', 'restrict'],
    'SET NULL' => ['SET NULL', 'set null'], 'CASCADE' => ['cascade', 'cascade'],
]);

test('the foreign key action is worded the same in both engines', function () {
    $want = fingerprintOf(['jobs' => sampleTable()]);
    $sameRule = fingerprintOf(['jobs' => sampleTable(['foreign_keys' => ['client_id -> clients(id) on delete restrict']])]);

    expect(SchemaFingerprint::differences($want, $sameRule))->toBe([]);
});

test('the audit is read only and sits in the MySQL folder, so it never runs on SQLite', function () {
    $audit = file_get_contents(base_path('tests/Concurrency/Mysql/TestingDatabaseIntegrityAuditMysqlTest.php'));

    expect($audit)->toContain("'--database' => 'migration_copy'")->toContain("toBe('Thai_Visa_Services_Testing')")
        ->not->toMatch('/DB::(statement|unprepared|insert|update|delete)\(\s*[\'"]/')
        ->and(file_exists(base_path('tests/Support/SchemaFingerprint.php')))->toBeTrue();
});

test('on MySQL only the tables of the connected database are read, not those of every database the user may see', function () {
    $source = file_get_contents(base_path('tests/Support/SchemaFingerprint.php'));

    expect($source)->toContain('$schema->getTables($database)')->toContain("getDriverName() === 'mysql'");
});
