# File Storage Audit: Local Disk to DigitalOcean Spaces

- Date: 2026-11-22
- Type: read-only audit. No code, configuration, environment file, package, database or stored file was changed. Only this report was written.
- Scope: branch `master` at `588dd87`.
- Working tree note: `bootstrap/app.php` has an uncommitted change (`$middleware->trustProxies(at: '*')`). This audit did not make that change and did not touch it.

## 1. Executive summary

**Changing environment variables alone is not enough.** Two problems would stop a switch made only through `.env`:

1. **The S3 adapter package is not installed.**
   - `composer.json` does not require `league/flysystem-aws-s3-v3` (or `aws/aws-sdk-php`).
   - `composer.lock` and `vendor/` contain only `league/flysystem` 3.36.0 and `league/flysystem-local` 3.35.3.
   - With `STORAGE_DRIVER=s3`, Laravel's `createS3Driver()` would fail with a "class not found" error the first time either disk is used.
2. **The `root` setting of both disks would become the prefix of every Spaces key.**
   - Both the `private` and `public_assets` disks keep a local absolute `root` (for example `storage_path('app/private')`) even when the driver is `s3`.
   - For the S3 driver, Laravel passes `root` to the adapter as a key prefix (`FilesystemManager::createS3Driver`, line 263; `PathPrefixer`). In production, a document would be stored at a key like `home/…/storage/app/private/documents/<uuid>.pdf`, not `documents/<uuid>.pdf`.
   - The same prefix would appear inside every public image URL (`AwsS3V3Adapter::url()` adds the prefix).
   - This cannot be fixed from `.env`:
     - the `private` disk has no environment variable for `root`;
     - for `public_assets`, `PUBLIC_ASSETS_ROOT=` (empty) falls back to the local path because of `?:`;
     - `PUBLIC_ASSETS_ROOT=/` gives keys that start with `/` (Flysystem's `PathPrefixer` keeps a single `/` prefix).

Apart from those two problems, the application is well prepared:

- **Two disks are already separate:** `private` for documents and client photos, and `public_assets` for the three website images.
- **Two buckets are already configured:** `DO_SPACES_PRIVATE_BUCKET` and `DO_SPACES_PUBLIC_BUCKET`.
- **No hardcoded disk names:** every storage call in `app/` resolves its disk through a model constant (`Document::DISK`, `Client::PHOTO_DISK`, `SiteSetting::DISK`). None uses `local` or `public` directly, and none uses the default disk.
- **The database stores relative keys,** never URLs or absolute paths.
- **Document downloads already handle Spaces.** `DeliverDocument` switches to a 2-minute `temporaryUrl()` redirect when the driver is `s3`.

**No PHP file under `app/` needs to change.** The required changes are:

- one Composer package;
- `config/filesystems.php`;
- recommended updates to `.env.example`, `phpunit.xml` and the tests;
- an operational copy of the existing files.

## 2. Current architecture

### 2.1 Packages

| Package | Installed | Version |
|---|---|---|
| `laravel/framework` | yes | v13.43.0 |
| `league/flysystem` | yes | 3.36.0 |
| `league/flysystem-local` | yes | 3.35.3 |
| `league/flysystem-aws-s3-v3` | **no** | — |
| `aws/aws-sdk-php` | **no** | — |

No service provider registers or changes disks. The only filesystem configuration is `config/filesystems.php`. `config/app.php` and the providers do not call `Storage::extend`.

### 2.2 Disks (`config/filesystems.php`)

| Disk | Line | Driver | Root | URL | Visibility | Used by the app |
|---|---|---|---|---|---|---|
| default | 16 | `env('FILESYSTEM_DISK','local')` | — | — | — | **Not used**: no code calls `Storage::` without a disk name |
| `local` | 33 | `local` (fixed) | `storage/app/private` | — | — | Not used by app code |
| `public` | 42 | `local` (fixed) | `storage/app/public` | `APP_URL/storage` | public | Not used by app code (Laravel default) |
| **`private`** | 58 | `env('STORAGE_DRIVER','local')` | `storage/app/private` (**fixed**, line 60) | `APP_URL/private-files` (served by nothing) | private | Documents and client photos |
| **`public_assets`** | 75 | `env('STORAGE_DRIVER','local')` | `env('PUBLIC_ASSETS_ROOT') ?: storage/app/public` (line 77) | `env('PUBLIC_ASSETS_URL') ?: APP_URL/storage` (line 80) | public | Logo, favicon, hero image |
| `s3` | 92 | `s3` | — | `AWS_URL` | — | Not used (Laravel default, `AWS_*` variables) |

Other facts about the configuration:

- **Separate disks:** public and private storage use separate disks, not two folders on one disk. Locally they are two separate roots, `storage/app/private` and `storage/app/public`. The local `local` disk and the `private` disk share one root, which is harmless because `local` is unused.
- **Spaces settings:** `private` and `public_assets` already carry the Spaces keys: `key`, `secret`, `region` and `endpoint` from `DO_SPACES_*`, a separate `bucket` each, and `use_path_style_endpoint` set to false. Once the two problems in §1 are fixed, the design fits Spaces.
- **Shared credentials:** both disks use the same `DO_SPACES_KEY` and `DO_SPACES_SECRET`. HLD §10 (line 1001) asks for "each with its own access keys limited to that bucket where Spaces permissions allow (to confirm)".
- **Local serving:** `serve` is `false` on `local` and `private`, so Laravel registers no local file-serving route for them. The `public/storage` symbolic link points to `storage/app/public`. It exists, and local website images depend on it.
- **`.env.example`** (lines 50–62) has the following:
  - `FILESYSTEM_DISK=local` and `STORAGE_DRIVER=local`;
  - empty `DO_SPACES_KEY`, `DO_SPACES_SECRET`, `DO_SPACES_REGION`, `DO_SPACES_ENDPOINT`, `DO_SPACES_PRIVATE_BUCKET` and `DO_SPACES_PUBLIC_BUCKET`;
  - `PUBLIC_ASSETS_URL`, commented out;
  - no `PUBLIC_ASSETS_ROOT`.
- **Local `.env`:** the non-secret values only were inspected. They are `APP_ENV=local`, `STORAGE_DRIVER=local`, `FILESYSTEM_DISK=local` and `PUBLIC_ASSETS_URL=` (empty, so the fallback applies).

## 3. Inventory of file operations

The whole of `app/`, `routes/`, `config/`, `bootstrap/` and `database/` was searched for the following:

- `Storage::`, `->disk(`, `store`, `storeAs` and `storePublicly`;
- `putFileAs`, `temporaryUrl`, `->url(` and `download` / `response`;
- `file_get_contents`, `fopen`, `unlink` and `readfile`;
- `storage_path` and `public_path`.

"Spaces" in the last two columns assumes the two problems in §1 are fixed. "Code change" means a change to PHP code under `app/` or `database/`.

### 3.1 Documents (private: passports, visas, work permits and similar)

| File:line | Operation | Disk | Disk resolution | On Spaces | Code change |
|---|---|---|---|---|---|
| `app/Models/Document.php:45` | `DISK = 'private'` | private | constant → configured disk | ✅ | No |
| `app/Models/Document.php:47` | `DIRECTORY = 'documents'` (key prefix) | — | — | ✅ becomes the object key prefix | No |
| `app/Models/Document.php:52` | `SIGNED_URL_MINUTES = 2` | — | — | ✅ | No |
| `app/Http/Controllers/Admin/DocumentController.php:106–109` | `putFileAs('documents', file, '<uuid>.<ext>')` on upload | private | `Storage::disk(Document::DISK)` | ✅ (ACL private, from the disk's `visibility`) | No |
| `app/Http/Controllers/Admin/DocumentController.php:129` | `delete($key)` if the database write fails | private | same | ✅ | No |
| `app/Http/Controllers/Admin/DocumentController.php:199–202` | `putFileAs` on replace | private | same | ✅ | No |
| `app/Http/Controllers/Admin/DocumentController.php:214` | `delete($key)` if the replace fails | private | same | ✅ | No |
| `app/Models/Document.php:144–146` | `delete($key)` and `exists($key)` after commit (erase and replace) | private | `Storage::disk(self::DISK)` | ✅ (deleting a missing object succeeds on S3) | No |
| `app/Actions/Document/DeliverDocument.php:22` | `exists($key)` before delivery | private | `Storage::disk(Document::DISK)` | ✅ (HEAD request) | No |
| `app/Actions/Document/DeliverDocument.php:36–41` | `temporaryUrl($key, now()+2 min, ResponseContentType/ResponseContentDisposition)`, then `redirect()->away()` | private | same | ✅ native S3 presigned GET | No |
| `app/Actions/Document/DeliverDocument.php:44` | `download($key, $name, $headers)` (local streaming) | private | same | used only when the driver is not `s3` | No |
| `app/Actions/Document/DeliverDocument.php:52` | `usesSignedUrls()`: `config('filesystems.disks.private.driver') === 's3'` | — | configuration | ✅ | No |
| `app/Actions/Document/IdentifyDocumentFile.php:34` | `file_get_contents(tmp, 0, 8)` reads the magic bytes of the PHP upload temp file | — | local temp file | ✅ (never the disk) | No |


### 3.2 Client photos (private)

| File:line | Operation | Disk | Resolution | On Spaces | Code change |
|---|---|---|---|---|---|
| `app/Models/Client.php:44, 46` | `PHOTO_DISK = 'private'`, `PHOTO_DIRECTORY = 'client-photos'` | private | constant | ✅ | No |
| `app/Models/Client.php:85` | `$photo->storeAs('client-photos', '<uuid>.jpg', 'private')` | private | constant | ✅ | No |
| `app/Models/Client.php:136–138` | `delete($path)` and `exists($path)` (replace, erase, client delete after commit) | private | `Storage::disk(self::PHOTO_DISK)` | ✅ | No |
| `app/Models/Client.php:75` | `URL::temporarySignedRoute('client-photos.show', +1 h)`: a Laravel signed route, **not** a storage URL | — | — | ✅ unaffected | No |
| `app/Http/Controllers/Client/PhotoController.php:27–35` | `exists()`, then `$disk->response()`, which streams the file through the app | private | `Storage::disk(Client::PHOTO_DISK)` | ✅ works on S3 (`readStream`; `size` is one extra HEAD request; the type is fixed to `image/jpeg`). The bytes pass through the Droplet | No |
| `routes/web.php:35–37` | `client-photos/{client}` with `signed` middleware, plus a viewer check in the controller | — | — | ✅ | No |

### 3.3 Website images (public: logo, favicon, hero)

| File:line | Operation | Disk | Resolution | On Spaces | Code change |
|---|---|---|---|---|---|
| `app/Models/SiteSetting.php:77` | `DISK = 'public_assets'` | public_assets | constant | ✅ | No |
| `app/Concerns/ValidatesWebsiteImage.php:110` | Key `site/<uuid>.<ext>` | — | — | ✅ | No |
| `app/Http/Controllers/Admin/AppearanceController.php:54, 66` | `putFileAs('site', file, '<uuid>.<ext>')` | public_assets | `Storage::disk(SiteSetting::DISK)` | ✅ (ACL `public-read`, from `visibility: public`) | No |
| `app/Http/Controllers/Admin/AppearanceController.php:137–139` | `delete` and `exists` (replaced files, cleanup after a failure) | public_assets | same | ✅ | No |
| `app/Models/SiteSetting.php:154` | `url($key)` plus `?v=<updated_at>` | public_assets | same | ✅ uses `PUBLIC_ASSETS_URL` + key (**provided the root is empty**, see §1) | No |
| `app/Actions/Site/IdentifyWebsiteImage.php:44` | `file_get_contents(tmp, 0, 12)` reads magic bytes | — | local temp file | ✅ | No |
| `app/Http/Middleware/SecurityHeaders.php:66` | CSP `img-src` adds the origin of `filesystems.disks.public_assets.url` | — | configuration | ✅ (a Spaces or CDN host is added automatically) | No |

### 3.4 Seeders and other code

- **`database/seeders/BrowserTestSeeder.php:64–67`**
  - It writes two 1×1 PNGs to `public_assets` (`site/browser-seed-*.png`).
  - It is used only by Playwright, which forces `STORAGE_DRIVER=local` and its own `PUBLIC_ASSETS_ROOT` (`tests/Browser/support/environment.ts:52–54`).
  - It needs no change.
- **Other storage use:** none.
  - No exports, PDFs, backups or queue jobs touch storage.
  - `storage_path` appears only in the cache, session and log configuration.
  - `public_path` appears only in the `links` map.
  - No code builds an uploaded file's URL with `asset()` or by string concatenation.

### 3.5 Hardcoded disk names

There are none in `app/`. The literal `'private'` and `'public_assets'` appear only as the three model constants. The tests use those constants, except `tests/Feature/StorageDisksTest.php` (`'private'`) and `tests/Concurrency/Mysql/Support/run-workflow.php:120` (`Storage::fake('private')`), which is expected in tests.

## 4. Private file access flow

### Documents

1. **Upload.** The `StoreDocumentRequest` / `ReplaceDocumentRequest` rules apply first. The type is then decided from the file's content (`IdentifyDocumentFile`); the browser's claim is not trusted.
2. **Key.** The key is `documents/<uuid>.<detected ext>`. The admin's title is never part of the path.
3. **Storage.** The file is stored before the transaction (`putFileAs`). The record is then written inside a transaction that locks the Person and the service, and the policy is checked again under the lock. If that fails, the stored file is deleted.
4. **Database.** `documents.storage_key` holds the relative key.
5. **Authorisation.**
   - Admin: `Gate::authorize('view')`.
   - Client: the `client.session` middleware, then ownership (404, `not_owner`), availability (404), the lock (403), `hasFile` (404) and the `throttle:document-downloads` limit.
   - Every attempt is written to `document_access_logs`.
6. **Delivery.**
   - Local: `download()` streams the file through the app as `attachment`, with `private, no-store` and `nosniff`.
   - Spaces: `temporaryUrl()` creates a presigned GET that lasts 2 minutes and forces `ResponseContentType` and `Content-Disposition: attachment`. The browser gets a 302 to that URL.
   - The front end uses plain `<a href>` links (`DocumentList.vue:325`, `client/Profile.vue:81, 230`), not Inertia XHR visits, so a cross-origin redirect works and needs no CORS rule.
7. **Deletion.** The record is deleted in a transaction and the file after the commit (`DB::afterCommit`). A failure is reported and never blocks the delete. Deleting a client removes all its documents and its photo after the commit.

### Checks made

- **Private without authorisation (local):**
  - `serve: false` on the private disk, and no route serves it.
  - `APP_URL/private-files/...` and `/storage/...` return 404 (`StorageDisksTest`, test 3).
  - `storage/app/private` is outside `public/`.
  - ✅ Verified.
- **Private without authorisation (Spaces):**
  - Objects are written with ACL `private` (the disk's `visibility: private` is passed to Flysystem as its default visibility).
  - Without a signature they are readable only if the **bucket** itself is public, for example through a bucket policy or "public file listing". That is an operational setting, not a code setting; see §9.
  - The folder name `private` gives no protection on Spaces: only the bucket and the object ACL do.
- **Temporary URL uses the abstraction:** `Storage::disk(...)->temporaryUrl()` (`AwsS3V3Adapter::temporaryUrl` → `S3Client::createPresignedRequest`). ✅
- **S3 compatibility:** Spaces supports SigV4 presigned GET with `response-content-type` and `response-content-disposition`. ✅
- **Expiry:**
  - 2 minutes, as in HLD §3.1 option B. The test checks it is no more than 5 minutes.
  - The server clock must be correct (NTP), or links fail early.
  - A link can be used by anyone, any number of times, until it expires. The HLD accepts this.
- **Photos:**
  - They do **not** use storage temporary URLs. The 1-hour Laravel signed route checks the viewer (superadmin, or the same client's PIN session) and streams the photo through the app.
  - This is the stronger HLD option A: the bucket key is never exposed.
- **Code that works only with local file serving:** none in app code. `DeliverDocument::download()` and `PhotoController::response()` both use the disk API, which also works on S3. Only local website images need the `public/storage` symbolic link, which is development only.
- **Risk of accidental exposure on the switch:**
  - From the code: low. Private objects get ACL `private`, and nothing calls `url()` on the private disk. If something did, it would get the dead `APP_URL/private-files/...`, not a bucket URL.
  - From the configuration: higher. The two disks share credentials, so swapping `DO_SPACES_PRIVATE_BUCKET` and `DO_SPACES_PUBLIC_BUCKET` would put documents in the public bucket (still ACL `private`, but in the wrong place), and the public bucket's settings would then apply to them. Per-bucket keys (§7, change C3) would turn that mistake into access-denied errors.

## 5. Database paths

| Table.column | Migration | Format | Example (development DB, read-only query) |
|---|---|---|---|
| `documents.storage_key` (NOT NULL) | `2026_11_08_032858_create_documents_table.php:19` | `documents/<uuid>.<ext>` | `documents/c222bb39-….jpg` |
| `clients.photo_path` (nullable) | `2026_10_29_130955_create_clients_table.php:17` | `client-photos/<uuid>.jpg` | `client-photos/981195d4-….jpg` |
| `site_settings.logo_key`, `favicon_key`, `hero_image_key` (nullable) | `2026_11_19_100000_create_site_settings_table.php:39–41` | `site/<uuid>.<ext>` | all null at present |

How the values were read:

- **Connection:** the development DB was checked first (`APP_ENV=local`, MySQL `127.0.0.1`, database `Thai_Visa_Services`), then queried with SELECT statements only.
- **No absolute or URL values:** no document key starts with `/` or `http`, or contains `storage/`.
- **`document_access_logs`:** it has no key column, and keeps its own copies of the text.

Conclusions:

- **Keys reuse as-is.** The keys are relative, with no local prefix and no URL, so they can be used directly as Spaces object keys. This needs the `root` fix in C2, so that no prefix is added.
- **No data migration:** no database migration or rewrite of existing records is needed.
- **Unreferenced files:** the development disk has files that no record points to:
  - 2 files in `documents/` with only 1 record;
  - `site/9a4ef411-….jpg` with no record.

  These are left over from earlier runs. A migration should copy the keys that records point to, and report the unreferenced files rather than delete them.

## 6. Tests

| Area | Tests | Assumes the local disk? |
|---|---|---|
| Upload, replace, delete documents | `Admin/ClientDocumentTest`, `JobSearch/JobSearchDocumentsTest`, `PersonDeletionTest` | `Storage::fake(Document::DISK)`: a local fake of the configured disk name. Tests behaviour, not the driver |
| Client download, access log, lock | `Client/PortalDocumentDownloadTest` | Fake, plus **two Spaces tests** (lines 249, 272): driver set to `s3` and a **mocked** `FilesystemAdapter` that checks `temporaryUrl` gets the key, an expiry of 5 minutes or less and `attachment`; a denied attempt never signs |
| Photos | `Admin/ClientPhotoTest` | Fake; signed-route tests (valid, altered, expired, other viewer) |
| Website images | `Admin/AppearanceTest`, `SiteAppearancePublicTest`, `LandingSeoTest` | Fake |
| Disk configuration | `StorageDisksTest` | Checks that the driver is `local` by default, the visibility, that private is not under `public/`, the public URL fallback and `PUBLIC_ASSETS_URL` |
| CSP image origin | `SecurityPolicyHeadersTest`, `SecurityHeadersTest` | Configuration only |
| Browser | Playwright | Forces `STORAGE_DRIVER=local` and its own folders |

Gaps:

1. **No test builds a real S3 disk** (the package is not installed). So no test would catch the `root`-prefix problem, the missing package or a bad URL shape. The Spaces download tests replace the disk with a mock.
2. **`StorageDisksTest`, test 3, writes to the real `private` disk** (`testing/passport-<random>.pdf`) and deletes the file afterwards. It does not use a fake. The empty `storage/app/private/testing/` folder comes from it.
3. **`phpunit.xml` and `phpunit.mysql.xml` do not set `STORAGE_DRIVER`.** The suite takes the value from `.env`. On a machine whose `.env` has `STORAGE_DRIVER=s3`:
   - test 1 would fail;
   - test 3 would write a file to the **real Spaces bucket**.

   Playwright already pins the value.

## 7. Compatibility assessment and required changes

### Already compatible (no change)

- **Disks and buckets:** separate `private` and `public_assets` disks, each with its own bucket variable.
- **No hardcoded disks:** every call goes through a model constant, the default disk is unused, and `FILESYSTEM_DISK` has no effect on application files.
- **Keys:** relative, random UUID keys in the database, with prefixes `documents/`, `client-photos/` and `site/`.
- **Object ACLs:** `private` for documents and photos, `public-read` for website images (from the disk `visibility`).
- **Document delivery:** the switch to `temporaryUrl()` is driven by configuration (`driver === 's3'`). It forces attachment, the content type and a 2-minute expiry.
- **Photo delivery:** streamed through the app behind a signed and viewer-checked route.
- **Public URLs and CSP:** public image URLs come from `PUBLIC_ASSETS_URL`, and the CSP `img-src` adds that origin automatically.
- **Files and database stay consistent:** stored before the transaction, cleaned up on failure, deleted after the commit, and delete failures reported. All of this uses only the disk API.

### Configuration changes only

- **E1 (environment):** `STORAGE_DRIVER=s3` and all `DO_SPACES_*` variables, `PUBLIC_ASSETS_URL` (the public bucket or CDN address), and keep `FILESYSTEM_DISK=local`.
- **E2 (environment):** `DO_SPACES_REGION` must be set. The AWS SDK refuses an empty region.

### Required changes outside `app/` (no application PHP changes)

| # | File | Change | Why |
|---|---|---|---|
| **C1** | `composer.json`, `composer.lock` | `composer require league/flysystem-aws-s3-v3 "^3.0"` (brings in `aws/aws-sdk-php`) | Without it the `s3` driver cannot be built |
| **C2** | `config/filesystems.php` lines 60 and 77 | Set `root` only for the local driver, for example `'root' => env('STORAGE_DRIVER', 'local') === 's3' ? '' : storage_path('app/private')`, and the same rule for `public_assets` (keeping `PUBLIC_ASSETS_ROOT` for local use) | Otherwise every key and every public URL gets the absolute local path as a prefix |

### Recommended changes

| # | File | Change | Why |
|---|---|---|---|
| C3 | `config/filesystems.php` lines 65–66, 82–83, plus `.env.example` | Separate credentials per bucket, for example `DO_SPACES_PRIVATE_KEY` / `_SECRET` and `DO_SPACES_PUBLIC_KEY` / `_SECRET`, using Spaces per-bucket access keys | HLD §10 (line 1001); limits the damage of a leaked key or a swapped bucket name |
| C4 | `.env.example` | Document the production variables and an example `PUBLIC_ASSETS_URL`; say that `FILESYSTEM_DISK` is not the switch | Prevents the `FILESYSTEM_DISK=s3` mistake |
| C5 | `phpunit.xml`, `phpunit.mysql.xml` | `<env name="STORAGE_DRIVER" value="local" force="true"/>`, with matching `<server>` entries, as for the DB values | The suite can never write to a real bucket |
| C6 | `tests/Feature/StorageDisksTest.php` | (a) With `STORAGE_DRIVER=s3`, assert that both disks have an empty `root`. (b) Build the disks with fake credentials and check, with no network: `url('site/x.png')` is `PUBLIC_ASSETS_URL/site/x.png`, and `temporaryUrl('documents/x.pdf', +2 min)` has host `<private-bucket>.<endpoint>`, path `/documents/x.pdf` and `X-Amz-Expires=120`. (c) Change test 3 so it uses a fake or a scratch disk | Catches the problems in §1 before deployment |
| C7 | `config/filesystems.php` (optional) | `'options' => ['CacheControl' => 'public, max-age=31536000']` for `public_assets` | Images already have unique keys and a `?v=` suffix |
| C8 | `config/filesystems.php` (only if staging shows errors) | `'request_checksum_calculation' => 'when_required'` and `'response_checksum_validation' => 'when_required'` on both Spaces disks | Recent AWS SDK versions send CRC checksums by default; some S3-compatible services rejected them. **Not verified for Spaces: test on staging** |

No PHP file under `app/` or `database/` needs to change.

## 8. Example configuration (placeholders only, no real values)

### Local development (unchanged)

```dotenv
FILESYSTEM_DISK=local
STORAGE_DRIVER=local
# PUBLIC_ASSETS_URL=          # unset → APP_URL/storage; run `php artisan storage:link` once
DO_SPACES_KEY=
DO_SPACES_SECRET=
DO_SPACES_REGION=
DO_SPACES_ENDPOINT=
DO_SPACES_PRIVATE_BUCKET=
DO_SPACES_PUBLIC_BUCKET=
```

### Production (after C1 and C2)

```dotenv
FILESYSTEM_DISK=local                 # not the switch; app code never uses the default disk
STORAGE_DRIVER=s3
DO_SPACES_KEY=<access key id>
DO_SPACES_SECRET=<secret>
DO_SPACES_REGION=<region slug, e.g. sgp1>
DO_SPACES_ENDPOINT=https://<region>.digitaloceanspaces.com
DO_SPACES_PRIVATE_BUCKET=thai-visa-private
DO_SPACES_PUBLIC_BUCKET=thai-visa-public
PUBLIC_ASSETS_URL=https://thai-visa-public.<region>.digitaloceanspaces.com
# or the Spaces CDN address: https://thai-visa-public.<region>.cdn.digitaloceanspaces.com
```

Notes:

- **Endpoint and URLs:** the endpoint is the region endpoint, without the bucket. `use_path_style_endpoint=false` gives virtual-host URLs such as `https://thai-visa-private.<region>.digitaloceanspaces.com/documents/…?X-Amz-…`.
- **Bucket names:** Spaces bucket names must be unique within a region, so the agreed names may need a suffix if they are taken. Only the environment values change in that case.
- **After a change:** run `php artisan config:clear` (or `config:cache`) after any environment change in production.

### Buckets (operational settings in the DigitalOcean control panel)

| Bucket | File listing | Object ACL from app | CORS | Notes |
|---|---|---|---|---|
| `thai-visa-private` | **Restricted** | `private` | none needed (plain links, server redirect) | No public bucket policy; check encryption at rest (HLD §9, "to confirm") |
| `thai-visa-public` | Restricted (listing off) | `public-read` per object | none needed (`<img>` only) | Holds only `site/*`; optional CDN |

## 9. Safe migration plan for existing files

Switching the driver **does not move any files.** After the switch, each existing record points at a key that does not exist in Spaces:

- documents: `hasFile()` fails, so they return 404, are logged as `not_available` and are reported;
- photos: 404;
- website images: broken.

Steps:

1. **Prepare.**
   - Make C1 and C2, plus C5 and C6, and deploy them while `STORAGE_DRIVER` is still `local`.
   - Create both buckets with listing restricted, and create the access keys.
2. **Staging test.** Point a staging environment at test buckets. Check:
   - upload, download (redirect, attachment, link expired after 2 minutes), replace and delete;
   - photo display;
   - website image save and display;
   - the CSP.
3. **Inventory.**
   - On production, list every key the database refers to:
     - `documents.storage_key`;
     - `clients.photo_path` (including soft-deleted clients, which keep their files);
     - the three `site_settings` keys.
   - Compare the list with the files under `storage/app/private` and `storage/app/public/site`, and record the unreferenced files.
4. **Pause writes.** Run `php artisan down` (or a maintenance window), so no upload happens during the copy.
5. **Copy**, using `rclone`, `s3cmd` or `aws s3 sync` with the Spaces endpoint. Keep the relative keys exactly:
   - `storage/app/private/documents/*` → `s3://thai-visa-private/documents/` (ACL private);
   - `storage/app/private/client-photos/*` → `s3://thai-visa-private/client-photos/` (ACL private);
   - `storage/app/public/site/*` → `s3://thai-visa-public/site/` (ACL **public-read**);
   - set `Content-Type` from the extension (for example rclone's default MIME detection, or `--guess-mime-type` for s3cmd).
6. **Verify.**
   - Every key from the inventory exists in the right bucket with the same size (or checksum).
   - A sample private object returns **403** without a signature.
   - A public image returns 200.
7. **Switch.**
   - Set the production environment values (§8) and run `config:cache`.
   - Bring the app back with `php artisan up`.
   - Smoke test: one admin download, one client download, one photo and the landing page images.
8. **Rollback.** Keep the local files unchanged. If anything fails, set `STORAGE_DRIVER=local`, run `config:cache`, and the old files work again.
   - Files uploaded to Spaces after the switch would need copying back.
   - Keep the local copy for an agreed period, then remove it securely. These are passport and visa scans.
9. **Clean up.** Decide separately whether to delete the unreferenced files. The migration only reports them.

## 10. Risks and recommendations

| Risk | Severity | Mitigation |
|---|---|---|
| Switch made with environment variables alone (package missing) | High: every storage call fails | C1 before any switch |
| `root` prefix in keys and URLs | High: wrong keys; public URLs show server paths | C2; test C6 |
| Existing files not copied | High: documents, photos and images missing | §9 plan, with inventory and verification |
| Private bucket left with public listing, or a public bucket policy | High: client documents exposed | Bucket settings in §8; unsigned 403 check in §9, step 6 |
| Bucket names swapped or credentials shared | Medium | C3 per-bucket keys; check in §9, step 6 |
| Test suite writes to a real bucket | Medium | C5; C6(c) |
| Presigned link shared within 2 minutes | Accepted (HLD option B) | Keep it short; access is logged |
| Server clock drift breaks presigned links | Low | NTP on the Droplet |
| AWS SDK checksum defaults with Spaces | Unverified | Staging test; C8 if needed |
| Photo traffic passes through the Droplet | Low (photos are 2 MB or less) | Accepted; stronger privacy |
| `FILESYSTEM_DISK=s3` set by mistake | Low: app files unaffected; only the unused default disk points at `AWS_*` | C4 note in `.env.example` |
| Unreferenced files copied or left behind | Low | Report them in §9, step 3; decide separately |

## 11. Answers to the questions in the brief

1. **Environment variables only?** No. The S3 adapter package (C1) and the `root` fix in `config/filesystems.php` (C2) are required.
2. **Is `FILESYSTEM_DISK=s3` sufficient?** No, and it is the wrong setting. The switch is `STORAGE_DRIVER=s3`, which controls the `private` and `public_assets` disks. `FILESYSTEM_DISK` controls only the default disk, which the application never uses.
3. **Keep the public/private folder structure?** Yes. `documents/`, `client-photos/` and `site/` become the object key prefixes. `storage/app/private` and `storage/app/public` are local roots only and are not part of any key.
4. **New `spaces_public` / `spaces_private` disks?** Not needed. `private` and `public_assets` already are those two disks, with the driver chosen by `STORAGE_DRIVER`.
5. **Two buckets?** Already supported (`DO_SPACES_PRIVATE_BUCKET`, `DO_SPACES_PUBLIC_BUCKET`). Keep the agreed two-bucket design. The only gap is shared credentials (C3, recommended).
6. **Code files that must change?** No PHP file under `app/` or `database/`. Required: `composer.json` and `composer.lock` (C1), `config/filesystems.php` (C2). Recommended: `config/filesystems.php` (C3, C7, C8 if needed), `.env.example` (C3, C4), `phpunit.xml` and `phpunit.mysql.xml` (C5), `tests/Feature/StorageDisksTest.php` (C6).
7. **Database paths unchanged?** Yes. No migration and no record rewrite.
8. **Will private temporary URLs work?** Yes, after C1 and C2. `DeliverDocument` already uses `temporaryUrl()` through the disk abstraction, with a 2-minute expiry and forced attachment. Photos use a Laravel signed route and stream through the app, which also works.
9. **What happens to the local files?** Nothing moves them. Without the copy in §9, every existing document, photo and website image is missing after the switch.
10. **Configuration for local and production?** See §8. Local: `STORAGE_DRIVER=local` with `storage:link`. Production: `STORAGE_DRIVER=s3`, the `DO_SPACES_*` values, `PUBLIC_ASSETS_URL` set to the public bucket or CDN, and `FILESYSTEM_DISK` left as `local`.
