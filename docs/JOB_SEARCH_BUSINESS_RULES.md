# Job Search – Master Business Rules

**Status:** Authoritative for Job Search business behaviour.

- **Round 1 (2026-11-16):** restored from the product owner's consolidated decisions after the project moved to a new laptop.
- **Round 2 (2026-11-16):** updated with further confirmed decisions:
  - reassignment requires all applications to be terminal
  - closing requires resolving active applications
  - Outstanding for Complete engagements
  - remarks after cancellation
  - Application Activity as database history (*superseded in round 6: excluded from V1*)
  - no anomaly workflow
- **Round 3 (2026-11-16):** added:
  - the global Person deletion rule
  - the single atomic resolve-and-close transaction
  - confirmation of the Application Activity UI (*superseded in round 6*)
- **Round 4 (2026-11-16):** Person deletion details:
  - no refund
  - already-Cancelled services skipped
  - no remarks or activity records
  - Job Order Money attribution preserved
  - one database transaction, with file deletion after commit
- **Round 5 (2026-11-17):** the corrections below.
  - **Person deletion rule replaced.** The round 3–4 cancellation cascade is **revoked**. Deletion now only soft-deletes, deletes files and sets the remaining balance to zero, and changes nothing else.
  - **Application Activity:** actions and core fields approved (*superseded in round 6*).
  - **Resolve-and-close:** a stale state aborts the operation.
  - **Manpower warning:** uses Hired Workers.
  - **Worker-name links:** open the Client Detail page.
- **Round 6 (2026-11-17):**
  - **Person deletion:** the remaining balance is **derived** (Option A); the **profile photo** is deleted too.
  - **Job Order close:** soft-deleted Persons' Applied/Interview applications are **automatically set to Cancelled** as part of the close.
  - **Application Activity:** **excluded from V1**, superseding rounds 2, 3 and 5.
  - **Lock order:** the target order is accepted in principle; the per-workflow protocol is still under review.
- **Round 7 (2026-11-17):** the close's automatic cancellation of a deleted Person's Applied/Interview application **removes** that application's Job Order Money attribution from the order being closed. The Kept Amount is unchanged and no transaction is created. Person deletion itself still changes no attribution.
- **Round 9 (2026-11-17):**
  - **Hired → Cancelled** is now an allowed transition (the only way out of Hired), with a mandatory confirmation. It also works on a closed Job Order (Section 3.1).
  - The ClientService goes back from Complete to In Progress in the same transaction, so the same engagement can continue with another Job Order.
  - **Job Order Money is live**, also after the order closes. It is never frozen at Hired or at close (Section 6.3).
  - New classification, **Unassigned Kept Amount**: Kept Amount that no Job Order is attributed (Section 6.3.2).
  - The Hired Workers card counts only applications that are Hired now.
  - These supersede the earlier text that called Hired irreversible and its attribution permanent.

- **Round 10 (2026-11-17):** the five open round 9 questions are decided:
  - **Hired → Cancelled** is refused when the Person already has another In Progress Job Search engagement (§3.1).
  - It is refused for a soft-deleted Person (§3.1).
  - It is an **individual** action only, never in bulk updates or the close modal (§3.1).
  - **Unassigned Kept Amount** is defined as money retained by a Job Search ClientService and not currently attributed to any Job Order (§6.3.2).
  - The same Person may be **added to the same Job Order again** after the earlier application on it is terminal (§2.2).

**Precedence:**

- These rules override HLD v0.8 and any HLD v1.0 text wherever they conflict.
- HLD v0.9 was the last approved HLD baseline. The original is not in this repository. A *reconstructed* v0.9 (`Project Resource/…High-Level Design (v0.9).md`) was **approved by the user on 2026-11-17**. It replaces v0.8, which was removed from the repository.
- HLD v1.0 is **not approved**.
- Technical constraints live in [`JOB_SEARCH_TECHNICAL_DECISIONS.md`](JOB_SEARCH_TECHNICAL_DECISIONS.md).

**Rules are not implementation status.** An approved rule here may not be built yet. Never infer the intended rule from the current code. Known gaps are listed separately in [Section 13](#13-implementation-status-not-rules), which is not part of the rules.

**Resolved decisions are final.** Do not reopen a decision recorded here without an explicit instruction from the product owner.

---

## 1. Person and Worker identity

- A Worker is **not** a separate person type or client entity. A Worker is an existing or newly created Person (`clients` record) taking part in Job Search.
- The global Person/Client records are the source of truth.
- **Add Worker by phone** searches **all active** Person records, not only Job Search history. If the phone number belongs to an existing client, that Person is reused.
- Phone numbers are normalised consistently with the existing E.164 approach.
- **Soft-deleted Persons:**
  - A soft-deleted Person is never reused as an active Worker.
  - Their phone number may be reused by a new active Person, following the existing phone-uniqueness and deletion behaviour.
  - They are excluded from operational Worker lists and operational counts.
  - Their historical financial records stay included in financial totals and money/profit calculations where applicable.

### 1.1 Global Person deletion rule (all services, not only Job Search; round 5)

> **Superseded (revoked in round 5).** Rounds 3–4 said that Person deletion cancels every eligible service and sets every Job Search application to Cancelled, while keeping the attribution. **That rule is revoked and must not be implemented.** It is kept here only as history.

Soft-deleting a Person is **effectively permanent**: there is no restore or undo. When the admin soft-deletes a Person:

1. **Soft-delete** the Person record.
2. **Delete the Person's documents, PDF files and profile photo** (the photo was confirmed in round 6). Never delete unrelated files or documents.
3. **The remaining balance of every associated ClientService becomes zero**, for every service type. This is an approved exception, achieved through the **derived** remaining balance (see below).
4. **Preserve everything else exactly as it was** before the deletion.

**Person deletion must NOT** (all service types):

- **Services:** cancel any ClientService, or change any ClientService status. In Progress stays In Progress, Complete stays Complete, Cancelled stays Cancelled.
- **Job Search:** change any JobOrderWorker application status (Applied, Interview, Hired, Rejected, Cancelled), or any Job Order status.
- **Attribution:** change the Job Order Money attribution, or the Job Order the money is attributed to.
- **Money:** change service fees; modify or delete payments or refunds; create fake payments, refunds, write-off transactions or adjustment-ledger entries; modify financial history.
- **Remarks:** change ClientService remarks.
- **No restore:** offer no restore workflow.

**Remaining balance = 0 (approved exception).** A deleted Person no longer contributes an outstanding amount, because the admin can no longer reach that Person through the normal operational client records.

| | Before deletion | After deletion |
|---|---|---|
| Example 1: fee | 20,000 | 20,000 (unchanged) |
| Example 1: payments | 15,000 | 15,000 (unchanged) |
| Example 1: refunds | 0 | 0 (unchanged) |
| Example 1: remaining | 5,000 | **0** |
| Example 1: Outstanding | includes 5,000 | **excludes it** |
| Example 2: fee / payments / remaining | 20,000 / 20,000 / 0 | unchanged |

**Mechanism: derived remaining balance (Option A, approved in round 6).**

- The remaining balance is **derived, never stored**.
- For a soft-deleted Person, the derived remaining balance of each of their services is **zero**, and **Outstanding excludes** the Person.
- Nothing is written to the services, payments or refunds to achieve this. There is no write-off column, write-off transaction or adjustment ledger.
- Fees, payments, refunds, Kept Amount, Job Order Money and historical financial totals stay unchanged. Historical totals keep including deleted Persons where previously agreed.
- Operational counts exclude soft-deleted Persons.

How the existing derived calculation applies this is in Technical Decisions §12. It is consistent with the existing architecture and adds no new mechanism.

**Atomicity and files:**

- **One database transaction:** soft-delete the Person (the derived balance follows from that) and clear the document and photo references. Any failure rolls back all of it.
- **Files are deleted after commit:** the Person's documents, PDFs and photo are identified before or inside the transaction, and the physical files are deleted **only after commit**. A database rollback cannot undo deleting an external object.
- **Recovery:** if a file deletion fails, enough information must remain to retry or recover. **How is not approved**, and no queue, table, retry job or cleanup mechanism is decided (Technical Decisions §15). There is no generic orphan-file cleanup.
- **Retry and timeout:** Person deletion is not retried and does not use the 5-second timeout.

**Consequences:**

- The Person is left out of operational lists and counts (Section 1).
- Their services and applications keep their states, and their money stays exactly where it was, including Job Order Money attribution.
- Person deletion is **not** an exception to the application freeze or transition rules, because it changes no application status.
- **Later, when a Job Order is closed:** that Person's Applied or Interview application on that order is automatically set to Cancelled as part of the close (Section 4.1). This happens in the **close** workflow, not in Person deletion. Only then is that application's Job Order Money attribution removed (round 7, Section 6.3 rule 9). The Kept Amount stays unchanged.

**Relationship to whole-service cancellation (Section 5):**

| | Whole-service cancellation | Person deletion |
|---|---|---|
| Scope | One Job Search ClientService | The Person record, and every service's remaining balance |
| Precondition | ClientService is In Progress | None |
| Started from | Client Detail page | The Person delete action |
| Service status | → Cancelled | **Unchanged** |
| Application statuses | Every row → Cancelled | **Unchanged** |
| Refund | Cancel refund entered by the admin | None |
| Remarks | Automatic cancel remark | None |
| Job Order Money | Removed from every Job Order (6.3 rule 6) | **Unchanged** (6.3 rule 8) |
| Remaining balance | 0, because the service is cancelled | 0, derived from the soft delete (approved exception) |
| Files | Unchanged | The Person's documents, PDFs and profile photo deleted after commit |
| Retry / 5-second timeout | Neither | Neither |

The two are separate workflows and must not be merged.

## 2. Engagement model (Job Search ClientService)

A **Job Search ClientService** is one continuous **engagement** between the agency and a client. Over time it can hold several **applications** (`job_order_workers` rows).

- One ClientService engagement → many JobOrderWorker application rows.
- One JobOrderWorker row → one application to one Job Order.
- A Person has **at most one In Progress** Job Search engagement.

### 2.1 First Add Worker

When a Person is added to Job Search for the first time, the Job Search ClientService and the first JobOrderWorker application row are created **atomically**.

### 2.2 Subsequent Add Worker / reassignment

When the same Person is added to another Job Order:

1. **Look for** an existing **In Progress** Job Search ClientService for the Person.
2. **None In Progress:** create a new ClientService, even if historical Complete or Cancelled engagements exist.
3. **In Progress engagement exists:** check **every** application row of that engagement, not only the latest one.
   - **All rows Rejected or Cancelled:** reassignment is allowed. Reuse the engagement and create a **new** JobOrderWorker row for the new Job Order.
   - **Any row Applied or Interview:** **refuse** the new assignment. This is a strict V1 block, not a warning. The open application must first become Rejected or Cancelled.
   - A Hired row never permits reassignment while it is Hired. Hired completes the engagement (Section 3), so it is no longer In Progress.
   - If the Hired application is later changed to Cancelled (Section 3.1), the engagement returns to In Progress with every row Rejected or Cancelled, and reassignment is allowed again. The old rows are kept and never reused.
4. **In Progress engagement with no application rows:** this is an anomaly. Refuse the operation and log the problem. Do not silently reuse it, and do not create another engagement.
5. **Never reactivate or overwrite** an old application row. Always create a new row.
   - **Same Job Order again (round 10).** A Person may be added to the **same** Job Order again when every earlier application of theirs on it is terminal (Rejected or Cancelled). A **new** row is created and the old row stays as history. It is refused while the Person has an **active** application on that order: Applied, Interview or Hired. This is checked inside the Add Worker transaction under the order and Person locks (Technical Decisions §17.16).
   - The reassignment rules above are unchanged: an open application anywhere, or a Complete engagement, still blocks as before. A Hired Person on an order cannot be added to it again, because Hired is an active application.
6. During reassignment the admin may **keep or change** the engagement fee (Section 6.1).
7. Reassignment moves money attribution only (Section 6.3):
   - the current Kept Amount is attributed to the new Job Order
   - the full attribution is released from the previous one
   - no payment or refund is created, and the engagement's real financial history and Kept Amount stay accurate

### 2.3 Database relationship (business-level requirement)

- `job_order_workers.client_service_id` is not unique: one engagement holds many applications. Its foreign key keeps its ordinary index.
- **Round 10:** there is no unique constraint on `(job_order_id, client_id)`, because the same Person may have several applications on one order over time. It is removed from the original create migration, and **no replacement composite index is added** unless query-performance evidence requires one. The `client_id` index and the foreign-key indexes stay. No separate migration is needed, because the database is rebuilt from scratch. Preventing a duplicate **active** application rests on the transaction and its row locks, not on a database key (Technical Decisions §17.16).
- No `live_service_id` database backstop in V1. "At most one In Progress engagement" is enforced by transaction boundaries and row locking (see the Technical Decisions document).

## 3. Application statuses and transitions

V1 application statuses: **Applied, Interview, Hired, Rejected, Cancelled.**

`Working` and `Self Rejected` are **not** V1 application statuses.

| From | Allowed to |
|---|---|
| Applied | Interview, Hired, Rejected, Cancelled |
| Interview | Applied, Hired, Rejected, Cancelled |
| Hired | **Cancelled only** (Section 3.1, the single exception; round 9) |
| Rejected | — (terminal) |
| Cancelled | — (terminal) |

- **Terminal:** Rejected and Cancelled. **Hired** has exactly one way out, Hired → Cancelled (Section 3.1). Hired → Applied, Interview or Rejected are never allowed, and nothing else leaves Hired.
- **Rejected and Cancelled** can never be reactivated. To apply to another Job Order, create a new row under the same eligible In Progress engagement (2.2).
- **Hired** keeps the engagement Complete. When an application becomes Hired, its ClientService becomes **Complete in the same transaction**. Hired can be undone only by Hired → Cancelled, which returns the ClientService to In Progress in the same transaction (3.1).
- **Application-level Cancelled** means the Worker/client no longer wants that particular Job Order.
  - It does **not** refund the fee and does **not** cancel the ClientService.
- After application-level **Rejected** or **Cancelled**, the ClientService stays **In Progress**, so the agency can keep looking for another opportunity.
- Normal status changes are only possible while the application's Job Order is **Open** (Section 4.3).
- There are **two** exceptions to the transition limits and the closed-order freeze, and they are separate workflows that must not be merged:
  1. **Hired → Cancelled** of one application (Section 3.1, round 9).
  2. **Whole-service cancellation** (Section 5).

### 3.1 Hired → Cancelled (approved in round 9)

An admin may change one application from **Hired** to **Cancelled**, for example when the worker tries the job and finds it unsuitable.

**The transition**

- Hired → Cancelled is the **only** transition out of Hired. Hired → Applied, Hired → Interview, Hired → Rejected and any other transition remain forbidden.
- Cancelled stays terminal. It can never be reactivated.
- The application row is **never deleted or recreated**. It stays in the database as Cancelled.
- It works whether the Job Order is Open, Done or Cancelled. It does **not** reopen a closed Job Order (Section 4.3).

**Confirmation is mandatory.** Before anything is changed, a confirmation dialog must state that:

1. The application changes from Hired to Cancelled.
2. Its ClientService changes from Complete back to In Progress.
3. The worker can then be assigned to another Job Order, under the existing reassignment rules (2.2).
4. The previous Job Order's attributed Money will decrease.
5. The Kept Amount becomes **Unassigned** until another Job Order is assigned (6.3.2).

The admin must explicitly confirm. If the admin cancels or dismisses the dialog, **no data changes**.

**Lifecycle.** In **one atomic transaction** the application becomes Cancelled and its ClientService changes from Complete to **In Progress**.

- Never leave the application Cancelled with the ClientService still Complete.
- Never leave the application Hired with the ClientService already In Progress.
- **No new ClientService is created** because of this. The same continuous engagement continues.
- Because the ClientService is In Progress again, its fee, remarks and application changes follow the In Progress rules again (Section 7).

**Reassignment after it (example).**

1. The worker is assigned to Job Order A and becomes Hired, and the ClientService becomes Complete.
2. The worker finds the job unsuitable. The admin confirms Hired → Cancelled, and the ClientService returns to In Progress.
3. The admin assigns the same worker to Job Order B. A **new** JobOrderWorker row is created for B, and the old row stays Cancelled and is never reused.

The existing reassignment limits still apply (2.2): every earlier application of the engagement must be Rejected or Cancelled, and there is never more than one active application per engagement. An In Progress engagement with no application rows is still refused and logged. A Person still has at most one In Progress Job Search engagement.

**Money.** Money moves by attribution only (6.3). No payment, refund, write-off or adjustment is created.

**Not the same as:**

- the automatic cancellation of a soft-deleted Person's Applied/Interview application at Job Order close (Section 4.1)
- whole-service cancellation (Section 5), which overrides every row, refunds, and keeps its own rules

**Validation conditions (decided in round 10).** Hired → Cancelled is refused, and nothing is changed, unless all of these hold when the locks are held:

1. **Individual action only.** It is never part of a bulk status update and never an option in the Job Order close modal. Both workflows keep refusing any change out of Hired. The close keeps its existing all-or-nothing behaviour.
2. **The Person is not soft-deleted.** The deleted Person's Hired application, its Job Order Money attribution and every other record stay exactly as they are. The workflow never reactivates or modifies a deleted Person's records.
3. **The application is Hired and its ClientService is Complete.** A Hired row on any other kind of ClientService is an anomaly: refuse and log.
4. **The Person has no other In Progress Job Search engagement.** The Person must not end up with two. The message tells the admin to deal with the other In Progress Job Search service first, for example by completing it through a Hired application, or by cancelling it from Client Detail. Nothing is invented: no new workflow state, and **no other ClientService is silently changed**.
5. **The admin confirmed** (the mandatory dialog above), and the server checks the confirmation too.

Business context for rule 4: when a Person has moved on and a newer Job Search service has been linked to another Job Order, the older Complete service is a finished earlier engagement and stays that way until the newer one is resolved.

## 4. Job Order closing

An Open Job Order can be closed as **Done** or **Cancelled**. Both follow the same workflow.

**Superseded:** the round-1 rule that automatically turned Applied/Interview applications into Rejected on close is withdrawn. Closing never auto-rejects. The **only** automatic change at close is that soft-deleted Persons' Applied/Interview applications are set to Cancelled (approved in round 6; Section 4.1). Nothing else changes automatically.

### 4.1 Case A: at least one Applied or Interview application on the order

When the admin starts the close, a **resolution modal** opens. It must:

- **Soft-deleted Persons (approved in round 6):** their Applied or Interview applications on this order are **not** listed for the admin. The close sets them to **Cancelled** automatically. No other status is changed automatically: Hired, Rejected and Cancelled rows stay as they are.
- **List** every **non-deleted** Person on this order whose application is Applied or Interview.
- Let the admin choose a final status for each: **Hired**, **Rejected** or **Cancelled**.
  - Choices can be made **individually** and with **bulk** selection.
- Let the admin **review** the chosen results before confirming.
- Require **every** listed application to be resolved. The order cannot close while any non-deleted Person's application is still Applied or Interview.
- If the only Applied/Interview applications on the order belong to soft-deleted Persons, there is nothing for the admin to resolve. The close still cancels those applications automatically (Case B below).

Then:

- After all are resolved, the admin completes the close as Done or Cancelled.
- **Hired keeps all its consequences.** The explicit Hired confirmation is required here too (Section 11.3), and the ClientService becomes Complete. Only Hired → Cancelled (Section 3.1) can later undo it.
- **Bulk updates are all-or-nothing.**

**One atomic transaction (approved in round 3).** When the admin confirms the resolution choices, **one single database transaction** does all of the following:

1. Automatically set the soft-deleted Persons' Applied/Interview applications on the order to **Cancelled**.
2. Apply the admin's individual or bulk resolutions for the remaining non-deleted Persons.
3. Apply the Hired consequences, including completing each affected ClientService.
4. Close the Job Order as Done or Cancelled.

If any step fails, the **whole transaction rolls back**. There is no partial resolution and no partial close. The existing Hired confirmation and all locking rules (Technical Decisions §5) still apply.

**Automatic cancellation of a deleted Person's application at close (approved in round 7).** When the close automatically sets a soft-deleted Person's Applied or Interview application to Cancelled:

1. That application's Job Order Money attribution is **removed** from the Job Order being closed (rule 6.3.2 applies from then on).
2. The ClientService's Kept Amount is **unchanged**.
3. **No** payment, refund, write-off or adjustment transaction is created.
4. The deleted Person stays **excluded from Outstanding**, with a derived remaining balance of 0.
5. All payment, refund, fee and financial history is preserved.

Example: before the close, Job Order A has 10,000 THB attributed to the deleted Person. After the automatic cancellation, Job Order A's attribution for that application is **0 THB**, and the ClientService's Kept Amount is **still 10,000 THB**.

**Changed state while waiting for locks (approved in round 5).** After acquiring its locks, the operation re-reads the Job Order and the application states and compares them with the state the admin's resolution screen was based on. If any relevant application or Job Order state has changed:

- **abort** the operation and **roll back** the whole transaction
- show a friendly message that the data changed and the admin must refresh and review
- **never** apply stale decisions automatically

The admin reviews the latest state and submits again. How the comparison is implemented is in Technical Decisions §14.

### 4.2 Case B: no Applied or Interview application on the order

If **no non-deleted Person** has an Applied or Interview application on the order, **no modal is shown**. That covers three situations: every application is already settled (Hired, Rejected or Cancelled), the order has none, or the only open ones belong to soft-deleted Persons.

The admin closes the order directly as Done or Cancelled. In the same atomic transaction, any soft-deleted Persons' Applied/Interview applications are set to Cancelled (Case A, step 1).

### 4.3 After closing

A Done or Cancelled Job Order is **frozen**:

- It cannot be edited or reopened.
- No Worker can be assigned to it.
- Its application statuses cannot be changed through normal status-update operations.
- **Its Money is not frozen.** The order's attributed Money stays live and keeps following the attribution rules (6.3).

The normal freeze is explicit: no edit, no reopen, no new Worker, and no normal status change. These things still continue:

- **Payments** go on through the Client page payment workflow. They belong to the engagement, not the order. A payment made after the close changes that order's attributed Money (6.3.1, Example A).
- The automatic cancellation of soft-deleted Persons' applications happens **during** the close, before the freeze applies. It is not a change to a closed order.
- **Hired → Cancelled (round 9)** of one application belonging to a closed order, through the confirmation workflow (Section 3.1). The order stays Done or Cancelled, and is not edited, reopened or given new Workers. It is the only normal-path status change allowed on a closed order. Every other transition stays prohibited.
- **Whole-service cancellation** (Section 5) remains the other approved exception. It may change every associated application row to Cancelled, on closed orders as well. It is a separate workflow and keeps its own behaviour.

## 5. Whole-service cancellation (Job Search only)

This is different from cancelling one application.

- It is started from the **Client Detail page only**.
- A Job Search ClientService can be whole-service cancelled **only while In Progress**.
- On whole-service cancel, **every** associated JobOrderWorker row becomes **Cancelled**:
  - whatever its previous status was
  - on Open, Done and Cancelled Job Orders alike
  - including rows that are already Cancelled: the row is kept
  - including an anomalous Hired row, which whole-service cancellation overrides to Cancelled
- This overrides the normal transition limits (Section 3) and the closed-Job-Order freeze (Section 4.3).
- Application history is never deleted.
- **Atomic, all three together:**
  1. Cancel the ClientService.
  2. Process the applicable refund.
  3. Set every associated application row to Cancelled.
- **No Application Activity records** are written (round 6: Activity is excluded from V1). The earlier `cancelled_with_service` activity requirement is superseded.
- This cascade is **specific to Job Search**. It does not apply automatically to other service types.
- Person deletion is a separate, global workflow. It does **not** cancel services or applications (Section 1.1).
- **Hired → Cancelled** (Section 3.1) is a different workflow: one application, no refund, ClientService Complete → In Progress. Do not merge the two.

## 6. Fees, payments and money

### 6.1 Fee ownership

- The fee belongs to the **ClientService engagement**, not to any single application row.
- While the engagement is **In Progress**, the fee may be edited, including during reassignment.
- The existing fee-change, refund and remaining-balance logic applies.
- The fee of a **Complete** engagement is locked.

### 6.2 Payments and refunds

- Payments are **append-only**.
- Never create fake payments or refunds just to attribute money to a different Job Order.
- **Kept Amount** = actual received payments − actual refunds.
  - Actual refunds are fee-change refunds plus whole-service cancellation refunds.
  - Use the authoritative ClientService kept calculation (equivalent to `ClientService::kept()`).
  - Never subtract the same refund twice.
- **Payments may continue after the Job Order is closed**, through the Client page payment workflow. They change that order's attributed Money (6.3.1, Example A).

### 6.3 Job Order Money attribution

Job Order Money is the current Kept Amount attributed to the current application.

**It is live (round 9).** It is calculated from current states and payments, never frozen at Hired and never frozen when the order closes. Closing an order does not fix its Money. Any later payment, status change or reassignment that the rules below attribute to or away from the order changes the order's Money.

1. **Engagement In Progress, application Applied or Interview:** the whole current Kept Amount is attributed to that Job Order.
2. **Application Rejected or application-level Cancelled:** no money is attributed to that Job Order until a new application is created.
3. **Reassignment A → B:** the full attributed amount is released from A and attributed to B. No payment or refund is created.
4. **Engagement Complete, application Hired:** the Kept Amount is attributed to the hired Job Order **for as long as that application stays Hired**, including after the order is closed. (Round 9: it is no longer "permanent". If the application is changed to Cancelled, rule 10 applies.)
5. Later allowed payments to a Complete engagement increase the amount attributed to its hired Job Order, whether that order is Open or closed.
6. **Whole-service Cancelled:** nothing is attributed to any Job Order. The ClientService's financial history stays intact.
7. No partial money transfers between Job Orders in V1.
8. **Person deletion (round 5):** attribution is **unchanged**. Person deletion changes no service status, application status or money record, so rules 1–7 give exactly the same attribution after the deletion as before. Example: a Person Hired on Job Order A with 10,000 THB kept is deleted; A's Money still includes the 10,000 THB. When a Job Order's Money includes deleted Persons' amounts, the card shows `includes people who were deleted` (Section 11.1). Deletion alone never moves this attribution; see rule 9 for what a later close does.
9. **Close auto-cancels a deleted Person's Applied/Interview application (approved in round 7):**
   - The application's attribution is **removed** from the Job Order being closed.
   - The ClientService's Kept Amount is **unchanged**, and no payment, refund, write-off or adjustment transaction is created.
   - The deleted Person stays excluded from Outstanding, and all financial history is preserved.
   - Example: Job Order A goes from 10,000 THB attributed to 0 THB for that application; the Kept Amount stays 10,000 THB.
   - A deleted Person's **Hired** application is not auto-cancelled, so its attribution stays (rule 4).
10. **Hired → Cancelled (round 9, Section 3.1):**
    - The previous Job Order **loses** the attribution, because its application is no longer Hired. Its Hired Workers count decreases.
    - The ClientService returns to In Progress with every row Rejected or Cancelled, so rule 2 applies: no Job Order is attributed.
    - No payment, refund, write-off or adjustment is created, and the Kept Amount is unchanged. The order stays closed.
11. **Unassigned Kept Amount (round 9):** see 6.3.2.
12. **No double counting.** One engagement's Kept Amount is attributed to **at most one** Job Order at a time. Moving it, as in rules 3 and 10, always removes it from the old order and gives it to the new one. The same amount is never counted in two Job Orders.

#### 6.3.1 Examples (round 9)

**Example A: payment after the Job Order closes.** The worker was Hired on Job Order A, which was then closed. The worker had paid 10,000 THB, and the remaining 5,000 THB is paid after the close.

- Job Order A's attributed Money goes from 10,000 to **15,000 THB**.
- Outstanding goes from 5,000 to **0 THB**.
- Job Order A is not reopened.

**Example B: the Hired application is later cancelled.** Continuing from A, the worker reports that the job is unsuitable, and the admin confirms Hired → Cancelled.

- The ClientService returns to In Progress.
- Job Order A's attributed Money goes from 15,000 to **0 THB**.
- The 15,000 THB Kept Amount becomes **Unassigned**.
- No payment, refund, write-off or artificial transaction is created.
- Job Order A stays closed, and its Hired Workers count decreases.

**Example C: the worker is assigned to Job Order B.** The admin creates a new application for B.

- Job Order A stays at **0 THB**.
- Job Order B receives the applicable **15,000 THB** attributed Kept Amount (rule 1).
- The amount is not counted in both orders (rule 12).

#### 6.3.2 Unassigned Kept Amount (round 9)

**Unassigned Kept Amount is a classification, not a transaction.** It is the part of existing Kept Amount that currently has no Job Order attribution. It is not a payment, a refund, a write-off or a separate financial record, and it must never be double counted.

- **When it arises.** Whenever rule 2 leaves an engagement's Kept Amount with no Job Order. After Hired → Cancelled, the whole Kept Amount of the engagement is Unassigned until a new eligible application exists.
- **Payments while unassigned** increase the Unassigned Kept Amount.
- **When a new eligible application is created** (rule 1), the Kept Amount is attributed to the new Job Order and is no longer Unassigned. It is not attributed to a new Job Order before then.
- **Outstanding** keeps using the existing approved calculation. Being Unassigned does not change it.
- **It never appears in any Job Order's Money card** (Section 11.1).

**Definition (decided in round 10).** Unassigned Kept Amount is money **retained by a Job Search ClientService that is not currently attributed to any specific Job Order**. It stays money earned and retained by that service.

- It is what is left of an engagement's Kept Amount when rule 2 (or rule 6, or rule 9) gives it no Job Order. Examples: after a Rejected or Cancelled application, after Hired → Cancelled, for a whole-service Cancelled engagement (its Kept Amount after the cancel refund), and the amount released when the close cancels a deleted Person's application.
- **The Kept Amount formula does not change:** real payments received minus real refunds. Nothing new is stored, and no payment, refund, write-off or other record is created to move it.
- **The ClientService keeps showing its own retained money** (Kept) even when no Job Order owns it. The Client Detail page also shows which Job Order, if any, it is currently attributed to, or that it is Unassigned.
- **Never double counted.** An amount is either attributed to one Job Order or Unassigned, never both. When a new application is created, the Unassigned portion falls by exactly the amount that Job Order receives.
- **Shown** on the Business Dashboard as its own figure, so that Total Kept = Job Order Money (all orders) + Unassigned Kept Amount for Job Search.

#### 6.3.3 Business totals versus Job Order amounts (round 9)

- **Business-level amounts** come from the engagements and payments: **Total Received / Kept** is the sum of every engagement's Kept Amount, including Unassigned amounts, whole-service Cancelled engagements and deleted Persons' history. It does not depend on attribution.
- **Job Order-level amounts** are only the parts currently attributed to each order.
- Totals must **not** be built by adding the Job Order Money cards. That would leave out Unassigned amounts, and could count an amount twice if it were counted from both ends.
- **Consistency check:** the Money of all Job Orders (Open and closed) plus the Unassigned Kept Amount equals the Job Search part of Kept.
- **Outstanding** is unchanged (6.4).

### 6.4 Outstanding

**Outstanding** is the remaining balance of engagements that are not cancelled.

- An engagement whose application is Rejected or application-level Cancelled stays In Progress, and its balance **stays in Outstanding**.
- A **Complete** engagement may still have an unpaid balance, and it **stays in Outstanding**.
  - Complete means the service workflow has finished, not that the client has fully paid.
  - Becoming Complete never clears Outstanding automatically.
- **Hired → Cancelled (round 9)** does not change the engagement's fee, payments or remaining balance, so Outstanding is the same before and after (In Progress and Complete are both included). Unassigned Kept Amount does not change it.
- **Person deletion (approved exception):**
  - The remaining balance of every service of the deleted Person is **zero**, so Outstanding no longer includes it. This is consistent with the existing approved rule (HLD v0.8 §4.12) that a deleted Person's unpaid balance is no longer in Outstanding.
  - The service keeps its status. For example, a Complete service with an unpaid balance stays Complete but now owes 0.
  - Fee, payments and refunds are unchanged, and no refund, write-off or adjustment entry is created. The zero is **derived** (Option A, Section 1.1).

### 6.5 Historical totals

- Financial history and totals keep soft-deleted Persons' historical amounts unchanged: received, refunds, kept, and Job Order Money attribution (6.3 rule 8).
- Operational Worker counts exclude soft-deleted Persons.

## 7. ClientService lifecycle summary (Job Search)

| | In Progress | Complete | Cancelled (whole-service) |
|---|---|---|---|
| How it is reached | First Add Worker, or a new engagement when none is In Progress; **or back from Complete** when its Hired application is changed to Cancelled (3.1) | Automatically when an application becomes Hired (same transaction) | Whole-service cancel from the Client Detail page (from In Progress only) |
| Fee | Editable (incl. during reassignment) | Locked | Settled by the cancel refund |
| Payments | Allowed up to the remaining balance | Allowed while a balance remains | None |
| Outstanding | Included | Included while unpaid | Excluded |
| Job Order Money | Attributed to the current Applied/Interview order; none after Hired → Cancelled until a new application exists (Unassigned, 6.3.2) | Attributed to the hired order while the application stays Hired, live after the order closes (6.3) | None |
| If the Person is deleted (1.1) | Status, applications and attribution unchanged; remaining balance 0 | Status, applications and attribution unchanged; remaining balance 0 | Unchanged |
| ClientService status change | → Complete (only through Hired); → Cancelled (whole-service cancel) | **→ In Progress only through Hired → Cancelled** (3.1); never by editing the record | None |
| Application statuses | Changeable per Section 3 while the order is Open | Locked, except Hired → Cancelled (3.1), also on a closed order | All rows Cancelled |
| Reassignment / new application | Only when every row is Rejected or Cancelled | Not allowed (until the Hired row is cancelled, which returns the engagement to In Progress) | Not allowed |
| ClientService remarks | Allowed (existing behaviour) | **No adding or editing** while Complete | **New remarks allowed** (Section 10) |
| Documents (admin) | Full CRUD | Full CRUD | Read and download only |
| Documents (Client Portal) | Never shown | Never shown | Never shown |

Person deletion never changes an engagement's status (Section 1.1). After Hired → Cancelled the engagement is In Progress again and the In Progress column applies.

**Complete: prohibited operations**

- changing the fee
- changing the ClientService status by editing the record, including reverting to In Progress. The **only** way back to In Progress is Hired → Cancelled (3.1)
- changing application statuses, except Hired → Cancelled (3.1)
- reassigning the engagement, or adding another application
- adding or editing ClientService remarks

**Complete: documents (final decision)**

This supersedes any older "Complete documents are read-only" rule or HLD text. The admin keeps **full Documents CRUD**:

- upload/create
- view and download
- replace
- rename
- delete

## 8. Cancelled Job Search ClientService documents

For a whole-service Cancelled engagement:

- Documents are **read-only**. The admin can still view and download them.
- Document history is retained and access is logged.
- No upload, replace, rename or delete, unless a future decision explicitly changes this.

## 9. Documents and the Client Portal

- Job Search documents belong to the **ClientService engagement**, not to an individual application row. They can therefore be reused across sequential Job Orders within the same engagement.
- Job Search documents are **admin-only**. They are never exposed through the Client Portal, whether the engagement is In Progress, Complete or Cancelled.
- Admin document rights by engagement status:

  | Engagement status | Admin document rights |
  |---|---|
  | In Progress | Full CRUD |
  | Complete | Full CRUD |
  | Cancelled | Read and download only |

## 10. Application Activity (out of scope for V1) and remarks

### 10.1 Application Activity: excluded from V1 (round 6)

**Superseded:** rounds 2, 3 and 5 approved recording Application Activity (status changes, reassignment, close resolutions, `cancelled_with_service`), with core fields and a secondary audit view. **Round 6 excludes the whole feature from V1.** This is a personal agency management website, and the admin is not expected to inspect an activity history.

For V1:

- No Application Activity feature, database table or model.
- No activity records for status changes, reassignment, close resolutions or whole-service cancellation (`cancelled_with_service` is dropped).
- No activity timeline or audit-history UI.
- No activity-related schema fields, such as `operation_id`, an activity `job_order_id` or an activity `job_order_worker_id`.
- **No replacement audit-logging feature.**

The UI shows each application's **current status** only.

**Out of scope for V1 / future consideration:** an application activity history could be reconsidered in a later version. Nothing about it is decided.

### 10.3 ClientService remarks

- Existing remarks and their purpose stay as they are: admin notes, and the automatic refund and cancel notes. Their history is preserved.
- **Complete engagement:** remarks cannot be added or edited.
- **Whole-service Cancelled engagement:** the admin **may add new remarks**, for example for refund follow-up and administrative notes. No new permission to edit or delete historical remarks is granted beyond existing approved rules.

## 11. Job Order Detail UI

### 11.1 Summary cards

The page has **exactly four** summary cards. The earlier six-card design is obsolete.

| Card | Rule |
|---|---|
| **Manpower Requested** | The company's requested manpower. |
| **Applied Workers** | Applied + Interview applications on this order. Excludes soft-deleted Persons and applications whose ClientService is whole-service Cancelled. |
| **Hired Workers** | Applications on this specific order whose **current** status is Hired. Excludes soft-deleted Persons. A worker whose Hired application was changed to Cancelled (3.1) is no longer counted, also on a closed order. The cancelled row stays in the database as history. This is the current count, not a history of hirings. |
| **Money** | Current Kept Amount attributed to this order (Section 6.3). It includes deleted Persons' amounts, whose attribution is unchanged (6.3 rule 8). When any of the amount comes from deleted Persons, show the hint `includes people who were deleted`. It is **live**: it keeps following payments after the order closes, and drops when a Hired application is cancelled (6.3). **Unassigned Kept Amount is never included.** |

### 11.2 Other UI rules

- **Worker-name links (approved in round 5)** open the existing **Client Detail page** of that Person. A Worker is not a separate person type, and the global Person record is the source of truth.
- **Manpower warning (approved in round 5):** compares **Hired Workers** (Section 11.1, excluding soft-deleted Persons) with **Manpower Requested**. Applied + Interview is not the capacity count.

  | Hired Workers vs Manpower Requested | State | UI |
  |---|---|---|
  | Hired < Manpower (e.g. 9 of 10) | Below requested capacity | No warning |
  | Hired = Manpower (e.g. 10 of 10) | Capacity reached | No warning. A neutral "capacity reached" indicator is a UI detail, not an approved requirement |
  | Hired > Manpower (e.g. 11 of 10) | Over requested capacity | **Warning shown** |

  - It is a warning only. It never blocks adding Workers or hiring.
  - Do not enforce male + female ≤ manpower.
- Show historical cancelled applications separately from operational Worker counts.
- Cancelled and soft-deleted Workers must not inflate operational counts.
- Show each application's current status only. There is no status history in V1 (Section 10.1).

### 11.3 Individual and bulk status updates

- While the order is **Open**, the admin can update application statuses **individually** or **in bulk**. The close-resolution modal (Section 4.1) uses the same rules.
- **Bulk updates are all-or-nothing.**
- **Hired requires explicit confirmation**, individually and in bulk. The confirmation explains that:
  - the associated ClientService becomes Complete
  - it can no longer be reassigned while the application stays Hired
  - Hired can later be undone only by Hired → Cancelled (3.1), which needs its own confirmation
- Bulk Hired may affect several ClientServices. All affected ClientServices are locked in **ascending ID order**, and the whole bulk operation is atomic.
- **Hired → Cancelled (round 9)** needs its own explicit confirmation (3.1), explaining the ClientService returning to In Progress, the possible reassignment, the decrease of the previous order's Money and the Unassigned Kept Amount. The control is available on Open **and** closed orders for a Hired application. Dismissing the dialog changes nothing.

### 11.4 Anomalous records

The strict V1 rules mean a normal workflow cannot leave an Applied or Interview application under a Complete or Cancelled ClientService:

- reassignment is blocked while any row is Applied or Interview (2.2)
- an order cannot close while any of its rows is Applied or Interview (4.1)
- Hired completes the engagement atomically (3), and Hired → Cancelled returns it to In Progress atomically (3.1)
- whole-service cancellation cancels every row atomically (5)

A Hired application whose ClientService is not Complete is therefore an anomaly: Hired → Cancelled refuses it and logs the problem (see Technical Decisions §17.3).

Applied or Interview applications of **soft-deleted Persons** are **not** anomalies. Person deletion deliberately leaves application statuses unchanged (1.1), and the Job Order close cancels them automatically (4.1).

For V1:

- No special anomaly modal or business workflow is built for such records.
- They are treated as a **data-integrity concern for future audits**.
- No automatic repair is invented.

## 12. Open questions (not decided; do not invent answers)

**Resolved in round 6 and removed from this list:**

- the remaining-balance mechanism (Option A, derived)
- deleted Persons' open applications at close (cancelled automatically by the close)

**Resolved in round 7:** Job Order Money when the close auto-cancels a deleted Person's application (attribution removed, Kept unchanged; rule 6.3.9).
- the profile photo (deleted)
- Activity fields (the feature is excluded from V1)

Resolved earlier: stale state (abort), manpower count (Hired Workers), Worker-name links (Client Detail), the detector (Laravel default).

**Still open:**

**Resolved in round 10 and removed from this list:** a second In Progress engagement (refuse), a deleted Person's Hired application (refuse), bulk and close (individual only), the meaning and display of Unassigned Kept Amount (6.3.2), and the same Person on the same order again (allowed after a terminal application; 2.2).

1. **Post-commit file deletion and recovery for Person deletion.** Files are deleted after commit (approved), and recovery information must remain (approved). The mechanism, whether a queue, table, retry job or the error log, is **not approved**. Technical Decisions §15.
2. **The detailed lock protocol.** The target order is accepted in principle. The per-workflow protocol and its documented exceptions (Technical Decisions §11) still need approval.
3. **MySQL runtime verification of the retry detector** for 1213 and 1205 (Technical Decisions §3.6).
4. **Reconstructed `CLAUDE.md`:** the proposal in `docs/CLAUDE_MD_PROPOSAL.md` awaits review and is not active.

## 13. Implementation status (not rules)

Refreshed on 2026-11-17 against the working tree (branch `phase-4-step-5`): Phase 4 steps 5–9 are committed up to Step 8, and Step 9 and the round 9–10 work are **uncommitted**. This list is a status report, not a rule. Where it differs from the rules above, the rules win.

**Built (verified by tests, and by a browser run on an isolated database):**

- The engagement model: one Job Search ClientService can hold several applications. The unique key on `client_service_id` is gone, and Add Worker reuses an In Progress record only when every row is Rejected or Cancelled, refuses an open application, and refuses and logs a record with no application.
- The statuses `applied, interview, hired, rejected, cancelled`, the transition table, and single and bulk status updates (all or nothing, with the Hired confirmation), on Open orders.
- The shared retry helper (3 attempts, 5-second session timeout restored on every exit), used by Add Worker, status updates, the close, and Hired → Cancelled.
- Resolve-and-close in one transaction, with the automatic cancellation of deleted Persons' open applications and the stale-state abort. The Job Order edit is locked against a concurrent close.
- **Round 9–10:** Hired → Cancelled (individual only, confirmation, closed orders, refusals for a deleted Person, another In Progress engagement, or a non-Complete record), live Job Order Money on the Job Order page, Unassigned Kept Amount on the dashboard, and the attribution line on Client Detail.
- **Round 10:** the same Person on the same Job Order again after a terminal application (there is no unique `(job_order_id, client_id)` key any more; a duplicate active application is refused under the order and Person locks).

- **Step 10 (2026-11-18):** the **Complete engagement lock** and **whole-service cancellation**.
  - The Client page edit form no longer changes the status of a Job Search service at all (the request prohibits it). A Complete Job Search service also has a locked fee and takes no new remark; its dates stay editable. Both are checked again on the locked row, so a form opened before another action cannot write stale data. Other services are unchanged.
  - Whole-service cancellation of a Job Search service (`CancelJobSearchService`) cancels the service, records the refund and its remark, and cancels every application row of it in one transaction, on closed orders as well (Section 5). It runs on the normal lock timeout and is not retried.
  - MySQL concurrency tests on `Thai_Visa_Services_Testing` cover Hired → Cancelled, Add Worker, reassignment, the Money snapshot and the whole-service cancellation, with real separate connections.

- **Step 11 (2026-11-18):** the **Job Order detail page** of Section 11. Exactly four cards (Manpower Requested, Applied Workers, Hired Workers, Money); the manpower warning compares the **Hired** Workers with the manpower (the Job Orders list and the edit page use the same rule); worker names open Client Detail; the historical Cancelled applications are listed apart and are never counted as workers. The counts are worked out in the database on each request and leave out deleted Persons.

**Not built yet:**

- **Job Search documents.** Upload is still refused (`DocumentPolicy::create`). The portal exclusion is in place.
- **Person deletion.** It still takes no explicit Person lock; the derived remaining balance of 0 is not in `remaining()` and `canTakePayment()` (Outstanding already excludes deleted Persons); a photo-deletion failure is not reported; the document-upload and Add Service races remain. The file-recovery mechanism is not approved.
- **Lock protocol.** The detailed protocol is still pending approval. The workflows named above have MySQL concurrency tests that pass; the status-edit form's lock is proven on SQLite and by reading, and has no MySQL contention test.
- Application Activity is not built, which matches the rules (excluded from V1).
