# Decision Log

Append-only architectural + scope decisions.

---

## 2026-06-23 — Achievements/Reviews phase

**D-013 · Performance reviews as a new table, achievements enriched in place.**
`performance_reviews` is a distinct domain (cycle, rating, competencies, self-assessment) → new table +
model. `achievements` already existed (title/who/date_label) but too thin for a real screen → added
`category`, `icon`, `points`, `date` via additive migration `000010`. Reversible; matches the
established additive-migration convention. No production data exists (dev-only, `migrate:fresh` reset path).

**D-014 · Review write-paths are employee-owned; reviewer rating-entry deferred.**
Implemented `acknowledge` (completed → acknowledged) and `selfAssessment` (save on open cycle), both
restricted to the review's own employee + active tenant. A manager/HR "finalise review + enter rating"
workflow is the natural next step but widens scope (rating UI, competency entry, submit→complete state
machine). Deferred and logged in ISSUES (I-003). Reviews are seeded as `completed` so the acknowledge
path is exercisable today. Safest/simplest choice consistent with the existing approve-style patterns.

**D-015 · "Give recognition" gated to manager/management/hr.**
Recognition is a managerial act, mirroring claim/leave approver gating. Employees see the feed +
leaderboard but not the create form (server-enforced 403, not just hidden). Chosen employee is
re-validated against the active tenant (defense-in-depth beyond the tenant global scope).

**D-016 · Leaderboard aggregation computed in PHP, not SQL HAVING.**
`withSum`/`withCount` then `filter`/`sortByDesc` in the collection — avoids cross-DB HAVING-on-alias
differences (MySQL prod vs sqlite tests). Small result set, negligible cost.

**D-017 · Route-model-bound reviews assert tenant_id in the controller.**
`SubstituteBindings` resolves `{review}` before the `tenant` middleware sets `CurrentTenant`, so the
global scope is not yet active at bind time. Every controller therefore re-asserts
`$review->tenant_id === CurrentTenant::id()` (same defense-in-depth already used by Leave/Claim approve).

**D-018 · Authorization gates the data load, not just the render (from self-review).**
Independent review flagged that `teamReviews` (confidential review content) and `recipients` were
loaded for every role and only hidden in the template. Changed `reviewsData`/`achievementsData` to
take the real role and return `collect()` for non-privileged roles — data the user isn't authorised to
see is never loaded. Also tenant-scoped the recognition `exists` rule and the leaderboard aggregate
sub-queries, and added an explicit `tenant_id` on recognition create. Principle: never rely on the
view layer for access control.

---

## 2026-06-23 — AI layer

**D-019 · AI behind a provider interface + feature flag, canned by default.**
`AiProvider` contract with `CannedAiProvider` (default, summarises live tenant facts with no
external call) and `ClaudeAiProvider` (Anthropic Messages API). Bound by config; live only when
`AMANAHKU_AI_DRIVER=claude` + `ANTHROPIC_API_KEY`. Claude failures degrade to canned. This makes the
headline "AI" real and switch-on-able without hard-coding a vendor or requiring a key to run the app,
and keeps the demo deterministic. Egress tradeoff documented (ISSUES I-010).

---

## Earlier (reconstructed)

- **D-001** Stack: Laravel 13 + Blade + Tailwind v4 + Alpine (keep bespoke design).
- **D-002** Auth: Fortify backend + custom login view (not Breeze) to preserve the design.
- **D-003** Multi-tenancy: single DB, `tenant_id` column + `BelongsToTenant` global scope (no per-tenant DB).
- **D-004** Role from `tenant_user` pivot; dashboard persona toggle is a demo affordance gated to privileged roles.
- **D-005** One `AppController@screen` action routes all read screens; `View::exists` picks real-vs-empty.
- **D-006** Write-paths are dedicated controllers (Leave/Attendance/Weekly/Claim/Handbook/Admin), PRG + flash.
- **D-007** Approval actions role-gated + tenant-asserted; balance decrement in a DB transaction.
- **D-008** `AuditLog::record()` static helper, tenant auto-filled, fired on every state-changing admin/approval action.
- **D-009** Demo password `password` is intentional for the seeded demo account only.
- **D-010** `.env` gitignored; commits carry no AI attribution (per global settings).
- **D-011** Not deployed — no remote/host configured; deploy awaits explicit target (user said "not yet").
- **D-012** AI assistant + workforce intel are canned/seeded this milestone (no live LLM).

## 2026-06-24 — Phase E: Payroll & compensation (decisions)

- **Module chosen:** Payroll & compensation (user picked from feature-depth options).
- **Statutory deductions (EPF/SOCSO/EIS):** implement standard published Malaysian rules as **editable rate tables** stored per-tenant, seeded with current values, marked "verify against official KWSP/PERKESO tables before a real run". Rates confirmed via web research 2026-06-24: EPF employee 11% / employer 13% (wage <= RM5,000) or 12% (> RM5,000); SOCSO employer 1.75% / employee 0.5%; EIS 0.2% each; SOCSO+EIS wage ceiling RM6,000 (since Oct 2024). Percentage-on-capped-wage model (PERKESO exact bracket table is an approximation here — flagged verify-before-prod, ISSUES I-013).
- **PCB / income tax (MTD):** **manual entry per employee per payslip** — no auto-calculation. Avoids encoding the full LHDN progressive table + reliefs (yearly-changing, error-prone). User explicit choice (legal-rule guardrail).
- **Scope:** Core run + payslips **+ variable inputs** (overtime, bonus/one-off additions, unpaid-leave proration via unpaid-days x daily rate, pull approved expense claims into the run as reimbursements -> mark claims `paid` on finalize). **Excluded this pass:** bank payment export file, statutory contribution report exports.
- **ACL:** payroll administration (runs, salary structures, rate config) = `management` + `hr` only. Every employee sees **own** payslips read-only. Managers do NOT get payroll admin (compensation is sensitive). Data load is role-gated, not just template-hidden.
- **Calc model:** OT amount = hours x (basic / 26 / 8) x 1.5 (Employment Act ordinary-rate-of-pay default, configurable constants in PayrollCalculator); unpaid_deduction = unpaid_days x (basic / 26). Statutory computed on total gross earnings (after unpaid deduction); wage-definition refinement per KWSP/PERKESO flagged verify-before-prod.
- **State machine:** payroll run `draft -> approved -> finalized`. Payslips editable only while `draft`/`approved`. On finalize: lock payslips, notify each employee, mark included claims `paid`.

## 2026-06-24 — Phase E+: Payroll production tier (decision)

User approved expanding payroll to the production tier previously deferred ("finish payroll to prod tier"): EPF/SOCSO/EIS statutory contribution reports + a bank payment file. Added bank/statutory identifiers (bank name + account, EPF no, SOCSO no, NRIC) to salary structures. Bank file is a generic CSV (bank-specific formats deferred, I-017); NRIC is PII (I-018). Exports are management/hr-only, tenant-asserted, finalized-runs-only.

## 2026-07-28 — Delivery scope cut to six features (decision)

**D-0xx · Descoped modules are switched off, not deleted.**
User asked to cut the app back to attendance, timesheets, T.A.A. (the `board` screen),
TOT, claims and leave, plus basic HR administration. Chosen mechanism: the existing
feature registry, not code deletion.

- `Features::NOT_READY` renamed to `Features::OFF` and widened from one key to 24. It now
  covers two reasons that resolve the same way: **descoped** (out of this delivery scope)
  and **not signed off** (`module.ai`, I-025). `Features::defaults()` and
  `FeatureManager::applyCategoryPackage()` both read it, so a descoped module stays off at
  every company-category stage.
- This is a **default, not a lock.** A super-admin or tenant admin can still switch any of
  them on per company from the Features panel; the tenant override beats the registry.
  Re-scoping a module back in = delete one line from `Features::OFF`.
- **Why not delete the code.** The tables already carry staging data, so dropping them is
  not revertable by `git revert`. The per-tenant module entitlement (Stage 1/2/3 packages)
  is a deliberate product concept — deleting modules would delete the product model, not
  the bloat. And ~40 test classes cover the parked modules; keeping them alive keeps the
  parked code provably working.
- **Bundles split** so a kept feature is not dragged out by a dropped one:
  `module.claims` now gates only `claims` + `claim-approvals`; the new `module.expenses`
  gates `expenses` + `travel` and is off. `module.knowledge` keeps both `knowledge-bank`
  and `tot` (user kept Knowledge Bank).
- **Screens that had no gating module** and so leaked into the nav were given one:
  `onboarding-content` folded into `module.onboarding`; new `module.sharedresources` and
  `module.profiletest`, both off. `module.profiletest` was re-scoped **in** on 2026-07-31:
  the welcome wizard links every new starter at `/app/profile-test`, so the gate turned
  that link into a 404. Its two blades survived the purge, so the revival was one line.
- **Dashboard widgets do not inherit the screen gate.** `StuckRequests` and
  `BuildsDashboardData::pendingActions` were rendering rows that link to now-404 screens,
  so both now filter their request types by `FeatureManager::screenAllowed`. Other
  cross-module dashboard copy is a known remnant (see below).
- **Tests run with every module ON.** `Tests\TestCase::setUp` writes an unlocked platform
  row per `Features::OFF` key, so parked modules keep their coverage. Tests that assert
  the shipped default call `useShippedModuleDefaults()`. `ShippedScopeTest` pins the scope
  in both directions and fails if a descoped screen drifts back in.

- **Probation remnant on the HR dashboard, closed.** The heading read "N on probation · N
  confirmations due this month" and a stat tile read "On probation / confirmations
  pending" while `module.probation` was off. The split follows the data source, not the
  word "probation": `on_probation` is an `Employee.status` value (basic HR) and stays
  visible, while `confirmations_due` reads `ProbationReview` (module data) and is now
  gated. `dashStats()` omits the key entirely when the module is off, and `dashHeading()`
  builds the HR subtitle by joining the clauses that are present, so nothing dangles. The
  tile keeps its count but drops the "confirmations pending" promise (and its amber
  needs-action tone) for a neutral "of active headcount". `DashboardProbationGatingTest`
  pins both sides of the gate. The `Probation` row in `reportsData()` is a plain status
  breakdown, so it was left alone.

## 2026-07-28 — Descoped screen blades deleted (decision)

**D-0xx · The 38 blades of the 24 `Features::OFF` modules were deleted; their models,
controllers, routes, and registry entries were not.**
Follow-up to the scope cut above. The revamp of the UI has to restyle every screen, and
38 of them were unreachable: no nav entry, no module switched on. Restyling them would
have been wasted work, so the views go and everything behind them stays.

- **Nothing 500s.** `AppController::screen` resolves the view through
  `View::exists("screens.$screen") ? … : 'screens.empty'`, so a module switched back on
  now renders the empty screen instead of crashing. That fallback predates this change;
  it is what made the deletion safe.
- **Rollback is a git tag.** `pre-blade-purge` marks the commit before the delete.
  Everything back: `git checkout pre-blade-purge -- resources/views/screens/`.
  One module: append `<screen>.blade.php` to that path. The tag is local until someone
  runs `git push origin pre-blade-purge`.
- **Five view tests are `markTestSkipped`, not deleted** (overtime approval chain, two
  performance screens, the achievements leaderboard, shared resources). Each names the
  blade it lost. Their model and controller coverage is untouched and still runs.
- **Descoped modules are hidden from the tenant Features panel.**
  `BuildsSettingsData::featureRows` now skips a module that is both in `Features::OFF`
  **and** resolves off for that company. Offering a toggle that can only deliver
  `screens.empty` is worse than offering nothing. The condition is keyed on the resolved
  value, not on `Features::OFF` alone, so a company that already has an override stays
  able to switch the module back off.
- **The super-admin matrix still lists every key.**
  `SuperAdmin\FeatureController::matrixData` is deliberately unfiltered: it is now the
  only route back for a descoped module, and reviving one is a platform decision, not a
  tenant one. Three tests in `FeatureToggleUiTest` pin all three rules.
- **Reviving a module is now two steps, not one.** Deleting its line from
  `Features::OFF` still switches it on, but the UI will be the empty screen until the
  blade is restored from the tag. `Features::OFF`'s own docblock says "brought back by
  deleting one line" — that sentence is now only true for the gate, not for the screen.

**Not a remnant.** `resources/views/partials/pt-question-form.blade.php` was listed here as
orphaned. It is not: `screens/profile-test-admin.blade.php` includes it three times, and
both profile-test blades survived the purge — which is why re-scoping the module back in
took one line.

---

## 2026-07-31 — Production go-live

**D-020 · Production runs on devops-owned infrastructure, released through GitLab.**
`https://amanahku.unijaya.com` is live, provisioned by the devops team, seeded with one
super-admin account. Devops takes GitHub `main`, uploads it to
`gitlab.com/developer-unijaya/claudecode/amanahku`, and releases from there. Development
stays on GitHub; the developer side of the boundary ends at a merge into `main`. There is no
developer shell, database or log access on prod — only an app-level super-admin login.

**The consequence that changes how we write code:** `migrate:fresh` is no longer an escape
hatch. Earlier entries in this log (D-013 among them) justify schema choices with "no
production data exists". That sentence is now false. Migrations are forward-only against real
staff records, so a destructive or non-reversible migration is a data-loss event, and
`APP_KEY` is load-bearing for every encrypted NRIC.

**Open, not decided:** the two repositories share no commit ancestor, so a prod release
cannot be traced to a sha. Options are laid out in [ROADMAP.md](ROADMAP.md); the choice needs
devops, because two of the three touch their repository.
