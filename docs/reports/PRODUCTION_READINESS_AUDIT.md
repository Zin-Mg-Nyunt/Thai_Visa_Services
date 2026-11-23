# Production Readiness Audit

- Date: 2026-11-23
- Type: read-only audit of the code and configuration, plus a cleanup of the Spaces integration-test objects. No application code, `.env`, `.env.example`, database or Git state was changed. Only this report was written.
- Scope: branch `master`, HEAD `d9a0f8c`, with uncommitted work (see A).
- Evidence labels: **source** = read in the code or configuration; **tested** = run in this audit; **report** = taken from an earlier report and not re-run; **not verified** = no evidence available.

## 1. Executive summary

The application is well built and well tested, but it is **not ready to deploy yet**. The code, the Spaces storage design and the security design are in good shape. What is missing is the deployment itself: there are no server files, no production configuration, and no mail setup. Four production settings are also still open: the proxy trust, the secure session cookie, the real domain, and an enforced Content Security Policy (CSP).

- The automated test suite passes (1,405 passed, 63 skipped, 0 failed), Pint passes, `vue-tsc` passes, and `composer audit` and `npm audit --omit=dev` report 0 advisories.
- **PHPStan fails with 19 errors**, so `composer test` and `composer ci:check` fail. There is no baseline file, even though earlier notes say there is one.
- Both Spaces buckets work from the app: read, write and delete passed on both, and the public URL and the private denial both behaved correctly.
- The local `.env` points at the same two real buckets that production would use.
- Nothing is wrong in a way that loses data. The blockers are missing configuration, missing infrastructure and unmade owner decisions, not defects in the code.

**Recommendation: READY AFTER REQUIRED FIXES** (section 12).

## 2. Spaces cleanup results (Phase 1)

I listed `spaces-integration-test/` in both buckets, using read-only listing through the app's own disk configuration. Each bucket held exactly one object under that prefix. Both matched the keys created and reported in our earlier test runs, and both had the expected size of 32 bytes. Each bucket's only top-level folder was that prefix, so no application data was near them.

| Bucket | Object key | Action | Verified |
|---|---|---|---|
| Private (`paingthu-visa-service-private`) | `spaces-integration-test/20261123-144905-67553dd0-private.txt` | Deleted | No longer exists; 0 objects remain under the prefix |
| Public (`paingthu-visa-service-public`) | `spaces-integration-test/20261123-145352-c5a9ec43-public_assets.txt` | Deleted | No longer exists; 0 objects remain under the prefix; its public URL now returns 403 |

**Left behind: nothing.** The earlier failed public uploads (write permission was missing) and the permission diagnostic created no objects. Only those two keys were deleted. No bucket was emptied, and no key, permission or bucket setting was changed. The temporary scripts are in the session scratchpad, not in the project.

## 3. Production readiness status

| Area | Status |
|---|---|
| A. Git and project state | WARNING |
| B. Environment and configuration | FAIL (production values not defined) |
| C. Database and migrations | WARNING |
| D. Spaces and file storage | WARNING (the app works; the setup needs decisions) |
| E. Security | WARNING |
| F. Application functionality | PASS for tested workflows; WARNING for the rest |
| G. Deployment and server | FAIL (nothing exists yet) |
| H. Tests and quality | WARNING (PHPStan fails) |

## 4. Findings by category

Severity is Critical / High / Medium / Low. "Before launch" says whether it must be resolved first.

### A. Project and Git state

**A1. WARNING, Medium: uncommitted work that deployment preparation could lose or mix up. Before launch: yes (commit or discard).**
- Source: `git status` shows modified `database/seeders/DatabaseSeeder.php`, `resources/css/app.css`, `resources/js/components/Heading.vue` and `resources/js/layouts/auth/AuthSimpleLayout.vue`, plus untracked `database/seeders/SiteSettingSeeder.php` and `tests/Feature/SiteSettingSeederTest.php`.
- `SiteSettingSeeder` and its test are a finished, tested feature but are not committed. A deploy from a clean clone would not contain them.
- The CSS, Vue and layout changes look like formatting-only edits (single quotes changed to double quotes in `Heading.vue`). Nobody has confirmed they are intended.
- Action: review, then commit or discard in a separate step.

**A2. WARNING, Medium: the CI workflow does not run on the default branch. Before launch: recommended.**
- Source: `.github/workflows/tests.yml:3-8` runs on pushes to `main` and on pull requests. The repository's branch is `master`. Pushes to `master` run no CI; only pull requests do.
- Action: change the trigger to `master`, or rename the branch.

**A3. WARNING, Low: other branches and stash.** `phase-4-step-5` and `docs/hld-v0.9` exist locally. No stash. Not a deployment risk, but confirm both are merged or intentionally dormant.

**A4. WARNING, Low: documents.** The reconciled HLD v0.9 is approved and the code was reconciled with it on 2026-11-19. v1.0 is not approved. The Spaces storage work, the site-settings feature and the trusted-host protection came after, or alongside, that reconciliation. Check that the HLD and the docs mention them before launch (`docs/reports/FILE_STORAGE_SPACES_MIGRATION_AUDIT.md` covers storage). `CLAUDE.md` activation (Step 5i.2) and the final §13.3 refresh are deliberately not started.

### B. Environment and configuration

The local `.env` is a development file, as expected (`APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=http://localhost:8000`, `LOG_LEVEL=debug`, `MAIL_MAILER=log`, `GOOGLE_REDIRECT_URI` on localhost). The table lists what production needs. Values are reported as configured, missing or needing verification, with no secrets shown.

| Setting | Production requirement | Status |
|---|---|---|
| `APP_ENV` | `production` | Not set for production; not verified |
| `APP_DEBUG` | `false` | Not set for production |
| `APP_URL` | real `https://` domain | Not set; a wrong value makes **every request return 400** (R1 in `PHASE_5_OPEN_RISKS.md`, `config/security.php`, `app/Support/TrustedHosts.php`) |
| `TRUSTED_HOSTS` | `www` name and any health-check name | Not set; no domain decided |
| `APP_KEY` | new key generated on the server | Local key set; the production key must be new and must never be the local one |
| `DB_*` | production MySQL, utf8mb4 | Not configured; only the local database exists |
| `SESSION_SECURE_COOKIE` | `true` | **Missing** from `.env` and `.env.example` (`config/session.php:172`) |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` (as now) | Works on MySQL, but see G for the worker |
| `LOG_STACK`, `LOG_LEVEL` | `daily`, `warning` or `error` | Local values are `single` and `debug` (no rotation, verbose) |
| `MAIL_*` | a real SMTP service | Local value is `log`. **Missing for production** |
| `STORAGE_DRIVER` | `s3` | Local `.env` already uses `s3` |
| `DO_SPACES_*` | production keys, region `sgp1` | Configured locally (see D) |
| `PUBLIC_ASSETS_URL` | public bucket address or CDN | Configured locally |
| `GOOGLE_*` | production client and the production redirect URI | Local values only; the production URI must be added in the Google console |
| `CSP_ENFORCE` | `true` once the browser console is clean | Still report-only |
| `TRUSTED_PROXIES` | does not exist | The proxy list is hard-coded (see E) |

**B1. FAIL, High: no production environment definition exists.** There is no `.env.production` template and `.env.example` carries development defaults (`APP_ENV=local`, `APP_DEBUG=true`, `LOG_LEVEL=debug`, `APP_URL=http://localhost:8000`). Open risk R6 is still waiting on the hosting answer. Action: write a production checklist or template from the table above. Must be resolved before launch.

**B2. FAIL, High: mail is not configured, and two features need it.** `Features::resetPasswords()` and `Features::emailVerification()` are enabled (`config/fortify.php`). With `MAIL_MAILER=log`, reset emails would only be written to the log file. Action: pick an SMTP provider, or decide that password reset is unavailable and Google sign-in is the only recovery. Must be resolved before launch.

**B3. WARNING, High: the local `.env` points at the real production buckets.** Development uses the same `paingthu-visa-service-*` buckets and `STORAGE_DRIVER=s3`. Running a local seeder, uploads or tests-by-hand would write to production storage. The automated tests are protected (`phpunit.xml` forces `STORAGE_DRIVER=local`), but a developer is not. Action: separate development buckets or keys, or `STORAGE_DRIVER=local` for development. Recommended before launch.

**B4. WARNING, Medium: `PUBLIC_ASSETS_URL` and trusted hosts.** The URL is a fixed bucket address, which is correct, and the CSP builds its image source from it (`SecurityHeaders::policy`). No problem found, but see E for the CSP.

### C. Database and migrations

**C1. PASS, source: migrations look safe on a fresh MySQL database.** There are 16 migrations, ordered by date, with sensible foreign keys (`restrictOnDelete` on money and document tables, `nullOnDelete` on user references, `cascadeOnDelete` only on `client_pin_attempts`). Phone uniqueness among active clients is solved with a MySQL-compatible index (`2026_10_30_092029_...`). None of the migrations assumes existing data. The full MySQL suite passed in the Phase 4 review (report), and `PHASE_5_MYSQL_CHECKS.md` describes the audit.

**C2. NOT VERIFIED, High: migrations have not run on a production-like server.** `db:check-server` (R8) has not been run. Action: run `php artisan migrate --force` on a separate, disposable staging MySQL database of the same version as production, then `db:check-server`. Before launch: yes.

**C3. WARNING, High: `ServiceSeeder` inserts business facts in every environment.** `database/seeders/ServiceSeeder.php:34,61,71` sets default fees (3500, 4000, 8000 for Passport, Pink Card and Work Permit) and public descriptions. `DatabaseSeeder` calls it everywhere. Earlier rules say seeders must not invent business facts. Action: ask the owner to confirm these are the real fees, or remove the fees from the production seed. Before launch: yes.

**C4. PASS, source: `SiteSettingSeeder` cannot run in production.** It runs only when `APP_ENV=local` (`SiteSettingSeeder.php:36`), and `DatabaseSeeder` also guards the call. It writes example.com contact details and a 000 phone number. It is a development helper only. It is also uncommitted (A1).

**C5. PASS with WARNING: the first Super Admin.** `php artisan app:create-superadmin` creates it (`app/Console/Commands/CreateSuperadmin.php`): one only, hidden password prompt, validation, email marked verified. It is safe, but it is not described in any deployment document. Google sign-in only links to an existing user, never creates one (`GoogleAuthController.php:41-53`). Action: document the order: migrate, seed services, create the Super Admin.

**C6. WARNING, Medium: data-loss risks to plan around.** A deleted Person keeps name, phone, birthday and gender (PDPA finding 6, an owner decision). `client_pin_attempts` and `document_access_logs` keep IP addresses forever (finding 7). Neither loses data; both are retention decisions that are still open.

### D. DigitalOcean Spaces and file storage

| Check | Result |
|---|---|
| `private` disk to private bucket | PASS, tested: create, read, update, delete all worked (`config/filesystems.php`, `STORAGE_DRIVER=s3`, bucket `paingthu-visa-service-private`) |
| `public_assets` disk to public bucket | PASS, tested: create, read, update, delete all worked; public URL returned 200 with the right content |
| `public` disk to local public storage | PASS, source: unchanged, always local, not used by app code |
| Separate bucket credentials | PASS, source and tested: separate keys, secrets and buckets per disk |
| Region and endpoint | PASS, tested: `sgp1`, `https://sgp1.digitaloceanspaces.com` |
| Public URL generation | PASS, tested: `https://paingthu-visa-service-public.sgp1.digitaloceanspaces.com/<key>` |
| Private object anonymous access | PASS, tested: virtual-host URL, path-style URL and listing were all denied |
| Private document download | PASS, source: `DeliverDocument` redirects to a signed URL valid for `Document::SIGNED_URL_MINUTES` (the audit report describes 2 minutes) after the policy checks; tested earlier (report) |
| Client photos | PASS, source: served by a signed, one-hour route (`Client.php:75`, `routes/web.php`), not from a public URL |
| No exposed credentials or private URLs | PASS, source: disks are resolved through model constants; keys come only from environment variables; no private bucket URL is built by hand |
| Local fallback saving to the wrong place | WARNING, see D1 |

**D1. WARNING, Medium: an unset `STORAGE_DRIVER` falls back to `local`.** `config/filesystems.php` defaults to `local`. If the production `.env` loses this one line, documents and client photos would be written to the server's disk without any error. Action: put a check in the deployment checklist (and consider a boot-time guard later). Recommended before launch.

**D2. WARNING, Medium: the public bucket's anonymous listing was not tested.** Objects need to be publicly readable (confirmed), but the bucket itself should not list its contents anonymously. Action: check in the DigitalOcean panel that "File Listing" is Restricted on the public bucket, and that the private bucket stays Restricted. Before launch: yes.

**D3. WARNING, Medium: access key permissions.** Both disks need read, write and delete (document replacement and removal call delete), so the keys cannot be reduced to write-only. Use new, separate production keys, one per bucket, limited to that bucket. The keys used today were created for development; rotate them before launch if the development machine is not trusted. Before launch: recommended.

**D4. NOT VERIFIED: Spaces encryption at rest, backups and a virus scan** (PDPA finding 13). Spaces object versioning is not enabled, and nothing copies the buckets elsewhere (see G).

**D5. WARNING, Low: no CORS configuration was needed or checked.** The private download is a redirect and the public images are plain `<img>` loads, so no CORS is expected. Confirm in the browser on staging.

No upload or delete was done in the D checks of this audit. The results above are from the earlier integration test, which this audit also cleaned up.

### E. Security

Overall, source review matches the earlier `PHASE_5_SECURITY_PDPA_REVIEW.md` (13 findings, no Critical). Status of each:

| Item | Status |
|---|---|
| Admin authentication and roles | PASS, source: admin routes sit behind `auth`, `verified` and `role:superadmin` (`routes/web.php`); one Super Admin only; Google sign-in cannot create accounts |
| Registration | PASS, source: Fortify registration is not enabled (`config/fortify.php` features: reset passwords and email verification only) |
| Client portal | PASS, source and tests: token in the URL plus a PIN; wrong PINs are counted per client under a lock; `client.session` middleware; 10 downloads a minute per client and address |
| CSRF | PASS, source: standard `web` middleware group |
| Rate limiting | PASS, source: login 5 a minute, tracking and landing 60, Google 10, worker lookups 30, password change 6 |
| Upload validation | PASS, source: photos JPG only, 2 MB, type read from content; website images identified by their bytes (`IdentifyWebsiteImage`) |
| IDOR | PASS, source: scoped bindings on nested routes; policies for client, document and service |
| Error handling and logging | WARNING, see E4 |
| Security headers | WARNING, see E3 |
| Dependencies | PASS, tested: `composer audit` and `npm audit --omit=dev` found 0 advisories on 2026-11-23 |

**E1. FAIL, High: trusted proxies are loopback only, and the proxy list cannot be set from the environment.** `bootstrap/app.php` trusts `127.0.0.1` and `::1`. If production sits behind a DigitalOcean load balancer or App Platform, the app sees plain http: HSTS is never sent (`SecurityHeaders.php`), generated links use `http://`, and every rate limit and the PIN and document logs see one shared proxy address. With Nginx on the same Droplet this is fine. Action: decide the hosting layout (R6), and if there is a load balancer, add a `TRUSTED_PROXIES` setting and test it on staging. Before launch: yes.

**E2. FAIL, High: the session cookie is not marked Secure.** `SESSION_SECURE_COOKIE` is unset (`config/session.php:172`), so the cookie goes out without the flag even over https. Action: set `SESSION_SECURE_COOKIE=true` in production and add it to `.env.example`. Before launch: yes.

**E3. WARNING, Medium: the CSP is Report-Only.** `CSP_ENFORCE` defaults to false (`config/security.php`). Action: browse the public pages and the admin on staging, check the console is clean, then set `CSP_ENFORCE=true`. HSTS is sent only in production over https, for six months, without subdomains or preload (good). `X-Frame-Options: DENY` and `nosniff` are already set. There is no CORS configuration, and none looks necessary. Recommended before launch.

**E4. WARNING, Medium: logging.** `mask_bindings_in_exception_messages` is on (fixed in 5f.2), which keeps phone numbers and names out of logged query errors. But `LOG_LEVEL=debug` and a single unrotated file are the defaults, and the retention of IP-address rows is still undecided. Action: `LOG_STACK=daily`, `LOG_LEVEL=warning`, and a rotation limit (`LOG_DAILY_DAYS`, default 14). Before launch: yes.

**E5. WARNING, Medium: PDPA decisions pending (findings 6 and 7).** What "delete a person" keeps, and how long the PIN-attempt and document-access logs are kept. These are legal and business decisions for the owner, not code defects. Before launch: the owner should decide; the code can follow later if documented.

**E6. WARNING, Low: PIN is the last 4 digits of the phone, and the tracking token cannot be replaced** (finding 12, approved by the owner). Accepted risk.

**E7. WARNING, Low: no two-factor sign-in** (finding 13, later per the HLD).

**E8. WARNING, Low: dependency update was wider than needed** (R4): 62 packages changed, including `laravel/passkeys` 0.2.1 to 0.3.0, which the app does not use. Full test suites passed afterwards.

No intrusive security tests were run. No SQL injection or XSS pattern was found while reading the controllers and requests that this audit looked at; this is source inspection, not penetration testing.

### F. Application functionality

I did not run any manual workflow in a browser in this audit. The statuses below come from the automated suites (tested: 1,405 passing) and from reading the routes. The Playwright suite last passed on 2026-11-23 00:31 (`test-results/.last-run.json`: "passed", report) but I did not rerun it, so it may not include the uncommitted CSS and Vue edits.

| Workflow | Evidence | Status |
|---|---|---|
| Admin login (password and Google) | Feature and browser tests | PASS (production Google redirect URI needs adding) |
| Services and sub-services, icons | Tests | PASS |
| Person (client) management, unlock | Tests | PASS |
| Client services, payments, balances, refunds | Tests; money uses `bcmath` | PASS (see F1) |
| Job Search and worker assignment | Tests; business rules in `docs/JOB_SEARCH_BUSINESS_RULES.md` | PASS |
| Client portal and PIN lock | Tests, browser test | PASS |
| Website settings and landing page | Tests, browser test | PASS |
| Logo and favicon upload | Tests, local disk only | WARNING: the Spaces path was tested at the storage level, not through the admin form (F2) |
| Private documents and client photos | Tests (local disk), Spaces test (storage level) | WARNING: same as F2 |

**F1. WARNING, Low: the code never uses mock or placeholder content in production paths.** A search for `TODO`, `FIXME` and `XXX` in `app`, `resources/js`, `routes` and `database` found nothing. Burmese strings are unreviewed by a Burmese speaker (R9). Review is recommended before launch.

**F2. WARNING, Medium: no end-to-end test of uploads through the application against Spaces.** The storage layer works on both buckets, but the full flow (admin uploads a document, client downloads through the signed URL, photo viewed through the signed route, logo shown on the landing page with the CSP) has not been exercised through the application against Spaces. Action: do this once on staging. Before launch: yes.

### G. Deployment and server requirements

Nothing deployment-related exists in the repository: no Nginx or PHP-FPM configuration, no supervisor or systemd unit, no cron entry, no deploy or rollback script, no backup script, no Dockerfile.

| Item | Status |
|---|---|
| PHP | `composer.json`: `^8.3`; developed and CI-tested on 8.5. Install 8.5 (or 8.3 or newer after a test run) |
| PHP extensions | NOT VERIFIED for the server. The app needs at least `bcmath` (money maths), `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl`, `dom`/`xml`, `ctype`, `tokenizer`, `intl` is not required by `composer.json`. Run `composer check-platform-reqs` on the server |
| Composer | `composer install --no-dev --optimize-autoloader` |
| Node and build | Node 22 in CI (this machine runs 24). `npm ci`, then `npm run build` (Vite+). `public/build` is not in Git, so it must be built on the server or in a release step. `public/hot` exists on this machine and must **not** be on the server |
| Nginx, PHP-FPM, HTTPS | Missing. Document root `public/`, deny dotfiles, `client_max_body_size` above the document size limit, TLS certificate (for example Let's Encrypt) and redirect to https |
| Database | Missing: create the MySQL database and a user limited to it. Application user needs no DDL after migrating if you use a separate migration user (optional) |
| Permissions | `storage/` and `bootstrap/cache/` writable by the PHP user; `.env` mode 600 |
| Optimisation | `php artisan config:cache`, `route:cache`, `view:cache`, `event:cache`. Config caching also means `.env` is read once; remember to rebuild after any change |
| Storage link | Not needed for the Spaces disks. Needed only if the local `public` disk is used, which the app does not |
| Queue worker | Probably not needed: no queued jobs or queued notifications were found (only `User.php` mentions notifications, and Fortify's are sent immediately). Not verified in depth. If a worker is added, it needs supervisor |
| Scheduler and cron | `routes/console.php` schedules nothing. No cron is required today. A retention job (finding 7) would need one |
| Log rotation | The `daily` channel rotates by itself (14 files); `logrotate` is needed only for `single` |
| Backups | Missing: MySQL dumps (daily, copied off the Droplet) and a copy or versioning plan for the two buckets. A restore has never been rehearsed |
| Monitoring | Missing: disk space, MySQL, uptime check on `/up` (and its host name must be in `TRUSTED_HOSTS`, R2) |
| Deploy and rollback | Missing: a written sequence (see section 11). Maintenance driver is `file`, which is fine for a single server |
| Error reporting | None configured beyond log files |

**G1. FAIL, High: no deployment artefacts at all.** Must be created before launch.

### H. Automated tests and quality checks

I ran these on 2026-11-23 against the SQLite in-memory test configuration (`phpunit.xml` forces `DB_DATABASE=:memory:` and `STORAGE_DRIVER=local`, with `MAIL_MAILER=array`), so neither the development database nor Spaces was touched.

| Check | Result | Status |
|---|---|---|
| Pest, main suite | **Passed**: 1,468 tests, 1,405 passed, 63 skipped (mainly the two-factor tests, since two-factor is not enabled), 8,050 assertions, 149 s | PASS (tested) |
| Pest, MySQL suite (`phpunit.mysql.xml`) | Not run now (needs the testing database password). Earlier: passed (report, Phase 4/5) | NOT VERIFIED for the current code |
| Playwright browser tests | Not run now (needs a production build into `public/build`). Last run passed on 2026-11-23 00:31 (report) | NOT VERIFIED for the current code |
| Laravel Pint | Passed | PASS (tested) |
| PHPStan (level 7) | **Failed: 19 errors** | FAIL, see H1 |
| `vue-tsc --noEmit` | Passed | PASS (tested) |
| `vp check` (lint and format) | Not run; earlier report: fails on 4 Markdown docs and `my.ts` (R10) | NOT VERIFIED |
| Production frontend build | Not run (it would write to `public/build`); a build is part of the Playwright script | NOT VERIFIED |
| `composer validate` | Valid | PASS (tested) |
| `composer audit`, `npm audit --omit=dev` | 0 advisories, 0 vulnerabilities | PASS (tested) |

**H1. FAIL, Medium: PHPStan reports 19 errors, and there is no baseline.** Earlier notes (R10) call this "a baseline of 19", but `phpstan.neon` contains no baseline and no baseline file is in the repository, so the errors are reported live. `composer test`, `composer types:check` and `composer ci:check` therefore fail, and so would the CI job if it ran. All 19 are **pre-existing**; none can be tied to the uncommitted changes:
- 13 in `app/Actions/Dashboard/BusinessReport.php` and `app/Concerns/HandlesMoney.php` (`bcadd`/`bcsub` given `string` instead of `numeric-string`; one return-type mismatch).
- `CreateSuperadmin.php:55` (`CarbonImmutable` assigned to a `Carbon` property).
- `GoogleAuthController.php:23,46` (Socialite's interface lacks `with()` and `getRaw()`).
- `Client.php:90` (`string|false` passed to `removePhotoFile()`).
- `User.php:60` (comparison always true).

The `bcadd` ones touch money handling, so they deserve a proper look, not a suppression. Recommended before launch: fix or generate an honest baseline, so CI is green and new errors stand out.

**Tests I did not rely on:** the skipped 63 include `SecurityTest` and `EmailVerificationTest` behaviour that depends on features not enabled, so email verification is covered only where Fortify has the feature on.

## 5. Critical blockers (must be fixed before production)

No code defect blocks launch, but these must be done:

1. **Define the production environment** (B1): `APP_ENV=production`, `APP_DEBUG=false`, the real `https://` `APP_URL`, `TRUSTED_HOSTS`, a new `APP_KEY`, production MySQL, `LOG_STACK=daily`, `LOG_LEVEL=warning`.
2. **Settle the hosting layout and proxy trust** (E1), then test that https is detected.
3. **Set `SESSION_SECURE_COOKIE=true`** (E2).
4. **Configure real outgoing mail**, or decide to drop password reset (B2).
5. **Build the deployment infrastructure**: server, Nginx, PHP-FPM, TLS, MySQL, permissions, backups and a rollback plan (G1).
6. **Prove the migrations on a disposable staging MySQL** and run `db:check-server` (C2).
7. **Confirm the seeded service fees** (3500, 4000, 8000) are the owner's real values, or remove them (C3).
8. **Use separate production Spaces keys, confirm the buckets do not list anonymously**, and stop development from using the production buckets (B3, D2, D3).
9. **Commit or discard the uncommitted work** so what is deployed is what was reviewed (A1).

## 6. Important issues recommended before production

1. Fix the 19 PHPStan errors (or baseline them honestly), and make CI run on `master` (H1, A2).
2. Enforce the CSP after a clean console check on staging (E3).
3. Run an end-to-end upload and download test through the app against Spaces on staging (F2).
4. Add a deployment check that `STORAGE_DRIVER=s3` is set (D1).
5. Re-run the MySQL suite, the Playwright suite and the production build against the exact commit to be deployed (H).
6. Owner decisions on what deleting a person keeps and how long logs are kept (E5).
7. Burmese review of the interface text (R9).
8. Add the production Google redirect URI in the Google console (B).
9. Confirm the HLD and the docs mention the Spaces, site-settings and trusted-host work (A4).

## 7. Optional improvements that can wait

- Two-factor sign-in (HLD: later).
- Narrower dependency update (R4) and the Laravel Boost hook failure in `composer update` (R5).
- `vp check` failures on four docs and `my.ts` (R10).
- A boot-time guard against the local-disk fallback (D1).
- A scheduled retention job for the PIN-attempt and document-access logs (finding 7).
- Error reporting service and uptime monitoring beyond the basic check.
- Bucket versioning or a scheduled copy of the buckets.

## 8. Missing infrastructure and configuration

- Droplet (or other host), firewall, SSH hardening, automatic security updates.
- Domain name, DNS records, TLS certificate and renewal.
- Nginx site file, PHP-FPM pool, PHP settings (upload size, memory, OPcache).
- Production MySQL instance, database user, backups and a restore rehearsal.
- Production `.env` (kept out of Git), production Spaces keys, SMTP account, Google production redirect URI.
- Deploy script, rollback procedure, and a written first-deploy runbook (migrate, seed services, create the Super Admin).
- Supervisor (only if a queue worker is ever needed) and cron (only if a scheduled job is ever added).
- Monitoring and alerting for disk, database and uptime.

## 9. Manual steps for the project owner

1. Decide where the app runs (Droplet alone, or behind a load balancer) and the real domain names, including `www` and any health-check name.
2. Choose and set up an email provider, or decide how passwords are recovered.
3. Confirm the seeded service fees and descriptions are real.
4. Decide the two PDPA questions: what deleting a person keeps, and log retention.
5. In DigitalOcean: create separate production access keys for each bucket, confirm listing is Restricted on both buckets, and decide on backups.
6. In the Google console: add the production redirect URI.
7. Have a Burmese speaker review the interface text.
8. Approve (or reject) the proposed CLAUDE.md and the final HLD refresh when ready.

## 10. Prioritised pre-deployment checklist

1. Decide hosting, domain and mail (owner).
2. Commit or discard the uncommitted work; tag the release commit.
3. Fix PHPStan and CI trigger; get a green `composer ci:check`.
4. Write the production `.env` template (section 4B) and the server files (Nginx, PHP-FPM, deploy and rollback scripts).
5. Create the staging server and a disposable MySQL; run `migrate --force`, the services seed, `app:create-superadmin`, `db:check-server`.
6. On staging: run the end-to-end flows against Spaces (documents, photos, logo), check https detection, the secure cookie, HSTS, and the CSP console; then enforce the CSP.
7. Run the MySQL suite and the Playwright suite on the release commit.
8. Create production keys, set bucket listing to Restricted, confirm development no longer uses the production buckets.
9. Set up backups (database dumps off-server and bucket protection) and rehearse a restore.
10. Set up monitoring, log rotation and the `/up` check (with its host in `TRUSTED_HOSTS`).
11. Owner sign-off on the PDPA decisions and the Burmese review.

## 11. Proposed safe deployment sequence

1. Freeze a release commit on `master`; confirm green CI.
2. Provision the server, TLS, Nginx, PHP-FPM, MySQL, firewall.
3. Place the production `.env` (mode 600) with a fresh `APP_KEY`.
4. `composer install --no-dev --optimize-autoloader`; `npm ci && npm run build`.
5. Take a database backup (empty on first deploy, but the habit matters).
6. `php artisan migrate --force`; `php artisan db:seed --class=ServiceSeeder --force` (only the services, not the local sample content).
7. `php artisan app:create-superadmin`.
8. `php artisan config:cache route:cache view:cache event:cache`.
9. Restart PHP-FPM; reload Nginx.
10. Smoke test: `/up`, the landing page, admin login, one test person, one document upload and download, a client portal visit, the logo on the landing page. Delete the test data afterwards.
11. Turn on monitoring and backups; announce.
12. **Rollback:** keep the previous release directory and switch the symlink back; restore the database dump only if a migration changed data (the migrations in this release are additive on a fresh database, so a first deploy has nothing to roll back in the database).

## 12. Final recommendation

**READY AFTER REQUIRED FIXES**

The application itself is in good condition and the Spaces storage works on both buckets. But the project cannot be deployed today, because there is no production configuration, no server setup, no mail, no staging run of the migrations, and four settings (proxy trust, secure cookie, real domain, enforced CSP) are still open. Complete the nine blockers in section 5, then re-run the checks in H on the release commit.
