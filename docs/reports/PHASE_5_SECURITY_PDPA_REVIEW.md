# Phase 5 security and PDPA review (step 5f.2)

> **Scope.** A read-only review of the whole app against HLD Section 6 and the Thai PDPA basics, at commit `d5364cb` (after Phase 5 steps 5d.1–5h.1). It changed no code and no configuration. Evidence is from reading the code, running the test suites (PHP 1347 tests, browser 31 tests) and `composer audit` / `npm audit`. Nothing here was checked on a production or staging server: items that depend on the deployment are marked **Deployment**.
>
> **How to read it.** Each finding has a rating: **High** (fix before going live), **Medium** (fix soon), **Low** (worth doing), or **Note** (a decision or a limit that is already approved). Nothing is rated Critical: no way was found to read another person's data, reach the admin area as a client, or run injected code.

## 1. Summary

|   # | Finding                                                                                        | Rating                             |
| --: | ---------------------------------------------------------------------------------------------- | ---------------------------------- |
|   1 | Behind a proxy or load balancer the app will not see https (trusted proxies are loopback only) | **High** · Deployment              |
|   2 | The session cookie is not marked Secure unless `SESSION_SECURE_COOKIE` is set                  | **High** · Deployment              |
|   3 | Installed packages have published security advisories (Laravel 13.39.0, Symfony 8.1.9)         | **High**                           |
|   4 | No trusted-host list: URLs the app generates can follow the request's Host header              | **Medium**                         |
|   5 | Query values can appear in logged errors (`mask_bindings_in_exception_messages` is off)        | **Medium** · PDPA                  |
|   6 | A deleted Person keeps their name, phone, birthday and gender in the database                  | **Medium** · PDPA · decision       |
|   7 | No retention rule for PIN-attempt rows and the document access log (IP addresses)              | **Medium** · PDPA                  |
|   8 | `.env.example` defaults are development defaults (`APP_DEBUG=true`, `LOG_LEVEL=debug`)         | **Low** · Deployment               |
|   9 | The Settings page says "two-factor sign-in", but two-factor sign-in is not built               | **Low** · my error                 |
|  10 | The Content Security Policy is still Report-Only                                               | **Low**                            |
|  11 | PIN lock counters live in the cache                                                            | **Low**                            |
|  12 | Tracking token cannot be replaced; the PIN is the last 4 digits of the phone                   | **Note** (approved)                |
|  13 | No 2FA; Spaces encryption at rest and a virus scan are not confirmed                           | **Note** (HLD: later / to confirm) |

## 2. Findings

### 1. High · Deployment: trusted proxies are loopback only

`bootstrap/app.php` trusts only `127.0.0.1` and `::1`. If production runs behind a load balancer or a platform proxy (for example DigitalOcean App Platform, or a separate Nginx on another host), the app sees plain http from an untrusted address. Consequences:

- `Strict-Transport-Security` (step 5g.2) is **never sent**, because it needs a secure request.
- Generated links use `http://`, and signed URLs (client photos, Spaces redirects) can fail their signature check or point at the wrong scheme.
- The client IP written to the PIN-attempt and document logs, and used by every rate limit, becomes the proxy's address, so all visitors share one limit.

**Fix:** trust the actual proxy, from an environment variable (for example `TRUSTED_PROXIES`), and test on the real server. This needs your hosting answer ("Step 6").

### 2. High · Deployment: session cookie is not forced to Secure

`config/session.php`: `'secure' => env('SESSION_SECURE_COOKIE')`, and `.env.example` does not set it, so it is unset and the cookie is sent without the Secure flag even over https. The admin and the client portal both use this cookie.

**Fix:** set `SESSION_SECURE_COOKIE=true` in the production environment (and add it to a production checklist). `SameSite=lax` and `HttpOnly` are already on, which is correct.

### 3. High: packages with published advisories

`composer audit` reports advisories for versions that are installed:

| Package                                                             | Installed | Fixed in       | Note                                                                                                                                                                                                                                                                                                                                                                 |
| ------------------------------------------------------------------- | --------- | -------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `laravel/framework`                                                 | 13.39.0   | 13.40.0        | Low severity; the concurrency process-output case                                                                                                                                                                                                                                                                                                                    |
| `symfony/http-foundation`                                           | 8.1.9     | 8.1.11         | Four advisories, among them client-chosen host in generated URLs, trusted-proxy range handling, and a path-rule bypass when a front-end rule is in front of PHP                                                                                                                                                                                                      |
| `symfony/http-kernel`, `routing`, `yaml`, `serializer`, `phpseclib` | see audit | patch releases | Lower in practice: the app's own code calls neither the serializer nor a YAML parser; the serializer comes in through `web-auth/webauthn-lib`, the YAML packages are development tools (`laravel/roster`, `laravel/sail`), and `phpseclib` comes in through Socialite (it reads Google's keys, not an upload). `routing` and `http-kernel` still deserve the update. |

`npm audit --omit=dev` reports **0** vulnerabilities.

**Fix:** a normal `composer update` within the current constraints, then the full test suite. It is an update, not a code change.

### 4. Medium: no trusted-host list

The app does not restrict which Host headers it accepts, and does not force its root URL. Laravel builds URLs (`route()`, signed links, the tracking link and QR shown to the admin, password-reset links) from the request host. A forged `Host` header can therefore put an attacker's domain into a link the app emails or shows. The advisory above for `http-foundation` is the same class of problem.

**Fix:** either `TrustHosts` middleware with the real domain(s), or force the root URL from `APP_URL` in production, plus a test.

### 5. Medium · PDPA: query values in logged errors

`mask_bindings_in_exception_messages` is not set on the database connections, so a failed query's message can contain the values it was called with: a phone number, a name, a birthday. The Phase 4 review already made the retry log PII-safe; this is the general case. The plan listed it as 5f.4 and it is "not approved" today.

**Fix:** one setting per connection in `config/database.php`, plus a test that a forced query error logs no value. Nothing else changes. Combine with finding 8 (production log level).

### 6. Medium · PDPA · decision: what "delete a person" keeps

The approved design (HLD Section 6 and 4.12) removes the person's **documents and photo files** after the database commit and soft-deletes the record. What stays in the database: `name`, `phone`, `birthday`, `gender`, the tracking token, remarks, payments, and the PIN-attempt rows. The client can no longer sign in, and admin screens hide the person, but the personal data itself is retained.

Under the PDPA a person can ask for their data to be erased, with exceptions where the law requires records (for example accounting records). The design may well be right, because the money records must stay. But the **identifying fields** could be anonymised at deletion (name replaced, phone and birthday cleared) while payments and amounts stay. That is a business and legal decision, not a code one.

**Decision for you:** keep as designed (document why: financial record-keeping), or anonymise the identifying fields on deletion. I would ask your legal adviser before launch.

### 7. Medium · PDPA: no retention rule for logs of visitors

- `client_pin_attempts` keeps the IP address and browser string of every wrong PIN, forever.
- `document_access_logs` keeps the IP address of every document access, forever (the HLD requires the log; it does not say for how long).

An IP address is personal data. **Fix:** decide a retention period (for example 12 or 24 months) and add a scheduled clean-up. The audit value of the document log is real, so a long period is reasonable; the point is to state one.

### 8. Low · Deployment: `.env.example` defaults

`APP_DEBUG=true`, `LOG_LEVEL=debug`, `APP_ENV=local`, no `SESSION_SECURE_COOKIE`, `CACHE_STORE=database`. These are right for development, but a production `.env` copied from the example would show stack traces to visitors. **Fix:** a short production checklist next to the example (for example in the deployment section of the HLD): `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning` or higher, `SESSION_SECURE_COOKIE=true`, `PUBLIC_ASSETS_URL` set to the public bucket, `TRUSTED_PROXIES` set (finding 1), `CSP_ENFORCE` decided (finding 10).

### 9. Low · my error: "two-factor" text on the Settings page

In step 5b.1 I wrote the Security link's hint as "Your password and two-factor sign-in." (and its Burmese). The app has **no two-factor sign-in** (Fortify's two-factor feature is not enabled; HLD: "optional 2FA later"). The Security page only changes the password. It is a small false claim to the admin, not a security hole. **Fix:** change the two strings to "Your password." (`resources/js/locales/en.ts`, `my.ts`, key `siteSettings.account.securityHint`).

### 10. Low: Content Security Policy is Report-Only

Step 5g.2 sends the policy as Report-Only, as you chose. In the browser tests (31) no violation was reported on the landing page, the admin login, dashboard and client list, the appearance screen, the Add worker dialog, or the client portal. **Next:** browse the remaining admin screens once with the console open (Job Search order page, service pages, document upload, QR code), then set `CSP_ENFORCE=true`.

### 11. Low: PIN lock counters are in the cache

The wrong-PIN count and the 15-minute lock are kept in the cache (`TrackingPinGuard`). With the database cache store this survives restarts. If someone runs `cache:clear`, or the cache is moved to a store that is emptied (for example an in-memory one), the counters reset and a locked link unlocks. The attempts themselves are also written to the database, so the audit trail is not lost. **Fix, if wanted:** derive the lock from the database rows, or document "do not clear the cache casually".

### 12. Note (approved): token and PIN

- The tracking token is 48 random characters and never expires. It cannot be replaced (documented in the HLD), so a link shared by mistake stays valid. The PIN is the last 4 digits of the phone number, so anyone who has the link and knows the phone can sign in. The protections are: 5 wrong PINs lock the client for 15 minutes across all addresses, the PIN page shows nothing about the client, errors are generic, and the pages are `noindex` with `Referrer-Policy: no-referrer`. A future "regenerate link" action would remove the main residual risk.
- The token is in the URL, so it also appears in web-server access logs and browser history. Keep access logs private and short-lived.

### 13. Note: items the HLD already marks as later or "to confirm"

Two-factor sign-in (later), encryption at rest on the Spaces buckets (provider setting, to confirm), and a virus scan of uploads (optional).

## 3. What was checked and found sound

| Area                                 | Result                                                                                                                                                                                                                                                                                                                                                                                            |
| ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Admin sign-in**                    | Laravel session auth; bcrypt (12 rounds in the example); production password rule of 12+ characters, mixed case, numbers, symbols and not in a breach list; login limited to 5 a minute; no public registration (the admin account is created by a command); Google sign-in accepts only an **existing** admin whose Google email is verified, never creates a user, and links the Google id once |
| **CSRF, mass assignment, injection** | CSRF protection is on the web group. Models list their fillable fields. Every `selectRaw` or `whereRaw` uses a fixed string or bound `?` values; none interpolates user input (the client search escapes `LIKE` characters).                                                                                                                                                                      |
| **XSS**                              | No `v-html` and no unescaped Blade output anywhere. Admin text in the page head is escaped (tested with a script in the agency name).                                                                                                                                                                                                                                                             |
| **Open redirects**                   | The only redirects to outside addresses are the Spaces signed link the app builds itself. Sign-in uses the framework's "intended" URL.                                                                                                                                                                                                                                                            |
| **Authorization**                    | Every admin route is behind `auth`, `verified` and `role:superadmin`; policies and the `manage-site-settings` gate protect the screens. Tested: a signed-in client is sent to the admin login on seven admin URLs; guests and the portal see no admin data.                                                                                                                                       |
| **Client portal**                    | A client sees only their own services through their own record; another person's link needs that person's PIN; a deleted person's link is a plain 404; Job Search documents and a cancelled service's documents are never read for the portal. Tested end to end by the data exposure suite (19 tests), with six deliberate leaks all caught.                                                     |
| **Files**                            | Private disk with no URL of its own; random object keys; type judged from the file content, never the name, and size limits (PDF 5 MB, images 2 MB); every download is checked and written to the access log; on Spaces the redirect is a signed link of 2 minutes; `Cache-Control: private, no-store` and `nosniff` on files. Client photos need a signed link and the right visitor.            |
| **Website images**                   | Public bucket only for the three website images; SVG refused (checked by content); random names; old files removed only after the save commits.                                                                                                                                                                                                                                                   |
| **Headers**                          | `nosniff` everywhere, `X-Frame-Options: DENY`, `noindex` and no-referrer on client pages, HSTS in production over https (but see finding 1), the Content Security Policy (Report-Only).                                                                                                                                                                                                           |
| **Rate limits**                      | Login 5/min, tracking pages 60/min, document downloads 10/min per client and address, worker lookups 30/min per admin, Google sign-in 10/min, landing page 60/min.                                                                                                                                                                                                                                |
| **Logging**                          | The retry log is PII-safe (Phase 4); the app writes no personal data to the application log by itself (see finding 5 for query errors).                                                                                                                                                                                                                                                           |
| **Secrets**                          | No secret in `.env.example`; Google and Spaces credentials come from the environment only.                                                                                                                                                                                                                                                                                                        |

## 4. Suggested order

1. Findings 3 and 4 (update the packages; trusted hosts): code and test work, no business decision needed.
2. Findings 1, 2 and 8 together as one **production checklist**, once you tell me where the app will be hosted. They can only be proved on the real server.
3. Finding 5 (mask query values) and finding 9 (the wrong "two-factor" text): small, ready to do.
4. Findings 6 and 7 need your decision (and your legal adviser's): what a deletion keeps, and how long visitor logs are kept.
5. Finding 10 after one more console check, then switch the policy to enforcing.

Nothing in this report is built yet. Every fix needs your approval first.
