# Proposed `CLAUDE.md` (reconstruction draft, **awaiting user review, NOT active**)

**Status:** this is a **proposal**, written on 2026-11-17.

- It is **not** the original `CLAUDE.md`. That file was lost with the old laptop. It is not in this repository or its Git history, and there is no `~/.claude/CLAUDE.md`; `~/.claude/settings.json` holds only a theme setting.
- This file is deliberately **not** named `CLAUDE.md`, so Claude Code does not load it.
- To activate it, the user reviews it, fills the placeholders, and copies the approved part to `CLAUDE.md` at the repository root.

Every instruction is labelled:

- **[Confirmed]**: stated or approved by the user in the 2026-11-16/17 recovery sessions
- **[Recommended]**: inferred from the codebase's conventions; needs the user's approval

---

## Draft content

### Model selection

- **[Confirmed that a rule exists; content unknown]** The user's original `CLAUDE.md` had a model-selection requirement to follow before any code change. Its wording was not recovered.
- **[Confirmed, 2026-11-19, revised the same day]** Model choice is **task-dependent and flexible**, not a fixed rule. The usual workflow:
    - **Opus 5.5** (`claude-opus-5-5`): most planning, HLD and documentation work, reviews and audits, and code fixes.
    - **Sonnet 5.5** (`claude-sonnet-5-5`): most implementation work.
    - The user may also use Sonnet for planning or Opus for implementation when it suits the task. Either model may work on any task the user gives it; neither is forbidden from switching.
    - At the start of a task, state which model is running. Do not refuse or stop work only because the other model is the usual one for that kind of task.
    - (Superseded: "Use Opus 5.5 for all work … If another model is in use, say so and ask before changing code.")

### Sources of truth

- **[Confirmed]** For Job Search, `docs/JOB_SEARCH_BUSINESS_RULES.md` (business) and `docs/JOB_SEARCH_TECHNICAL_DECISIONS.md` (technical) are authoritative. Read both before planning or changing Job Search code.
- **[Confirmed, clarified 2026-11-20]** Current code describes implemented behaviour, not automatically the intended business rule; the code may lag the approved rules. In a documentation reconciliation, use the code to document accurately what is implemented. If behaviour looks like a bug, is ambiguous, or conflicts with an approved rule, report it separately and ask the user before changing code or recording a new rule. (Superseded: "Never infer an intended business rule from the current code.")
- **[Confirmed]** HLD status:
    - v0.8 was removed on 2026-11-17 and remains in Git history.
    - The original v0.9 was the last approved baseline and is lost.
    - `Project Resource/…High-Level Design (v0.9).md` (reconstructed) was **approved by the user on 2026-11-17**.
    - v1.0 is **not approved**.
    - HLD approval is not implementation approval.
- **[Confirmed]** Keep approved rules, approved technical decisions, recommendations pending approval, verified code behaviour and open questions clearly separated. Never present a recommendation as approved.
- **[Confirmed]** Do not reopen a resolved decision without the user's explicit instruction. Do not invent answers to open questions.

### Working process

- **[Confirmed]** Work in gated steps. At a review checkpoint, deliver the report and **stop**. The user has reports reviewed independently before authorising the next step.
- **[Confirmed]** Do not commit or push unless asked. Never reset, stash, discard or overwrite uncommitted work.
- **[Recommended]** Commit messages follow the existing pattern `Phase N step M: <summary>`.

### Database and testing

- **[Confirmed]** MySQL is production. SQLite tests cannot prove locking or concurrency.
- **[Confirmed]** Concurrency tests use independent MySQL connections on `Thai_Visa_Services_Testing` (case-sensitive; the test guard refuses any other database).
- **[Confirmed]** Never run destructive tests against the development or production database.
- **[Confirmed]** No Redis in the MVP.
- **[Confirmed]** Retry policy, lock-wait timeout and lock order are in `docs/JOB_SEARCH_TECHNICAL_DECISIONS.md` §3 and §11.
- **[Recommended]** Run the existing quality tools before finishing a change: Pest tests (`php artisan test`), Pint (`pint.json`), PHPStan (`phpstan.neon`), and the frontend lint and type checks.

### Code conventions observed in the repository

- **[Recommended]** Money is never a float: decimal strings with bcmath in PHP, and whole-satang sums in SQL. HLD §3 states "never floats" as a rule.
- **[Recommended]** Every user-facing string is translated into English and Burmese (`lang/my.json`, `lang/my/*.php`, `resources/js/locales/{en,my}.ts`). HLD §4.9 states this as a rule.
- **[Recommended]** Authorisation goes through policies, and validation through Form Requests (HLD §3).
- **[Recommended]** File deletion happens after the database commit (`DB::afterCommit`), following the existing `Document::erase` pattern.

---

_End of proposal. Nothing here is active until the user approves it._
