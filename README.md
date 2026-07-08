# Amanahku

Multi-tenant HR + weekly work-tracking + AI workforce-intelligence platform for Unijaya (with Shell S2 and Petron TL as additional tenants). Built from the design reference in `fromClaudeDesign/`.

**Stack:** Laravel 13 · Blade · Tailwind v4 · Alpine.js · Vite · MySQL · Laravel Fortify (auth).

## Features

- **Auth** — Fortify session auth behind a custom login UI.
- **Multi-tenancy** — single database, `tenant_id` row scoping via a global scope. Users belong to many tenants with a per-tenant role. Data is isolated per active tenant.
- **4 personas** — Employee, Manager, Management, HR. The dashboard adapts to the signed-in user's role.
- **15 screens** — login, tenant select, dashboard (4 variants), employee directory, 360 profile, task/assignment board, weekly update, AI workforce intel, attendance, leave, KPI, onboarding (+ empty-state stubs for not-yet-built modules).
- **Working flows** — clock in/out, leave application + manager/HR approval (with balance decrement), weekly-update submit.

## Requirements

- PHP 8.3+, Composer
- Node 20+ / npm
- MySQL 8 (Laragon provides all of the above)

## Setup

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

# create the database (Laragon MySQL, root / no password by default)
mysql -u root -e "CREATE DATABASE IF NOT EXISTS amanahku CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan migrate:fresh --seed
npm run build
php artisan serve
```

Open the served URL → log in with the demo account:

```
aisyah.rahman@unijaya.example  /  password
```

The demo user can access all three tenants (HR in Unijaya, Manager in Shell, Employee in Petron) — handy for seeing every role and confirming tenant isolation.

## Development

```bash
npm run dev          # Vite dev server (hot reload)
php artisan serve    # app server
php artisan test     # full test suite
```

## Architecture notes

- `app/Tenancy/CurrentTenant.php` — request-scoped active tenant (singleton).
- `app/Models/Concerns/BelongsToTenant.php` — global scope + `tenant_id` auto-fill; applied to every tenant-owned model.
- `app/Http/Middleware/ResolveTenant.php` (alias `tenant`) — resolves the active tenant from session, verifies membership, exposes the current role + employee.
- `app/Http/Controllers/AppController.php` — single entry for all `/app/{screen}` views; loads per-screen data.
- `app/Support/Amanahku.php` — UI constants (nav, personas, page meta, AI seed messages).
- Domain data lives in Eloquent models + `database/seeders/DatabaseSeeder.php`.

## Production checklist (before public deploy)

This repo is configured for **local/demo** use. Before exposing it publicly:

**Code-level hardening — DONE (shipped in app, covered by `HardeningTest`):**

- [x] Security-headers middleware on every response: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`; **HSTS** auto-added over HTTPS
- [x] Forced password rotation — invited members must change the one-time password on first sign-in before reaching any route (`ForcePasswordChange` middleware + `password_change_required` flag; cleared on change/reset)
- [x] 2FA management requires a recent password confirmation (`confirmPassword=true`)

**Deploy-env — operator must set on the production box (not code):**

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-host`
- [ ] Dedicated MySQL user (not `root`) with a strong password and limited grants
- [ ] Fresh `APP_KEY` (`php artisan key:generate`)
- [ ] `SESSION_ENCRYPT=true` (already set); set `SESSION_DOMAIN` to your host
- [ ] `MAIL_MAILER=smtp` (or `ses`) with real credentials so reset/invite emails send (dev writes to log)
- [ ] `php artisan config:cache route:cache view:cache` and `npm run build`
- [ ] Serve over HTTPS (HSTS only activates on a TLS request)
- [ ] Replace the demo seeder with real data; remove the demo-credentials hint on the login page
- [ ] Still deferred: full CSP (needs nonce refactor, I-007 note), passkeys frontend (I-006), NRIC-at-rest encryption (I-018)

## Not yet built (next phases)

- Live AI assistant (responses are canned this phase)
- Attendance geolocation / photo capture (UI only)
- Claims, assets, training, org-chart, reports, admin modules (empty-state stubs)
- Polish pass on the derived manager/management dashboards
