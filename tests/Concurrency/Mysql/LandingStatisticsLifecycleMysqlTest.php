<?php

use App\Actions\Landing\LandingStatistics;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Service;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The landing statistics refresh on Laravel's REAL transaction manager (no wrapping test transaction), on MySQL. The SQLite suite
 * runs under RefreshDatabase, whose test manager treats the first nested level as the outermost; here the outermost transaction
 * is the real one. Every row made is removed again by its id. Run with:
 *   DB_USERNAME=zin_visa_test php artisan test -c phpunit.mysql.xml --filter=LandingStatisticsLifecycleMysqlTest
 */
beforeEach(function () {
    $this->made = ['client_services' => [], 'clients' => [], 'services' => []];
    $this->passport = Service::factory()->create(['title' => 'Lifecycle test passport']);
    $this->made['services'][] = $this->passport->id;
    $this->person = Client::factory()->create();
    $this->made['clients'][] = $this->person->id;
    // The testing database may hold other rows: the expected numbers are the ones before the test plus what the test adds.
    $this->before = [
        'clients' => DB::table('client_services')->distinct()->count('client_id'),
        'services' => DB::table('client_services')->count(),
    ];
    Cache::put(LandingStatistics::CACHE_KEY, ['clients_served' => 999, 'services_provided' => 999], 600);
});

afterEach(function () {
    if (! isset($this->made)) {
        return; // the test was skipped (not MySQL) before anything was made
    }

    DB::table('client_services')->whereIn('id', $this->made['client_services'])->delete();
    DB::table('client_services')->where('client_id', $this->person->id)->delete();
    DB::table('clients')->whereIn('id', $this->made['clients'])->delete();
    DB::table('services')->whereIn('id', $this->made['services'])->delete();
});

function mysqlLifecycleService(object $test): ClientService
{
    $service = ClientService::factory()->forService($test->passport)->create(['client_id' => $test->person->id]);
    $test->made['client_services'][] = $service->id;

    return $service;
}

/** @return array<int, string> */
function mysqlLifecycleCounts(Closure $callback): array
{
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        if (str_contains($query->sql, 'client_services') && str_contains($query->sql, 'count(')) {
            $statements[] = $query->sql;
        }
    });
    $callback();

    return $statements;
}

/** @return array{clients_served: int, services_provided: int} */
function mysqlExpected(object $test, int $services): array
{
    return ['clients_served' => $test->before['clients'] + 1, 'services_provided' => $test->before['services'] + $services];
}

function mysqlLifecycleCached(): mixed
{
    return Cache::get(LandingStatistics::CACHE_KEY);
}

test('the real manager has no wrapping transaction: the outermost transaction is level 1', function () {
    expect(DB::transactionLevel())->toBe(0)
        ->and(app('db.transactions'))->toBeInstanceOf(DatabaseTransactionsManager::class)
        ->and(app('db.transactions'))->not->toBeInstanceOf(Illuminate\Foundation\Testing\DatabaseTransactionsManager::class);
});

test('a service created inside a nested transaction is not refreshed when the nested one commits, only when the real outermost one does', function () {
    $afterInner = null;

    $statements = mysqlLifecycleCounts(function () use (&$afterInner) {
        DB::transaction(function () use (&$afterInner) {
            DB::transaction(fn () => mysqlLifecycleService($this));
            $afterInner = mysqlLifecycleCached();
        });
    });

    expect($afterInner)->toBe(['clients_served' => 999, 'services_provided' => 999])
        ->and($statements)->toHaveCount(2)
        ->and(mysqlLifecycleCached())->toBe(mysqlExpected($this, 1));
});

test('three services in one transaction refresh once', function () {
    $statements = mysqlLifecycleCounts(fn () => DB::transaction(function () {
        mysqlLifecycleService($this);
        mysqlLifecycleService($this);
        mysqlLifecycleService($this);
    }));

    expect($statements)->toHaveCount(2)
        ->and(mysqlLifecycleCached())->toBe(mysqlExpected($this, 3));
});

test('a rollback after the created event leaves no row and no refresh, and the next transaction refreshes', function () {
    $statements = mysqlLifecycleCounts(function () {
        try {
            DB::transaction(function () {
                mysqlLifecycleService($this);
                throw new RuntimeException('fail');
            });
        } catch (RuntimeException) {
        }
    });

    expect($statements)->toBeEmpty()
        ->and(ClientService::where('client_id', $this->person->id)->count())->toBe(0)
        ->and(mysqlLifecycleCached())->toBe(['clients_served' => 999, 'services_provided' => 999]);

    DB::transaction(fn () => mysqlLifecycleService($this));
    expect(mysqlLifecycleCached())->toBe(mysqlExpected($this, 1));
});

test('scenario C: nested commits, outer rolls back: no refresh, flag reset, next creation refreshes', function () {
    $statements = mysqlLifecycleCounts(function () {
        try {
            DB::transaction(function () {
                DB::transaction(fn () => mysqlLifecycleService($this));
                throw new RuntimeException('outer failed');
            });
        } catch (RuntimeException) {
        }
    });

    expect($statements)->toBeEmpty()
        ->and(ClientService::where('client_id', $this->person->id)->count())->toBe(0)
        ->and(mysqlLifecycleCached())->toBe(['clients_served' => 999, 'services_provided' => 999]);

    DB::transaction(fn () => mysqlLifecycleService($this));
    expect(mysqlLifecycleCached())->toBe(mysqlExpected($this, 1));
});

test('scenario D: nested rolls back, outer commits: no refresh and no wrong number', function () {
    $statements = mysqlLifecycleCounts(function () {
        DB::transaction(function () {
            try {
                DB::transaction(function () {
                    mysqlLifecycleService($this);
                    throw new RuntimeException('inner failed');
                });
            } catch (RuntimeException) {
            }
        });
    });

    expect($statements)->toBeEmpty()
        ->and(ClientService::where('client_id', $this->person->id)->count())->toBe(0)
        ->and(mysqlLifecycleCached())->toBe(['clients_served' => 999, 'services_provided' => 999]);

    DB::transaction(fn () => mysqlLifecycleService($this));
    expect(mysqlLifecycleCached())->toBe(mysqlExpected($this, 1));
});

test('scenario E: commit, rollback, commit nested, outer commits: one refresh with two services', function () {
    $statements = mysqlLifecycleCounts(function () {
        DB::transaction(function () {
            DB::transaction(fn () => mysqlLifecycleService($this));
            try {
                DB::transaction(function () {
                    mysqlLifecycleService($this);
                    throw new RuntimeException('second failed');
                });
            } catch (RuntimeException) {
            }
            DB::transaction(fn () => mysqlLifecycleService($this));
        });
    });

    expect($statements)->toHaveCount(2)
        ->and(mysqlLifecycleCached())->toBe(mysqlExpected($this, 2));
});

test('three levels deep: deepest commits, middle rolls back, outer commits: nothing refreshed', function () {
    $statements = mysqlLifecycleCounts(function () {
        DB::transaction(function () {
            try {
                DB::transaction(function () {
                    DB::transaction(fn () => mysqlLifecycleService($this));
                    throw new RuntimeException('middle failed');
                });
            } catch (RuntimeException) {
            }
        });
    });

    expect($statements)->toBeEmpty()
        ->and(mysqlLifecycleCached())->toBe(['clients_served' => 999, 'services_provided' => 999]);

    DB::transaction(fn () => mysqlLifecycleService($this));
    expect(mysqlLifecycleCached())->toBe(mysqlExpected($this, 1));
});

test('separate transactions each refresh once', function () {
    $first = mysqlLifecycleCounts(fn () => DB::transaction(fn () => mysqlLifecycleService($this)));
    $second = mysqlLifecycleCounts(fn () => DB::transaction(fn () => mysqlLifecycleService($this)));

    expect($first)->toHaveCount(2)
        ->and($second)->toHaveCount(2)
        ->and(mysqlLifecycleCached())->toBe(mysqlExpected($this, 2));
});

test('the refresh counts committed rows: another connection sees the new service already when the cache is replaced', function () {
    $seen = null;

    DB::transaction(function () use (&$seen) {
        mysqlLifecycleService($this);
        // Not committed yet: another connection cannot see the row, and the cache is not refreshed.
        $seen = $this->peerConnection()->table('client_services')->where('client_id', $this->person->id)->count();
    });

    expect($seen)->toBe(0)
        ->and($this->peerConnection()->table('client_services')->where('client_id', $this->person->id)->count())->toBe(1)
        ->and(mysqlLifecycleCached())->toBe(mysqlExpected($this, 1));
});
