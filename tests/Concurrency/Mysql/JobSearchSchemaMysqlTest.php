<?php

use Illuminate\Support\Facades\DB;

/**
 * The real MySQL schema of the testing database (not the migration files): no unique key on (job_order_id, client_id) or on
 * client_service_id, and the foreign keys and ordinary indexes the Job Search workflows rely on.
 * Run with: php artisan test -c phpunit.mysql.xml --filter=JobSearchSchemaMysqlTest
 */
function workerIndexes(): array
{
    $rows = DB::select(
        'select index_name as index_name, non_unique as non_unique, seq_in_index as seq_in_index, column_name as column_name from information_schema.statistics where table_schema = database() and table_name = ? order by index_name, seq_in_index',
        ['job_order_workers'],
    );

    $indexes = [];

    foreach ($rows as $row) {
        $indexes[$row->index_name]['unique'] = (int) $row->non_unique === 0;
        $indexes[$row->index_name]['columns'][] = $row->column_name;
    }

    return $indexes;
}

test('the testing database is the dedicated one, on MySQL, InnoDB', function () {
    expect(DB::getDriverName())->toBe('mysql')
        ->and(DB::selectOne('select database() as name')->name)->toBe('Thai_Visa_Services_Testing')
        ->and(DB::selectOne("select engine as engine from information_schema.tables where table_schema = database() and table_name = 'job_order_workers'")->engine)->toBe('InnoDB');
});

test('job_order_workers has no unique key on (job_order_id, client_id) and none on client_service_id', function () {
    $unique = collect(workerIndexes())->filter(fn ($index) => $index['unique'])->map(fn ($index) => $index['columns']);

    // The primary key is the only unique index. An old schema (unique (job_order_id, client_id) or unique client_service_id)
    // fails here and names the index.
    expect($unique->all())->toBe(['PRIMARY' => ['id']]);
});

test('job_order_workers keeps its ordinary indexes and foreign keys', function () {
    $indexes = workerIndexes();
    $leading = collect($indexes)->map(fn ($index) => $index['columns'][0])->values()->all();

    expect($leading)->toContain('job_order_id')->toContain('client_id')->toContain('client_service_id')
        ->and($indexes['job_order_workers_client_id_index'])->toBe(['unique' => false, 'columns' => ['client_id']]);

    $foreignKeys = collect(DB::select(
        'select column_name as column_name, referenced_table_name as referenced_table_name, referenced_column_name as referenced_column_name from information_schema.key_column_usage where table_schema = database() and table_name = ? and referenced_table_name is not null',
        ['job_order_workers'],
    ))->mapWithKeys(fn ($row) => [$row->column_name => $row->referenced_table_name.'.'.$row->referenced_column_name])->all();

    expect($foreignKeys)->toBe([
        'client_id' => 'clients.id',
        'client_service_id' => 'client_services.id',
        'job_order_id' => 'job_orders.id',
    ]);
});

test('the foreign keys restrict deletes, so history cannot be removed', function () {
    $rules = collect(DB::select(
        'select constraint_name as constraint_name, delete_rule as delete_rule from information_schema.referential_constraints where constraint_schema = database() and table_name = ?',
        ['job_order_workers'],
    ))->pluck('delete_rule')->unique()->values()->all();

    expect($rules)->toBe(['RESTRICT']);
});
