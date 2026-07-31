# Amanahku

Multi-tenant HR platform for Unijaya. The shipped scope is attendance, leave, timesheets,
the T.A.A. work board, TOT, claims and basic HR administration. A further 24 modules
(payroll, performance, onboarding, recruitment, and more) are built and switched off.

**Stack:** Laravel 13 · Blade · Tailwind v4 · Alpine.js · Vite (Rolldown) · MySQL 8 · Laravel Fortify (auth).

## Features

- **Auth** — Fortify session auth behind a custom login UI. 2FA, forced first-login password
  rotation, per-tenant branded login at `/login/{slug}`.
- **Multi-tenancy** — single database, `tenant_id` row scoping via a global scope. Users belong
  to many tenants with a per-tenant role. A super-admin tier provisions companies.
- **Personas** — Employee, Manager, Management, HR, plus the platform super-admin. The dashboard
  and navigation adapt to the signed-in user's role.
- **Per-company modules** — 30 toggleable modules, of which 6 ship on (leave, claims,
  knowledge bank/TOT, documents, reports, messaging) alongside the non-toggleable core
  (dashboard, my-work, people, attendance, admin, security). The other 24 are built but off
  by default; a super-admin switches any of them on per company. The registry is
  [app/Support/Features.php](app/Support/Features.php) — single source of truth. See
  [docs/PRD.md](docs/PRD.md) for the full scope.
- **Two-step approvals** — request flows (leave, claims, overtime) go submit → manager verify →
  management approve.

## Requirements

- PHP 8.3+ (local dev runs 8.5), Composer
- Node 22 — use **bun**, not npm
- MySQL 8, Redis, an SMTP sink

Local development runs under [Lerd](https://github.com/lerd-env/lerd), which provides PHP-FPM,
MySQL, Redis and Mailpit as Podman containers. See [CLAUDE.md](CLAUDE.md) for the lerd commands
and [docs/RULES.md](docs/RULES.md#topology) for the full topology.

## Setup

```bash
composer install
bun install

cp .env.example .env
php artisan key:generate      # fresh install only — see the warning below

php artisan migrate --seed
bun run build
```

Under lerd the app is served at **http://localhost:9100** (not `amanahku.test` — `.test` DNS is
unreliable on the dev machine; the reason is documented in `CLAUDE.md`). Without lerd, use
`php artisan serve` against your own MySQL.

> **Never run `php artisan key:generate` on an environment that already has data.** `APP_KEY`
> encrypts NRICs and sessions. Rotating it makes the encrypted payroll columns permanently
> unrecoverable and logs every user out.

## Development

```bash
bun run dev              # Vite dev server (hot reload)
php artisan test         # full test suite
vendor/bin/pint          # code style
vendor/bin/phpstan       # static analysis
```

Before building assets, run `php artisan view:cache` first. Tailwind scans the compiled Blade
cache (`@source` in `resources/css/app.css`), so building against a partial cache silently drops
utilities from the stylesheet. CI enforces this in the `Committed assets match sources` job.

## Architecture notes

- `app/Tenancy/CurrentTenant.php` — request-scoped active tenant (singleton).
- `app/Models/Concerns/BelongsToTenant.php` — global scope + `tenant_id` auto-fill; applied to
  every tenant-owned model.
- `app/Http/Middleware/ResolveTenant.php` (alias `tenant`) — resolves the active tenant from
  session, verifies membership, exposes the current role + employee.
  **Route-model binding runs before this**, so a bound model is not automatically tenant-safe —
  controllers must check ownership explicitly.
- `app/Http/Controllers/AppController.php` — single entry for all `/app/{screen}` views.
- `app/Support/Features.php` — module registry + company-category staging.
- `app/Support/Permissions.php` — role tiers, including the management tier that grants final
  approval.

## Deployment

Assets are **built locally and committed** (`public/build`); the host builds nothing. Deploy is
`git pull && bash deploy.sh`.

The full checklist, security gate, and the cron jobs that email and accrual depend on are in
**[docs/RULES.md](docs/RULES.md#part-2--operational-rules)** — that is the single source of
truth. Do not keep a second copy here.

## Documentation

| Doc | Purpose |
|-----|---------|
| [docs/PRD.md](docs/PRD.md) | What the product is, who uses it, what is in scope. |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Access model, tenancy, request flow, key files. |
| [docs/RULES.md](docs/RULES.md) | Invariants: HR domain rules, plus the deploy and security gate. |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Open work, ranked. Production cutover, auth hardening, deferred items. |
| [docs/DECISIONS.md](docs/DECISIONS.md) | Append-only architectural decision log. |
| [docs/DESIGN.md](docs/DESIGN.md) | Design system: tokens, type ramp, components, motion, and the reasoning behind them. |
| [docs/SYSTEM_GUIDE.html](docs/SYSTEM_GUIDE.html) | Developer walkthrough. Last verified 2026-07-17. |

Progress history lives in `git log`, not in a doc.
