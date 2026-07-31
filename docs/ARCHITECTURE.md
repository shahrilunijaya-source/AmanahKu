# Amanahku — Architecture

How the system is built. Product scope is in [PRD.md](PRD.md); the invariants you must not
break are in [RULES.md](RULES.md); the reasoning behind past choices is in
[DECISIONS.md](DECISIONS.md).

**Stack:** Laravel 13 · Blade · Tailwind v4 (CSS-first `@theme`) · Alpine.js · Vite
(Rolldown) · MySQL 8 · Laravel Fortify.

## Access model

Final access is the product of four layers. All four must pass.

```
company feature entitlement  ×  role permission  ×  user override  ×  data scope
```

1. **Feature entitlement** — is the module switched on for this company?
   `FeatureManager` resolves it; `EnsureModuleEnabled` middleware (alias `module.enabled`)
   404s every `/app/<segment>/...` route whose owning module is off, reads *and* writes.
2. **Role permission** — `Permissions::ROLE_PERMISSIONS` maps role → permission set.
   Roles: `employee`, `manager`, `management`, `director`, `hr`, plus the platform
   `is_super_admin` tier. `Permissions::effectiveRole()` collapses `director` to
   `management`, which is the single hinge letting `director` exist without touching the
   ~30 `['management', …]` gates in controllers.
3. **User override** — `user_permissions` rows grant or deny a single permission to one
   member. `User::canInTenant($tenant, $perm)` resolves role permissions ± overrides. A
   redundant override that merely restates the role is not stored.
4. **Data scope** — `tenant_user.data_scope`, one of `own · team · department · branch ·
   company`. `App\Services\DataScope` narrows queries. A new manager defaults to `team`,
   everyone else to `company`, so a manager does not read the whole company's attendance on
   day one.

**Known weakness:** enforcement is decentralized. No Gates or Policies exist; the
`Permissions` matrix is advisory, and the real boundary lives in ~50 controllers as
`authorizeTenantRole()` calls, eight private `isPrivileged()` copies, and inline role
literals. Tracked as I-019/I-020/I-021 in [ROADMAP.md](ROADMAP.md).

## Tenancy

Single database, `tenant_id` column on every tenant-owned table.

- `app/Tenancy/CurrentTenant.php` — request-scoped active tenant (singleton).
- `app/Models/Concerns/BelongsToTenant.php` — global scope + `tenant_id` auto-fill.
- `app/Http/Middleware/ResolveTenant.php` (alias `tenant`) — resolves the active tenant from
  session, verifies membership, exposes the current role and employee.
- `EnsureCompanyIsActive` (alias `company.active`) — blocks `/app/*` for a suspended company
  or an expired subscription.

> **Route-model binding is not tenant-scoped.** `SubstituteBindings` runs *before*
> `ResolveTenant`, so `CurrentTenant` is not set and the global scope is not active when a
> `{model}` is resolved. A bound model can therefore come from **any** tenant. Every
> controller re-asserts ownership by hand — 66 occurrences of
> `abort_unless($m->tenant_id === CurrentTenant::id(), 403)`. This **fails open**: one
> forgotten guard on a new endpoint is a cross-tenant hole. See [RULES.md](RULES.md).

## Request flow

```
route  →  SubstituteBindings  →  auth  →  ResolveTenant  →  company.active
       →  ForcePasswordChange  →  module.enabled  →  controller
```

- `AppController::screen` is the single entry for all read screens at `/app/{screen}`.
  It resolves the view with `View::exists("screens.$screen") ? … : 'screens.empty'`, which
  is why a module switched back on renders an empty screen rather than crashing.
- Write paths are dedicated controllers (Leave, Attendance, Weekly, Claim, Admin, …), all
  post/redirect/get with a flash message.
- `AuditLog::record()` fires on every state-changing admin or approval action.

## Frontend

- **No full-page reloads for in-screen actions.** `resources/js/partial-nav.js` fetches a
  Blade partial and swaps only that region; `pushState` keeps the URL honest. Do not
  duplicate markup in Alpine — fetch the partial the server already renders.
- Alpine for local interactivity, a global `$store.toast` for feedback.
- Design tokens and the type ramp live in [DESIGN.md](DESIGN.md).

## Company provisioning

```
Super-admin → create company (+ category, profile) → category seeds entitlement
            → create first company admin (one-time password, emailed)
Company admin → branded /login/{slug} → forced password rotation on first sign-in
            → setup wizard → branches / departments / positions / levels / types / roles
            → add staff → staff activate via signed link → see only permitted modules
```

- `company_setup_progress` tracks 10 ordered steps. Data-backed steps auto-detect; the rest
  the admin marks done. The wizard reuses existing CRUD screens — no duplicated logic.
- `GET /login/{tenant:slug}` renders the shared login view with the company's logo, colours
  and welcome message, and stores `intended_tenant` in session. Unknown slug 404s. After
  auth, a genuine member goes straight into that company; a non-member silently gets their
  own workspace picker, so membership is never disclosed.
- Invite mail carries a signed, expiring activation link **and** a one-time password.
  Activating sets the member's own password, clears `password_change_required`, stamps
  `email_verified_at`, and logs them in. The link is single-use in effect — 410 once active.
- Bulk staff import is native CSV, capped at 1000 rows, invalid rows skipped and reported.
  It creates directory records only; login accounts still come from the per-member invite.

**Field ownership is enforced server-side.** Super-admin owns category, plan, slug, status
and subscription dates. Company admin owns branding, industry, contact and address.
Restricted fields are absent from the admin form *and* rejected by the controller.

## Key files

| Path | Role |
|------|------|
| [app/Support/Features.php](../app/Support/Features.php) | Module registry, category stages, `OFF` list, behavioural settings |
| [app/Support/Permissions.php](../app/Support/Permissions.php) | Roles, tiers, permission map, data scopes |
| [app/Services/DataScope.php](../app/Services/DataScope.php) | Narrows queries by member scope |
| [app/Services/Payroll/PayrollCalculator.php](../app/Services/Payroll/PayrollCalculator.php) | Pure payroll calc, unit-tested |
| [app/Services/Ai/AiProvider.php](../app/Services/Ai/AiProvider.php) | AI contract; canned default, Claude behind a flag |
| [app/Http/Controllers/AppController.php](../app/Http/Controllers/AppController.php) | All read screens + branded login |
| [bootstrap/app.php](../bootstrap/app.php) | Middleware aliases + the schedule |

## Scheduled work

Everything below runs from a single `schedule:run` cron. Without that cron they fail
silently — see [RULES.md](RULES.md#operational-rules).

| Command | Cadence |
|---------|---------|
| `leave:accrue` | Monthly, 1st at 02:00 |
| `leave:carry-forward` | Yearly, 1 Jan at 01:00 |
| `digest:weekly` | Mondays 08:00 |
| `timesheet:remind` | Fridays 17:00 |
| `staff:archive-departed` | Daily 00:30 |
| `attendance:remind` | Every 15 min, 06:00–22:00 |
| `tot:remind` | Daily 08:00 |
