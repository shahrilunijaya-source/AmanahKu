# Amanahku — Master Plan

Multi-tenant HR + weekly work-tracking + AI workforce-intelligence platform for Unijaya
(with Shell Seremban 2 and Petron Tg Lumpur as additional tenants).

**Stack:** Laravel 13.8 · Blade · Tailwind v4 (CSS-first `@theme`) · Alpine.js · Vite · MySQL 8 (Laragon).
**Auth:** Laravel Fortify (backend) + custom login UI.
**Design:** red `#d6232b` / white / near-black `#1f1e1a`, canvas `#f6f6f3`, Poppins + JetBrains Mono.

---

## Modules

| # | Module | Screens | State |
|---|--------|---------|-------|
| 1 | Auth & Tenancy | login, tenant-select | Done |
| 2 | Dashboard | dash (4 persona variants) | Done |
| 3 | People | directory, profile (360), orgchart | Done |
| 4 | My Work | board (kanban), weekly update | Done |
| 5 | Attendance | clock in/out + history | Done |
| 6 | Leave | balances, apply, approve/reject, calendar, holidays | Done |
| 7 | Performance | kpi, **achievements**, **reviews** | kpi done; achievements + reviews = THIS PHASE |
| 8 | Onboarding | checklist (general + position tracks) | Done |
| 9 | Training | courses, mandatory/overdue flagging | Done |
| 10 | Handbook | policy sections + acknowledgement | Done |
| 11 | Claims | submit + approve/reject | Done |
| 12 | Assets | register | Done |
| 13 | Reports | capacity / status / workload / leave | Done |
| 14 | AI Workforce Intel | capacity bars + recommendations (canned) | Done |
| 15 | Administration | settings, roles, audit log | Done |

---

## Dependencies

```
Auth+Tenancy ─► everything (tenant scope + role come from here)
Employee ─► profile, board, weekly, attendance, leave, kpi, claims, assets, training, achievements, reviews
AuditLog ◄─ leave/claim approve+reject, role change, settings, handbook ack, review ack, recognition
BelongsToTenant trait ─► every tenant-owned model (global scope + auto-fill tenant_id)
```

## Implementation Order (this phase)

1. Migration `000010` — `performance_reviews` table + `achievements` enrichment (category/icon/points/date).
2. Model `PerformanceReview`; `Employee` relations (`performanceReviews`, `achievements`).
3. Controllers — `ReviewController` (acknowledge, self-assessment), `AchievementController` (give recognition).
4. Wiring — routes, nav ids (`soon` → `achievements`/`reviews`), page meta, `screenData` arms + data methods.
5. Screens — `achievements.blade.php`, `reviews.blade.php`.
6. Seed — enrich achievements, add performance reviews.
7. Tests — write-paths + screen GET + tenant/ACL gates.
8. Verify — migrate/build/test/Playwright; self-review; commit.

---

## Acceptance Criteria (this phase)

- [ ] No nav item routes to the generic empty-state; Achievements + Reviews render real data.
- [ ] **Achievements**: recognition feed, points leaderboard, stat row; "Give recognition" form visible only to manager/management/hr; creating a recognition is validated, tenant-scoped, audit-logged.
- [ ] **Reviews**: open-cycle self-assessment form (employee), latest scorecard (overall rating + competency bars + strengths/improvements/goals), acknowledge button on a completed own-review, history list, team-reviews card for privileged roles.
- [ ] **Tenant isolation**: every new query scoped to active tenant; a user cannot acknowledge/edit a review or recognise an employee in another tenant (defense-in-depth `tenant_id` assert on bound models).
- [ ] **ACL**: employee cannot give recognition (403); employee cannot acknowledge another employee's review (403); self-assessment limited to own open review.
- [ ] **Validation**: recognition (employee_id exists + in tenant, title, category enum, points range); self-assessment (required, max length).
- [ ] **States**: empty (no reviews / no achievements), populated, validation-error, and success-flash all handled.
- [ ] **Design**: matches red/white/black + Poppins token system; no template-default look; responsive (wrap at small widths).
- [ ] `php artisan test` green; `npm run build` clean; `migrate:fresh --seed` clean.

## Testing Requirements

- Feature tests (PHPUnit + RefreshDatabase): recognition store (privileged ok / employee 403 / validation), review acknowledge (own completed ok / not-own 403 / wrong-status 422), self-assessment save, screen GET renders for employee & HR.
- Manual journey (Playwright): HR gives recognition → appears in feed + leaderboard + audit; employee submits self-assessment + acknowledges review.
- Regression: full existing suite must stay green.

## Scope Guards

- AI features remain canned/seeded this phase (no live LLM).
- Manager "finalise review" workflow (rating entry by reviewer) is out of scope this phase — reviews are seeded as completed; employees act on them. Documented in ISSUES as a future enhancement.
- Attendance geolocation/photo capture remains UI-only.

## Phase E — Payroll & Compensation (active, 2026-06-24)

New pillar. Tables: `salary_structures`, `statutory_rates`, `payroll_runs`, `payslips` (migration `2026_06_24_000004`) + `paid_at` on claims. Service `App\Services\Payroll\PayrollCalculator` (pure calc, unit-tested) returning a readonly `PayslipComputation`. `PayrollController` (run lifecycle draft->approve->finalize + payslip edit/recompute + salary-structure CRUD + statutory rate config). Screen `screens/payroll.blade.php` (privileged: run list/create/detail + per-payslip variable inputs + finalize; employee: own payslips + payslip detail). Nav item `payroll`. Seed: salary structures for Unijaya staff + EPF/SOCSO/EIS rate tables + one finalized prior-month run. ACL management/hr for admin, employees see own payslips only. See DECISIONS 2026-06-24 for statutory/PCB/scope choices.
