# Phase 5 MySQL checks (step 5f.3): runbook and status

> **Status: the testing-database checks are DONE and PASSED; the production server check is NOT RUN.** The audit, the full MySQL suite and the audit again have run on `Thai_Visa_Services_Testing` (see "Results"). `db:check-server` still has to be run on the production or staging server, which does not exist yet ("Step 6").

## What was built

| Tool                                                 | What it does                                                                                                                                                                                                                                                                                                                        | Writes to a database?                                                                                                                                                                                                     |
| ---------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `TestingDatabaseIntegrityAuditMysqlTest`             | The deferred Phase 4 audit of `Thai_Visa_Services_Testing`: the real MySQL schema equals the schema the migration files create; the foreign keys of `job_order_workers` and an index leading with each of their columns are still there; no row points at a missing parent; and a fingerprint of the schema and the rows is printed | **No.** Only SELECT on MySQL. The migrations are run on a throw-away in-memory SQLite copy (`--database=migration_copy`) for the comparison.                                                                              |
| `php artisan db:check-server`                        | Reads the server settings the app depends on and judges them: isolation level, message language, storage engine, character set, strict SQL mode (plus notes: lock wait timeout, version, collation, time zone)                                                                                                                      | **No.** Only `select @@variable`.                                                                                                                                                                                         |
| The existing MySQL suite (`tests/Concurrency/Mysql`) | Locking, deadlock, retry and money-equivalence tests on a real MySQL server                                                                                                                                                                                                                                                         | **Yes, on the testing database only**: it commits test rows and deletes them by id. The benchmark file is skipped unless `JOB_SEARCH_BENCHMARK=1` is set, so it does not create its 40,000 rows or its temporary indexes. |

## Run it

1. **Audit, before** (read only):

    ```
    php artisan test -c phpunit.mysql.xml --filter=TestingDatabaseIntegrityAuditMysqlTest
    ```

    Keep the line `INTEGRITY AUDIT FINGERPRINT  schema=…  rows=…`.

2. **The full MySQL suite** (writes to the testing database only):

    ```
    php artisan test -c phpunit.mysql.xml
    ```

3. **Audit, after:** run step 1 again. If the two fingerprints are identical, the suite left nothing behind. If `rows=` differs, the "Tables with rows" list says where.

4. **Production or staging** (needs that server; read only):

    ```
    php artisan db:check-server
    ```

    It exits with an error and marks the row `FAIL` when the isolation level is not `REPEATABLE-READ` or the server does not answer in English (a lock-wait timeout is recognised by its English text). A `FAIL` means: do not go live until the locking tests have been repeated under the other setting, or the setting is changed.

## What a result means

- A schema difference is printed by name, for example `job_order_workers: unexpected index:job_order_id,client_id` (a leftover from an experiment) or `job_order_workers: missing foreign key client_id -> clients(id) on delete restrict`.
- The audit does not repair anything. A difference is reported to you; any repair is a separate, approved step.

## Already known

- `db:check-server` was run once, by mistake, against the development database connection while its output was being checked (it only reads server variables). It reported that the development server meets every assumption. That says nothing about production.

## Results

The testing database user is `zin_visa` (the same MySQL user as the development database; its grants cover `Thai_Visa_Services`, `Thai_Visa_Services_Testing` and an empty `Thai_Visa_Services_db`). The user and password come from the local `.env`; `phpunit.mysql.xml` forces the database name `Thai_Visa_Services_Testing` and the guards refuse any other.

| Run                                        | Date       | Result                                                                                                                                                                                                                                                                                                                                                                               |
| ------------------------------------------ | ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Integrity audit, first run                 | 2026-11-19 | **Drift found, stopped.** The schema comparison reported exactly one difference: `missing table: site_settings`. The testing database had 15 of the 16 migrations. Everything else matched; no orphans; every table empty. Fingerprint `schema=1bf5c61125f2fcce rows=7ca418b11fda74e0`.                                                                                              |
| Migration (approved by you)                | 2026-11-19 | `DB_DATABASE=Thai_Visa_Services_Testing php artisan migrate --database=mysql --force` applied exactly `2026_11_19_100000_create_site_settings_table` (batch 2). `migrations` now has 16 rows. `site_settings` has the 29 expected columns, 0 rows, and one foreign key `updated_by` to `users(id)` with `ON DELETE SET NULL`. The pretend output had been shown and confirmed first. |
| Integrity audit, before the suite          | 2026-11-19 | **PASS**, 5 of 5 tests. Fingerprint `schema=c27bf5608504c510 rows=cb8904422f0bdab2`; every table empty.                                                                                                                                                                                                                                                                              |
| Full MySQL suite                           | 2026-11-19 | **PASS**: 82 tests, 80 passed, 2 skipped, 642 assertions, 86.6 s, exit 0. The 2 skipped are the benchmark (it only runs with `JOB_SEARCH_BENCHMARK=1`, so its 40,000 rows and temporary indexes were not created) and one log-privacy test that is SQLite-only.                                                                                                                      |
| Integrity audit, after the suite           | 2026-11-19 | **PASS**, 5 of 5 tests. Fingerprint `schema=c27bf5608504c510 rows=cb8904422f0bdab2`: **identical** to the one before the suite. The suite left no row and no schema change behind.                                                                                                                                                                                                   |
| `db:check-server` on production or staging |            | NOT RUN (needs that server)                                                                                                                                                                                                                                                                                                                                                          |

Saved outputs are in the assistant's scratchpad folder (`.../scratchpad/mysql/`): `audit-before.txt`, `audit-before-v2.txt`, `migrate-pretend.txt`, `migrate-run.txt`, `verify-schema.txt`, `audit-after-migration.txt`, `full-suite.txt`, `audit-after-suite.txt`.

**An audit bug found and fixed on the way:** the first run reported 29 false "missing column" lines for `site_settings`, because Laravel's schema reader lists the tables of every database the MySQL user may see (including the development database, which has `site_settings`). The audit now asks only for the connected database's tables. Only table and column names were read from the other database; no data.

**Keeping the testing database current:** after every new migration, show the SQL and then apply it to the testing database only, with your approval: `DB_DATABASE=Thai_Visa_Services_Testing php artisan migrate --database=mysql --pretend`, then the same with `--force`.
