# Job Search – Approved Technical Decisions

**Status:** Authoritative for the approved technical constraints of Job Search.

- **Round 1 (2026-11-16):** restored from the product owner's consolidated decisions.
- **Round 2 (2026-11-16):** aligned with the updated business rules. There are no new technical decisions, only scope notes and newly identified unrecorded items.
- **Round 3 (2026-11-16):**
  - the latest retry policy: 3 attempts and an explicit scope
  - whole-service cancellation removed from the retry scope
  - verified Laravel retry behaviour
  - Person deletion and resolve-and-close transaction notes
- **Round 4 (2026-11-16):**
  - Person deletion is one database transaction, with file deletion after commit
  - whole-service cancellation and Person deletion use neither the retry helper nor the 5-second timeout
  - read-only code audit of locking and file deletion (§10)
  - recommended lock protocol, pending approval (§11)
- **Round 5 (2026-11-17):**
  - Laravel's **default concurrency detector approved** (§3.5)
  - lock-order **direction accepted**; the detailed protocol is pending (§11)
  - **Person deletion corrected:** no cascade (§5, §6.1)
  - new pending proposals: remaining balance = 0 (§12), Activity fields (§13), stale-state detection (§14), Person-deletion file recovery (§15)
- **Round 6 (2026-11-17):**
  - **remaining balance = 0** approved as **Option A, derived** (§12)
  - **Application Activity** excluded from V1 (§13; §8 U1 superseded)
  - the close **auto-cancels soft-deleted Persons'** Applied/Interview applications (§5, §11.3, §14)
  - the **profile photo** is in Person-deletion scope (§6.1)
  - lock-order audit refreshed (§11)
- **Round 7 (2026-11-17):** the close's auto-cancel of a deleted Person's Applied/Interview application removes that application's Job Order Money attribution. This needs no write, because attribution is computed. The Kept Amount is unchanged and no financial row is created (§5, §14, §16).

- **Round 9 (2026-11-17):** consequences of the new business rules (Hired → Cancelled, live Job Order Money, Unassigned Kept Amount), in §17:
  - Hired → Cancelled runs in the existing **single application status update** retry workflow, with its own validation path and a server-checked confirmation (§3.1, §17.1–§17.3, §17.13)
  - it works on a closed order and returns the ClientService to In Progress in the same transaction (§17.3, §17.4)
  - Job Order Money and Unassigned Kept Amount are **computed, not stored**; no schema change is proposed (§17.6–§17.11)
  - lock-protocol row and test scenarios added (§11.3, §16, §17.14)
  - implementation choices still pending review are marked in §17.15
- **Round 10 (2026-11-17):** the round 9 open items are decided (§17.15), and the implementation was started:
  - Hired → Cancelled refuses a soft-deleted Person and a Person with another In Progress Job Search engagement, and is individual only (§17.3, §17.13)
  - Unassigned Kept Amount is defined and derived, with a Business Dashboard figure (§17.9, §17.11)
  - the unique `(job_order_id, client_id)` key is removed from the create migration, with no replacement index; duplicate **active** applications are prevented by locks (§17.16)

The business behaviour these decisions support is in [`JOB_SEARCH_BUSINESS_RULES.md`](JOB_SEARCH_BUSINESS_RULES.md). HLD v0.9 was the last approved HLD baseline; HLD v1.0 is **not approved**.

These are approved decisions, not a record of what is built. Most of them are **not implemented yet** (see the business rules, Section 13).

---

## 1. Database and testing

- **MySQL** is the production target.
- **SQLite** may be used for ordinary tests. It **cannot** prove MySQL locking or concurrency behaviour.
- **Concurrency tests** run on MySQL with **independent connections**.
- **No Redis** for the MVP.
- **Testing database:** the user will create a separate MySQL database, `thai_visa_services_testing`, and grant the existing database user access to it.
- **Never** run destructive tests against the development or production database.

## 2. Engagement relationship

- `job_order_workers.client_service_id` has no unique constraint; its foreign key keeps an ordinary index.
- **Round 10:** `(job_order_id, client_id)` has no unique constraint either, and no replacement composite index (§17.16).
- No `live_service_id` backstop in V1.
- "At most one In Progress Job Search engagement per Person" rests on transaction boundaries and row locking. These concurrency assumptions must be written down wherever the workflow is implemented.

## 3. Retried workflows and the lock-wait timeout (latest policy, round 3)

**Superseded:** rounds 1–2 listed whole-service cancellation as a retried workflow. Round 3 removes it from the retry scope.

### 3.1 Retry scope

Exactly **four** workflows retry, through one shared helper:

1. Add Worker, including reassignment, and a fee change made inside Add Worker.
2. Single application status update, including Hired and, from round 9, **Hired → Cancelled** (§17.13). Hired → Cancelled is not part of bulk updates or the close (pending confirmation, §17.15).
3. Bulk application status update.
4. Job Order close, including the resolutions made in the close modal (business rules §4.1).

### 3.2 Not retried

- Whole-service cancellation.
- Person deletion.
- Payments.
- Fee changes as standalone operations, for example the Client page service edit.
- Existing non-Job-Search cancellation operations.
- Document and file operations.

### 3.3 Retry policy

- **Laravel transaction attempts: `3`**.
- **Retry only deadlocks and lock-wait timeouts.** Every other error fails at once.
- **No custom backoff or delay** is approved. Do not add one without approval.
- **After the attempts are exhausted,** the UI shows a friendly "Please try again" message in English and Burmese. Raw SQL or exception details are never shown.
- **Rollback** must prevent partial writes.
- **The retried transaction closure must be side-effect free**, because it may run up to three times:
  - no file operations
  - no success logging or reporting
  - no flash messages
  - no mutation of variables outside the closure

  Those belong after the transaction commits.

### 3.4 Lock-wait timeout

The four retried workflows use a **5-second session-level `innodb_lock_wait_timeout`**. The helper must:

1. Read and keep the connection's original session-level timeout.
2. Set the session timeout to 5 seconds.
3. Run the transaction with its retries.
4. Restore the original session value on **every** exit path: success, exception, and exhausted retries.

A connection must never be left with the modified timeout after the workflow ends.

The **document workflow is separate**. It does not use this helper or the 5-second timeout (Section 6).

**Whole-service cancellation and Person deletion** use neither the retry helper nor the 5-second timeout. They run with the connection's normal timeout (round 4). No other workflow changes the session timeout.

### 3.5 Concurrency-error detector (approved in round 5)

- **Use Laravel's default `ConcurrencyErrorDetector`.** Do not bind a custom detector unless testing proves the default cannot handle a required case.
- **No numeric error-code list is approved.** The policy is "deadlocks and lock-wait timeouts". §3.6 records how the default detector recognises MySQL 1213 and 1205.

### 3.6 Verified Laravel behaviour (facts, not decisions)

Verified on 2026-11-16 against the installed **Laravel Framework v13.39.0** source in `vendor/laravel/framework/src/Illuminate/Database/`.

**The retry loop** (`Concerns/ManagesTransactions.php`, `transaction(Closure $callback, $attempts = 1)`):

- It runs the closure up to `$attempts` times. The default is **1**, meaning no retry.
- **On an exception inside the closure** (`handleTransactionException`), Laravel rolls back:
  - It retries **only** when `causedByConcurrencyError($e)` is true and attempts remain.
  - Any other exception, or the last attempt, is rethrown.
- **On a commit failure** (`handleCommitTransactionException`), the same rule applies: retry only on a concurrency error with attempts remaining.
- **There is no sleep or backoff** between attempts. The retry is immediate.
- **Nested transactions:** if the concurrency error happens while the transaction is nested (`$this->transactions > 1`), Laravel does **not** retry. It throws `Illuminate\Database\DeadlockException` to the outer level. Retries therefore only work when the retried workflow's transaction is the **outermost** one.

**What counts as a concurrency error** (`ConcurrencyErrorDetector::causedByConcurrencyError`, the default unless the app binds its own `ConcurrencyErrorDetector` contract; this project binds none):

- A `PDOException` whose code is `40001`, as int or string. Laravel's `QueryException` extends `PDOException`, and its code is the SQLSTATE.
- **Or** a message containing one of the listed strings, including:
  - `Deadlock found when trying to get lock`, the MySQL deadlock (error 1213, SQLSTATE 40001)
  - `Lock wait timeout exceeded; try restarting transaction`, the MySQL lock-wait timeout (error 1205)

So with `attempts: 3`:

- **Deadlocks** are retried. They are detected by SQLSTATE 40001 and by the message.
- **Lock-wait timeouts** are retried too. They are detected **by message text only**, because MySQL 1205 does not use SQLSTATE 40001.
- MySQL error numbers are **not** checked directly. Detection depends on SQLSTATE and the English message text.
- The same detector also matches SQLite, PostgreSQL, SQL Server and Galera lock messages, for example `database is locked` and `deadlock detected`.

**MySQL 1213 and 1205, traced through the installed source** (round 5; source analysis, not a runtime test):

- **How the exception reaches the detector.** PDO raises a `PDOException`. Laravel wraps it in `QueryException`, which extends `PDOException`. Its constructor sets `$this->code = $previous->getCode()`, the 5-character SQLSTATE string, and builds the message from the driver message plus `(Connection: …, SQL: …)`. The detector's `Str::contains` is a substring test, so the extra text does not matter.
- **1213 (deadlock): retried.**
  - The driver message is `SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction`.
  - It is detected **twice over**: by code `'40001'` and by the message `Deadlock found when trying to get lock`.
  - InnoDB has already rolled back the whole transaction. Laravel's `rollBack()` then resets its counter, and the loop re-runs the closure.
- **1205 (lock-wait timeout): retried.**
  - The driver message is `SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction`.
  - The code is `'HY000'`, which does not match, so it is detected **by message only**.
  - With the default `innodb_rollback_on_timeout = OFF`, InnoDB rolls back only the statement. Laravel then calls `rollBack()` on the whole transaction before retrying, so no partial writes remain.
- **Caveats** (to be covered by MySQL tests, §16):
  1. Detection of 1205 depends on the **English** server message. If the MySQL server's `lc_messages` is not English, 1205 would not be detected.
  2. A 1213 or 1205 raised while the transaction is **nested** is not retried (`DeadlockException`).
  3. A 1205 at commit time is handled by `handleCommitTransactionException` with the same detector.
- **Verdict:** in Laravel v13.39.0, the default detector retries **both** MySQL 1213 and 1205 when the retried transaction is outermost and the server messages are English. This is **not yet verified on a running MySQL server**.

## 4. Fee change during Add Worker

- When the fee changes during Add Worker or reassignment, Add Worker may call the existing `ClientService::refundForFee()`.
- Duplicating the small Refund-remark logic inside Add Worker is acceptable. Do not add an abstraction just for it.
- Financial records must stay accurate. Calculate each refund once, never twice.

## 5. Lock ordering and concurrency

- **Add Worker** locks the JobOrder and the relevant Person/Client records in a consistent order.
- **Person deletion** locks the Person first, per the approved lock-order decision.
  - **Round 5–6:** it is one database transaction that **only** soft-deletes the Person and clears their document and photo references. The remaining balance becomes 0 by derivation (§12, Option A), so no service row is written.
  - It cancels **no** service and changes **no** application status, Job Order, fee, payment, refund or remark. The round-4 cascade text is revoked.
  - Any failure rolls the whole database side back.
  - It is **not retried** and does not use the 5-second timeout (§3.2, §3.4).
  - Its physical file deletion happens **only after commit** (§6.1, §15).
- **Resolve-and-close** is one transaction covering:
  - the **automatic cancellation** of soft-deleted Persons' Applied/Interview applications on the order (round 6)
  - the admin's resolutions for non-deleted Persons
  - the Hired consequences
  - the order close

  No activity records are written (round 6).

  **Money (round 7):** the auto-cancel is only an application status update (`case_status` → cancelled). Job Order Money attribution is **computed** from application and engagement states (business rules §6.3). So once the row is Cancelled, rule 6.3.2 removes its attribution from the order with **no** extra write. Nothing is written to `client_services`, `payments` or `client_service_remarks`, so Kept is unchanged and no payment, refund, write-off or adjustment is created.

  It is retried as a whole (§3.1). A retry reruns the entire closure.
- **Hired → Cancelled (round 9)** is one transaction that changes the application and its ClientService together. It locks the Person and all of their Job Search engagements before any application row (§11.3, §17.3). It does not lock or change the Job Order row's data.
- **Bulk Hired** locks all affected ClientServices in **ascending ID order**.
- Keep lock ordering consistent across workflows to reduce deadlocks.
- Never claim concurrency safety because SQLite tests pass.
- **Data integrity, round 2:** under the strict V1 rules, an Applied or Interview application under a Complete or Cancelled ClientService should be impossible (business rules §11.4).
  - Treat such a record as a data-integrity finding for audits.
  - Do not build an anomaly workflow or any automatic repair.
  - The existing rule still applies: an In Progress engagement with no application rows refuses Add Worker and is logged.

## 6. Document workflow

- Document operations are separate from the four retried workflows. They are not retried and do not use the 5-second helper.
- **Database/storage consistency risk:** a document operation spans a database transaction and an external file-storage operation (DigitalOcean Spaces in production). The two can end up inconsistent, for example:
  - a file uploaded but its row not saved
  - a row deleted but its file not removed
  - a file replaced while the transaction rolls back
- **Orphaned files:** V1 has **no** automatic orphan cleanup, and orphaned files are not harmless. Do not describe the problem as handled, and do not build a cleanup mechanism unless it is separately approved.

### 6.1 Person deletion and external files (corrected in round 5)

1. **Before or inside the database transaction:** identify the Person's files.
   - document storage keys, through `documents.client_service_id → client_services.client_id`
   - the profile photo path (`clients.photo_path`), which is **in scope** (round 6)
2. **In the transaction:** soft-delete the Person and clear the photo path. Remove or mark the document rows according to the recovery design once approved (§15). The remaining balance becomes 0 by derivation (§12), with **no** service, application or money changes.
3. **Commit.**
4. **After commit only,** delete the physical files.
5. **If a deletion fails,** enough information must remain to retry or recover (§15).

Constraints:

- File deletion is **not** part of the MySQL atomic transaction.
- There is no generic orphan-file cleanup.
- The recovery design in §15 is a proposal and is **not approved**. No queue, table, retry job or cleanup mechanism is decided.

## 7. Lock diagnostics

- To diagnose MySQL lock waits, prefer `performance_schema.data_lock_waits`.
- If the environment lacks permission to read it, rely on genuine evidence of blocking and waiting.
- Never claim a lock wait or concurrency problem without evidence.

## 8. HLD v1.0 review items (to carry into any corrected HLD)

HLD v1.0 was reviewed and **not approved**. Any corrected HLD must reflect these items, and its approval is a separate step:

| Item | Correction |
|---|---|
| ~~**U1** Whole-service cancellation activity~~ | **Superseded in round 6:** Application Activity is excluded from V1, so no `cancelled_with_service` records are written. |
| **U2** Document workflow timeout | The document workflow does not use the four-workflow 5-second helper. State the document/storage consistency risks honestly. |
| **U3** Orphaned files | Do not call orphaned files harmless or solved in V1. State the actual risk. Invent no unapproved cleanup feature. |
| **U4** MySQL lock diagnostics | Prefer `performance_schema.data_lock_waits`. If it is unavailable, require genuine blocking evidence. |
| ~~Application Activity~~ | **Superseded in round 6:** excluded from V1. |
| Complete documents | Remove any text saying documents of a Complete Job Search engagement are read-only. Complete allows full admin Documents CRUD; Cancelled stays read-only and downloadable. |

## 9. Not recovered (needs confirmation)

- **Detailed lock protocol:** the direction is accepted (round 5). The detailed per-workflow protocol in §11 is **pending approval**.
- **Recovery for failed post-commit file deletion:** §15, pending.
- ~~Concurrency-error detector~~ **Resolved in round 5:** Laravel default (§3.5).
- ~~Retry policy details~~ **Resolved in round 3** (§3): 3 attempts, deadlocks and lock-wait timeouts only, no custom backoff. The numeric-code mapping is deliberately not a decision; verified behaviour is in §3.6.
- **`CLAUDE.md` model-selection requirement:** referenced by the product owner, but no `CLAUDE.md` exists in this repository or the user's home configuration on this laptop. `~/.claude/settings.json` holds only a theme setting. Its content could not be recovered. A reviewable proposal is in `docs/CLAUDE_MD_PROPOSAL.md`; it is not active.

## 10. Read-only code audit: current locking and file behaviour (verified facts)

> **Historical audit.** Sections 10.1–10.6 describe the code at commit `b7730dd` (round 4–6 audit). Since then Add Worker, the status updates, the close, the Job Order edit lock and Hired → Cancelled were built (see business rules §13), so their rows in §10.1, §10.2 and race 3 of §10.6 no longer describe today's code. The Person-deletion, Add Service, document and financial-model findings are still current.

Audited on 2026-11-16 at commit `b7730dd` by reading the code. No code was run against a database. These are facts about **today's code**, not decisions.

**Environment notes:**

- `config/database.php` sets no transaction isolation level. On MySQL this means the server default, normally **REPEATABLE READ**. The production server setting was not checked.
- Tests run on in-memory SQLite (`phpunit.xml`), which takes database-level locks, not row locks.

**10.1 Explicit row locks in the application.** `lockForUpdate` appears only in these places:

| Workflow | Code | Rows locked, in order |
|---|---|---|
| Add Worker | `App\Actions\JobSearch\AddWorker::handle` | 1. `job_orders` row (FOR UPDATE). 2. Existing person: `clients` row (FOR UPDATE). A new person is INSERTed instead. |
| Job Order close | `JobOrder::close` | `job_orders` row only |
| Payment | `PaymentController::store` | `client_services` row only |
| Service edit / fee change | `ClientServiceController::update` | `client_services` row only |
| Service cancel (generic) | `ClientServiceController::cancel` | `client_services` row only |
| Document file replace | `Document::replaceFile` | `documents` row only |

There is **no** application status update, bulk update, resolve-and-close, Job Search whole-service cascade, or Person-deletion cancellation in the code yet. So there is no current lock order for them.

**10.2 Add Worker today**, in order:

1. Lock the `job_orders` row.
2. Read the Job Search `services` row (no lock).
3. Lock the existing `clients` row, or INSERT a new client. The insert takes unique-index locks on `unique_token` and the `active_phone` generated column.
4. `exists()` check on `job_order_workers` (plain read).
5. INSERT `client_services`. The FK check takes shared locks on the `clients` row and the Job Search `services` row.
6. `JobOrderWorker::saving` re-reads the ClientService (plain read).
7. INSERT `job_order_workers`. The FK checks take shared locks on the `job_orders`, `clients` and `client_services` parents. No unique key on `(job_order_id, client_id)` or `client_service_id` is checked: both were removed, so a duplicate active application is prevented by the locks (§17.16).

**Notes:**

- Two concurrent "new person" adds with the same phone collide on the `active_phone` unique index. The loser gets a duplicate-key error (SQLSTATE 23000). Laravel does not treat that as a concurrency error, so it is not retried. Today it is not caught either: it surfaces as a server error.

**10.3 Implicit locks from foreign keys (InnoDB behaviour).**

- **Inserting a child row** takes a shared lock on each referenced parent row. That applies to `payments`, `client_service_remarks` and `documents` (parent `client_services`), `client_services` (parents `clients` and `services`), and `job_order_workers` (three parents).
- **Updating a child row** checks parents only when an FK column changes. Changing `case_status` or `status` alone does not.
- **Deleting a `documents` row** fires `ON DELETE SET NULL` on `document_access_logs.document_id`, which updates and locks those log rows.
- **`client_pin_attempts`** cascades on a hard delete of a client, but clients are only soft-deleted (an UPDATE), so it never fires in practice.
- **Every other FK is RESTRICT,** so no other cascade fires.

**10.4 Soft-delete scopes.**

- `Client` uses `SoftDeletes`, so route binding and default queries skip deleted Persons. A `lockForUpdate()->findOrFail()` on a Person deleted by a concurrent, committed transaction returns 404.
- `JobOrderWorker::client()` and `ClientService::client()` use `withTrashed()`. `JobOrderWorker::operational()` filters deleted Persons with `whereHas('client', withoutTrashed)`.

**10.5 Person deletion today** (`ClientController::destroy` → `DB::transaction(fn () => $client->delete())` → `Client::deleting` hook):

1. **Locks:** no explicit lock on the Person.
2. **Photo** (`erasePhoto()`, only if the Person has a photo): `UPDATE clients SET photo_path = NULL` (saveQuietly). This takes an X lock on the Person row. The file is deleted in `DB::afterCommit`, and the result is **not checked or reported**.
3. **Documents** (`eraseDocuments()`): finds every document of every service of the Person with a plain read. For each one, `Document::erase()` runs a nested transaction (savepoint) that DELETEs the row; the access logs are SET NULL and keep copies of the title and client id. The file delete is queued with `DB::afterCommit`. A failure is detected and `report()`ed with the storage key, but the key then survives **only in the error log**.
4. **Soft delete:** `UPDATE clients SET deleted_at`, an X lock on the Person row.
5. **Services and applications:** not touched. No service is cancelled and no `job_order_workers` row changes.

**Effective lock order today:** Person (only if a photo exists) → documents → access logs → Person. So the order depends on whether a photo exists.

Already correct:

- Files are deleted only after the outermost transaction commits. `DB::afterCommit` waits for the outermost commit.
- A rollback keeps the files.

**10.6 Races found in today's code** (risks; none fixed):

1. **Document upload during Person deletion.**
   - `Admin\DocumentController::store` uploads the file and then INSERTs the row, with no transaction or lock.
   - If it runs after `eraseDocuments()` has listed the documents but before the deletion commits, the new document survives on a deleted Person.
   - The INSERT's shared FK lock is on `client_services`, which the deletion does not lock.
2. **Add Service to Client during Person deletion.** `ClientServiceController::store` INSERTs a service with no lock on the Person. It can add an In Progress service to a Person who is being deleted, and the deletion would not cancel it.
3. **Job Order edit during close.**
   - `JobOrderController::update` checks the policy (order is Open) before the write and does not lock the row.
   - An edit that passed the check can still write after a concurrent close has committed.
4. **Payment during Person deletion.** Not a race today: the payment locks the ClientService and re-checks its status. But today's deletion does not cancel services, so a payment can still land on a deleted Person's service.

**10.7 Financial model: remaining balance and Outstanding** (round 5).

- **Remaining balance is derived, never stored.**
  - `ClientService::remaining()` returns `0` for a Cancel service, and otherwise `max(0, fee − received)`.
  - `received` is the sum of the `payments` rows.
  - The refund columns (`refund_amount`, `cancel_refund_amount`) are not subtracted from remaining. They only reduce `kept()`.
- **Also derived:**
  - `paymentStatus()`: Unpaid, Deposit or Fully Paid, from received vs fee
  - `canTakePayment()`: not cancelled and remaining > 0
  - `unlocksDocuments()`
- **Outstanding** (`BusinessReport::outstanding`) is a **separate SQL calculation**, not a call to `remaining()`. It sums `fee × 100 − paid satang` over client services that are not cancelled, **joined to `clients` with `whereNull('clients.deleted_at')`**, and drops negatives. So **Outstanding already excludes deleted Persons today**.
- **Kept Amount** (`ClientService::kept()`) is `received − refund_amount − cancel_refund_amount`. It does not use remaining. `ServiceStatistics` uses the same formula in SQL.
- **Job Order Money** is not built yet. It is approved to use kept (business rules §6.3), so it does not depend on remaining.
- **Where `remaining()` is read today:**
  - Client page totals and `ClientServiceResource` (Client page; deleted Persons cannot be opened, because route binding excludes them)
  - `ClientPortalServiceResource` (the portal; the token route binding excludes deleted Persons)
  - `JobOrderWorkerResource` (the worker list; `operational()` excludes deleted Persons)
  - `PaymentController` (route binding excludes deleted Persons)
- **No write-off, adjustment or exclusion column exists.** The only "exclusion" is the `deleted_at` filter in Outstanding and the soft-delete scopes.

**10.8 File storage** (round 5).

- **Disks:**
  - Documents and photos use the `private` disk (`Document::DISK`, `Client::PHOTO_DISK`). Its driver is `env('STORAGE_DRIVER', 'local')`: the local disk under `storage/app/private` in development, and S3-compatible DigitalOcean Spaces (`DO_SPACES_*`) in production.
  - The disk has `'throw' => false`, so `delete()` returns `false` instead of throwing.
- **Paths:**
  - Documents: `documents/{uuid}.{ext}`, stored as `documents.storage_key`.
  - Photos: `client-photos/{uuid}.jpg`, stored as `clients.photo_path`.
  - Every upload and replace generates a **new random UUID key**. No code path copies a key to another record.
  - `storage_key` has **no unique constraint**, so sharing is prevented by code, not by the database.
- **Ownership:** a document belongs to a client service (`documents.client_service_id`), which belongs to a Person (`client_services.client_id`). All of a Person's document keys can be found reliably with one query before deletion, which `Client::eraseDocuments` does today. The photo key is on the Person row.
- **Duplicate deletion attempts:**
  - Deleting an already-missing object succeeds on S3/Spaces, and Flysystem's local adapter ignores a missing file. So a repeated delete is harmless (idempotent).
  - `Document::removeFileAfterCommit` treats "`delete()` returned false **or** the file still exists" as a failure and `report()`s it.
- **Today's recovery information:**
  - Documents: the document **row is hard-deleted** in the transaction, so after a failed file delete the key survives **only in the error log**.
  - Photos: `erasePhoto` does not check the result at all.
  - The access logs keep the document title and client id, but not the key.

**10.9 Worker-name link** (round 5).

- `resources/js/components/JobOrderWorkers.vue` renders `{{ worker.name }}` as **plain text**, not a link.
- `JobOrderWorkerResource` already sends `client_id`.
- The approved target route exists: `admin.clients.show` (`/admin/clients/{client}`, from `Route::resource('clients', …)`).
- `/admin/workers/{id}` (in HLD v0.8) has **no route**.
- **Mismatch:** a link to `admin.clients.show` still has to be added.

**10.10 Test coverage for retries and concurrency** (round 5).

- No test or application code uses `attempts`, `DeadlockException`, `40001`, `lock_wait` or `innodb_lock_wait_timeout`.
- Concurrency is only *simulated* on a single SQLite connection: tests in which the order was closed "a moment ago", in `JobOrderControllerTest` and `JobOrderWorkerTest`.
- No MySQL test database is configured: `phpunit.xml` uses in-memory SQLite.
- Tests to add later are listed in §16.

**10.11 Other workflows that lock or write these rows** (round 5).

| Workflow | Lock behaviour |
| --- | --- |
| `ServiceController::update` / `destroy` | Plain UPDATE (X lock) on a `services` row. This briefly conflicts with Add Worker's implicit shared lock on the Job Search `services` row. |
| `ClientController::update` (profile edit) | Plain UPDATE on the Person row, no explicit lock |
| `ClientPhotoController` (photo replace or delete) | Photo replaced or deleted outside a transaction |
| `Document` rename / release (`DocumentController::update`) | Plain UPDATE on the document row, no lock |
| PIN checks (`TrackingPinGuard`) | Use a **cache** lock, not database rows |

## 11. Lock protocol: target order accepted in principle (rounds 5–6); detailed protocol **pending approval**

**Accepted target order (in principle):**

1. JobOrders, ascending id
2. Persons, ascending id
3. ClientServices, ascending id
4. JobOrderWorkers, ascending id
5. Dependent financial rows, in a consistent order

**What "accepted in principle" means:**

- It is the target design, **not** permission to change every workflow blindly.
- The per-workflow protocol below, with its documented exceptions, comes from the code audit (§10, re-checked in round 6 at `b7730dd` with no code changes since). It is **not approved** until reviewed.
- The protocol is **not final.** No deadlock cycle was found in the target design, but the open items in §11.7 remain.

### 11.1 Levels

| Level | Rows | Rule |
| --- | --- | --- |
| L1 | `job_orders` | Ascending id; acquired only at the **start** of a workflow, never after an L2–L5 lock |
| L2 | `clients` (Persons) | Ascending id |
| L3 | `client_services` | Ascending id |
| L4 | `job_order_workers` | Ascending id |
| L5 | Dependent rows | INSERT `payments`, `client_service_remarks` and `documents`; DELETE `documents`, which sets null on `document_access_logs`. Within L5: remarks and payments, then document rows; each set in ascending id where several are touched. There are no Activity rows (round 6). |

### 11.2 Rules

- **Discover, then lock, then re-validate.**
  - Find the ids with plain reads, lock level by level in ascending id, then **re-read with locking reads** before deciding.
  - Under REPEATABLE READ, plain reads after the first consistent read see an old snapshot.
- **Never lock an L4 row before its L3 row.**
- **Never take an X lock on a `services` catalogue row inside these workflows.** Child INSERTs take shared locks on it.
- **Single-service workflows** (payment, standalone fee change, generic cancel, remark) may start at L3. That is consistent with the order because they never take an L1 or L2 lock afterwards.
- **The order holds within each transaction.** Re-locking a row already held is harmless.

### 11.3 Per-workflow audit: current vs target

"Current" is verified code at `b7730dd`. "Target" is the recommendation.

| Workflow | Current (explicit / implicit) | Target sequence | Conflict with direction? |
| --- | --- | --- | --- |
| **Add Worker** | Explicit: JobOrder FOR UPDATE → existing Person FOR UPDATE. Implicit: INSERT client (unique-index locks), INSERT client_service (S on Person and the `services` row), INSERT worker (S on JobOrder, Person, client_service). | L1 order → L2 Person (or INSERT) → L3 the Person's In Progress Job Search engagement FOR UPDATE (detects more than one) → L4 **all** its rows FOR UPDATE, ascending → INSERT the new row, plus a refund remark (L5) if the fee changes. **Round 10:** after the Person lock and before the insert, check that the Person has no **active** (Applied, Interview or Hired) application on this order | None. Today's order L1 → L2 already matches. |
| **Reassignment** | Not built | Same as Add Worker. The previous Job Order is **not** locked, because attribution is computed. | — |
| **Single status update** | Not built at `b7730dd` (built later in Phase 4) | L1 order (must be Open) → L2 Person → L3 engagement → L4 row → UPDATE (L3 too when Hired) | — |
| **Hired → Cancelled** (round 9; **pending approval**) | Not built | L1 order (**no Open check**; locked only so the order of locking matches the other workflows) → L2 Person → L3 **all** the Person's Job Search engagements, ascending → L4 the engagement's rows, ascending → re-validate (§17.3) → UPDATE row → Cancelled and UPDATE engagement → In Progress | None. L1 → L2 → L3 → L4 holds. It conflicts with no other workflow, because the Person lock serialises it with Add Worker for the same Person (§11.4). |
| **Bulk status update / bulk Hired** | Not built | L1 order → L2 Persons ascending → L3 engagements ascending → L4 rows ascending → UPDATEs | — |
| **Resolve-and-close** | Only the JobOrder FOR UPDATE (`JobOrder::close`) | L1 order → L2 Persons of **all** the order's Applied/Interview rows, **including soft-deleted Persons** (locked `withTrashed`), ascending → L3 their engagements ascending → L4 **all** the order's rows ascending → re-validate (§14) → auto-cancel the deleted Persons' open rows; apply the admin's resolutions (L4 updates; L3 updates for Hired) → UPDATE order | None. The close must not lock L4 before L3. Deleted Persons must be locked too, or a concurrent deletion could re-classify a row between the read and the update. |
| **Whole-service cancellation** | Explicit: client_service FOR UPDATE. Implicit: INSERT remark (S on client_service). No cascade. | L2 Person → L3 engagement → L4 **all** its rows across orders, ascending (**no L1**) → L5 cancel remark | **Documented exception:** it skips L1. It must change rows on closed orders without the freeze check, and never needs a JobOrder lock, so skipping L1 avoids an L1-after-L2 inversion. |
| **Person deletion** | No explicit lock. Implicit order: UPDATE Person (photo, only if one exists) → DELETE documents, cascading SET NULL on access logs → UPDATE Person (deleted_at). | L2 Person FOR UPDATE → L5 document rows (delete or mark, ascending) → UPDATE Person (photo path, deleted_at). **No L1, L3 or L4** writes: Option A writes nothing to the services (§12). | **Documented exception:** it skips L1, L3 and L4, because it changes no Job Order, service or application. Document rows are reached through the Person's services with a plain read, safe because the Person lock blocks new uploads (race 1). |
| **Payment** | Explicit: client_service FOR UPDATE. Implicit: INSERT payment (S on client_service and the user row). | Unchanged (starts at L3) | None |
| **Standalone fee change** | Explicit: client_service FOR UPDATE → INSERT remarks | Unchanged | None |
| **Generic cancel (non-Job-Search)** | Explicit: client_service FOR UPDATE → INSERT remark | Unchanged | None |
| **Add Service to Client** | No lock. INSERT client_service (S on Person). | L2 Person FOR UPDATE, re-check not deleted → INSERT | Missing the L2 lock (race 2) |
| **Document upload** | No transaction, no lock. File stored first, then INSERT (S on client_service). | L2 Person FOR SHARE or UPDATE (re-check not deleted) → L3 client_service (re-check status rules) → INSERT. File stored **before** the transaction and removed if it fails (existing pattern). | Missing L2/L3 (race 1) |
| **Document replace** | Explicit: document FOR UPDATE | L2 → L3 (re-check) → L5 document FOR UPDATE | Missing L2/L3 checks |
| **Document rename / release / delete** | No lock (rename, release); delete in its own transaction | L2 → L3 (re-check) → L5 | Missing L2/L3 checks |
| **Job Order edit** | Policy check before the write; plain UPDATE, no lock | L1 FOR UPDATE → re-check Open → UPDATE | Missing L1 lock (race 3) |

### 11.4 Deadlock analysis of the target protocol

- **Add Worker vs Person deletion.**
  - Add Worker holds L1 and waits for L2. Deletion starts at L2 and never takes L1, so there is no cycle.
  - If deletion commits first, Add Worker's `FOR UPDATE` on the Person returns not-found (soft-delete scope), and the add is refused.
- **Close vs status updates vs Add Worker.** All three take the same L1 row first, so they are serialised.
- **Close vs whole-service cancellation.**
  - Both go L2 → L3 → L4 in ascending order. The cancellation skips L1, but no workflow takes L1 after L2, so there is no cycle.
  - A close that waited may then find rows Cancelled, which is caught by the stale-state check (§14).
- **Close vs Person deletion (round 6).**
  - The close holds L1 and locks the Persons (L2). Deletion starts at L2 and never takes L1, so there is no cycle.
  - If a deletion commits while the close waits, that Person's open row moves from "admin resolves" to "auto-cancel". The stale-state check sees the change and aborts (§14), so the admin's choice for that row is not applied silently.
- **Hired → Cancelled vs Add Worker for the same Person (round 9).** Both lock the Person (L2) before touching an engagement, so they run one after the other.
  - If Add Worker commits first, it may have created a second In Progress engagement, and the cancellation refuses (§17.3).
  - If the cancellation commits first, the engagement is In Progress with every row Cancelled, and Add Worker reuses it as a reassignment.
  - They lock different L1 rows (two orders) in the same direction, so there is no cycle.
- **Hired → Cancelled vs close, whole-service cancellation, payment (round 9).** The close and the whole-service cancellation go L2 → L3 → L4 in ascending order, as the cancellation does. A payment locks one L3 row, which the cancellation also locks, so they are serialised. A waiting workflow re-reads after the lock and finds the changed state (stale-state rules, §14, §17.3).
- **Bulk Hired and close** lock several L3 rows in ascending id, as approved.
- **Implicit FK shared locks** are always on parents already held in X mode by the same transaction, or on catalogue and user rows that are never X-locked inside these workflows.
- **Unique-index insert races** can still raise duplicate-key errors, and rarely InnoDB deadlocks among three or more inserters (race 4).

### 11.5 Race findings (current code) and recommended protection

| # | Race | Current behaviour | Risk | Recommended protection |
| --- | --- | --- | --- | --- |
| 1 | Document upload + Person deletion | The upload stores the file, then INSERTs the row, with no transaction or lock. Deletion lists documents with a plain read. | An upload landing after the list but before commit leaves a **live document (and file) on a deleted Person** (privacy). An upload just after commit is still accepted if route binding ran before the commit. | Upload in a transaction that locks the Person (L2) and re-checks `deleted_at`, then locks the client service (L3) and re-checks its status. On refusal, delete the just-stored file (existing pattern). Deletion locks the Person first. |
| 2 | Add Service + Person deletion | `ClientServiceController::store` INSERTs with no lock. | A **new service on a deleted Person**, including a first payment if `received_now` was given. Under Option A its remaining balance would show 0, but it would be a live record created after the deletion. | Lock the Person FOR UPDATE and re-check not deleted inside the store transaction (L2 → INSERT). |
| 3 | Job Order edit + close | The policy check (`isOpen`) happens in the FormRequest before the write; the UPDATE has no lock. | An edit can commit **after** the close, so a closed order changes. | Lock the order (L1) inside a transaction, re-check Open, then UPDATE. |
| 4 | Concurrent duplicate phone creation | Two new Persons with the same phone (Add Worker or Create Client): both pass validation; the second INSERT hits the `active_phone` unique index. | The `UniqueConstraintViolationException` (SQLSTATE 23000, not retried) surfaces as a **server error**. | Catch `Illuminate\Database\UniqueConstraintViolationException` around the INSERT and return the normal "phone already belongs to an active client" validation error. A retry would not help. |

### 11.6 Code changes this would require later (not done)

- Person deletion: an explicit Person lock first. Then Option A in `remaining()` and `canTakePayment()` (§12). The photo is already deleted; a photo-deletion failure still needs reporting.
- Locks in Add Service, document workflows and Job Order edit, as in §11.5.
- Duplicate-phone handling (§11.5, race 4).
- The four retried workflows built on the §3 helper, as the **outermost** transaction.
- Stale-state detection in resolve-and-close (§14).
- Person-deletion file recovery, once a design is approved (§15).
- Reporting of failed photo deletions.

### 11.7 Unresolved risks

- None of this can be proven on SQLite.
- The production MySQL isolation level and `lc_messages` setting are unverified.
- Post-commit file deletion runs inside the web request.
- **Locking soft-deleted Persons:** `Client::query()` hides them, so the close must lock with `withTrashed()`. Code must not rely on the default scope here.
- **Still to approve:**
  - the per-workflow sequences in §11.3
  - the three documented exceptions: whole-service cancellation skips L1; Person deletion skips L1, L3 and L4; single-service workflows start at L3
  - the Hired → Cancelled row of §11.3 (round 9), including locking the order without an Open check
  - the L2 `FOR SHARE` vs `FOR UPDATE` choice for document workflows

## 12. Remaining balance = 0 on Person deletion: **Option A, derived (approved in round 6)**

**Approved:**

- The remaining balance is **derived, not stored**. It has no write-off amount, no write-off transaction and no adjustment ledger.
- A soft-deleted Person's services have a derived remaining balance of **0**, and **Outstanding excludes** the Person.
- Payments, refunds, fees, Kept Amount, Job Order Money and financial history are unchanged. Historical totals keep including deleted Persons. Operational counts exclude them.

**How it fits the existing architecture** (verified in §10.7; implementation not done):

- **`ClientService::remaining()`** already derives `max(0, fee − received)`, and `0` for Cancel. Option A adds one more condition: **0 when the service's Person is soft-deleted**. `canTakePayment()` follows from remaining.
- **`BusinessReport::outstanding`** already excludes soft-deleted Persons (`whereNull('clients.deleted_at')`). No change is needed there.
- **`kept()`, `ServiceStatistics` and Job Order Money** do not use remaining, so they are unaffected. There is no double subtraction.
- **Person deletion writes no service row.** The soft delete itself is the atomic act.

**Rejected (record only):**

- **B.** A stored write-off column.
- **C.** An adjustments ledger.
- Lowering the fee.
- Fake payments or refunds.

**Technical details to settle at implementation** (not new business rules):

- `remaining()` needs the Person's `deleted_at`. Lists must eager-load or join the Person to avoid N+1 queries.
- `paymentStatus()` is derived from received vs fee and is not changed by Option A. A partly paid deleted Person's service would still compute Deposit. That only appears where deleted Persons are already excluded.

## 13. Application Activity: **excluded from V1 (round 6)**

**Superseded:** the round-5 approved core fields (acting admin id, action, old and new status, JobOrderWorker id, timestamp) and all proposed extras (`operation_id`, `job_order_id`, `related_job_order_worker_id`, action values) are **withdrawn**.

For V1:

- no Activity table, model, records or UI
- no activity-related columns
- no replacement audit-logging feature

**Current code:** no Activity model, migration or table exists, which is consistent.

*Out of scope for V1 / future consideration.*

## 14. Resolve-and-close stale-state detection (behaviour approved; implementation **pending approval**)

**Current code:** `JobOrder::close()` locks the order and re-checks only `isClosed()`. There is no resolution data, so nothing else can be stale today.

**Proposed implementation:**

1. **The page renders the expected state.** It sends back:
   - the order status
   - the exact set of **non-deleted** Persons' Applied/Interview application ids with their statuses, which the admin resolves
   - the exact set of **soft-deleted** Persons' Applied/Interview application ids, which will be auto-cancelled
   - each row's engagement status
2. **The server locks first.** In the retried transaction it locks the rows per §11.3, then re-reads with locking reads:
   - the order (must still be Open)
   - **all** the order's application rows: the current set of Applied/Interview ids and statuses, split by whether the Person is soft-deleted (Persons read with `withTrashed()`)
   - their engagements' statuses
3. **It compares exact sets.**
   - Any difference aborts the transaction. That includes a missing or extra id, a changed status, a changed engagement status, a Person deleted since the page was shown (so a row moves set), or an order that is no longer Open.
   - The abort uses a dedicated domain exception, so it is **not** retried (not a concurrency error), everything rolls back, and the UI shows a friendly "The data changed. Please review and try again" in English and Burmese.
4. **Compare ids and statuses, not `updated_at`.** `updated_at` has one-second precision and is not touched by every relevant change. A version column is an option but not needed.
5. **The detection is reliable** under the §11 lock protocol, because every workflow that can change those rows takes conflicting locks first.
6. **If unchanged:** the same transaction auto-cancels the soft-deleted set, applies the admin's resolutions with the Hired consequences, and closes the order. The auto-cancel removes those applications' Job Order Money attribution by computation and writes no financial row (round 7).

## 15. Person-deletion file deletion and recovery: **PENDING TECHNICAL DECISION — NOT APPROVED FOR IMPLEMENTATION**

**Approved:** files (documents, PDFs and the profile photo) are deleted only after the database commit, and enough information must remain to retry or recover.

**Not approved:** the mechanism. No queue, table, retry job or cleanup mechanism is decided. The options below are proposals for review only.

**Today:** document rows are hard-deleted in the transaction, and files are deleted after commit (`DB::afterCommit`).

- A failed document deletion is `report()`ed with its key, which survives only in the log.
- A photo failure is not checked.
- If the process dies after commit, nothing records the pending files.

**Option 1 (for discussion)**, scoped to Person deletion only and not an orphan scanner:

1. **Record the files.** Add a `person_file_deletions` table (client_id, disk, path, kind document/photo, attempts, last_error, completed_at). In the **same** deletion transaction, insert one row per file of the Person. These are the exact keys known from the database, so nothing is scanned or guessed.
2. **Delete after commit.** For each pending row: delete the object, verify it is gone (`exists()` is false), then set `completed_at`.
3. **Retry.** On failure, record the attempt and the error. Retries come from a queued job or an artisan command that processes only pending rows. Because deletes are idempotent, re-running is safe and duplicate attempts are harmless.
4. **Integrity.** Document rows can still be hard-deleted in the transaction, because the paths live in the deletion table. Optionally, the table could have a guard against keys shared with live documents; no code path shares keys today.

**Option 2 (for discussion, minimal):** keep today's behaviour, add a report for photo failures, and accept the error log as the V1 recovery source. This is weaker, because logs rotate and are unstructured.

**No option is selected** (round 8). These details remain undecided:

- a table or a queue
- the record structure and fields
- which metadata is stored (keys, file types, person ids)
- the retry mechanism (queued job, Artisan command or other)
- retry frequency and maximum attempts
- failure retention and monitoring

Nothing here may be built without approval.

## 16. Tests to add later (not written in this task)

- **MySQL concurrency suite** on `thai_visa_services_testing` with independent connections:
  - a forced **1213 deadlock** retried and succeeding within 3 attempts
  - a forced **1205 timeout** under the 5-second session timeout, retried
  - exhaustion showing the friendly message
  - the session timeout **restored** after success, exception and exhaustion
  - a nested-transaction call not retried
- **Concurrency scenarios:**
  - Add Worker for the same Person on two orders
  - close during a status update
  - Person deletion during Add Worker, document upload and Add Service
  - bulk Hired lock order
  - a stale resolution list aborting
  - duplicate-phone insert returning a validation error
- **Person deletion:**
  - statuses, applications, fees, payments, refunds, remarks and attribution unchanged
  - documents, PDFs and the photo deleted after commit
  - derived remaining balance 0
  - Outstanding excludes the deleted Person
  - files deleted after commit, and recovery rows (if §15 is approved)
- **Job Order close:**
  - soft-deleted Persons' Applied/Interview rows are auto-cancelled; other statuses are untouched
  - non-deleted Persons' rows must be resolved
  - everything rolls back on any failure
  - a Person deleted while the close waits causes an abort
  - **round 7:** after the auto-cancel, the closed order's Money excludes that application (for example 10,000 → 0)
  - **round 7:** the ClientService's Kept Amount is unchanged (10,000), and no payment, refund or remark rows were added
  - **round 7:** the deleted Person stays out of Outstanding
  - **round 7:** Person deletion **alone** leaves the order's Money unchanged
- **No Activity rows** are written by any workflow (round 6).
- **Round 9:** the Hired → Cancelled, live Money, Unassigned Kept Amount and Hired Workers scenarios are in §17.14.
- **UI rules:** the manpower warning only when Hired > manpower; the Worker-name link goes to Client Detail.

## 17. Hired → Cancelled, live Job Order Money and Unassigned Kept Amount (round 9)

Technical consequences of the round 9 business rules (`JOB_SEARCH_BUSINESS_RULES.md` §3.1, §6.3). The business rules are approved. Where a point below is an implementation choice, it is marked **pending review**. No schema change is proposed, and none may be assumed. Nothing here is built.

### 17.1 The transition and its special validation path

- The transition table gains one exception: **Hired → Cancelled**. Every other transition out of Hired stays refused, in every workflow (single, bulk and close).
- Cancelled stays terminal.
- Hired → Cancelled has its **own validation path**. It differs from the normal status update in three ways:
  1. **No Open-order check.** The closed-order freeze does not apply to this transition. It still applies to every other transition on a closed order.
  2. **Mandatory confirmation, checked on the server.** The request must carry an explicit confirmation, as Hired does today. The server must not rely on the dialog alone. The request field name is an implementation choice (**pending review**).
  3. **Preconditions re-checked under locks** (§17.3).
- It uses the existing admin authorization. Whether it gets its own policy ability is an implementation choice (**pending review**).
- The application row is updated in place (`case_status` → cancelled). It is never deleted or recreated.

### 17.2 Confirmation dialog

The dialog is a UI requirement. It must state the five consequences of business rules §3.1: Hired → Cancelled; the ClientService returning from Complete to In Progress; possible reassignment under the existing rules; the previous Job Order's Money decreasing; the Kept Amount becoming Unassigned.

- The request is sent only after the admin confirms.
- Cancelling or dismissing the dialog sends nothing, so no data changes.
- The dialog texts need English and Burmese.

### 17.3 Atomic update, locks and re-validation

One retried transaction (§17.13). Lock sequence in §11.3. Inside it:

1. Discover the Person and engagement with plain reads, then lock in order and re-read with locking reads (§11.2).
2. **Re-validate** under the locks:
   - the application is still **Hired**. If not, it is a changed state: abort with a friendly message. This is a domain error and is **not** retried.
   - its ClientService is still **Complete**. A Hired row whose engagement is not Complete is an anomaly (business rules §11.4): refuse and log it after the transaction, as the other anomalies are.
   - **Decided (round 10):** the Person is **not soft-deleted**. The Person is read with `withTrashed()` under the lock, as in the close. If deleted, refuse with the existing "was deleted" message and change nothing.
   - **Decided (round 10):** the Person has **no other In Progress Job Search engagement**. Under the L3 locks, any other `client_services` row of the Person for the Job Search service with status In Progress means refuse, with a message that names the cause and tells the admin to resolve that other service first. It is a domain error: not retried, and no other ClientService is touched.
3. **Write, in the same transaction:**
   - `job_order_workers.case_status` → `cancelled`
   - `client_services.status` → `in_progress`
4. Nothing else is written: no fee, refund field, payment, remark, document or Job Order change. Marking Complete currently writes only `status`, so there is no end date or other field to undo.
5. The status change of the ClientService goes through this workflow, **not** through the Client page edit form, which keeps refusing changes to a Complete record. The exact method is an implementation choice.

If any step fails, both changes roll back. A partial result (row Cancelled with the engagement still Complete, or the reverse) must be impossible.

### 17.4 Closed Job Orders

- The operation does not use the order's status as a gate and does not write the order row. The order stays Done or Cancelled, with the same `closed_at`.
- It does not reopen, edit or add workers to the order. Those rules are not touched.
- Taking the order lock (L1) first, with no Open check, is for lock-order consistency only (§11.3, pending approval).

### 17.5 Reassignment creates a new row

- After the transaction, the engagement is In Progress with every row Rejected or Cancelled. `FindEngagement` therefore returns **Reusable**, and Add Worker creates a **new** `job_order_workers` row for the new order, as it does today.
- The old Cancelled row is not updated or reused. The existing rules stay: an open (Applied/Interview) row still blocks reassignment, and an In Progress engagement with no rows is still refused and logged.
- Round 10 decided the same-order case: the same Person may be added to the same order again once the earlier application there is terminal, and a new row is created (§17.16).

### 17.6 How current Job Order Money is derived

- **Nothing is stored.** The Money on a Job Order is computed, as in §5 (round 7).
- Per engagement, the **Kept Amount** is `received − refund_amount − cancel_refund_amount` (the existing `ClientService::kept()` formula, and the same whole-satang arithmetic as `ServiceStatistics` in SQL).
- That Kept Amount is attributed to the order of **one** application of the engagement:

  | Engagement | Application | Attributed to |
  | --- | --- | --- |
  | In Progress | Applied or Interview | that application's order |
  | Complete | Hired | that application's order |
  | In Progress | every row Rejected or Cancelled (including after Hired → Cancelled) | no order (Unassigned) |
  | Cancelled (whole-service) | any | no order |

- **Job Order Money** = the sum of the Kept Amounts attributed to it, with the existing handling of soft-deleted Persons (business rules §6.3 rules 8 and 9).
- The query shape, an accessor or a view is an implementation choice for Step 11 (**pending review**). It must not double count (§17.11).
- **Closure freezes nothing.** The order's `status` and `closed_at` do not enter the calculation, so the Money of a closed order stays live.

### 17.7 Payments after the Job Order closes

A payment is an INSERT into `payments` through the existing payment workflow. Because Money is computed from payments, the closed order's Money rises with no write to the order and no reopening. Example A of the business rules (10,000 → 15,000 THB; Outstanding 5,000 → 0) follows from this. Payment still locks only the ClientService row.

### 17.8 How Hired cancellation removes the attribution

The only write is the status change of §17.3. After it, the engagement is In Progress with no Applied/Interview row, so the table in §17.6 attributes it to no order. The old order's Money falls by the Kept Amount without any write to financial tables.

### 17.9 How Unassigned Kept Amount is represented

- It is **derived, never stored**: no table, column, enum value, payment, refund or ledger row.
- For one engagement, it is the Kept Amount when §17.6 attributes it to no order. For the Job Search part of the business:

  `Unassigned = Σ Kept (all Job Search engagements) − Σ Job Order Money (all orders, open and closed)`

- Payments received while unassigned raise the Kept Amount and therefore the Unassigned total. When a new Applied/Interview application is created (§17.5), the same formula attributes the Kept Amount to the new order and removes it from Unassigned.
- **Decided (round 10):** the name covers **all** Kept Amount of Job Search engagements that §17.6 attributes to no order (all-Rejected/Cancelled In Progress engagements, whole-service Cancelled engagements, the amount released by the deleted-Person auto-cancel, and after Hired → Cancelled). Kept is unchanged: real payments minus real refunds.
- **Display (round 10):** the Business Dashboard shows the Unassigned figure, and Client Detail shows each Job Search service's attribution (Job Order or Unassigned) next to its own Kept Amount. No Job Order card includes it.
- **Query:** one aggregate over `job_order_workers` joined to `client_services` and a per-engagement payments subquery, in whole satang, as in `ServiceStatistics`. An engagement with more than one qualifying row (an anomaly) is attributed through its newest qualifying row only, so it can never be counted twice. No column, table or view is added.

### 17.10 A new Job Order assignment receives the amount

No transfer is written. Creating the new row in Add Worker (§11.3) changes the engagement's attributed application, so §17.6 attributes the Kept Amount to the new order the moment the transaction commits. The old order stays at zero because its application is Cancelled.

### 17.11 Business Dashboard totals without double counting

- Dashboard and statistics totals stay computed **from `client_services` and `payments`**: Total Received / Kept is the sum of all engagements' Kept Amounts (including Unassigned amounts, whole-service Cancelled engagements and deleted Persons' history, as today). They do **not** read attribution.
- **Never total the Job Order Money cards.** That would drop Unassigned amounts, and it could double count if one amount were counted from both the engagement side and the order side.
- Job Order Money cards show only the attributed part. Unassigned amounts are in no card.
- **Outstanding** keeps its existing SQL (`BusinessReport::outstanding`, unchanged). Attribution does not enter it.
- **Consistency invariant for tests:** Σ Job Order Money (all orders) + Unassigned = Job Search Kept. An engagement is attributed to at most one order (§17.6), so the same Kept Amount can never appear in two orders.

### 17.12 Hired Workers card recalculation

The Hired Workers count is a query on the current data: rows of the order whose `case_status` is `hired` and whose Person is not soft-deleted. It is computed on each request, with no stored counter. After Hired → Cancelled the row no longer matches, so the count falls, also on a closed order. The row stays in the table, and no history table is added (round 6).

### 17.13 Transaction, locking and retry

- **Hired → Cancelled uses the existing "single application status update" workflow** (§3.1, item 2). It gets **no new retry policy**.
  - 3 attempts, deadlocks and lock-wait timeouts only, Laravel's default detector, no backoff.
  - The 5-second session `innodb_lock_wait_timeout` is set and **restored on every exit path** by the shared helper.
  - It must be the **outermost** transaction, and its closure free of side effects: anomaly logging happens after it.
  - Domain refusals (changed state, anomaly, second In Progress engagement) are not concurrency errors and are not retried.
  - After exhaustion, the existing "Please try again" message.
- It is **not** part of bulk updates or the close (decided in round 10). Both keep refusing every change out of Hired, because the transition table (`WorkerCaseStatus::allowedNext`) still has no exit from Hired. Their retry behaviour is unchanged.
- Whole-service cancellation and Person deletion stay not retried and keep the normal timeout. This is a different workflow and is not merged with them.
- The lock sequence is in §11.3. It is part of the lock protocol that is **pending approval**.

### 17.14 Required test scenarios

Pest tests on SQLite for behaviour, plus MySQL tests (independent connections) wherever locking, retry or timeout restoration is claimed.

**Transition and confirmation**

1. Hired → Cancelled succeeds after the admin confirms.
2. Without the confirmation, the request is refused. If the dialog is dismissed, no request is sent and nothing changes (a browser test checks the dialog).
3. Hired → Applied is refused.
4. Hired → Interview is refused.
5. Hired → Rejected is refused.
6. Hired → Cancelled changes the ClientService from Complete to In Progress in the same transaction.
7. If any write fails, neither the application nor the ClientService is changed.

**Closed orders**

8. Hired → Cancelled works when the Job Order is Done.
9. Hired → Cancelled works when the Job Order is Cancelled.
10. The closed order stays closed, with the same status and `closed_at`, cannot be edited, and still takes no new worker. Other transitions on a closed order are still refused.

**Reassignment**

11. A new Job Order assignment creates a new `job_order_workers` row and reuses the same ClientService.
12. The old Cancelled row is unchanged afterwards.
13. A worker cannot be reassigned while any application of the engagement is still Applied or Interview.

**Money**

14. The old Job Order's attributed Money falls after Hired → Cancelled.
15. The amount becomes Unassigned until a new order is assigned.
16. A payment while Unassigned increases the Unassigned amount.
17. Outstanding is correct and unchanged while the amount is Unassigned.
18. The new Job Order gets the correct attributed amount after assignment.
19. The same Kept Amount is never in two Job Orders.
20. A payment after the Job Order closed raises that closed order's Money while the attribution stays there (Example A: 10,000 → 15,000; Outstanding 5,000 → 0).
21. The Hired Workers card falls after Hired → Cancelled (Open and closed orders), and the row remains.
22. Dashboard totals include Unassigned amounts without double counting: Σ Job Order Money + Unassigned = Job Search Kept, and Total Received / Kept is unchanged by the cancellation and reassignment.

**Retry, timeout and concurrency (MySQL)**

23. Retry exhaustion shows the friendly message, and the session timeout is restored after success, exception and exhaustion, for this workflow.
24. Hired → Cancelled versus Add Worker for the same Person, versus a payment, and versus the close of the same order: no partial state and no deadlock cycle.

**Guards (decided in round 10)**

25. Refusal when the Person already has another In Progress Job Search engagement, with nothing changed.
26. A Hired row whose ClientService is not Complete is refused and logged.
27. Refusal for a soft-deleted Person; the Hired application and its attribution stay.
28. A bulk update and the close modal cannot perform Hired → Cancelled (the request is refused and nothing changes).
29. Round 7 and Person-deletion behaviours are unchanged (Person deletion alone leaves Money unchanged; the close auto-cancel still removes that application's attribution).

**Same Person, same Job Order (round 10)**

30. After a Rejected or Cancelled application on an order, adding the same Person to the same order creates a new row and the old row is unchanged.
31. An Applied, Interview or Hired application of the Person on that order blocks the new application.
32. Two concurrent Add Worker requests for the same Person and order create one row (MySQL).
33. Existing reassignment rules still hold (open application elsewhere blocks; Complete engagement is not reused).

**Unassigned Kept Amount (round 10)**

34. The Business Dashboard shows the Unassigned figure; Total Kept = Σ Job Order Money + Unassigned; a whole-service Cancelled engagement's Kept and a deleted Person's auto-cancelled amount count as Unassigned and nothing is double counted.

### 17.15 Decisions of round 10 and what remains

**Decided:**

- Refusal when the Person has another In Progress Job Search engagement (§17.3).
- Refusal for a soft-deleted Person (§17.3).
- Individual action only, outside bulk updates and the close (§17.13).
- The meaning, scope and display of Unassigned Kept Amount (§17.9) and the aggregate query (§17.6, §17.9).
- Same Person on the same order again (§17.16).

**Implementation choices made** (no schema change except §17.16): the confirmation field is `confirm_cancel_hired`; the route is `PATCH job-search/{jobOrder}/workers/{worker}/cancel-hired`; authorization is a new `cancelHired` ability on `JobOrderWorkerPolicy` (admin, no Open check).

**Still pending:** the detailed lock protocol as a whole, including the L1 → L2 → L3 → L4 sequence of Hired → Cancelled in §11.3 and the Add Worker check of §17.16. Neither has been proven on MySQL by a concurrency test unless §17.17 says so.

### 17.16 Same Person on the same Job Order again (round 10)

- **Index change.** `unique(['job_order_id', 'client_id'])` is removed from the original create migration (`create_job_order_workers_table`). **No replacement composite index is created** unless query-performance evidence requires one. The existing `client_id` index and the foreign-key indexes (`job_order_id`, `client_id`, `client_service_id`) stay. **No separate migration** is needed (the earlier prepare and "allow same person" migrations do not exist), because the database is rebuilt from scratch. A database built from the old migrations would still carry the unique key and would need a guarded migration.
- **What "active" means.** An application that is Applied, Interview or Hired. A Rejected or Cancelled application is terminal and does not block.
- **Where it is enforced.** Inside the Add Worker transaction, after the order lock (L1) and the Person lock (L2), a locking read looks for an active application of that Person on that order and refuses if there is one ("This person is already on this job order."). The form request rule is changed the same way (active only), so the usual message is shown without opening a transaction. Add Worker reads the Person row under `FOR UPDATE`, so two requests for the same Person queue on that lock, and two requests for the same order queue on the order lock.
- **No database backstop** for "one active application per Person per order" (the same position as for "one In Progress engagement": §2). This is a weaker guarantee than the unique key that was removed, and it relies on every writer of `job_order_workers` taking the locks. Add Worker is the only writer that creates rows.
- **Compatibility with reassignment.** The engagement rules are evaluated first and unchanged: an open application anywhere blocks, a Complete engagement is not reused, and old rows are never touched. The new row is a new application under the same engagement when reusable.
- **Worker list and counts.** Several rows of one Person can now show on one order. The Applied Workers and Hired Workers counts already count by current status, so history rows do not inflate them.

### 17.18 Step 10: Complete engagement lock and whole-service cancellation (built 2026-11-18)

- **Edit form.** `UpdateClientServiceRequest` prohibits `status` for a Job Search service. In `ClientServiceController::update`, under the service row lock (L3 only, a single-service workflow), a **Complete** Job Search service refuses a changed fee and a new remark (`ValidationException` on `fee` and `remark`); dates and the rest stay editable. A cancelled service ignores everything but a remark, also when the form was opened before it was cancelled. The page does not send a status for a Job Search service and shows it read-only.
- **Whole-service cancellation.** `CancelJobSearchService`, called by `ClientServiceController::cancel` for the Job Search service. One transaction on the normal timeout, no retry: L2 Person (`withTrashed`) → L3 the service (must still be In Progress; the refund must not exceed the money kept) → L4 **all** its application rows ascending → UPDATE service, UPDATE every row not already Cancelled to Cancelled, then L5 the cancel remark. No L1 (no job order is read or locked), so closed orders are changed without the freeze check. Nothing is deleted, written to Activity, or paid.
- **Tests.** SQLite: `CompleteEngagementLockTest`, `WholeServiceCancelTest`. MySQL (`Thai_Visa_Services_Testing`): the cancellation waits for a lock at levels 2, 3 and 4 and then cancels atomically with one attempt and the normal timeout; two cancellations of one service; the cancellation against Add Worker in both orders (a cancelled service never keeps a live application). **Not covered on MySQL:** the edit form against a concurrent Hired / Hired → Cancelled / cancellation. It checks the locked row and is proven by simulated stale forms on SQLite.

### 17.19 Step 11: Job Order detail page (built 2026-11-18)

- **Counts.** `JobOrder::workerCounts()` returns `applied_workers_count` (Applied + Interview, person not deleted, service not whole-service Cancelled), `hired_workers_count` (current status Hired, person not deleted) and `cancelled_workers_count` (rows whose status is Cancelled, person not deleted). They are `withCount` subqueries on each request, with no stored counter, so the rows of one Person (a Cancelled one and a later current one) never inflate them. `exceeds_manpower` is `hired_workers_count > manpower`.
- **Lists.** `JobOrderController::show` sends `workers` (every application that is not Cancelled: Applied, Interview, Hired, Rejected) and `cancelledWorkers` (the Cancelled ones, as history). Deleted Persons are in neither. Rejected rows stay in the operational list with their status; only Cancelled ones are treated as history (rules 11.2).
- **UI.** `Show.vue` has four `StatCard`s. Worker names are Inertia links to Client Detail in `JobOrderWorkers.vue` and `CancelledApplications.vue`.
- **Tests.** SQLite: `JobOrderDetailPageTest`, plus the updated `JobOrderWorkerTest` and `JobOrderControllerTest`.

### 17.17 Verification status (round 10)

Implemented in the working tree, uncommitted. Behaviour is covered by SQLite tests (`CancelHiredTest`, `JobOrderMoneyTest`, `AddWorkerEngagementTest`, and the updated Job Search suite) and by a browser run on an isolated scratch database. **No MySQL concurrency test was run** for Hired → Cancelled, the same-order duplicate check, or the Money query: SQLite ignores `FOR UPDATE`, so the lock sequence of §11.3 and §17.16 is argued from the code, not proven. The tests listed as MySQL in §17.14 (23 and 24, 32) are still to be written and run on `Thai_Visa_Services_Testing`.
