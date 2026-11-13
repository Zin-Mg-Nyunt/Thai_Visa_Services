# Thai Visa Management Web App – High-Level Design (v0.8)

Status: Draft v0.8 for client review and approval · Stack: Laravel, Vue.js, Inertia.js, MySQL · Hosting: DigitalOcean Droplet · File storage: DigitalOcean Spaces (approved)

## 1. Purpose and Scope

A web app for a visa-services agency in Thailand to manage clients, the services each client buys, payments, and documents. Clients get a simple read-only portal to track progress and download their own documents. The admin also manages Job Search (company hiring requests and the workers matched to them).

**In scope:** admin back office, client tracking portal with tracking link and QR code, service catalog with optional sub-services, payments and profit reporting (profit = received money), Work Permit document delivery, Job Search module, a public landing page, English and Burmese UI.

**Out of scope (v1):** online payment gateway, automated LINE/SMS notifications, additional staff roles (planned for version 2), mobile native apps. These can be added later (see Section 12).

## 2. Users and Roles

| Role | Authentication | Capabilities |
| --- | --- | --- |
| Admin | Email + password, or Google sign-in | Full access: clients, services, payments, documents, job search, dashboard, settings |
| Client | Tracking link or QR code (unique token) + 4-digit PIN (last 4 digits of phone) | Read own profile and service status; view and download own documents when permitted; switch UI language |
| Public visitor | None (no login) | View the public landing page only; no access to admin features or client data |

v1 has two roles: admin (full permission) and client (read and download their own data only). The `users` table keeps a `role` column so more roles can be added in version 2; they are not part of v1.

## 3. System Architecture

```
Browser (Vue 3 + Inertia pages, English / Burmese UI)
        |  HTTPS
DigitalOcean Droplet (client's DigitalOcean account)
   Laravel app (routes, controllers, policies, services, jobs)
   |-- MySQL  (on the same Droplet)
   |-- Queue + scheduler (cleanup, reports; database/Redis driver)
   |-- Google OAuth (admin sign-in)
   |
   |  S3-compatible API (private access keys from .env)
DigitalOcean Spaces - approved, separate resource, client's account
   private bucket: user photos, PDF documents
   public bucket: website logo, favicon, hero image
```

**Key choices**

- **Inertia.js monolith:** Laravel controllers return Vue pages directly. No separate REST API is needed for v1, which keeps auth, validation and policies in one place.
- **Private object storage (DigitalOcean Spaces, approved):** no public objects. Files are reached only after Laravel checks permissions (Section 3.1).
- **Laravel Socialite** for Google sign-in; **Laravel Policies** for authorization; **Form Requests** for validation.
- **Money** stored as integers (satang) or `DECIMAL(12,2)`, never floats. Currency is THB.
- **Localization:** Laravel language files and vue-i18n provide the English and Burmese UI (Section 4.9).

### 3.1 File Storage (DigitalOcean Spaces – approved)

DigitalOcean Spaces is **approved by the client** as the file storage provider. It replaces the AWS S3 option in the earlier draft. Spaces is S3-compatible, so Laravel uses its standard S3 filesystem driver pointed at the Spaces endpoint. Hosting (Droplet) and storage (Spaces) are separate resources with separate cost estimates (Section 10.1).

| Topic | Design |
| --- | --- |
| Provider | DigitalOcean Spaces (S3-compatible object storage) |
| Environments | Development uses the Laravel local filesystem disk on the developer machine, so no cloud account is needed. Production uses DigitalOcean Spaces. The disk is chosen by an environment variable, the application code is the same, and the database stores the same relative key in both. In development, private files are served through a permission-checked Laravel route and public website images from the local public disk. This is server-side file storage; the browser localStorage is used only to remember the selected language (Section 4.9). |
| File types | User photos and PDF documents (private bucket), plus the website logo, favicon and hero image (separate public bucket, Section 4.11) |
| Storage access | Private by default. Public access must not be enabled for private documents: no public-read ACLs, and no CDN on private files. The only exception is a separate public bucket that holds the website logo, favicon and hero image (Section 4.11) |
| Authorization | Laravel verifies permissions before any private file is accessed. Admin has full access; a client reaches only files on their own record (never job order documents), and Work Permit documents only when the record is Fully Paid or the admin has released that document (release does not depend on payment status) |
| File delivery | Short-lived signed URL (option B, selected): Laravel checks permissions first, then redirects to a signed Spaces URL that expires within minutes |
| Configuration | Access keys, bucket name, region and endpoint are kept in environment variables and never committed to Git; the repository holds only a `.env.example` with variable names |
| Ownership | Production infrastructure and billing belong to the client's DigitalOcean account |

| Delivery option | How it works | Trade-off |
| --- | --- | --- |
| A. Laravel-controlled download | Laravel checks permission, reads the file from Spaces and streams it to the user | Strongest control and simple audit trail; file traffic passes through the Droplet |
| B. Short-lived signed URL | Laravel checks permission, then redirects to a signed Spaces URL that expires within minutes | Less load on the Droplet; the URL works for anyone holding it until it expires |

Option B (short-lived signed URL) was selected; option A is kept for reference.

### 3.2 Image and Document Upload Rules

| File | Accepted formats | Size limit | Notes |
| --- | --- | --- | --- |
| Website logo | PNG, JPG, WebP, SVG | 2 MB | Public bucket; required; SVG is sanitized |
| Favicon | PNG, ICO | 2 MB | Public bucket; required |
| Hero image | JPG, PNG, WebP | 2 MB | Public bucket; optional, the area is left blank if absent |
| Profile photo | JPG only | 2 MB | Private bucket; a user icon is shown if absent |
| Other advertising images | To be defined when added | 2 MB | Public bucket; the image area is left blank if absent |
| PDF documents | PDF | Maximum 5 MB | Private bucket |

- All photo and image files must not exceed 2 MB. The browser reduces images before upload (Section 3.3).
- File type and size are validated in the browser and again on the server; the server check always applies.
- SVG files are sanitized (scripts and external references removed) before they are stored and before they are displayed.
- Public website assets live in a separate public bucket. Client profile photos and private documents stay in private storage, apart from the public assets.
- The database stores object keys, never the uploaded image files.

### 3.3 Frontend Image Size Reduction

Images are reduced in the browser before they are uploaded, so large photos from a phone camera do not fail the 2 MB limit and storage and page loading stay light. Uploads are made by the admin only.

| File | Processing in the browser |
| --- | --- |
| Profile photo | Resized and re-encoded as JPG (the only accepted format) to 2 MB or less |
| Logo (PNG, JPG, WebP) | Resized if very large; PNG and WebP keep transparency. SVG is not processed in the browser; it is sanitized on the server |
| Hero image and other advertising images | Resized and re-encoded (JPG or WebP) to 2 MB or less |
| Favicon (PNG, ICO) | Not changed; only validated |
| PDF documents | Not changed; limited to 5 MB |

**Steps**

1. The file type is checked first; an unsupported file is refused with a message in the selected language.
2. The image is drawn at a reduced size (a maximum width and height per image type, set at implementation), with the camera orientation applied.
3. It is re-encoded, lowering the quality step by step until it is 2 MB or less. If it still cannot reach 2 MB, the admin sees an error and nothing is uploaded.
4. The admin sees a preview and the final file size before saving.
5. Re-encoding removes embedded metadata such as camera details and location.

**Rules**

- The reduction is a convenience, not a security control: the server still checks type and size and rejects anything over the limit (Section 3.2).
- It runs in the browser, using the Canvas API or a small library, and images are not sent to any third-party service.

## 4. Functional Modules

### 4.1 Client Portal

1. **Tracking link:** `https://app.example.com/track/{unique_token}`. The token is a long random string (at least 32 chars), one per client, generated by the admin as a link or QR code (Section 4.8), shared with the client, and never expiring.
2. **Login:** the page asks for a 4-digit PIN. The server compares it with the last 4 digits of the client's stored phone number for that token. On success this client is signed in on that browser and sent to /client/{unique\_token}/profile. Several clients can be signed in on one browser. Logout (POST /client/{unique\_token}/logout) signs out only that client and returns to that client's PIN page. Repeated wrong PINs lock the client (Section 6).
3. **Profile page:** name, phone, birthday, gender, payment status (Deposit / Fully Paid) with received amount and remaining balance, service status, remarks.
4. **Per-service view:** each service shows its main service title with the optional sub-service title next to it, for example “Passport – Bath”; with no sub-service only “Passport” is shown.
   - **Work Permit:** list of documents the admin uploaded. Each document is either **Downloadable** (button shown) or **Locked** (message with the received amount and the remaining balance to pay).
   - **Other services:** only status (In Progress / Complete / Cancel); no documents section.
5. **Permissions:** a client can only see rows where `client_id` equals their own. Enforced in policies and in every query scope. Job order documents (Job Search) are never shown to the client; the client sees only the documents of their own other services, such as a Work Permit.

### 4.2 Admin Back Office

**Dashboard tabs:** Business, Services, Clients, Settings.

- **Business dashboard:** total received, outstanding balances, profit, by month and by main service with an optional sub-service breakdown; Job Search profit is included in the overall service profit. Date-range filter. Total received is the money kept (received minus refunds; not a net profit, as costs are not tracked) and does not drop when a person is deleted: a deleted person's financial history stays in it. **Outstanding** is the balance still owed today by clients who are not deleted: a deleted person's unpaid balance is left out, the same as the Clients list. **Active clients** counts clients who are not deleted. A deleted service stays in the figures with status inactive (Section 4.12).
- **Clients:** table with search; filter by service, sub-service and status; create/edit; cancel and delete follow Section 4.12.
- **Services:** catalog management for services and sub-services (add, edit, deactivate, delete), the clients of each service shown with their sub-service or “Parent service only”, a sub-service filter on that client list, and per-service statistics with an optional sub-service breakdown.
- **Settings:** admin account, Google sign-in link, default currency, document-lock default, public website content (Section 4.10) and website appearance (Section 4.11).

### 4.3 Client Management (CRUD)

Fields: profile picture, name, phone (required and unique), birthday, gender, remarks, unique\_token (auto). Per assigned service: main service and optional sub-service, status (In Progress / Complete / Cancel), start date, end date, service fee, received amount, remaining (calculated), remarks. Job Search is not offered in the normal “Add Service to Client” form; it is assigned through the job order workflow (Section 4.7).

A client can hold **several services** at once (for example Passport + Work Permit), so fees, payments and status belong to the *client-service* record, not the client.

**One person record per individual.** The phone number is the main identifier: it is required, it must be unique, and a client without a phone number cannot be created. Before creating a record the admin searches by phone, and an existing person is reused instead of duplicated. The same record serves as a client, a Job Search worker, or both; worker-specific and service-specific data sit in separate tables linked to the person (Section 5). Cancel and delete follow the rules in Section 4.12: cancel keeps the record, delete removes the files permanently and soft-deletes the database record.

**Profile photo.** JPG only, maximum 2 MB, stored in the private bucket (Section 3.2). If a person has no photo, a user icon is shown. Large photos are reduced in the browser first (Section 3.3); type and size are then checked in the browser and again on the server.

### 4.4 Service Catalog

Initial seed: Passport (sub-services: Bath, MOU), CI, Pink Card, Work Permit, 90 Days Report and Job Search. Work Permit and Job Search have documents. Default fees: Work Permit THB 8,000, Passport THB 3,500, Pink Card THB 4,000; the other services have no default fee until the admin sets one.

- Each service: title, status (active/inactive), default fee, `has_documents` flag (true for Work Permit and Job Search), optional `parent_id` for sub-services.
- Admin can add, edit or delete services and sub-services at any time (delete rules: Section 4.12).
- **Service overview cards:** total clients, in-progress count, complete count, cancelled count, total received payment (the money kept: received minus refunds). The counts are operational and leave out deleted clients, so they match the Clients list the service filter leads to; a cancelled service counts in total clients and in the cancelled count. The money is financial and includes every client-service record, also those of deleted clients and deleted sub-services. Everything is added up in the database in whole satang.

**Sub-services.** A main service can have optional sub-services (for example Passport – Bath and Passport – MOU).

- **One table, two levels:** main services and sub-services live in the same `services` table. A sub-service points to its main service through the nullable `parent_id`. There are at most two levels: Main Service and Sub-service; a sub-service cannot have its own sub-services.
- **Optional selection:** choosing a sub-service for a client is always optional.
- **Client-service link:** `client_services.service_id` always stores the main service ID. `client_services.sub_service_id` is nullable and stores the selected sub-service, if any.
- **Validation:** the backend and the database both validate the parent-child relationship: the selected sub-service must belong to the selected main service.
- **Fees:** a sub-service may have its own default fee. The default fee for a client-service is the sub-service default fee first, otherwise the main service default fee.
- **Payments and profit:** payments and profit stay on the client-service record. Profit is grouped by main service, with an optional sub-service breakdown.
- **History:** existing records and financial history are preserved when a sub-service is deactivated or soft-deleted (Section 4.12).

**Job Search service.** Job Search remains a **main service** and cannot have sub-services. It keeps the fixed system key `job_search` and is excluded from the normal “Add Service to Client” form. Its separate workflow (Section 4.7) and profit rules are unchanged, and its profit is still included in the overall service profit and in the dashboard reports.

**Bilingual service content.** The `services` table holds four nullable fields: `title` (English), `title_my` (Burmese), `public_description` (English) and `public_description_my` (Burmese). A **show on website** switch decides whether a service appears on the landing page (Section 4.10).

- **Validation:** when creating or updating a service, the admin must give a title in at least one language and a public description in at least one language. The other language version is optional and may be left empty.
- **Enforcement:** the rules are checked in the frontend and always enforced by the backend, whatever the frontend does.
- **Form:** the admin service form has separate English and Burmese inputs for the title and for the public description.
- **Display:** a service is shown in the selected language first and falls back to the other language if that value is missing or empty.

### 4.5 Payments

Rather than a single "received money" number, v1 keeps a **payment ledger** (`payments` table): amount, date, method, note, per client-service. This gives:

- `received = SUM(payments)`; `remaining = fee - received`.
- **Payment status** derived automatically: `received = 0` → Unpaid; `0 < received < fee` → Deposit; `received >= fee` → Fully Paid.
- Audit trail and accurate monthly profit reports.

The admin UI can still show a simple "received money" field; saving it creates or adjusts a ledger entry.

**Visibility:** admin and client both see the received amount and the remaining balance for every payment status (Unpaid, Deposit, Fully Paid). The money counted in reports is the amount kept: received money less refunds (fee-change refunds and cancel refunds); v1 tracks no costs, so it is not a net profit.

### 4.6 Work Permit Documents

- Admin uploads one or more files per Work Permit record (PDF/JPG/PNG; images up to 2 MB, PDFs up to 5 MB, Section 3.2), with a title and type.
- Each document has `client_can_download` (boolean) controlled by the admin per document.
- Documents unlock automatically when the record becomes Fully Paid. Separately, the admin can release any single document with the per-document flag; this release does not depend on the payment status and does not change it.
- Clients see Locked documents as a title plus remaining balance, with no file access.
- Download flow: request → policy check (owner? unlocked?) → short-lived signed Spaces URL that expires within minutes (option B, Section 3.1). Downloads are logged.

### 4.7 Job Search Module

**Job orders (companies):** company name, manpower needed, male/female split, interview date, start-working date, profit (calculated from worker payments, read-only), status (Open / Done / Cancelled).

- **List view:** Excel-like table (sortable, filterable, inline status). One-tap **Done** and **Cancel** buttons with a confirmation step; both close the order (Section 4.12).
- **Single tap on company name** opens the detail page. **Implemented now:** the order details, the requested manpower and male/female split, the **active workers** and **cancelled workers** counts, the over-manpower warning, and the worker list with case status, service status (cancelled workers are marked) and payment status (fee, received, remaining). Open orders also have an edit page with the same worker list and the Add worker dialog; a closed order has only the detail page. **Deferred to the next step (Step 5, not built yet):** a total visible workers count (active + cancelled), active male/female counts, the order's money total, links from a worker row to the person's record, and an "over by N" wording of the warning.
- **Worker counts:** a worker is **active** when the person is not deleted, the worker's Job Search service is not Cancel, and the case status is not **Rejected**. A worker whose Job Search service is Cancel is a **cancelled worker**: still listed as history, counted apart, never active. A rejected worker may still have a service In Progress; the case status alone leaves them out of the active count, and their record, payments and history stay. A deleted person is left out of every worker list and count, but the money of their Job Search record is kept (below).
- **Manpower warning:** manpower is the company's request, not a limit, and the male/female counts are not checked against it. When the active workers are more than the manpower (`active workers > manpower`), the list and the detail page show a warning; adding workers is never blocked. Cancelled workers, rejected workers and deleted people do not count toward it.
- **Single tap on worker name** opens worker detail: name, phone, birthday, gender, case status, payment status (fee, received, remaining if deposit), and only the documents that belong to this job order. Documents from the person's other services, such as a Work Permit, are not shown here; they stay on that service record.

**Job Search as a main service.** Job Search is a main service with the fixed system key `job_search`. It cannot have sub-services, and it is not offered in the normal “Add Service to Client” form: a Job Search service record is created only when a worker is added to a job order. The general sub-service rules (Section 4.4) therefore do not apply to it, and `client_services.sub_service_id` stays empty for Job Search records.

**Modelling decision (confirmed):** one shared person record per individual (the `clients` table). A person can be a normal client, a Job Search worker, or both. Linking a worker to a company adds a `job_order_workers` row (worker-specific data such as case status) and a Job Search `client_services` row (fee, payments, documents), both pointing to the same person. Section 4.3 states the rule that prevents duplicate records.

**Adding a worker to a job order:** the admin first searches by phone number. If a person matches, that record is reused. If not, the admin creates a new person record directly from the job order screen (phone number required). A person does not need an existing service record to be added as a worker; linking creates the Job Search service record.

**Documents per service association.** A worker's documents belong to the Job Search service record created for that job order (`documents.client_service_id`), not to the person in general. If a person first had a Work Permit and later becomes a worker, the two document sets stay separate: the worker detail shows only the job order documents, and the Work Permit documents stay on the Work Permit record. Job order documents are for the admin only; the client portal never shows them.

**Money of a job order:** read-only. It is the money kept on its workers' Job Search records: received minus the fee-change refund and the cancel refund (`kept`), the same rule as every other service. It is not called net profit, because costs are not tracked in v1. The admin cannot enter or edit it. Job Search money is included in the service statistics and in the dashboard reports (Section 4.2). **Not built yet (Step 5):** the per-order total. It must be added up in the database from `job_order_workers` joined to `client_services`, with payments summed per `client_service_id` first, in whole satang; it must not use the operational worker filter or a join that drops deleted people, so it includes the kept money of deleted people, cancelled workers and rejected workers.

### 4.8 Tracking Link and QR Code

- The admin opens a client and taps **Share access**. The system shows the tracking link (`https://app.example.com/track/{unique_token}`) and a QR code of the same link, with copy and save-image buttons.
- The QR code is generated on demand from the token. It is **not stored in the database** or in Spaces; only `unique_token` is stored.
- The link and QR code **have no expiration**. The token is created once with the client record. Regenerating a token is not supported in the current application (Section 6).
- Opening the link or scanning the QR code leads only to the PIN screen; the client still needs the 4-digit PIN (Section 6).
- Each client has one active token, created automatically with the client record.

### 4.9 Localization (English / Burmese)

- **Languages:** the admin back office, the client portal and the public landing page are available in **Burmese (Myanmar) and English**, with a language switcher on every page, including the PIN screen.
- **Default language:** **Burmese (Myanmar)**. The selected language is saved in the browser's `localStorage`, so it is remembered across page navigation and refreshes in that browser and can be changed at any time. Because `localStorage` is read in the browser, the app applies the saved choice at start-up, before the first screen is drawn where possible.
- **Localization files:** Laravel language files and vue-i18n hold all UI labels, buttons, navigation items, validation messages, status labels and user-facing system messages, including the messages returned by server-side validation. No text is hard-coded in components.
- **Fallback:** if a translation or a database value is missing or empty in the selected language, the other language is shown. If neither language has a value, an empty value is shown, never a raw translation key.
- **Fonts:** Burmese text uses Unicode with a bundled web font, so it displays the same on every device.
- **Entered data:** data typed by the admin (names, remarks) is shown exactly as entered, with no automatic translation. Service titles and descriptions, and the landing page content, have separate English and Burmese fields (Sections 4.4, 4.10 and 4.11).

### 4.10 Public Landing Page

A public home page at `/` lets anyone learn about the agency without logging in. It is read-only and open to search engines (the tracking pages stay `noindex`), and it follows the English / Burmese switch (Section 4.9).

| Section | Content | Source |
| --- | --- | --- |
| Navigation bar | Logo and agency name, links to the page sections, language switcher, and a clearly visible **Admin Login** button that opens the existing `/admin/login` page | Fixed layout |
| Hero | Hero image, hero heading and description (editable, Section 4.11), a clear summary of what the agency does, and buttons to Services and Contact | Site settings |
| Agency overview | Experience, mission, and how the agency helps its clients | Site settings |
| Services | One card per main service marked show on website, with title and short description (for example employment services, work permit assistance). Sub-services are never shown as separate cards | Services table |
| Agency statistics | Verified aggregate numbers only: Clients Served (unique people with at least one service record, including cancelled and deleted ones) and Services Provided (number of available main services, not sub-services and not individual service records) | Calculated from the database |
| Contact | Owner or business phone, email, address and social media links, with tap-to-call, email and messaging links; only fields that are filled in are shown | Site settings |

**Rules**

- **Real statistics only:** numbers are calculated from the database and never hard-coded or typed in. The admin chooses in Settings which statistics are approved for display. Only totals are shown: no names, photos, phone numbers, documents, payments, profit or per-person data. A number that cannot be calculated is hidden, not replaced by a placeholder.
- **Caching:** statistics are cached for a short time (for example 10 minutes) so public traffic does not query the database on every visit.
- **Editable content:** the admin edits the agency name, hero heading and description, overview, mission, contact details and social links in Settings (logo, favicon and hero image: Section 4.11), with separate English and Burmese values; if one language is missing, the other is shown. The developer drafts the text and does not invent business facts.
- **Separation from admin features:** public routes use their own controller, return only the fields listed above, and sit outside the login middleware. The Admin Login button is only a link; access still requires admin sign-in (Section 6).
- **Protection:** basic rate limiting and standard security headers on public pages.
- **Contact links:** tap-to-call, email and messaging links open the visitor's own app. A contact form is not part of v1.

**Content drafting and review.** The developer drafts professional English and Burmese text for the hero heading, hero description, agency overview, mission, services introduction and contact section. Nothing is invented: no agency name, years of experience, licences, qualifications, client counts, success rates, contact details or other unverified business information. Anything the client has not provided stays empty. The draft stays unpublished (`is_published` is off on the site settings) until the client reviews and approves it. After publishing, saved changes appear immediately (Section 4.11). Contact details and statistics stay hidden until they are provided or can be verified.

### 4.11 Website Appearance Settings

An admin screen at `/admin/settings/appearance` (Settings tab) controls how the public landing page (Section 4.10) looks.

| Setting | Behaviour |
| --- | --- |
| Website logo | Required; upload and replace; shown in the navigation bar. Accepted: PNG, JPG, WebP, or SVG after sanitization |
| Favicon | Required; upload and replace; shown in the browser tab. Accepted: PNG or ICO |
| Hero image | Optional; upload and replace the image in the homepage hero section. If none is uploaded, the hero image area stays blank. Accepted: JPG, PNG, WebP |
| Hero heading | Editable text, in English and Burmese |
| Hero description | Editable text, in English and Burmese |

**Rules**

- **Authorization:** only authorized admins can view or change these settings. Every route and action is protected by a Laravel policy (for example `manage-site-settings`). In v1 every admin has it; when roles are added in version 2 it can be granted per role. Clients and public visitors cannot reach the screen, and refused attempts return 403 and are logged.
- **Storage:** images are stored in DigitalOcean Spaces and only their **storage keys** are saved in the database (`logo_key`, `favicon_key`, `hero_image_key`), never the files or fixed URLs. Keys are random, not the original file names, so a replacement gets a new key.
- **Public bucket:** a public landing page cannot use short-lived signed URLs, so these three images go in a **separate public Spaces bucket** used only for website images. The private bucket for documents and photos stays private (Section 3.1). The public image address is built from a configured base URL plus the storage key.
- **Upload checks:** file type is verified from the file content, not only the extension; every image is limited to 2 MB (Section 3.2) and is reduced in the browser before upload (Section 3.3); the checks run in the browser and again on the server, and the server decides; SVG is accepted only for the logo and is sanitized before it is stored or displayed.
- **Save and publish:** validate, upload the new file to Spaces, then save the new key and text in one database transaction and clear the cached site settings. If any step fails, the previous logo, favicon, hero image and text stay unchanged and the admin sees an error. The old file is deleted from Spaces only after the new settings are saved.
- **Changes appear after saving:** the public site reads settings that are refreshed on every successful save, and image addresses carry a version value (from `updated_at`) so browsers fetch the new image straight away. Some browsers keep favicons cached longer than other images.
- **Required and optional images:** the logo and favicon are required, so the landing page cannot be published without them; the hero image is optional and its area is left blank when there is none; empty hero text is hidden.
- **Audit:** each change records who made it and when (`updated_by`, `updated_at`, plus an audit log entry).

### 4.12 Cancel, Close and Delete Rules

**Service status.** Every client service record has one of three statuses: **In Progress**, **Complete** or **Cancel**.

**Job order: Done or Cancel (closing)**

- Done and Cancel are the same kind of action: both **close** the job order.
- A closed job order cannot take any new worker.
- Workers already on the order can still pay their remaining balance after it is closed. The profit already received for the job order cannot be reduced. The only way it goes down is a worker cancellation with a refund (below).

**Worker or client cancel (cancel is not delete)**

- When the admin cancels a worker or client on a service, the service status becomes **Cancel**.
- If the person had paid only a deposit, the remaining balance becomes 0.
- A **refund** field appears on cancel. The admin can enter a refund amount, which is deducted from the total fees of that service, so reports and profit count only the amount kept (received minus refund).
- For a Job Search worker, the refund also reduces the profit of the job order, even if the order is already closed.
- The record stays in the database as history and stays visible in the UI with Cancel status.

**Worker or client delete**

- Delete permanently removes all of the person's documents (and profile photo) from Spaces storage.
- The MySQL record is **soft-deleted** (`deleted_at`); it is not erased.
- A deleted person no longer appears in the admin lists, the worker lists of job orders, or any operational count (service overview counts, active clients, active and cancelled workers, the manpower warning). Their unpaid balance is no longer in the dashboard **Outstanding**.
- Their financial history stays: nothing of their client-service records, Job Search records, job order worker rows, payments or refunds is deleted, and the money they paid (kept amount) stays in Total received, in the service statistics and in job order money. The planned landing page count of Clients Served also keeps counting them (Section 4.10).
- Delete is different from cancel: cancel keeps the record visible with Cancel status, delete hides it and removes its files.

**Service delete**

- The admin can delete a service. It disappears from the UI and from the landing page, and **cannot be chosen for new clients**.
- Clients already using the service continue without any problem, and their service records stay.
- The profit records of the service stay. The Business dashboard still shows its profit, with status **inactive**.
- Delete is a soft delete: nothing is removed from the database. Services Provided on the landing page counts only available (not deleted) main services.

#### Sub-service delete and deactivation

- The admin can deactivate a sub-service or delete it. Delete is a soft delete: nothing is removed from the database.
- A deactivated or deleted sub-service can no longer be chosen for new or changed client services.
- Existing client-service records that use it keep it, with their fees, payments, refunds and financial history unchanged. Its profit stays in the reports under its main service and in the sub-service breakdown.
- The database refuses to remove a sub-service row that other records point to (restrict foreign keys).
- When a main service is soft-deleted, its sub-services can no longer be chosen for new or changed client service assignments. The sub-services stay in the database; they are not automatically deleted or modified. If the main service is restored, its sub-services become available again, except those that were independently deactivated or deleted. Existing client services, fees, payments, refunds and historical records stay unchanged.

## 5. Data Model (core tables)

| Table | Key columns |
| --- | --- |
| users | id, name, email, password (nullable), google\_id, role |
| clients | id, unique\_token (unique), name, phone (required; unique among clients that are not deleted), birthday, gender, remarks, photo\_path (nullable), created\_at, updated\_at, deleted\_at (soft delete). The 4-digit PIN is calculated from the last four digits of the phone and is not stored. |
| services | id, parent\_id (nullable; main service or sub-service, maximum two levels), key (nullable, unique; job\_search for Job Search), title (nullable), title\_my (nullable), public\_description (nullable), public\_description\_my (nullable), show\_on\_website, status, default\_fee, has\_documents, deleted\_at (soft delete) |
| client\_services | id, client\_id, service\_id (always the main service), sub\_service\_id (nullable), status, start\_date, end\_date, fee, refund\_amount (set automatically when a fee change leaves more received than the fee), cancel\_refund\_amount (entered on cancel), cancelled\_at. Remarks are in `client_service_remarks`. |
| client\_service\_remarks | id, client\_service\_id, kind (note / refund / cancel), body (nullable), details (JSON, nullable; the numbers of an automatic refund or cancel remark), created\_by, created\_at, updated\_at. Add-only. |
| payments | id, client\_service\_id, amount, paid\_at, method, note, created\_by |
| documents | id, client\_service\_id, title, storage\_key, mime, size, client\_can\_download, uploaded\_by (no type column: the title is enough) |
| job\_orders | id, company\_name, manpower, male\_count, female\_count, interview\_date, start\_date, status, closed\_at, notes |
| job\_order\_workers | id, job\_order\_id, client\_id (shared person record), client\_service\_id, case\_status, notes |
| site\_settings | id, agency\_name, hero\_heading, hero\_description, overview, mission, logo\_key (required), favicon\_key (required), hero\_image\_key (nullable), phone, email, address, messaging\_link, social\_links, approved\_statistics (which public numbers may be shown), is\_published, updated\_by, updated\_at; Burmese versions of the text fields |
| document\_access\_logs | id, document\_id (nullable; kept when the document is deleted), client\_id and document\_title (copies), outcome (allowed / denied), reason (locked / not\_owner / not\_available), actor\_type, actor\_id, ip, created\_at |
| client\_pin\_attempts | id, client\_id, ip\_address, user\_agent (nullable), caused\_lock, created\_at. One row per wrong PIN. The PIN is never stored. The admin sees the rows of one client on that client's detail page, where the Unlock button is also found. |

**Implemented now (Phase 1):**

- `clients`: unique `unique_token`; index on `phone`; unique generated column `active_phone` (the phone while the client is not deleted), which gives phone uniqueness among clients that are not deleted.
- `client_pin_attempts`: foreign key `client_id` to `clients.id` (cascade on delete); index `(client_id, created_at)`.
- `services`: foreign key `parent_id` to `services.id` (restrict on delete); unique `key`; unique `(parent_id, id)`. The unique `(parent_id, id)` lets Phase 2 point a composite foreign key at it.

**Planned for Phase 2 (not yet created):**

- `client_services`: foreign keys `service_id` and `sub_service_id` to `services.id` (restrict on delete); composite foreign key `(service_id, sub_service_id)` to `services(parent_id, id)`, which the database checks only when `sub_service_id` is set; indexes `(client_id, service_id, status)`, `(service_id, sub_service_id)` and `(sub_service_id)`.
- `payments`: index `(client_service_id)`.

**Derived values** (calculated in query/accessor, not stored): received, remaining, payment status, service counts, profit (grouped by main service, with an optional sub-service breakdown), public statistics.

**Parent-child rule (enforced by the backend now; the database enforces the client-service pair from Phase 2):** a `services.parent_id` must point to a main service (a row whose own `parent_id` is empty); a client-service's `sub_service_id`, when set, must be a sub-service whose `parent_id` equals that client-service's `service_id`. Job Search (`key = job_search`) never has sub-services.

## 6. Authentication and Security

**Admin:** Laravel session auth, Google sign-in limited to allow-listed email(s), password hashing (bcrypt/argon), CSRF protection, optional 2FA later.

**Client PIN risk and mitigation.** A 4-digit PIN has only 10,000 combinations, and last-4-of-phone is guessable. The long random token makes brute force impractical *if* attempts are limited (a phone number is mandatory, so every client has a PIN):

- **PIN lock:** wrong PIN attempts are counted per client, across all IP addresses, not per IP. 5 consecutive incorrect PIN attempts lock the client for 15 minutes, and the client cannot sign in until the lock ends. These v1 rules are fixed in code; they are not configurable in Settings. Admin unlock: the admin can unlock a client's PIN lock manually with the Unlock button on the client detail page. The same page shows that client's wrong-PIN attempt records.
- **Token:** never derivable from client data. It is created once with the client record and has no expiration. The current application does not support regenerating a token, so the admin cannot replace a tracking link that has been shared by mistake.
- Sessions follow the application's session lifetime setting.
- Generic error message (do not reveal whether a token exists).
- Do not index tracking pages (`noindex`), and send `Referrer-Policy: no-referrer`.

**Files:** private Spaces bucket, no public-read ACLs, a Laravel permission check before every download, short-lived signed URLs (option B), encryption at rest as offered by the provider (to confirm), upload validation in the browser and again on the server (type and size, Section 3.2; virus scan optional), random object keys, and credentials only in environment variables.

**Privacy:** passports, birthdates and work-permit papers are personal data. Follow Thailand's PDPA basics: collect only what is needed, restrict access, when the admin deletes a person, remove their documents permanently from storage and soft-delete the database record (Section 4.12), and keep an audit log of document access.

## 7. Key Flows

**Client views a document:** open tracking link → enter PIN → profile → open Work Permit → document listed → if unlocked, Download → server checks policy → logs access → redirect to a short-lived signed Spaces URL (Section 3.1).

**Admin records a deposit:** Client → service → Add payment → ledger row saved → payment status recalculated → remaining balance shown to admin and (if fee/payment visible) to client.

**Admin adds a service to a client:** open the client → Add Service → choose a main service (Job Search is not listed) → optionally choose one of its sub-services → the fee is filled from the sub-service default fee, otherwise the main service default fee → save; the record stores the main service in `service_id` and the sub-service, if any, in `sub_service_id`.

**Job Search:** Create job order → add a worker (search by phone first; reuse the matching person record, or create a new person directly from this screen; no earlier service record is needed) → track each worker's payment → close the order with Done or Cancel (no more workers can be added) → profit rolls into the business dashboard.

**Admin shares tracking access:** open the client → Share access → link and QR code are generated on screen from the stored token (the QR image is not saved) → copy the link or save the QR image → send it to the client → the client opens it and enters the PIN.

## 8. UI Structure (Inertia pages)

- Public: / (landing page), `/track/{token}` (PIN form), `/client/{token}/profile (implemented)`, `/client/{token}/services/{id} (planned for Phase 3)`.
- Admin: `/admin/login`, `/admin/dashboard`, `/admin/clients`, `/admin/clients/{id}`, `/admin/services`, `/admin/services/{id}`, `/admin/job-search`, `/admin/job-search/{id}`, `/admin/workers/{id}`, `/admin/settings`.
- Mobile-first for the client portal (clients will open it from a chat link). Admin screens responsive, table-heavy on desktop.
- Shared Vue components: DataTable (filter/sort/inline actions), StatusBadge, MoneySummary, FileUploader, ConfirmDialog.

## 9. Non-Functional Requirements

- **Performance:** pages under 2 seconds for typical data (a few thousand clients); paginate all tables server-side.
- **Availability:** single region deployment, the app and MySQL run on one Droplet (Section 10).
- **Auditability:** created\_by/updated\_by on money and document records; access logs for downloads.
- **Localization:** Burmese (default) and English UI for admin and client, switchable at any time (Section 4.9); store dates in UTC, display in Asia/Bangkok; utf8mb4 for Burmese and Thai text.
- **Browser support:** current Chrome, Safari, Edge; mobile Safari and Chrome for the client portal.

## 10. Deployment and Environments

- Environments: local, staging, production.
- Hosting: one DigitalOcean Droplet in the client's account with Nginx, PHP-FPM, queue worker via Supervisor and scheduler via cron. MySQL runs on the same Droplet.
- Assets built with Vite; HTTPS via Let's Encrypt.
- Spaces buckets: for production, one private bucket for documents and photos and one public bucket for website images (development uses the local disk, Section 3.1; staging is to be decided), each with its own access keys limited to that bucket where Spaces permissions allow (to confirm); keys live only in environment variables.
- CI: run tests and build on each push; deploy by tag.

### 10.1 Indicative Cost Estimates

All pricing is an indicative estimate and must be confirmed against DigitalOcean's current price list and the final sizing before implementation. This HLD holds no verified per-resource prices, so none are stated. The Droplet and Spaces are separate resources with separate estimates.

**Agreed budget.** The client has accepted an approximate budget of **THB 1,000 per month** for hosting and storage (Droplet and Spaces). Domain registration is billed annually. Actual costs may vary with the DigitalOcean resources selected, storage use and traffic. The expected costs and their limitations are documented in the contract.

| Resource | Cost driver | Indicative estimate |
| --- | --- | --- |
| DigitalOcean Droplet (app server) | Droplet size, region | To be confirmed |
| DigitalOcean Spaces (object storage) | Plan fee, stored volume, outbound transfer; a second bucket for public website images | To be confirmed |
| Domain name and DNS | Registrar fee, billed annually | To be confirmed |

### 10.2 Handover Notes

- **Ownership:** the production Droplet, Spaces, any Managed Database, the domain and all billing belong to the client's DigitalOcean account. The delivery team works through invited access, not shared owner credentials.
- **Approval status:** The client approved DigitalOcean Spaces for file storage. No cloud resources have been created for this HLD.
- **Credentials:** access keys, app key, database and Google OAuth secrets live only in environment variables on the server. The repository holds a `.env.example` with variable names only, and secrets are never committed to Git. Any exposed key is rotated.
- **Handover pack:** admin account setup, deployment steps, the list of environment variables, and how to rotate Spaces keys.
- **Before go-live:** the resource sizes and prices in Section 10.1 are confirmed within the agreed budget, with the expected costs and limitations written into the contract.

## 11. Testing Strategy

- Feature tests for policies (a client can never read another client's data or documents).
- Unit tests for payment-status, refund and profit calculations; feature tests for the cancel, delete and job order closing rules (Section 4.12).
- Tests for sub-services (the parent-child rule is rejected by the backend and the database when violated, the fee fallback, Job Search cannot receive sub-services or be added through the normal form, profit grouped by main service); PIN lock tests (5 consecutive wrong PINs lock the client for 15 minutes, attempts counted per client across IP addresses, admin unlock); tests that the public pages expose no private data and show main services only; tests that only authorized admins can change website appearance and that invalid uploads are rejected.
- Browser tests (Laravel Dusk or Playwright) for the main admin and client flows, including the image size reduction. File tests run against the local disk.

## 12. Delivery Phases

1. **Foundation:** auth (admin + Google, client token/PIN), clients CRUD with unique phone, tracking link and QR generation, file upload with browser image size reduction and an environment-based disk (local in development, Spaces in production), English/Burmese UI setup, seed services.
2. **Services and payments:** service catalog with sub-services, client-services (with optional sub-service selection), payment ledger, service overview stats.
3. **Client portal:** profile, status view with main and sub-service titles, Work Permit documents (auto-unlock when fully paid).
4. **Job Search:** job orders table, detail pages, worker linking, profit.
5. **Business dashboard, public landing page, appearance settings and hardening:** reports, audit logs, security review.

Possible later additions: LINE/SMS notifications with the tracking link, staff roles, invoices/receipts as PDF, Thai UI language.

## 13. Open Questions

### 13.1 Confirmed Decisions

| Topic | Decision |
| --- | --- |
| Profit | For this version, the figure is the money kept: received money less refunds (fee-change refunds and cancel refunds). No costs are tracked in v1, so it is not a net profit; the dashboard Profit card stays disabled (Coming soon) |
| Job Search money | The money kept on the workers' Job Search records (received minus fee-change and cancel refunds), including deleted people, cancelled and rejected workers; not called net profit; the admin cannot enter or edit it. The per-order total is deferred to Step 5 |
| Active workers and manpower | Active = person not deleted, Job Search service not Cancel, case status not Rejected. Cancelled workers are counted apart and stay listed; deleted people are not listed or counted. Warning when active workers > manpower; never a block, and no male + female ≤ manpower rule |
| Workers and clients | One shared person record per individual; a person can be a client, a worker, or both; worker-specific and service-specific data are stored separately and linked to it |
| Adding workers | Search by phone first; reuse a match, or create a new person from the job order screen; no earlier service record needed |
| Document unlock | Automatic when fully paid, plus per-document release by the admin; the admin release is independent of payment status |
| Payment visibility | Client and admin both see the received amount and remaining balance for any payment status |
| Service status | In Progress / Complete / Cancel for every service (Section 4.12) |
| Reminders | Not needed |
| Roles | v1: admin (full permission) and client (read and download own data only); more roles in version 2 through the role column |
| Language | Burmese (Myanmar) is the default and English is also available; switchable by admin and client and remembered in localStorage; missing text falls back to the other language; the client provides other Burmese content and reviews the landing page text |
| Phone number | Main identifier; required and unique; stored in international format; no phone number, no account |
| Data retention | Delete removes the person's documents permanently from storage; the database record is soft-deleted; operational counts and lists leave the person out, their unpaid balance leaves Outstanding, and their financial history stays in the money totals (Section 4.12) |
| Hosting | DigitalOcean Droplet, with MySQL on the same Droplet |
| File storage | DigitalOcean Spaces for photos and PDFs, approved by the client; private by default |
| File delivery | Option B: short-lived signed URLs after a Laravel permission check |
| Tracking link and QR | Generated by the admin per client; the QR code is not stored in the database; no expiration |
| Public landing page | Public home page with agency overview, services, real aggregate statistics and contact details; Admin Login button in the navigation bar |
| Website appearance | Authorized admins upload and replace the logo, favicon and hero image and edit the hero heading and description; images in DigitalOcean Spaces with keys in the database; changes show on the public site after a successful save |
| Bilingual services | Title and public description each have an English and a Burmese field, all nullable; at least one language is required for each; enforced on frontend and backend |
| Landing page content | Developer drafts English and Burmese text without inventing business facts; editable by the admin; the client reviews it before publishing; contact details and statistics hidden until provided or verified |
| Statistic definitions | Clients Served = unique people with at least one service record; Services Provided = number of available main services (not sub-services); only verified statistics are shown |
| Budget | Approximate THB 1,000 per month for hosting and storage accepted by the client; domain billed annually; expected costs and limitations documented in the contract; sizing and prices confirmed before implementation |
| Public website images | Separate public Spaces bucket for logo, favicon and hero image; profile photos and private documents stay private; object keys stored in the database |
| Upload rules | Images up to 2 MB; logo PNG/JPG/WebP/SVG, favicon PNG/ICO, hero JPG/PNG/WebP, profile photo JPG only; PDFs up to 5 MB; SVG sanitized; checked on frontend and backend (Section 3.2) |
| Job order closing | Done and Cancel are the same closing action: no more workers can be added, and the received profit cannot be reduced except by a worker cancel with a refund |
| Worker or client cancel | Deposit-only payer's remaining balance becomes 0; a refund field appears and the refund is deducted from the service's total fees; the record stays in the database and in the UI with Cancel status |
| Worker or client delete | All documents permanently deleted from storage; MySQL record soft-deleted; left out of admin lists, worker lists and operational counts and of Outstanding; payment records and kept money stay in the dashboard, service statistics and job order money; the landing page Clients Served count does not reduce |
| Service overview statistics | Total clients, In Progress, Complete and Cancelled counts leave out deleted clients; total received (kept) includes them; added up in the database in whole satang |
| Service delete | Disappears from the UI and cannot be used for new clients; current clients, records and profit data remain; profit stays in the Business dashboard with status inactive |
| Worker documents | Worker detail shows personal details and only the documents of that job order, not documents from the person's other services; job order documents are admin-only and are not shown in the client portal |
| Required and optional images | Logo and favicon are required; the hero image is nullable and its area is left blank when there is none |
| PDF size limit | PDF documents up to 5 MB; images up to 2 MB |
| Refund calculation | After a cancel, the amount kept is received minus refund, and the remaining balance is 0 |
| Payments after closing | Workers already on a closed job order can still pay their remaining balance |
| Delete scope | The profile photo is removed from storage together with the documents |
| Storage by environment | Development uses the local filesystem disk; production uses DigitalOcean Spaces; the disk is selected by an environment variable (Section 3.1) |
| Image size reduction | Images are resized and compressed in the browser before upload; the server still validates type and size (Section 3.3) |
| Sub-services | One services table with a nullable parent\_id; maximum two levels (Main Service and Sub-service); selection is always optional; client\_services.service\_id always stores the main service and sub\_service\_id is nullable; the backend and the database both validate the parent-child relationship (Section 4.4) |
| Sub-service fees, payments and profit | A sub-service may have its own default fee; the fee falls back to the main service default fee; payments and profit stay on the client-service record; profit is grouped by main service with an optional sub-service breakdown; existing records and financial history are preserved when a sub-service is deactivated or soft-deleted |
| Client portal service title | The optional sub-service title is shown next to the main service title, for example “Passport – Bath”; with no sub-service, only “Passport” is shown |
| Job Search service | Remains a main service and cannot have sub-services; fixed system key job\_search; excluded from the normal “Add Service to Client” form; its workflow and profit rules are unchanged and its profit is included in overall service profit and dashboard reports |
| Landing page services | Main services only; sub-services never appear as separate cards; Services Provided counts available main services only |
| Client PIN lock and token | 5 consecutive incorrect PIN attempts lock the client for 15 minutes, counted per client across all IP addresses; fixed in code, not configurable in Settings; the admin can unlock manually from the client detail page; the token is created once with the client record and cannot be regenerated by the admin in the current application (Section 6) |

### 13.2 Remaining Items

**Deferred to the next step (Phase 4 Step 5, job order detail page):** total visible workers card (active + cancelled), active male/female counts, the per-order money card (kept money, aggregated as in Section 4.7), links from worker rows to the person's record, and the "over by N" warning text. None of these are built yet.

1. **Landing page content:** the client reviews and approves the drafted English and Burmese content before it is published (Section 4.10), and provides the agency name, contact details, social links and other verified facts.
2. **Cost and sizing confirmation:** before implementation, confirm the DigitalOcean resources and current prices within the THB 1,000 monthly budget, and write the expected costs and limitations into the contract (Section 10.1).
3. **Staging storage:** decide whether the staging environment uses the local disk or its own Spaces buckets.
