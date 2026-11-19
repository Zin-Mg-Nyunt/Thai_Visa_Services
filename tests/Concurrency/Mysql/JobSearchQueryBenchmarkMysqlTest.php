<?php

use App\Actions\JobSearch\JobOrderMoney;
use App\Actions\Landing\LandingStatistics;
use App\Enums\WorkerCaseStatus;
use App\Http\Controllers\Admin\JobOrderController;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use Illuminate\Support\Facades\DB;
use Tests\Support\JobOrderMoneyWorld;
use Tests\Support\LegacyJobOrderMoney;

/**
 * The measurements behind the Phase 4 review findings P1 to P5, on MySQL with a synthetic data set of realistic size. It is a
 * measurement, not a check of behaviour (the equivalence and feature tests do that), so it only runs when asked:
 *
 *   JOB_SEARCH_BENCHMARK=1 JOB_SEARCH_BENCHMARK_OUT=/path/report.md DB_USERNAME=zin_visa_test \
 *       php artisan test -c phpunit.mysql.xml --filter=JobSearchQueryBenchmarkMysqlTest
 *
 * The rows are committed (ANALYZE TABLE needs that, and it would commit an open transaction anyway), then deleted by id in a
 * finally block, as the other MySQL tests do. Two temporary composite indexes are created only to measure P3, and dropped in a
 * finally block too. Times are medians of repeated runs on this machine: they compare the two versions, they do not predict a
 * production server.
 */
const BENCH_RUNS = 15;

beforeEach(function () {
    if (getenv('JOB_SEARCH_BENCHMARK') !== '1') {
        $this->markTestSkipped('Measurement only. Run it with JOB_SEARCH_BENCHMARK=1.');
    }
});

/**
 * The median time in milliseconds of a callable, after warm-up runs, and the storage engine rows it read on its last run.
 *
 * @return array{ms: float, rows_read: int}
 */
function benchMeasure(Closure $work, int $runs = BENCH_RUNS): array
{
    $work();
    $work();

    $times = [];

    for ($i = 0; $i < $runs; $i++) {
        $start = hrtime(true);
        $work();
        $times[] = (hrtime(true) - $start) / 1e6;
    }

    sort($times);

    $before = benchHandlerReads();
    $work();
    $rowsRead = benchHandlerReads() - $before;

    return ['ms' => round($times[intdiv(count($times), 2)], 2), 'rows_read' => $rowsRead];
}

/**
 * The rows the storage engine handed to the server in this session so far (every Handler_read counter). SHOW STATUS itself adds
 * a few, which is the same on both sides of a comparison.
 */
function benchHandlerReads(): int
{
    return (int) collect(DB::select("show session status like 'Handler_read%'"))->sum(fn ($row) => (int) $row->Value);
}

/**
 * The traditional EXPLAIN rows of a query, as a compact markdown table.
 */
function benchExplain(string $sql, array $bindings): string
{
    $rows = DB::select('explain '.$sql, $bindings);
    $lines = ['| id | select_type | table | type | key | rows | filtered | Extra |', '| --- | --- | --- | --- | --- | --- | --- | --- |'];

    foreach ($rows as $row) {
        $lines[] = sprintf('| %s | %s | %s | %s | %s | %s | %s | %s |', $row->id, $row->select_type, $row->table, $row->type ?? '', $row->key ?? '', $row->rows ?? '', $row->filtered ?? '', $row->Extra ?? '');
    }

    return implode("\n", $lines);
}

function benchAnalyze(string $sql, array $bindings): string
{
    return (string) (DB::select('explain analyze '.$sql, $bindings)[0]->EXPLAIN ?? '');
}

/**
 * The SQL and bindings of the queries a closure runs (to EXPLAIN exactly what the application sends).
 *
 * @return array<int, array{sql: string, bindings: array<int, mixed>}>
 */
function benchCapture(Closure $work): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    return array_values(array_map(fn ($query) => ['sql' => $query['query'], 'bindings' => $query['bindings']], array_filter(
        $queries,
        fn ($query) => str_starts_with(strtolower($query['query']), 'select'),
    )));
}

/**
 * The worker loading of the order page as it was before the review (two loads, every payment column, the person twice).
 */
function benchOldWorkerLoad(JobOrder $order): array
{
    $load = fn (bool $cancelled) => $order->workers()
        ->operational()
        ->where('case_status', $cancelled ? '=' : '!=', WorkerCaseStatus::Cancelled->value)
        ->with(['client', 'clientService.payments', 'clientService.client'])
        ->latest('id')
        ->get();

    return [$load(false), $load(true)];
}

/**
 * The index set of job_order_workers: name => columns.
 *
 * @return array<string, string>
 */
function benchWorkerIndexes(): array
{
    return collect(DB::select(
        "select index_name as name, group_concat(column_name order by seq_in_index) as cols from information_schema.statistics where table_schema = database() and table_name = 'job_order_workers' group by index_name order by index_name",
    ))->mapWithKeys(fn ($row) => [$row->name => $row->cols])->all();
}

/**
 * Put the index set of job_order_workers back exactly as it was. Creating an index whose first column is a foreign key column
 * makes MySQL silently drop that foreign key's own index (the new one can enforce the key), and the temporary index then cannot
 * be dropped while it is the only one left (error 1553). So the original single-column indexes are created again FIRST, under
 * their original names, and only then are the temporary ones dropped.
 *
 * @param  array<string, string>  $original
 */
function benchRestoreWorkerIndexes(array $original): void
{
    $current = benchWorkerIndexes();

    foreach (array_diff_key($original, $current) as $name => $columns) {
        DB::statement("create index `{$name}` on job_order_workers ({$columns})");
    }

    foreach (array_keys(array_diff_key(benchWorkerIndexes(), $original)) as $name) {
        DB::statement("drop index `{$name}` on job_order_workers");
    }
}

test('benchmark: Job Search queries before and after the review optimisations', function () {
    $world = new JobOrderMoneyWorld(2026);
    $report = [];
    $originalIndexes = benchWorkerIndexes();

    try {
        $buildStart = hrtime(true);
        $world->build(people: 20_000, orders: 2_000, paymentsPerRecord: 3, otherServicePeople: 20_000);

        // A busy order for the page measurement: 200 workers with payments on it.
        $bigOrder = JobOrder::query()->findOrFail($world->orderIds[0]);
        $now = now()->toDateTimeString();
        $rows = DB::table('client_services')->whereIn('id', array_slice($world->jobSearchRecordIds, 0, 200))->get(['id', 'client_id']);
        DB::table('job_order_workers')->insert($rows->map(fn ($record) => [
            'job_order_id' => $bigOrder->id, 'client_id' => $record->client_id, 'client_service_id' => $record->id,
            'case_status' => 'rejected', 'created_at' => $now, 'updated_at' => $now,
        ])->all());

        foreach (['clients', 'client_services', 'job_orders', 'job_order_workers', 'payments'] as $table) {
            DB::statement("analyze table {$table}");
        }

        $counts = [];

        foreach (['clients', 'client_services', 'job_orders', 'job_order_workers', 'payments'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        $report[] = '# Job Search query benchmark (MySQL '.DB::selectOne('select version() as v')->v.')';
        $report[] = '';
        $report[] = sprintf('Synthetic data set (seed 2026), built in %.1f s. Rows in the testing database during the run: %s.', (hrtime(true) - $buildStart) / 1e9, json_encode($counts));
        $report[] = 'Times are medians of '.BENCH_RUNS.' runs after 2 warm-ups. "rows read" is the sum of the session Handler_read_* counters for one run.';

        // ---------------------------------------------------------------- P1
        $money = app(JobOrderMoney::class);
        $legacy = new LegacyJobOrderMoney;
        $sample = array_slice($world->orderIds, 0, 20);
        $mismatch = 0;

        foreach ($sample as $orderId) {
            $mismatch += (int) ($money->forOrder($orderId) !== $legacy->forOrder($orderId));
        }

        $records = $world->recordSample(3);
        $mismatch += (int) ($money->ordersOf($records) != $legacy->ordersOf($records));

        $oldFor = benchMeasure(fn () => $legacy->forOrder($bigOrder->id));
        $newFor = benchMeasure(fn () => $money->forOrder($bigOrder->id));
        $oldOf = benchMeasure(fn () => $legacy->ordersOf($records));
        $newOf = benchMeasure(fn () => $money->ordersOf($records));
        $oldTotals = benchMeasure(fn () => $legacy->totals(), 5);
        $newTotals = benchMeasure(fn () => $money->totals(), 5);

        $report[] = '';
        $report[] = '## P1 Job Order Money';
        $report[] = '';
        $report[] = "Same answers as the original queries on the 20 sampled orders and the client-page lookup: mismatches = {$mismatch}.";
        $report[] = '';
        $report[] = '| query | before: queries / ms / rows read | after: queries / ms / rows read |';
        $report[] = '| --- | --- | --- |';
        $report[] = sprintf('| forOrder (order page, order with 200+ workers) | 2 / %s / %s | 1 / %s / %s |', $oldFor['ms'], $oldFor['rows_read'], $newFor['ms'], $newFor['rows_read']);
        $report[] = sprintf('| ordersOf (client page, 3 records) | 1 / %s / %s | 1 / %s / %s |', $oldOf['ms'], $oldOf['rows_read'], $newOf['ms'], $newOf['rows_read']);
        $report[] = sprintf('| totals (not used by any page; unchanged) | 2 / %s / %s | 2 / %s / %s |', $oldTotals['ms'], $oldTotals['rows_read'], $newTotals['ms'], $newTotals['rows_read']);

        $oldSql = benchCapture(fn () => $legacy->forOrder($bigOrder->id));
        $newSql = benchCapture(fn () => $money->forOrder($bigOrder->id));
        $report[] = '';
        $report[] = '### EXPLAIN forOrder, before (first of its two queries)';
        $report[] = '';
        $report[] = benchExplain($oldSql[0]['sql'], $oldSql[0]['bindings']);
        $report[] = '';
        $report[] = '### EXPLAIN forOrder, after';
        $report[] = '';
        $report[] = benchExplain($newSql[0]['sql'], $newSql[0]['bindings']);
        $report[] = '';
        $report[] = '### EXPLAIN ANALYZE forOrder, before (first query)';
        $report[] = '';
        $report[] = "```\n".benchAnalyze($oldSql[0]['sql'], $oldSql[0]['bindings'])."\n```";
        $report[] = '';
        $report[] = '### EXPLAIN ANALYZE forOrder, after';
        $report[] = '';
        $report[] = "```\n".benchAnalyze($newSql[0]['sql'], $newSql[0]['bindings'])."\n```";

        $oldOfSql = benchCapture(fn () => $legacy->ordersOf($records));
        $newOfSql = benchCapture(fn () => $money->ordersOf($records));
        $report[] = '';
        $report[] = '### EXPLAIN ANALYZE ordersOf, before';
        $report[] = '';
        $report[] = "```\n".benchAnalyze($oldOfSql[0]['sql'], $oldOfSql[0]['bindings'])."\n```";
        $report[] = '';
        $report[] = '### EXPLAIN ANALYZE ordersOf, after';
        $report[] = '';
        $report[] = "```\n".benchAnalyze($newOfSql[0]['sql'], $newOfSql[0]['bindings'])."\n```";

        // ---------------------------------------------------------------- P2
        $controller = app(JobOrderController::class);
        $workersOf = (new ReflectionMethod($controller, 'workersOf'));
        $newLoad = function () use ($controller, $workersOf, $bigOrder): array {
            return $workersOf->invoke($controller, $bigOrder)->partition(fn (JobOrderWorker $worker) => $worker->case_status === WorkerCaseStatus::Cancelled)->all();
        };
        $oldQueries = count(benchCapture(fn () => benchOldWorkerLoad($bigOrder)));
        $newQueries = count(benchCapture($newLoad));
        $memory = function (Closure $work): int {
            gc_collect_cycles();
            $before = memory_get_usage();
            $result = $work();
            $used = memory_get_usage() - $before;
            unset($result);

            return $used;
        };
        $oldMemory = $memory(fn () => benchOldWorkerLoad($bigOrder));
        $newMemory = $memory($newLoad);
        $oldLoad = benchMeasure(fn () => benchOldWorkerLoad($bigOrder));
        $newLoadTime = benchMeasure($newLoad);
        $workerCount = $bigOrder->workers()->count();

        $report[] = '';
        $report[] = "## P2 Order page worker loading (order with {$workerCount} applications)";
        $report[] = '';
        $report[] = '| | queries | ms | rows read | PHP memory held by the result |';
        $report[] = '| --- | --- | --- | --- | --- |';
        $report[] = sprintf('| before (two loads, all payment columns, person twice) | %d | %s | %s | %d KiB |', $oldQueries, $oldLoad['ms'], $oldLoad['rows_read'], intdiv($oldMemory, 1024));
        $report[] = sprintf('| after (one load, split in PHP, amount column only) | %d | %s | %s | %d KiB |', $newQueries, $newLoadTime['ms'], $newLoadTime['rows_read'], intdiv($newMemory, 1024));

        // ---------------------------------------------------------------- P3
        $someWorker = DB::table('job_order_workers')->where('job_order_id', $bigOrder->id)->first();
        $p3Queries = [
            'order list counts (withWorkerCounts, 10 orders)' => fn () => JobOrder::query()->withWorkerCounts()->latest()->latest('id')->limit(10)->get(),
            'close preview (open applications of one order)' => fn () => $bigOrder->workers()->open()->orderBy('id')->get(),
            'Add Worker duplicate check (order + person + active)' => fn () => $bigOrder->workers()->where('client_id', $someWorker->client_id)->whereIn('case_status', WorkerCaseStatus::activeValues())->first(['id']),
            'FindEngagement (applications of one record)' => fn () => JobOrderWorker::query()->where('client_service_id', $someWorker->client_service_id)->orderBy('id')->get(['id', 'case_status']),
            'forOrder (after P1)' => fn () => $money->forOrder($bigOrder->id),
        ];

        $p3 = function (string $label) use ($p3Queries, &$report): void {
            $report[] = '';
            $report[] = "### {$label}";
            $report[] = '';
            $report[] = '| query | ms | rows read |';
            $report[] = '| --- | --- | --- |';

            foreach ($p3Queries as $name => $work) {
                $measured = benchMeasure($work);
                $report[] = "| {$name} | {$measured['ms']} | {$measured['rows_read']} |";
            }

            $sql = benchCapture($p3Queries['order list counts (withWorkerCounts, 10 orders)']);
            $report[] = '';
            $report[] = 'EXPLAIN of the order list counts query:';
            $report[] = '';
            $report[] = benchExplain($sql[0]['sql'], $sql[0]['bindings']);
        };

        $report[] = '';
        $report[] = '## P3 Composite indexes on job_order_workers';
        $p3('with the existing indexes only (foreign keys and client_id)');

        DB::statement('create index bench_tmp_order_status on job_order_workers (job_order_id, case_status)');
        DB::statement('create index bench_tmp_record_status on job_order_workers (client_service_id, case_status)');
        DB::statement('analyze table job_order_workers');
        $report[] = '';
        $report[] = 'Index set while measuring: '.json_encode(benchWorkerIndexes()).' (MySQL drops a foreign key\'s own index once a composite index starting with that column exists).';

        $p3('with temporary (job_order_id, case_status) and (client_service_id, case_status) indexes');

        benchRestoreWorkerIndexes($originalIndexes);
        DB::statement('analyze table job_order_workers');

        // ---------------------------------------------------------------- P4
        $orders = JobOrder::query()->count();
        $search = fn (string $term) => fn () => JobOrder::query()->withWorkerCounts()
            ->whereRaw("company_name like ? escape '!'", ['%'.$term.'%'])
            ->latest()->latest('id')->paginate(10);

        $report[] = '';
        $report[] = "## P4 Company search, LIKE '%term%' ({$orders} job orders)";
        $report[] = '';
        $report[] = '| search | ms (count + page) | rows read |';
        $report[] = '| --- | --- | --- |';

        foreach (['common term "and"' => 'and', 'rare term "Zzq"' => 'Zzq', 'no search (list only)' => ''] as $label => $term) {
            $measured = benchMeasure($term === ''
                ? fn () => JobOrder::query()->withWorkerCounts()->latest()->latest('id')->paginate(10)
                : $search($term));
            $report[] = "| {$label} | {$measured['ms']} | {$measured['rows_read']} |";
        }

        $sql = benchCapture($search('and'));
        $report[] = '';
        $report[] = 'EXPLAIN of the page query with a search:';
        $report[] = '';
        $report[] = benchExplain(end($sql)['sql'], end($sql)['bindings']);

        // ---------------------------------------------------------------- P5
        $statistics = app(LandingStatistics::class);
        $compute = new ReflectionMethod($statistics, 'compute');
        $computed = benchMeasure(fn () => $compute->invoke($statistics));

        config(['cache.default' => 'database']);
        $refresh = benchMeasure(fn () => $statistics->refresh());

        $report[] = '';
        $report[] = '## P5 Landing statistics';
        $report[] = '';
        $report[] = '| step | ms | rows read |';
        $report[] = '| --- | --- | --- |';
        $report[] = "| compute (the two counts a cache miss runs) | {$computed['ms']} | {$computed['rows_read']} |";
        $report[] = "| refresh after commit, uncontended, database cache store (lock + counts + put) | {$refresh['ms']} | {$refresh['rows_read']} |";
    } finally {
        // Each clean-up step runs even when the one before it fails.
        try {
            benchRestoreWorkerIndexes($originalIndexes);
        } finally {
            try {
                DB::table('cache')->where('key', 'like', '%landing.statistics%')->delete();
                DB::table('cache_locks')->where('key', 'like', '%landing.statistics%')->delete();
            } finally {
                $world->cleanUp();
            }
        }
    }

    $text = implode("\n", $report)."\n";
    $out = getenv('JOB_SEARCH_BENCHMARK_OUT');

    if (is_string($out) && $out !== '') {
        file_put_contents($out, $text);
    } else {
        fwrite(STDERR, $text);
    }

    expect($mismatch)->toBe(0)
        ->and(benchWorkerIndexes())->toBe($originalIndexes);
})->group('benchmark');
