# Thai Visa & Services Management

A web-based management system for a Thai visa and work-permit services agency.

The application helps administrators manage clients, visa services, payments, documents, and job-search operations. Clients can securely access their service progress and permitted documents through a private tracking portal.

## Overview

**Main goals**
- Centralize client and service management.
- Track payments, refunds, and outstanding balances.
- Manage client documents and work-permit files securely.
- Manage job orders and worker applications.
- Provide clients with a simple mobile-friendly tracking portal.
- Support both Burmese and English interfaces.

**Currency:** Thai Baht (THB)  
**Default language:** Burmese  
**Additional language:** English

## Features

### Admin Dashboard
- Business dashboard with total received, outstanding balances, and active clients.
- Monthly and service-based financial reports.
- Client, service, and Job Search management.
- Website appearance and content settings.
- Admin account and security settings.

### Client Management
- Create, view, and update client profiles.
- Store client information, profile photos, and remarks.
- Assign multiple services to one client.
- Track service status, fees, payments, and remaining balances.
- Cancel services and manage client deletion according to the defined business rules.
- Generate client tracking links and QR codes.

### Service Management
Initial service catalogue:
- Passport
  - Bath
  - MOU
- CI
- Pink Card
- Work Permit
- 90 Days Report
- Job Search

Administrators can manage services, optional sub-services, default fees, descriptions, and website visibility.

Sub-services support a maximum of two levels: Main Service → Sub-service.

### Payment Management
- Maintain a payment ledger for each client service.
- Record payment amount, date, method, and notes.
- Automatically calculate received amounts and remaining balances.
- Record refunds when services are cancelled or fees are reduced.
- Calculate money kept as received payments minus refunds.
- Support THB financial reporting.

**Note:** V1 does not track operating costs or calculate actual business profit.

### Job Search Management
- Create and manage company job orders.
- Add workers by searching their phone numbers.
- Reuse existing client records when appropriate.
- Track worker applications and their statuses.
- Manage hired workers and engagement completion.
- Record Job Search service payments.
- Close job orders with the required application resolutions.

### Client Tracking Portal
- Private tracking link and QR code for each client.
- Four-digit PIN authentication using the last four digits of the client's phone number.
- View personal information and all assigned services.
- Check service progress and payment status.
- View and download permitted documents.
- Mobile-first interface.

Client access is restricted to their own records and permitted documents.

### File Management
- Client profile photos.
- PDF documents.
- Website logo, favicon, and hero image.
- Browser-side image resizing and compression.
- Server-side file type and size validation.
- Private document access through Laravel authorization and short-lived signed URLs.

File limits:
| File type | Maximum size |
|---|---:|
| Client profile photos | 2 MB |
| Website images | 2 MB |
| PDF documents | 5 MB |

### Localization
- Burmese and English interfaces.
- Language switching for administrators and clients.
- Language preference stored in the browser.
- Date and time displayed using the Asia/Bangkok timezone.

## Technology Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.3+, Laravel 13 |
| Frontend | Vue 3, TypeScript |
| Application architecture | Inertia.js |
| Styling | Tailwind CSS |
| UI components | shadcn-vue |
| Database | MySQL 8 |
| Authentication | Laravel Fortify |
| Google Login | Laravel Socialite |
| Authorization | Laravel Policies |
| File storage | DigitalOcean Spaces (S3-compatible) |
| Frontend build | Vite |
| Web server | Nginx |
| PHP runtime | PHP-FPM |

## System Architecture

The application uses a Laravel and Inertia.js monolithic architecture.

```text
                  Browser
                     |
                  HTTPS
                     |
              Nginx + PHP-FPM
                     |
                Laravel App
                 /       \
                /         \
             MySQL     DigitalOcean
            Database      Spaces
                         /      \
                      Private   Public
                      Bucket    Bucket
```

### Storage Design

**Private bucket**
- Client profile photos.
- PDF documents.
- Files are accessed only after Laravel authorization checks.

**Public bucket**
- Website logo.
- Favicon.
- Hero image.

Development uses local filesystem storage. Production uses separate DigitalOcean Spaces buckets for private and public assets.

The application stores object keys in the database, not the uploaded file contents.

## Authentication and Security

### Administrator
- Email and password authentication.
- Google sign-in for an existing administrator account.
- No public administrator registration.
- Superadmin accounts are created through an Artisan command.
- Google sign-in requires a verified Google email matching the existing application account.

### Client
- Tracking token and four-digit PIN.
- Five consecutive incorrect PIN attempts trigger a 15-minute lock.
- PIN attempts are counted per client across IP addresses.
- Administrators can manually unlock a client's PIN.

### Security Measures
- HTTPS in production.
- Trusted host validation.
- Secure session cookies.
- Laravel authorization policies.
- Rate limiting.
- Private object storage.
- Short-lived signed download URLs.
- Document download access logging.
- Server-side upload validation.

## Local Development Setup

### Requirements

- PHP 8.3 or newer.
- Composer.
- Node.js and npm.
- MySQL 8.
- Git.

### 1. Clone the repository

```bash
git clone git@github.com:Zin-Mg-Nyunt/Thai_Visa_Services.git
cd Thai_Visa_Services
```

### 2. Install PHP dependencies

```bash
composer install
```

### 3. Install frontend dependencies

```bash
npm ci
```

### 4. Configure environment

```bash
cp .env.example .env
```

Configure the local database and other required environment variables in `.env`.

### 5. Generate application key

```bash
php artisan key:generate
```

### 6. Run database migrations

```bash
php artisan migrate
```

### 7. Seed the initial service catalogue

```bash
php artisan db:seed --class=ServiceSeeder
```

The service seeder initializes the catalogue only when it is empty. It does not overwrite existing administrator changes.

### 8. Create an administrator

```bash
php artisan app:create-superadmin
```

Follow the interactive prompts.

### 9. Start the development server

```bash
composer run dev
```

Alternatively, run the Laravel and Vite development servers separately:

```bash
php artisan serve
```

```bash
npm run dev
```

## Common Commands

| Task | Command |
|---|---|
| Run migrations | `php artisan migrate` |
| Seed services | `php artisan db:seed --class=ServiceSeeder` |
| Create superadmin | `php artisan app:create-superadmin` |
| Recover superadmin password | `php artisan app:reset-superadmin-password` |
| Clear Laravel caches | `php artisan optimize:clear` |
| Cache production configuration | `php artisan config:cache` |
| Run tests | `php artisan test` |
| Run frontend build | `npm run build` |

The password recovery command requires server shell access. It does not send an email.

## Production Deployment

Production is designed for a DigitalOcean Droplet with:

- Ubuntu Server.
- Nginx.
- PHP-FPM.
- MySQL on the same Droplet.
- DigitalOcean Spaces for file storage.
- Let's Encrypt SSL.

### Production Environment

The production environment template is:

```text
.env.production.example
```

Copy it to `.env` when preparing a new production environment.

Important environment settings include:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
TRUSTED_HOSTS=your-domain.com

SESSION_SECURE_COOKIE=true

STORAGE_DRIVER=s3
```

Configure database credentials, Google OAuth credentials, and separate Spaces credentials for private and public buckets.

**Never commit `.env` or production credentials to Git.**

### Production Build

```bash
composer install --no-dev --optimize-autoloader

npm ci

npm run build
```

Ensure that `public/hot` does not exist on the production server.

### Database Deployment

Back up the production database before deploying database changes.

Run migrations using:

```bash
php artisan migrate --force
```

Do not use `migrate:fresh` or other destructive database commands on production.

### Production Caching

```bash
php artisan optimize:clear
php artisan config:cache
```

V1 does not require Redis, queue workers, scheduled tasks, or an outgoing email service.

## Important V1 Decisions

- Only two application roles: superadmin and client.
- No public administrator registration.
- No outgoing email service.
- No online payment gateway.
- No automated LINE or SMS notifications.
- No additional staff roles in V1.
- No Redis.
- No automatic orphan-file cleanup.
- No native mobile applications.
- No cost tracking or profit calculation.
- No restore functionality for deleted clients.

These limitations are intentional V1 scope decisions, not missing configuration.

## Project Documentation

Refer to the project documentation for detailed business rules, technical decisions, implementation status, and deployment requirements.

Key documents:

- `docs/` — Project documentation.
- `docs/JOB_SEARCH_BUSINESS_RULES.md` — Job Search business rules.
- `docs/JOB_SEARCH_TECHNICAL_DECISIONS.md` — Job Search technical decisions.
- `docs/reports/` — Implementation reviews and technical reports.
- `docs/reports/HLD_CODEBASE_RECONCILIATION_AUDIT.md` — HLD and codebase reconciliation.

The High-Level Design is the main reference for approved V1 requirements. Where a more specific Job Search document conflicts with the HLD, the Job Search document takes precedence for that module.

## Ownership and Handover

Production infrastructure, domain, database, and DigitalOcean Spaces resources should belong to the client's DigitalOcean account.

Developer access should be granted through invited team access rather than sharing the client's owner credentials.

All application secrets and credentials must remain outside the Git repository.

---

**Thai Visa & Services Management**  
Private business management application for visa and work-permit service operations.
