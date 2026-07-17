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
