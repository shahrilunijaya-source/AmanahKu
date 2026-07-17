# AmanahKu — Codebase Map

Read-only orientation map (generated 2026-07-17). Facts, not plans.

## Stack

- **Laravel 13** on **PHP 8.3**, Blade templates, Tailwind v4, Alpine.js, Vite, MySQL 8.
- Auth: **Laravel Fortify** (session auth, 2FA) + **Sanctum** (API tokens).
- Dev tooling: PHPUnit 12, Larastan (phpstan.neon), Pint, Pail.
- No SPA framework. Server-rendered Blade throughout.

## Scale

| Thing | Count |
|---|---|
| Controllers (`app/Http/Controllers`) | 73 |
| Models (`app/Models`) | 103 |
| Migrations | 109 |
| Feature test files | 91 |
| Web routes file | `routes/web.php` (405 lines) |

## Entry points

- `routes/web.php` — nearly everything. `routes/api.php` is tiny (23 lines, Sanctum-token API).
- `app/Http/Controllers/AppController.php` — single entry for all `/app/{screen}` views.
- `bootstrap/app.php` — middleware registration **and the scheduler** (`->withSchedule(...)`).
- `routes/console.php` — only the default `inspire` command; real commands live in `app/Console/Commands`.

## Multi-tenancy (the core architectural idea)

Single database, row-scoped by `tenant_id`:

- `app/Tenancy/CurrentTenant.php` — request-scoped singleton holding the active tenant.
- `app/Models/Concerns/BelongsToTenant.php` — global scope + auto-fill of `tenant_id` on every tenant-owned model. **Reads fail OPEN** (no active tenant → all rows returned); writes fail closed. Any new artisan command or queued job must set tenant context per company.
- `app/Http/Middleware/ResolveTenant.php` (alias `tenant`) — resolves tenant from session, checks membership, exposes role + employee.
- Users belong to many tenants with a per-tenant role. 4 personas: Employee, Manager, Management, HR.

## Key modules

- **Services** (`app/Services/`): `Payroll/`, `Ai/`, `DataScope.php` (row-level access control), `FeatureManager.php` (per-tenant module toggles), `OnboardingService`, `OffboardingService`, `StaffArchiver`, `OidcClient`.
- **Middleware worth knowing**: `SecurityHeaders`, `ForcePasswordChange`, `EnforceTwoFactor`, `EnsureModuleEnabled`, `EnsureSuperAdmin`, `ApiTenant`.
- **Scheduled commands** (defined in `bootstrap/app.php`, run via `schedule:run` cron):
  - `leave:carry-forward` — yearly, Jan 1 01:00 (must run before accrual)
  - `leave:accrue` — monthly, 1st 02:00
  - `digest:weekly` — Monday 08:00
  - `timesheet:remind` — Friday 17:00
  - `staff:archive-departed` — daily 00:30
- **Sensitive data**: NRIC uses the `encrypted` cast (Employee, SalaryStructure) — tied to `APP_KEY`. `bank_account_no`/`epf_no`/`socso_no`/`salary` are plaintext (known PDPA gap).

## Tests

- `tests/Feature` (91 files) + `tests/Unit`. Runs on in-memory SQLite (see `phpunit.xml`).
- Security-relevant suites exist: `CrossTenantDenialTest`, `DataScopeEnforcementTest`, `CsvExportSafetyTest`, `AuthFlowsTest`, `HardeningTest` (per README).
- No CI config in the repo (no `.github/`). Tests run locally: `php artisan test`.

## Deployment reality (Hostinger shared, staging only)

- Deployed at `~/domains/amanahku-staging.myappsonline.net/public_html` on the `amanahku` SSH host — **the whole app lives inside `public_html`**; an untracked root `.htaccess` rewrites all requests into `public/`. Probed 2026-07-17: `/.env` → 404, `/composer.json` → 404, `/storage/logs/...` → 403. Holding, but fragile by construction.
- Server copy is a git checkout (`f2cf804`) with files not present in this local repo (`deploy.sh`, `docs/`, audit reports, screenshots) — local history was squashed to one "Initial public release" commit. **Local repo and server repo have diverged histories.**
- `deploy.sh` on the server is the deploy entry: maintenance mode → composer install → migrate → manual `ln -sfn` storage link (because `exec()` is disabled on the host) → asset handling (no Node on host; assets built locally and committed/uploaded).
- Cron (`php artisan schedule:run` every minute) is configured in hPanel only — not visible over SSH.

## Risks / flags

1. **`APP_KEY` is load-bearing** — encrypts NRIC + sessions. Never run `key:generate` on the server. The README setup section says to run it; that applies to fresh local installs only.
2. **Tenant reads fail open** (`BelongsToTenant.php`) — cross-tenant leak risk for any code path without tenant context (commands, jobs).
3. **App root = web root** on the server; safety rests on one `.htaccess` file that is untracked in git.
4. **Local/server drift**: server has `fromClaudeDesign/`, `docs/`, QA/audit markdown, and `deploy.sh` that the local squashed repo lacks. Pulling/pushing between them is not straightforward yet.
5. `artisan about` mail check is misleading (`AppServiceProvider.php:60`) — can report OK when mail is broken. Check `storage/logs/laravel.log`.
6. `exec()` disabled and no Node on the host — anything shelling out or building assets server-side fails.
7. Plaintext bank/EPF/SOCSO/salary columns (PDPA gap) — cheapest to fix now, before production cutover.
8. The handover runbook (`vault/`) referenced in prior notes is **not present in this working copy**.
