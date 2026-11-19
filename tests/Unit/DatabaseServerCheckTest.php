<?php

use App\Support\DatabaseServerCheck;

/**
 * The judgement of the MySQL server settings (HLD Section 14, risks 5 and 6), on plain values, with no server.
 */
function goodServer(array $override = []): array
{
    return [
        'version' => '8.4.13', 'transaction_isolation' => 'REPEATABLE-READ', 'lc_messages' => 'en_US', 'default_storage_engine' => 'InnoDB',
        'character_set_database' => 'utf8mb4', 'collation_database' => 'utf8mb4_0900_ai_ci', 'innodb_lock_wait_timeout' => '50',
        'sql_mode' => 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
        'time_zone' => 'SYSTEM', 'system_time_zone' => 'UTC', ...$override,
    ];
}

/** @return array<string, string> check => status */
function statuses(array $server): array
{
    return collect(DatabaseServerCheck::evaluate($server))->pluck('status', 'check')->all();
}

test('a server as the app assumes passes every check', function () {
    $rows = DatabaseServerCheck::evaluate(goodServer());

    expect(DatabaseServerCheck::passed($rows))->toBeTrue()
        ->and(statuses(goodServer())['Isolation level'])->toBe('ok')->and(statuses(goodServer())['Message language (lc_messages)'])->toBe('ok');
});

test('any isolation level but REPEATABLE-READ fails', function (string $level) {
    $rows = DatabaseServerCheck::evaluate(goodServer(['transaction_isolation' => $level]));

    expect(DatabaseServerCheck::passed($rows))->toBeFalse()->and(statuses(goodServer(['transaction_isolation' => $level]))['Isolation level'])->toBe('FAIL');
})->with(['READ-COMMITTED', 'READ-UNCOMMITTED', 'SERIALIZABLE', '']);

test('a server that does not answer in English fails, because lock-wait timeouts are recognised by their English text', function (string $language) {
    expect(statuses(goodServer(['lc_messages' => $language]))['Message language (lc_messages)'])->toBe('FAIL');
})->with(['th_TH', 'my_MM', 'de_DE', '']);

test('English in any form passes', function (string $language) {
    expect(statuses(goodServer(['lc_messages' => $language]))['Message language (lc_messages)'])->toBe('ok');
})->with(['en_US', 'en_GB', 'EN_us']);

test('the storage engine, the character set and a strict SQL mode are checked', function () {
    expect(statuses(goodServer(['default_storage_engine' => 'MyISAM']))['Default storage engine'])->toBe('FAIL')
        ->and(statuses(goodServer(['character_set_database' => 'latin1']))['Database character set'])->toBe('FAIL')
        ->and(statuses(goodServer(['sql_mode' => 'NO_ENGINE_SUBSTITUTION']))['SQL mode is strict'])->toBe('FAIL')
        ->and(statuses(goodServer(['sql_mode' => 'STRICT_ALL_TABLES']))['SQL mode is strict'])->toBe('ok');
});

test('the lock wait timeout, the version, the collation and the time zone are only notes and never fail', function () {
    $rows = DatabaseServerCheck::evaluate(goodServer(['innodb_lock_wait_timeout' => '5', 'time_zone' => '+07:00', 'collation_database' => 'utf8mb4_bin']));

    expect(DatabaseServerCheck::passed($rows))->toBeTrue()
        ->and(collect($rows)->where('status', 'note')->pluck('check')->all())->toBe(['Lock wait timeout', 'Server version', 'Collation', 'Time zone']);
});

test('a value that is missing is reported as not set, and counts as a failure for the checked settings', function () {
    $rows = DatabaseServerCheck::evaluate([]);

    expect(DatabaseServerCheck::passed($rows))->toBeFalse()
        ->and(collect($rows)->firstWhere('check', 'Isolation level')['value'])->toBe('(not set)');
});

test('the command reads only server variables by fixed names, and runs only SELECT', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/app/Console/Commands/CheckDatabaseServer.php');

    expect($source)->toContain('selectOne("select @@{$name} as value")')
        ->not->toMatch('/\b(insert|update|delete|drop|alter|truncate|set\s+(global|session))\b/i')
        ->not->toContain('DB::statement')->not->toContain('->statement(')->not->toContain('->unprepared(');
    foreach (DatabaseServerCheck::VARIABLES as $name) {
        expect($name)->toMatch('/^[a-z_]+$/');
    }
});
