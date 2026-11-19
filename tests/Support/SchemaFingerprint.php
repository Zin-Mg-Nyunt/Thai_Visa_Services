<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A comparable picture of a database schema (tables, columns, indexes, foreign keys), taken with Laravel's own schema reader so the same
 * code reads SQLite and MySQL. It only reads: it runs no statement that changes anything.
 *
 * It is used by the integrity audit of the MySQL testing database: the schema the migration files produce (read from an in-memory
 * SQLite copy) is compared with the schema that is really in MySQL. Differences between the two engines that mean nothing are left
 * out: column types, index names, and the index MySQL adds by itself behind every foreign key.
 */
final class SchemaFingerprint
{
    /**
     * @return array{tables: array<string, array{columns: array<string, bool>, indexes: list<string>, foreign_keys: list<string>}>}
     */
    public static function take(string $connection): array
    {
        $schema = Schema::connection($connection);
        $tables = [];

        // On MySQL the schema reader lists the tables of every database the user may see, so it is told which one: only the tables of
        // the connected database count (otherwise the development database's tables would be mixed in).
        $database = DB::connection($connection)->getDatabaseName();
        $listed = DB::connection($connection)->getDriverName() === 'mysql' ? $schema->getTables($database) : $schema->getTables();

        foreach ($listed as $table) {
            $name = strtolower($table['name']);

            if ($name === 'migrations' || str_starts_with($name, 'sqlite_')) {
                continue;
            }

            $columns = [];
            foreach ($schema->getColumns($name) as $column) {
                $columns[strtolower($column['name'])] = (bool) $column['nullable'];
            }

            $indexes = [];
            foreach ($schema->getIndexes($name) as $index) {
                if ($index['primary']) {
                    continue;
                }

                $indexes[] = ($index['unique'] ? 'unique' : 'index').':'.strtolower(implode(',', $index['columns']));
            }

            $foreignKeys = [];
            foreach ($schema->getForeignKeys($name) as $foreignKey) {
                $foreignKeys[] = strtolower(implode(',', $foreignKey['columns']).' -> '.$foreignKey['foreign_table'].'('.implode(',', $foreignKey['foreign_columns']).') on delete '.self::action($foreignKey['on_delete']));
            }

            sort($indexes);
            sort($foreignKeys);
            ksort($columns);
            $tables[$name] = ['columns' => $columns, 'indexes' => array_values(array_unique($indexes)), 'foreign_keys' => $foreignKeys];
        }

        ksort($tables);

        return ['tables' => $tables];
    }

    /**
     * What differs between the schema the migrations give (`$expected`) and the one in the database (`$actual`). An empty list means the
     * database matches its migrations.
     *
     * - A table, column, unique key, index or foreign key that is expected but missing is a difference, and so is a column whose
     *   nullability differs, a table or column nobody asked for, and a foreign key with another delete rule.
     * - An extra plain index is a difference too, except the one MySQL adds by itself for a foreign key (same columns as the foreign
     *   key), which is allowed. An extra index is how a leftover from an experiment (for example the benchmark's temporary indexes)
     *   shows.
     *
     * @param  array{tables: array<string, mixed>}  $expected
     * @param  array{tables: array<string, mixed>}  $actual
     * @return list<string>
     */
    public static function differences(array $expected, array $actual): array
    {
        $found = [];

        foreach (array_diff_key($expected['tables'], $actual['tables']) as $table => $unused) {
            $found[] = "missing table: {$table}";
        }

        foreach (array_diff_key($actual['tables'], $expected['tables']) as $table => $unused) {
            $found[] = "unexpected table: {$table}";
        }

        foreach (array_intersect_key($expected['tables'], $actual['tables']) as $table => $want) {
            $have = $actual['tables'][$table];

            foreach (array_diff_key($want['columns'], $have['columns']) as $column => $unused) {
                $found[] = "{$table}: missing column {$column}";
            }

            foreach (array_diff_key($have['columns'], $want['columns']) as $column => $unused) {
                $found[] = "{$table}: unexpected column {$column}";
            }

            foreach (array_intersect_key($want['columns'], $have['columns']) as $column => $nullable) {
                if ($nullable !== $have['columns'][$column]) {
                    $found[] = "{$table}: column {$column} is ".($have['columns'][$column] ? 'nullable' : 'not nullable').' but should be '.($nullable ? 'nullable' : 'not nullable');
                }
            }

            foreach (array_diff($want['foreign_keys'], $have['foreign_keys']) as $foreignKey) {
                $found[] = "{$table}: missing foreign key {$foreignKey}";
            }

            foreach (array_diff($have['foreign_keys'], $want['foreign_keys']) as $foreignKey) {
                $found[] = "{$table}: unexpected foreign key {$foreignKey}";
            }

            // The index MySQL adds behind a foreign key has the same columns as the key.
            $impliedByForeignKeys = array_map(fn (string $foreignKey): string => 'index:'.explode(' -> ', $foreignKey)[0], $have['foreign_keys']);

            foreach (array_diff($want['indexes'], $have['indexes']) as $index) {
                $found[] = "{$table}: missing {$index}";
            }

            foreach (array_diff($have['indexes'], $want['indexes'], $impliedByForeignKeys) as $index) {
                $found[] = "{$table}: unexpected {$index}";
            }
        }

        return $found;
    }

    /** A short fixed-length fingerprint of the schema, to compare before and after a run. */
    public static function hash(array $snapshot): string
    {
        return substr(sha1(json_encode($snapshot, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /** The two engines word the same rule differently: no rule and NO ACTION both mean RESTRICT. */
    public static function action(?string $action): string
    {
        $action = strtolower((string) $action);

        return $action === '' || $action === 'no action' ? 'restrict' : $action;
    }
}
