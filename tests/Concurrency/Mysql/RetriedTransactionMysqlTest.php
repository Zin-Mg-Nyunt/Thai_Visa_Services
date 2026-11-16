<?php

use App\Support\Concurrency\RetriedTransaction;
use App\Support\Concurrency\TryAgainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The retry helper against a real MySQL server, with two independent connections. Run with:
 * php artisan test -c phpunit.mysql.xml
 */
function sessionTimeout(): int
{
    return (int) DB::selectOne('select @@session.innodb_lock_wait_timeout as seconds')->seconds;
}

beforeEach(function () {
    app()->setLocale('en');

    // Never drop a table this test did not create: on a shared database that could be real data.
    if (Schema::hasTable('lock_probe')) {
        throw new RuntimeException('A table named lock_probe already exists; refusing to touch it.');
    }

    Schema::create('lock_probe', function ($table) {
        $table->id();
        $table->string('note')->default('');
    });
    DB::table('lock_probe')->insert([['note' => 'row one'], ['note' => 'row two']]);

    // A value that is neither MySQL's default (50) nor the helper's (5), so a restore cannot pass by accident.
    DB::statement('SET SESSION innodb_lock_wait_timeout = 37');
    // Waiting for a table metadata lock otherwise lasts a year: a stuck cleanup must fail fast instead.
    DB::statement('SET SESSION lock_wait_timeout = 10');
});

afterEach(function () {
    $this->closePeers();
    Schema::dropIfExists('lock_probe');
});

test('inside the workflow the timeout is 5 seconds, and afterwards the original value is back', function () {
    $inside = RetriedTransaction::run(fn () => sessionTimeout());

    expect($inside)->toBe(5)->and(sessionTimeout())->toBe(37);
});

test('the original timeout is also back after an exception', function () {
    expect(fn () => RetriedTransaction::run(function () {
        throw new RuntimeException('a real bug');
    }))->toThrow(RuntimeException::class);

    expect(sessionTimeout())->toBe(37);
});

test('a real lock-wait timeout (1205) is retried and the workflow then succeeds', function () {
    $peer = $this->peerConnection();
    $peer->beginTransaction();
    $peer->table('lock_probe')->where('id', 1)->lockForUpdate()->first();

    $runs = 0;

    $result = RetriedTransaction::run(function () use (&$runs, $peer) {
        $runs++;

        // The other transaction lets go before the second attempt.
        if ($runs === 2) {
            $peer->commit();
        }

        DB::table('lock_probe')->where('id', 1)->update(['note' => "updated on attempt {$runs}"]);

        return $runs;
    });

    expect($result)->toBe(2)
        ->and(DB::table('lock_probe')->where('id', 1)->value('note'))->toBe('updated on attempt 2')
        ->and(sessionTimeout())->toBe(37);
});

test('when every attempt times out the admin gets the friendly error, nothing is half written, and the timeout is restored', function () {
    $peer = $this->peerConnection();
    $peer->beginTransaction();
    $peer->table('lock_probe')->where('id', 1)->lockForUpdate()->first();

    $runs = 0;

    try {
        RetriedTransaction::run(function () use (&$runs) {
            $runs++;
            // Written first, then blocked: a rollback must remove it again.
            DB::table('lock_probe')->where('id', 2)->update(['note' => 'half written']);
            DB::table('lock_probe')->where('id', 1)->update(['note' => 'blocked']);
        });

        $this->fail('Expected a TryAgainException.');
    } catch (TryAgainException $exception) {
        expect($runs)->toBe(3)
            ->and($exception->getMessage())->toBe('The system is busy. Please try again.')
            ->and($exception->getPrevious()->getMessage())->toContain('Lock wait timeout exceeded');
    }

    $peer->rollBack();

    expect(DB::table('lock_probe')->where('id', 2)->value('note'))->toBe('row two')
        ->and(sessionTimeout())->toBe(37);
});
