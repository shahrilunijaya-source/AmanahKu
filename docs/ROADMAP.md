# Amanahku — Roadmap

Open work, ranked. Shipped history is in `git log`; the reasoning behind past choices is in
[DECISIONS.md](DECISIONS.md).

## Now — production cutover

Production **does not exist yet**. Staging is the only deployed instance. Before a cutover:

1. Provision the production host, or promote staging.
2. Both cron jobs in hPanel — scheduler and queue drain. Verify by sending real mail.
3. Nightly `mysqldump` + `APP_KEY` in a separate secret store.
4. Work the security gate in [RULES.md](RULES.md#security-gate--do-not-deploy-public-without-these).
5. Import real staff data.
6. Smoke test the approval round trip with real accounts.

## Next — authorization hardening

The highest-value engineering work outstanding. From an authorization-boundary review on
2026-07-23. Roles are well *defined*; enforcement has drifted.

| id | severity | title |
|----|----------|-------|
| **I-019** | **high** | **Tenant isolation relies on hand-written per-controller guards.** Route-model binding is not tenant-scoped (`SubstituteBindings` runs before `ResolveTenant`), so a bound `{model}` resolves across all tenants. Each controller re-defends by hand — 66 occurrences. This **fails open**: one forgotten guard on a new endpoint is a cross-tenant read/write. Fix: `->scopeBindings()` on the tenant route group, or a `resolveRouteBinding` override on `BelongsToTenant`, so isolation is structural. |
| I-021 | medium | **Role enforcement has no single source of truth.** No Gates or Policies; the `Permissions` matrix is advisory by its own docblock. The real boundary is scattered across ~50 controllers: `authorizeTenantRole()` (51 uses), 8 private `isPrivileged()` copies, inline literals (`['management','hr']` ×44, `['manager','management','hr']` ×27). Fix: promote `ROLE_PERMISSIONS` to authoritative `Gate::define`, then replace the literals. |
| I-020 | medium | **`director` access is inconsistent.** 4 controllers reimplement `isPrivileged()` inline and skip `effectiveRole()`, so a director is silently treated as a plain employee in `ComplianceController`, `ProbationController`, `VehicleController`, `Api/V1/ApiController`. Fails closed, so a consistency bug rather than a hole. A symptom of I-021. |

I-019 first — it is the only one that can leak data across companies.

## Deferred — security and auth

| id | severity | title |
|----|----------|-------|
| I-006 | medium | **Passkeys frontend not wired.** Fortify's passkey backend is available, but the WebAuthn browser ceremony and `APP_URL` origin matching (secure context, RP-ID) are not implemented. The Security page shows "Coming soon". Wire before advertising passkey sign-in. |
| I-005 | info | **CSP still allows `'unsafe-inline'`.** Needs a nonce refactor; the UI has inline styles and a few inline scripts. `frame-ancestors`, `base-uri`, `object-src`, `form-action` already enforced. |
| I-010 | info | **AI data egress under the `claude` driver.** With `AMANAHKU_AI_DRIVER=claude`, tenant workforce facts (names, counts, approval totals) go to Anthropic per question. Tenant-scoped, no cross-tenant data, but an external egress decision — confirm with the customer / DPA before enabling. Default `canned` sends nothing. |
| I-012 | info | **Assistant cost and abuse.** Throttled at 20/min. Production needs per-tenant spend caps and monitoring when the live driver is on. |

## Deferred — payroll

Payroll ships off. These matter only when it is switched on.

| id | severity | title |
|----|----------|-------|
| I-013 | medium | **SOCSO/EIS use a capped-percentage approximation.** PERKESO publishes exact stepped bracket tables; the app computes a flat percentage on the capped wage. Fine for estimates, wrong for a real run — an in-app banner warns. **Fully scoped below.** |
| I-016 | info | **PCB / MTD is manual entry.** By design — avoids encoding the yearly-changing LHDN progressive table plus reliefs. A future auto-MTD calculator could sit behind the editable rate config. |
| I-014 | low | **Payroll models use `$guarded = []`.** Consistent with all ~24 models. Every payroll write uses whitelisted computed attributes or explicitly validated fields, never `request()->all()`, so there is no live mass-assignment vector. Before a public payroll deploy, consider explicit `$fillable` allowlists on the four financial models excluding `tenant_id`, `status` and computed amounts. |
| I-015 | low | **Finalize allows draft → finalized with no approval.** Intentional single-operator shortcut; the `payroll.four_eyes` setting enforces the control when required. |
| I-017 | low | **Bank file is a generic CSV.** No./Name/Account/Amount. Bank-specific bulk formats (Maybank2u, CIMB BizChannel, RHB, DuitNow batch) are a future per-bank exporter. |

### I-013 in detail — PERKESO statutory bracket tables

**Goal:** replace the SOCSO/EIS flat-percentage-on-capped-wage approximation with the
official PERKESO stepped contribution tables (Jadual Caruman), so contributions match the
legally-published ringgit amounts exactly.

**Status:** scoped, not built. **Touches** I-014 indirectly. **Out of scope:** EPF (already
exact — a percentage with no bracket rounding), PCB/MTD (I-016), bank-specific export
formats (I-017).

Locked decision (2026-06-24): bracket data is stored as a **PHP constant class**
`StatutoryBrackets`, not a migration. Statutory data is identical for every tenant and only
changes when PERKESO republishes, so versioning it in git mirrors the existing
`StatutoryRate::defaults()` pattern and needs no schema change.

The `payroll.statutory_mode` setting already exists with `flat` and `brackets` options,
defaulting to `brackets` — the switch is in place ahead of the implementation.

## Deferred — features

| id | severity | title |
|----|----------|-------|
| I-025 | info | **AI Workforce Intelligence hidden.** `module.ai` defaults off via `Features::OFF` — built, not fake, but not signed off for release. Hides the `workload` screen, its nav entry, and the manager/management dashboard blocks that surfaced it. Re-enabling is a flag flip per company. |
| I-026 | medium | **`departmentCapacity()` fabricates its percentage.** `BuildsDashboardData::departmentCapacity()` computes `min(50 + head * 11, 99)` — derived purely from headcount — while the card labels it "Assigned load vs. available capacity, this week". Departments and headcounts are real; the load percentage is invented. Currently hidden behind I-025. **Fix before re-enabling the module**: compute from the live `Employee::workload` accessor that `WorkforceInsights::overloaded()` already uses. |
| I-011 | low | **Attendance clock-in `location` is a hardcoded string.** `AttendanceController` stores a fixed office label for every tenant. Derive it from the tenant or geocode before relying on it in reports. |
| — | low | **Permission enforcement is staff-domain only.** `canInTenant` gates the staff domain end-to-end; other domains still use plain role checks. |
| — | low | **Bulk import is CSV only.** True `.xlsx` parsing needs a spreadsheet dependency. |

## Reviving a parked module

24 modules are shipped off. Bringing one back takes **two** steps, not one:

1. Delete its line from `Features::OFF` in [app/Support/Features.php](../app/Support/Features.php).
2. Restore its screen blade — the 38 blades of the parked modules were deleted in the UI
   revamp: `git checkout pre-blade-purge -- resources/views/screens/<screen>.blade.php`

Without step 2 the module renders `screens.empty` rather than crashing, because
`AppController::screen` falls back on `View::exists`. The `pre-blade-purge` tag is local
until someone runs `git push origin pre-blade-purge`.

Models, controllers, routes, tests and registry entries were never deleted, so a parked
module is provably still working — roughly 40 test classes cover them, and the suite runs
with every module on.

Five view tests are `markTestSkipped` rather than deleted (overtime approval chain, two
performance screens, the achievements leaderboard, shared resources). Each names the blade
it lost; their model and controller coverage still runs.

## Known remnants

- `ecosystem.config.cjs` is a PM2 config from the previous maintainer's Windows/Laragon
  setup, with a hardcoded `C:/laragon/` path. Dead, kept for reference.
- `docs/SYSTEM_GUIDE.html` is a developer reference last verified 2026-07-17 and has not
  been re-checked against the shipped UI.
