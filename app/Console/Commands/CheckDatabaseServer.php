<?php

namespace App\Console\Commands;

use App\Support\DatabaseServerCheck;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reads the settings of the MySQL server and compares them with what the app assumes (see App\Support\DatabaseServerCheck). It only
 * runs SELECT statements: it changes nothing. Run it on the production or staging server (HLD Section 14, risks 5 and 6).
 */
#[Signature('db:check-server {--connection= : The database connection to check (the default one when left out)}')]
#[Description('Read-only: check the MySQL server settings the app depends on (isolation level, message language, ...)')]
class CheckDatabaseServer extends Command
{
    public function handle(): int
    {
        $connection = DB::connection($this->option('connection') ?: null);

        if ($connection->getDriverName() !== 'mysql') {
            $this->error('This check is for MySQL; the connection uses the ['.$connection->getDriverName().'] driver.');

            return self::FAILURE;
        }

        $server = [];

        foreach (DatabaseServerCheck::VARIABLES as $name) {
            // Only SELECT: a server variable is read, never set. The names come from the constant above, not from input.
            $server[$name] = $connection->selectOne("select @@{$name} as value")->value ?? null;
        }

        $rows = DatabaseServerCheck::evaluate($server);

        $this->line('Database: '.$connection->getDatabaseName().' on '.$connection->getConfig('host'));
        $this->table(['Check', 'Value', 'Result', 'Why it matters'], array_map(fn (array $row): array => [$row['check'], $row['value'], $row['status'], $row['note']], $rows));

        if (! DatabaseServerCheck::passed($rows)) {
            $this->error('At least one setting differs from what the app assumes. See the rows marked FAIL.');

            return self::FAILURE;
        }

        $this->info('All settings are as the app assumes.');

        return self::SUCCESS;
    }
}
