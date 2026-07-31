# Progress Log

Append-only. Most recent first.

---

## 2026-06-23 — Phase D: Production pass — DONE

Commits `f390c14` (responsive/a11y/headers/search/geo/reviewer-rate), `5e4b267` (notifications + CSV), `722347b` (final-review fixes).

- **Responsive**: off-canvas sidebar drawer + hamburger + backdrop under 900px; header/subheader/main reflow. Playwright-verified at 390px.
- **A11y**: global `:focus-visible` ring; aria-labels on icon controls; `.uj-sr-only`.
- **Security headers**: `SecurityHeaders` middleware (nosniff, X-Frame-Options, Referrer-Policy, Permissions-Policy) on the web group. `shouldRenderJsonWhen` now honours `expectsJson()`.
- **In-app notifications**: `app_notifications` + `AppNotification` (send/sendMany); fired on leave/claim approve+reject + recognition; header bell dropdown (view composer, unread badge, mark-all-read).
- **Report CSV export**: streamed tenant-scoped roster, manager/HR-gated.
- **Global search** (parallel build): `SearchController` JSON + live header dropdown (debounce, keyboard nav).
- **Attendance geolocation** (parallel build, closes I-002): geo columns + capture.
- **Reviewer rating-entry** (parallel build): `reviewer_*` columns + `ReviewController@rate` (draft/finalise) wired into the team-review form.
- Final security review: 2 HIGH (defense-in-depth tenant asserts on notifications) fixed; assistant/search throttled; CSV role-gated; search LIKE-escaped. CSP nonce refactor + attendance location string deferred (ISSUES).
- `php artisan test` = **74 passed, 185 assertions** (+ CoreWritePaths reviewer/export, SearchTest, NotificationsTest).

**All four requested tracks (core write-paths · auth & onboarding · real AI · production pass) are complete.**

---

## 2026-06-23 — Phase C: Real AI layer — DONE

The "AI Workforce Assistant" is no longer canned text — it's a live, interactive assistant grounded in tenant data.

- `App\Services\Ai\AiProvider` contract; `CannedAiProvider` (default, summarises live facts, no external call) and `ClaudeAiProvider` (Anthropic Messages API, behind `AMANAHKU_AI_DRIVER=claude` + `ANTHROPIC_API_KEY`, graceful fallback to canned on any error).
- Bound in `AppServiceProvider` by config (`config/services.php` → `ai`). Env documented in `.env.example`.
- `AssistantController@reply` (POST `/app/assistant`): validates the message, assembles **tenant-scoped** workforce facts (headcount, overloaded names, pending approvals, your tasks/weekly status), returns `{reply, source}`.
- AI panel rewired (`partials/ai-panel.blade.php`): Alpine chat with live fetch, user/AI bubbles, prompt chips, "Thinking…", source pill; CSRF meta added to the layout.
- Fixed `bootstrap/app.php` `shouldRenderJsonWhen` to also honour `expectsJson()` so in-app JSON endpoints return proper 4xx payloads (was api/* only).
- `php artisan test` = **65 passed, 163 assertions** (`AssistantTest`). Playwright-verified: asked "Who's overloaded?" → live reply naming the two red-workload employees + pending approvals, Source "Rule-based · live data".

---

## 2026-06-23 — Phase B: Auth & tenant onboarding — DONE

- **Add member** (`MemberController`): privileged invite creates a login + tenant role + employee with a one-time password. New accounts only (existing emails refused — closes a cross-tenant attach vector); invite role capped at manager (elevate via Roles, audited). UI on Roles & Permissions.
- **Password reset**: enabled Fortify reset; bespoke `auth/forgot-password` + `auth/reset-password` views; login "Forgot password?" wired; `MAIL_MAILER=log` (link → laravel.log).
- **Two-factor**: `TwoFactorAuthenticatable` on User; enabled the Fortify feature; in-app **Security** screen (enable → QR + setup key + recovery codes via authenticated fetch, confirm, disable); `auth/two-factor-challenge` view; header user block links to Security. Disable-when-active is password-protected (`SecurityController`, `current_password`).
- **Passkeys**: backend available; frontend deferred (ISSUES I-006) — "Coming soon" panel.
- Independent security review: 2 HIGH (cross-tenant attach, silent 2FA disable) fixed; self-escalation capped; temp-password exposure mitigated (`SESSION_ENCRYPT=true`) + forced-rotation deferred (I-008). confirmPassword posture documented (I-007).
- `php artisan test` = **61 passed, 153 assertions** (`AuthFlowsTest`, 12). Playwright-verified: 2FA enable→QR, member invite (one-time password + member list).

---

## 2026-06-23 — Phase A: Operable write-paths (CRUD across the app) — DONE

Closed the "reads everywhere, writes almost nowhere" gap. Commits `1c248b8` (A1), `8162f87` (A2), `d01f38c` (review fixes).

- **A1**: board work-item add + status move (own); employee add (directory) + edit (profile) — HR/management; reviewer rating-entry (manager/HR scores a review → employee acknowledges). `CoreWritePathsTest` (10).
- **A2**: onboarding task toggle; KPI own-progress update; training assign + complete; asset add/assign/return; announcement post. `OperationsWritePathsTest` (10).
- **Review fixes**: forms re-open on validation error (Alpine seeded from `$errors`; per-row discriminator for KPI/reviews); KPI red tier; deterministic onboarding profile; move flash label; tenant-id dedupe.
- Independent security + code review: no open CRITICAL/HIGH; tenant isolation + role/ownership gates verified on all new endpoints.
- `php artisan test` = **50 passed, 124 assertions**. Playwright-verified: board add, directory add form, onboarding toggle.

---

## 2026-06-23 — Phase 7b: Achievements + Reviews (final stubs) — DONE

Reconstructed status at start: 20 screens live, git clean at `6186dd2`, 18 tests passing.
Only stubs remaining: **Performance → Achievements** and **Performance → Reviews** (both nav id `soon` → generic empty-state).

**Delivered:**
- Migration `000010` — `performance_reviews` table + `achievements` enrichment (category/icon/points/date).
- `PerformanceReview` model; `Employee::performanceReviews()` + `achievements()` relations.
- `AchievementController@store` (give recognition — manager/management/hr only, tenant-scoped recipient validation, audit-logged).
- `ReviewController@acknowledge` + `@selfAssessment` (own-review only: tenant_id + employee_id asserted; acknowledge requires `completed`, self-assessment requires open cycle).
- Screens `achievements.blade.php` (stat row, category-coloured feed, points leaderboard, recognition form) and `reviews.blade.php` (open-cycle self-assessment banner, scorecard w/ competency bars + strengths/focus/goals, acknowledge, history, team-reviews for privileged).
- Nav ids `soon` → `achievements`/`reviews`; page meta; `screenData` arms + `achievementsData`/`reviewsData`.
- Seed: enriched recognition feed (6 entries) + 5 performance reviews (Aisyah history+open cycle, team reviews).
- 12 feature tests (`PerformanceTest`): recognition (privileged/employee/validation/cross-tenant), review acknowledge (own/not-own/wrong-status), self-assessment (save/required/blocked-when-completed), screen renders.

**Verification:**
- `php artisan migrate:fresh --seed` clean · `npm run build` clean · `php artisan test` → **30 passed, 70 assertions**.
- Playwright: gave a recognition (HR) → feed + leaderboard + audit updated; saved self-assessment + acknowledged a review (employee path) → audit updated; switched to Petron (employee role) → empty states, **no recognition form, no team-reviews card, Admin nav hidden, 0 cross-tenant data**.

**Independent self-review (security-reviewer + code-reviewer subagents) — findings resolved:**
- Role-gated `teamReviews` + `recipients` queries (don't load data a role can't see — was template-hidden only).
- Tenant-scoped the recognition `exists` validation rule (`Rule::exists()->where('tenant_id')`) + explicit `tenant_id` on create; kept the manual tenant assert as defense-in-depth.
- Explicit tenant constraint on the leaderboard `withSum`/`withCount` aggregate sub-queries.
- Fixed stale `date_label` ("just now" frozen forever): added `date` cast, derive relative time via `diffForHumans()` in feed + dashboard.
- Dismissed one false-positive ("missing `use App\Models\Achievement`" — import present; proven by passing test + live run).

Whole sidebar is now functional with zero generic empty-state stubs.

---

## Prior phases (reconstructed from git history + memory)

- **`6186dd2` Phase 6 — Handbook + Admin**: handbook acknowledge + HR ack-rate; admin settings/roles/audit (role-gated); `AuditLog::record` wired across leave/claim/role/settings/handbook; migration `000009`. Tests 18.
- **`42c26a6` Phase 5 — Claims/Assets/Training/OrgChart/Reports + directory search**: migration `000008`; directory filters; profile deep-link. Tests 15.
- **`830d429` Phase 1–4 — Foundation**: Fortify auth + custom login; single-DB multi-tenancy; ~20 tables (migrations `000001`–`000007`); breadth-first 15 screens; leave/attendance/weekly write-paths + leave approval; security hardening pass. Tests 13.

### Verification commands used historically
- `php artisan migrate:fresh --seed` — clean
- `npm run build` — clean
- `php artisan test` — 18 passed (43 assertions)
- Playwright login → tenant → every screen

---

## 2026-06-24 — Phase E: Payroll & Compensation — DONE

(Entry appended at file end due to the markdown read-guard; chronologically the latest.)

New pillar. User picked payroll from the feature-depth options; chose standard MY statutory rates (editable, verify-before-prod), manual PCB entry, and the "+variable inputs" scope.

- **Schema** (`2026_06_24_000004`): `salary_structures`, `statutory_rates`, `payroll_runs`, `payslips` + `claims.paid_at`.
- **Calculator**: `App\Services\Payroll\PayrollCalculator` (pure) → immutable `PayslipComputation`. EPF (employer tier by threshold), SOCSO/EIS (capped percentage), OT (Employment Act ORP = basic/26/8  x1.5), unpaid-leave proration (days x basic/26), manual PCB, gross floored at 0.
- **Controller**: `PayrollController` — salary-structure CRUD, statutory rate config, run lifecycle draft->approve->finalize, per-payslip variable-input edit/recompute. management/hr-gated; route-bound models tenant-asserted; claims double-pull guarded (whereNotIn used claim_ids + lockForUpdate); finalize locks payslips, notifies employees, marks reimbursed claims paid.
- **UI**: `screens/payroll.blade.php` — privileged tabs (runs / salary structures / statutory rates) + employee "my payslips" + full payslip detail. Nav item `payroll`. Red/white/black + Poppins, mono figures.
- **Seed**: rate tables + salary structures for 10 Unijaya staff + one finalized May 2026 run computed by the real calculator.
- **Verification**: `php artisan test` = **100 passed, 276 assertions** (+PayrollCalculatorTest 10 unit, +PayrollTest 16 feature). `migrate:fresh --seed` clean. `npm run build` clean. Playwright: HR payroll dashboard (seeded May run), generated June draft (on_leave excluded, Aisyah's approved claim pulled as +RM184.50 reimbursement → net RM10,422.00 exact), payslip detail (gross/deductions/employer-cost all matching hand-calc).
- **Independent review**: security-reviewer + php-reviewer. Confirmed-handled: tenant asserts complete, global-scope protects all queries, ACL solid, no IDOR. Fixed: negative-gross clamp, claim double-pull guard+lock, explicit tenant on period check, statutory-rates leak in employee data-bag, DRY rate-merge -> StatutoryRate::merged(), employed-status allowlist, route throttle 30/min. Accepted (app-wide convention): $guarded=[] (writes are whitelisted, never request()->all()); models without strict_types; draft->finalize shortcut (intentional). Deferred: I-013/014/015.

---

## 2026-06-24 — Phase E+: Payroll production tier (statutory reports + bank file) — DONE

Extends payroll to a runnable production tier (user-approved scope expansion beyond the original "+variable inputs").

- Migration `000011`: `bank_name`, `bank_account_no`, `epf_no`, `socso_no`, `nric` on `salary_structures` (nullable). Surfaced in the salary-structure form + `storeSalary` validation.
- `PayrollExportController`: `bankFile` (per-employee net-pay CSV + TOTAL) and `statutoryReport` (EPF/SOCSO/EIS employee+employer + PCB per employee + TOTAL). GET, management/hr-gated, tenant-asserted, finalized-runs-only.
- Finalized-run UI: "Bank file" + "Statutory report" download buttons + employer-contribution summary line.
- Seed: bank/statutory identifiers on all 10 salary structures.
- Verification: `php artisan test` = **164 passed, 426 assertions** (PayrollExportTest +6; payroll suite alone 32/108). migrate:fresh --seed clean; npm build clean. Live (Playwright fetch): both CSVs serve 200 text/csv — bank TOTAL 55,331.05 (= net payout), statutory employer EPF 7,973.12 / SOCSO 958.61 / EIS 109.55 / PCB 2,815.00 (matches on-screen summary).
- Deferred: bank-specific bulk formats (I-017), NRIC PII handling (I-018).
