# Amanahku — Roadmap

Open work, ranked. Shipped history is in `git log`; the reasoning behind past choices is in
[DECISIONS.md](DECISIONS.md).

## Now — close the gaps prod went live with

Production went live on **https://amanahku.unijaya.com** on 2026-07-31, on DigitalOcean,
provisioned by the devops team, with one seeded super-admin account. The cutover happened without the checklist
that used to sit here, so the checklist is now a list of unknowns to confirm with devops.
None of it can be verified from a developer machine — see
[RULES.md § Production handoff](RULES.md#production-handoff).

1. **Both cron jobs.** Scheduler and queue drain. Without them, leave accrual, digests and
   reminders stop, and invited users never receive an activation link. Highest risk of the
   set, because it fails silently.
2. **Mail actually delivers.** Send one real mail and read the log. `php artisan about`
   cannot detect this.
3. **Backups.** Nightly `mysqldump`, and `APP_KEY` stored somewhere other than the database
   host. Losing the key makes every encrypted NRIC unrecoverable.
4. **The security gate** in [RULES.md](RULES.md#security-gate--do-not-deploy-public-without-these).
   Confirm `APP_DEBUG=false` first; it is the one that leaks.
5. **Demo seeder not run on prod**, and the seeded super-admin password changed from whatever
   it shipped with.
6. Import real staff data, then smoke test the approval round trip with real accounts.

## Done — one history for two repos (2026-07-31)

The two repos shared no commit ancestor, so `git push gitlab main` was rejected and every
release crossed as a file upload, leaving prod untraceable to a sha. Fixed by merging the
GitLab baseline into `main` with `-s ours --allow-unrelated-histories` (`d8173a8`) and
restoring devops's 29 template files (`34329de`). GitLab `main` is now a fast-forward from
ours; their merge requests and history survived. Details in
[RULES.md](RULES.md#topology).

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
| I-016 | info | **PCB / MTD is manual entry.** By design — avoids encoding the yearly-changing LHDN progressive table plus reliefs. A future auto-MTD calculator could sit behind the editable rate config. |
| I-014 | low | **Payroll models use `$guarded = []`.** Consistent with all ~24 models. Every payroll write uses whitelisted computed attributes or explicitly validated fields, never `request()->all()`, so there is no live mass-assignment vector. Before a public payroll deploy, consider explicit `$fillable` allowlists on the four financial models excluding `tenant_id`, `status` and computed amounts. |
| I-015 | low | **Finalize allows draft → finalized with no approval.** Intentional single-operator shortcut; the `payroll.four_eyes` setting enforces the control when required. |
| I-017 | low | **Bank file is a generic CSV.** No./Name/Account/Amount. Bank-specific bulk formats (Maybank2u, CIMB BizChannel, RHB, DuitNow batch) are a future per-bank exporter. |

### I-013 — PERKESO statutory bracket tables (done)

SOCSO/EIS now compute from the official PERKESO stepped contribution tables (Jadual
Caruman), transcribed row-by-row from the published PDFs into
`tests/Fixtures/socso-third-schedule-act4.csv` and `tests/Fixtures/eis-second-schedule-act800.csv`,
and generated mechanically into `App\Services\Payroll\PerkesoSchedule` (a PHP constant
class, not a migration — statutory data is identical for every tenant and only changes
when PERKESO republishes). `SocsoCalculator`/`EisCalculator` look up bands from that
table; there is no flat-percentage fallback and no tenant override — same posture as
EPF. `StatutoryRate` now only stores PCB config.

Also landed alongside this: **SKBBK ("Lindung 24 Jam")**, voluntary since 8 July 2026 and
entirely employee-paid, as a per-employee opt-in (`salary_structures.skbbk_opt_in`) with
its own payslip line (`payslips.skbbk_employee`) — not folded into `socso_employee`.

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
