<?php

namespace App\Support;

/**
 * Judges the settings of the MySQL server the app runs on, against what the app assumes (HLD Section 14, risks 5 and 6). It works on
 * plain values, so it can be tested without a server; `php artisan db:check-server` reads the values and prints the result.
 *
 * - Isolation level: the locking design (row locks taken in a fixed order, re-reads under the lock) was proven under REPEATABLE READ.
 *   Another level changes what a read sees and how locks behave, so it is a failure until the tests are repeated under it.
 * - Message language: a lock-wait timeout has no special error code the retry helper can read; it is recognised by its English text
 *   ("Lock wait timeout exceeded"), so a server that answers in another language would stop the retries.
 */
final class DatabaseServerCheck
{
    public const OK = 'ok';

    public const FAIL = 'FAIL';

    public const NOTE = 'note';

    /** The server variables the check reads, by the name MySQL gives them. */
    public const VARIABLES = [
        'version', 'transaction_isolation', 'lc_messages', 'default_storage_engine', 'character_set_database', 'collation_database',
        'innodb_lock_wait_timeout', 'sql_mode', 'time_zone', 'system_time_zone',
    ];

    /**
     * @param  array<string, string|null>  $server  the variables, by name
     * @return list<array{check: string, value: string, status: string, note: string}>
     */
    public static function evaluate(array $server): array
    {
        $isolation = strtoupper((string) ($server['transaction_isolation'] ?? ''));
        $messages = strtolower((string) ($server['lc_messages'] ?? ''));
        $engine = strtolower((string) ($server['default_storage_engine'] ?? ''));
        $charset = strtolower((string) ($server['character_set_database'] ?? ''));
        $mode = strtoupper((string) ($server['sql_mode'] ?? ''));
        $timeout = (int) ($server['innodb_lock_wait_timeout'] ?? 0);

        return [
            self::row('Isolation level', $isolation, $isolation === 'REPEATABLE-READ', 'REPEATABLE-READ is what the locking tests proved; any other level must be tested again before launch.'),
            self::row('Message language (lc_messages)', $messages, str_starts_with($messages, 'en'), 'Lock-wait timeouts are recognised by their English text, so the server must answer in English.'),
            self::row('Default storage engine', $engine, $engine === 'innodb', 'Transactions and row locks need InnoDB.'),
            self::row('Database character set', $charset, $charset === 'utf8mb4', 'Burmese and Thai text need utf8mb4.'),
            self::row('SQL mode is strict', $mode === '' ? '(empty)' : $mode, str_contains($mode, 'STRICT_TRANS_TABLES') || str_contains($mode, 'STRICT_ALL_TABLES'), 'A strict mode refuses data that does not fit instead of silently changing it.'),
            self::note('Lock wait timeout', $timeout === 0 ? '(unknown)' : $timeout.' s', 'How long a request waits for a row lock before the retry helper takes over.'),
            self::note('Server version', (string) ($server['version'] ?? ''), 'The version these checks ran on.'),
            self::note('Collation', (string) ($server['collation_database'] ?? ''), 'For information.'),
            self::note('Time zone', (string) ($server['time_zone'] ?? '').' (system: '.($server['system_time_zone'] ?? '?').')', 'The app stores times in UTC and shows them in Asia/Bangkok; for information.'),
        ];
    }

    /**
     * @param  list<array{check: string, value: string, status: string, note: string}>  $rows
     */
    public static function passed(array $rows): bool
    {
        return collect($rows)->doesntContain('status', self::FAIL);
    }

    /** @return array{check: string, value: string, status: string, note: string} */
    private static function row(string $check, string $value, bool $good, string $note): array
    {
        return ['check' => $check, 'value' => $value === '' ? '(not set)' : $value, 'status' => $good ? self::OK : self::FAIL, 'note' => $note];
    }

    /** @return array{check: string, value: string, status: string, note: string} */
    private static function note(string $check, string $value, string $note): array
    {
        return ['check' => $check, 'value' => $value, 'status' => self::NOTE, 'note' => $note];
    }
}
