# Phase 5 open risks and questions

Status as of the 5f.2 security fixes (findings 3, 4, 5 and 9). This file is the single list of what is still open. The findings themselves are in `PHASE_5_SECURITY_PDPA_REVIEW.md`.

## Done in the 5f.2 fixes

| Finding                   | What was done                                                                                                                                                                     |
| ------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 3. Package advisories     | `composer update` within the current constraints. `composer audit`: 12 advisories before, none after. `npm audit --omit=dev`: 0.                                                  |
| 4. Trusted hosts          | Only the `APP_URL` host plus `TRUSTED_HOSTS` is answered outside local development and tests. It fails closed (see below). `app/Support/TrustedHosts.php`, `config/security.php`. |
| 5. Query values in errors | `mask_bindings_in_exception_messages => true` on all five connections in `config/database.php`.                                                                                   |
| 9. Wrong two-factor text  | `siteSettings.account.securityHint` is now "Your password." (English) and its Burmese equivalent.                                                                                 |

## Risks that remain

| #   | Risk                                                                                                                                                                                                                                                             | What to do                                                                                                                                           |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| R1  | **A wrong or placeholder `APP_URL` in production makes every request return 400.** The protection fails closed on purpose, so it never silently trusts every host. Nothing is logged. The `.env.example` default `http://localhost:8000` would do this.          | Set `APP_URL` to the real public address. Part of the production checklist (findings 1, 2, 8).                                                       |
| R2  | **Health checks and the `www` name are not trusted by default.** The `/up` route is host-checked too.                                                                                                                                                            | List them in `TRUSTED_HOSTS` (comma separated, bare hostnames only) once the real names are known. No production domain has been guessed or added.   |
| R3  | **Local development and tests are exempt** from the trusted-host check (Laravel skips it there).                                                                                                                                                                 | Proven only by booting the app as production (see `TrustedHostsTest` and the manual check in the 5f.2 fixes report). Re-check on the real server.    |
| R4  | **The dependency update was wider than the advisories needed.** 62 packages changed; 7 were required for advisories. `laravel/passkeys` moved 0.2.1 to 0.3.0 (a 0.x minor bump, pulled in by `laravel/fortify` 1.43). The app does not use passkeys.             | Accepted for now (full suite and MySQL suite pass). Option: restore the old lock and update only the 7 advisory packages with `--with-dependencies`. |
| R5  | **`composer update` exits with an error** because the post-update hook `php artisan boost:update` needs a local `boost.json` that was never created. It affects only the development tool Laravel Boost, not the application or CI (CI uses `composer install`). | Run `php artisan boost:install` locally, or ignore.                                                                                                  |
| R6  | **Findings 1, 2 and 8 (production checklist)** need the hosting decision (the "Step 6" question): trusted proxies, `SESSION_SECURE_COOKIE`, `APP_ENV`, `APP_DEBUG`, `LOG_LEVEL`. They can only be proved on the real server.                                     | Waiting for the hosting answer.                                                                                                                      |
| R7  | **Finding 10:** the Content Security Policy is still Report-Only.                                                                                                                                                                                                | Check the browser console once more, then decide on `CSP_ENFORCE=true`.                                                                              |
| R8  | **Production `db:check-server` has not run** (no server yet). The testing-database audit and the full MySQL suite pass.                                                                                                                                          | Run it on the real server (`docs/reports/PHASE_5_MYSQL_CHECKS.md`).                                                                                  |
| R9  | **The Burmese strings are unreviewed**, including the corrected hint.                                                                                                                                                                                            | A Burmese speaker should review.                                                                                                                     |
| R10 | **Pre-existing:** `vp check` fails on 4 Markdown docs and `resources/js/locales/my.ts`. PHPStan has a baseline of 19 errors.                                                                                                                                     | Left as they are; new files are kept clean.                                                                                                          |

## Questions for the owner

1. **Finding 6, what "delete a person" keeps** (a business and legal decision, PDPA).
2. **Finding 7, how long visitor logs are kept** (a business and legal decision, PDPA).
3. **Hosting:** where will the app run? This unblocks R6 and R8.
4. **Real domain names:** the main domain, the `www` name, and the name any health check will use (R2).
5. **Dependencies:** keep the wide update (R4) or reduce it to the 7 advisory packages?
6. **Finding 9:** should the Security page offer two-factor later? The HLD says "optional 2FA later"; for now the text is honest.

## Not started, by instruction

Step 5i.2 (activate `CLAUDE.md`, needs approval of `docs/CLAUDE_MD_PROPOSAL.md`) and the final §13.3 refresh (after all Phase 5 work).
