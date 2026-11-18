<?php

use App\Actions\Landing\LandingStatistics;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Audit tests for the refresh lifecycle: how the Eloquent `created` event, the transaction callbacks and the one shared
 * LandingStatistics object behave together, for several creations, several transactions, rollbacks and nested transactions.
 *
 * Under RefreshDatabase the test transaction wraps every test, and Laravel's test transaction manager treats the first nested
 * level as the outermost (see the Testing\DatabaseTransactionsManager). The same scenarios run on the real manager, against MySQL,
 * in tests/Concurrency/Mysql/LandingStatisticsLifecycleMysqlTest.php.
 */
beforeEach(function () {
    $this->passport = Service::factory()->create(['title' => 'Passport']);
    $this->person = Client::factory()->create();
    Cache::put(LandingStatistics::CACHE_KEY, ['clients_served' => 999, 'services_provided' => 999], 600);
});

function lifecycleService(object $test): ClientService
{
    return ClientService::factory()->forService($test->passport)->create(['client_id' => $test->person->id]);
}

function lifecycleCached(): mixed
{
    return Cache::get(LandingStatistics::CACHE_KEY);
}

/** @return array<int, string> */
function lifecycleCounts(Closure $callback): array
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

/** The callbacks the transaction manager is holding right now: [after commit, after rollback]. */
function lifecycleCallbacks(): array
{
    $records = app('db.transactions')->callbackApplicableTransactions();

    return [
        $records->sum(fn ($record) => count($record->getCallbacks())),
        $records->sum(fn ($record) => count($record->getCallbacksForRollback())),
    ];
}

function lifecyclePending(): bool
{
    return (new ReflectionProperty(LandingStatistics::class, 'refreshPending'))->getValue(app(LandingStatistics::class));
}

const LIFECYCLE_STALE = ['clients_served' => 999, 'services_provided' => 999];

test('three services in one transaction: three created events, one registration, one refresh, two count queries', function () {
    $created = 0;
    Event::listen('eloquent.created: '.ClientService::class, function () use (&$created) {
        $created++;
    });

    $registered = null;
    $inside = null;
    $statements = lifecycleCounts(function () use (&$registered, &$inside) {
        DB::transaction(function () use (&$registered, &$inside) {
            lifecycleService($this);
            lifecycleService($this);
            lifecycleService($this);

            $registered = lifecycleCallbacks();
            $inside = lifecycleCached();
        });
    });

    expect($created)->toBe(3)
        ->and($registered)->toBe([1, 1])
        ->and($inside)->toBe(LIFECYCLE_STALE)
        ->and($statements)->toHaveCount(2)
        ->and(lifecycleCached())->toBe(['clients_served' => 1, 'services_provided' => 3])
        ->and(lifecyclePending())->toBeFalse();
});

test('separate transactions in one request each refresh once: an earlier pending state never swallows a later refresh', function () {
    $first = lifecycleCounts(fn () => DB::transaction(fn () => lifecycleService($this)));
    expect($first)->toHaveCount(2)
        ->and(lifecycleCached())->toBe(['clients_served' => 1, 'services_provided' => 1])
        ->and(lifecyclePending())->toBeFalse();

    $second = lifecycleCounts(fn () => DB::transaction(function () {
        lifecycleService($this);
        lifecycleService($this);
    }));
    expect($second)->toHaveCount(2)
        ->and(lifecycleCached())->toBe(['clients_served' => 1, 'services_provided' => 3]);

    $third = lifecycleCounts(fn () => DB::transaction(fn () => lifecycleService($this)));
    expect($third)->toHaveCount(2)
        ->and(lifecycleCached())->toBe(['clients_served' => 1, 'services_provided' => 4]);
});

test('the pending flag is on while a transaction waits, and off after a commit and after a rollback', function () {
    expect(lifecyclePending())->toBeFalse();

    DB::transaction(function () {
        lifecycleService($this);
        expect(lifecyclePending())->toBeTrue();
    });
    expect(lifecyclePending())->toBeFalse();

    try {
        DB::transaction(function () {
            lifecycleService($this);
            expect(lifecyclePending())->toBeTrue();
            throw new RuntimeException('fail');
        });
    } catch (RuntimeException) {
    }
    expect(lifecyclePending())->toBeFalse();
});

test('scenario C: a nested transaction commits, the outer one rolls back: no refresh, nothing counted, and the next creation refreshes', function () {
    $statements = lifecycleCounts(function () {
        try {
            DB::transaction(function () {
                DB::transaction(fn () => lifecycleService($this));
                expect(lifecyclePending())->toBeTrue();
                throw new RuntimeException('outer failed');
            });
        } catch (RuntimeException) {
        }
    });

    expect($statements)->toBeEmpty()
        ->and(ClientService::count())->toBe(0)
        ->and(lifecycleCached())->toBe(LIFECYCLE_STALE)
        ->and(lifecyclePending())->toBeFalse();

    DB::transaction(fn () => lifecycleService($this));
    expect(lifecycleCached())->toBe(['clients_served' => 1, 'services_provided' => 1]);
});

test('scenario D: a nested transaction rolls back, the outer one commits: no refresh and no wrong number', function () {
    $statements = lifecycleCounts(function () {
        DB::transaction(function () {
            try {
                DB::transaction(function () {
                    lifecycleService($this);
                    throw new RuntimeException('inner failed');
                });
            } catch (RuntimeException) {
            }
            expect(lifecyclePending())->toBeFalse();
        });
    });

    expect($statements)->toBeEmpty()
        ->and(ClientService::count())->toBe(0)
        ->and(lifecycleCached())->toBe(LIFECYCLE_STALE);

    DB::transaction(fn () => lifecycleService($this));
    expect(lifecycleCached())->toBe(['clients_served' => 1, 'services_provided' => 1]);
});

test('scenario E1: one nested transaction commits, a second rolls back, a third commits, the outer commits: one refresh with two services', function () {
    $statements = lifecycleCounts(function () {
        DB::transaction(function () {
            DB::transaction(fn () => lifecycleService($this));
            try {
                DB::transaction(function () {
                    lifecycleService($this);
                    throw new RuntimeException('second failed');
                });
            } catch (RuntimeException) {
            }
            DB::transaction(fn () => lifecycleService($this));
        });
    });

    expect($statements)->toHaveCount(2)
        ->and(lifecycleCached())->toBe(['clients_served' => 1, 'services_provided' => 2]);
});

test('scenario E2: several nested transactions commit and the outer one rolls back: nothing is refreshed, the flag is reset', function () {
    $statements = lifecycleCounts(function () {
        try {
            DB::transaction(function () {
                DB::transaction(fn () => lifecycleService($this));
                DB::transaction(fn () => lifecycleService($this));
                throw new RuntimeException('outer failed');
            });
        } catch (RuntimeException) {
        }
    });

    expect($statements)->toBeEmpty()
        ->and(ClientService::count())->toBe(0)
        ->and(lifecycleCached())->toBe(LIFECYCLE_STALE)
        ->and(lifecyclePending())->toBeFalse();
});

test('scenario E3: three levels deep: the deepest commits, the middle one rolls back, the outer commits: nothing refreshed', function () {
    $statements = lifecycleCounts(function () {
        DB::transaction(function () {
            try {
                DB::transaction(function () {
                    DB::transaction(fn () => lifecycleService($this));
                    throw new RuntimeException('middle failed');
                });
            } catch (RuntimeException) {
            }
        });
    });

    expect($statements)->toBeEmpty()
        ->and(ClientService::count())->toBe(0)
        ->and(lifecycleCached())->toBe(LIFECYCLE_STALE)
        ->and(lifecyclePending())->toBeFalse();

    DB::transaction(fn () => lifecycleService($this));
    expect(lifecycleCached())->toBe(['clients_served' => 1, 'services_provided' => 1]);
});

test('scenario E4: three levels deep, all commit: one refresh', function () {
    $statements = lifecycleCounts(function () {
        DB::transaction(function () {
            DB::transaction(function () {
                DB::transaction(fn () => lifecycleService($this));
                lifecycleService($this);
            });
            lifecycleService($this);
        });
    });

    expect($statements)->toHaveCount(2)
        ->and(lifecycleCached())->toBe(['clients_served' => 1, 'services_provided' => 3]);
});

test('scenario E5: a service created in the outer transaction before a failing nested one is counted, the nested one is not', function () {
    DB::transaction(function () {
        lifecycleService($this);
        try {
            DB::transaction(function () {
                lifecycleService($this);
                throw new RuntimeException('nested failed');
            });
        } catch (RuntimeException) {
        }
        lifecycleService($this);
    });

    expect(lifecycleCached())->toBe(['clients_served' => 1, 'services_provided' => 2]);
});

test('LandingStatistics is one shared object: the observer, the controller and any later resolution use the same flag', function () {
    $first = app(LandingStatistics::class);

    expect(app(LandingStatistics::class))->toBe($first)
        ->and(app()->make(LandingStatistics::class))->toBe($first);

    // The observer got its LandingStatistics from the container as well: a request made through the model marks the same object
    // as pending, so asking the container's object again adds no second registration.
    DB::transaction(function () use ($first) {
        lifecycleService($this);
        expect(lifecyclePending())->toBeTrue()
            ->and(lifecycleCallbacks())->toBe([1, 1]);

        $first->refreshAfterCommit();
        app(LandingStatistics::class)->refreshAfterCommit();

        expect(lifecycleCallbacks())->toBe([1, 1]);
    });
});

test('a creation by a Person model and by a service model share the one pending state: one refresh for both', function () {
    $statements = lifecycleCounts(function () {
        DB::transaction(function () {
            $another = Client::factory()->create();
            ClientService::factory()->forService($this->passport)->create(['client_id' => $another->id]);
        });
    });

    expect($statements)->toHaveCount(2)
        ->and(lifecycleCached())->toBe(['clients_served' => 1, 'services_provided' => 1]);
});

test('every place that creates a Person or a service goes through Eloquent, so the observer sees it', function () {
    $patterns = [
        'DB::table(\'client_services\')', 'DB::table("client_services")', 'DB::table(\'clients\')', 'DB::table("clients")',
        'insertOrIgnore', 'insertGetId', 'upsert(', '->insert(', '::insert(', 'saveQuietly', 'createQuietly', 'withoutEvents',
        'ClientService::query()->create', 'DB::insert', 'DB::statement',
    ];

    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = file_get_contents($file->getPathname());
        foreach ($patterns as $pattern) {
            if (str_contains($code, $pattern)) {
                $found[] = str_replace(app_path().'/', '', $file->getPathname()).' : '.$pattern;
            }
        }
    }

    // Client::saveQuietly only removes the photo path of an existing Person (an update, not a creation).
    expect($found)->toBe(['Models/Client.php : saveQuietly']);
});

test('known limitation: a raw insert bypasses the model event, so the numbers catch up only when the cache expires', function () {
    DB::table('client_services')->insert([
        'client_id' => $this->person->id, 'service_id' => $this->passport->id, 'status' => 'in_progress',
        'start_date' => '2026-10-01', 'fee' => 1000, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(ClientService::count())->toBe(1)
        ->and(lifecycleCached())->toBe(LIFECYCLE_STALE);

    $this->travel(601)->seconds();

    expect(app(LandingStatistics::class)->counts())->toBe(['clients_served' => 1, 'services_provided' => 1]);
});
