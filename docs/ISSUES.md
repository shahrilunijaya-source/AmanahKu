# Issues & Blockers

Open items, blockers, and deferred enhancements. Blockers do not stop other work.

---

## Open / Deferred (non-blocking)

| id | severity | title | notes |
|----|----------|-------|-------|
| I-001 | low | Live AI assistant is canned | AI panel + workforce recommendations are seeded text. Needs an LLM adapter (feature-flagged) before it is "live". Not in approved scope this milestone. |
| I-002 | low | Attendance geolocation/photo is UI-only | Clock-in captures a seeded location string; no real device geolocation/photo capture. UI present, capture deferred. |
| I-003 | medium | Reviewer rating-entry workflow not built | Managers/HR cannot yet create/score/finalise a review from the UI (reviews are seeded). Employee-side acknowledge + self-assessment ARE built. Future phase: reviewer rating UI + submit→complete state machine. |
| I-004 | low | Header global search is non-functional | Search box in the header is decorative. Wire to directory query later. |
| I-005 | info | Production hardening checklist outstanding | Code-level hardening now DONE (baseline headers + HSTS-over-HTTPS, forced password rotation, 2FA password-confirm). Remaining is deploy-env only: `APP_DEBUG=false`, non-root DB user, fresh `APP_KEY`, full CSP, HTTPS. Documented in README. Not a code blocker. |
| I-006 | medium | Passkeys frontend not wired | Fortify 1.37 passkey backend is available and the feature can be enabled, but the WebAuthn browser ceremony (navigator.credentials) + `APP_URL` origin matching the serving host (localhost vs 127.0.0.1, secure-context/RP-ID) are not implemented. The Security page shows a "Coming soon" panel. Wire before advertising passkey sign-in. |
| I-009 | low | Real email delivery not configured | `MAIL_MAILER=log` — password-reset links are written to `storage/logs/laravel.log` in dev. `.env.example` now carries a commented SMTP template; operator must set `MAIL_MAILER=smtp`/`ses` + credentials before deploy so reset/invite emails actually send. |
| I-010 | info | AI data egress when `claude` driver enabled | With `AMANAHKU_AI_DRIVER=claude`, tenant workforce facts (employee names, counts, approval totals) are sent to Anthropic's API per question. This is tenant-scoped (no cross-tenant data) but is an external data-egress decision — confirm with the customer / DPA before enabling in production. Default `canned` sends nothing externally. |

## External Blockers

None. No feature is blocked on credentials, external access, or third-party approval.
All remaining work is implementable locally.

## Resolved

- **I-001 (partial) — Live AI**: provider abstraction + live `ClaudeAiProvider` behind `AMANAHKU_AI_DRIVER=claude` + `ANTHROPIC_API_KEY`; canned fallback. (Egress note → I-010.)
- **I-002 — Attendance geolocation**: real lat/lng capture added (migration `2026_06_24_000001` + AttendanceController/record/screen).
- **I-003 — Reviewer rating-entry**: `ReviewController@rate` (draft + finalise) + `reviewer_*` columns, wired into the team-review form.
- **I-004 — Global header search**: `SearchController` + live Alpine dropdown.
- **I-007 — 2FA password confirmation**: `confirmPassword=true` in `config/fortify.php`; `Fortify::confirmPasswordView` registered with a bespoke `auth/confirm-password.blade.php`. All Fortify 2FA management routes now require a recent password confirmation; the custom password-validated disable route is unchanged. (`HardeningTest`, `AuthFlowsTest`.)
- **I-008 — Forced password rotation**: `password_change_required` flag (migration `2026_06_24_000015`) set on invite; `ForcePasswordChange` middleware funnels flagged users to `auth/force-password-change` until they rotate; flag cleared on password change and on reset. (`HardeningTest`.)
- **Security headers / HSTS (part of I-005)**: `SecurityHeaders` middleware adds `Strict-Transport-Security` over HTTPS alongside the existing baseline headers. (`HardeningTest`.)

## New / deferred (this pass)

| id | severity | title | notes |
|----|----------|-------|-------|
| I-011 | low | Attendance clock-in `location` string hardcoded | AttendanceController stores a fixed office label for all tenants; derive from tenant/geocode before relying on it in reports. |
| I-012 | info | Assistant cost/abuse | Throttled (20/min); for production add per-tenant Anthropic spend caps + monitoring when the live driver is enabled. |

## Phase E — Payroll (deferred, non-blocking)

| id | severity | title | notes |
|----|----------|-------|-------|
| I-013 | medium | Statutory SOCSO/EIS use capped-percentage approximation | PERKESO publishes exact stepped bracket tables; this app computes SOCSO/EIS as a flat percentage on the capped wage. Close enough for estimates but **verify against official KWSP/PERKESO tables before a real payroll run** (in-app banner already warns). To make exact, replace the percentage config with the full bracket table per type. |
| I-014 | low | Payroll models use `$guarded = []` (app-wide convention) | Consistent with all ~24 models in the app. All payroll writes use whitelisted computed attributes (`toPayslipAttributes()`) or explicitly validated fields — never `request()->all()` — so no live mass-assignment vector. Before a public deploy, consider tightening the four financial models (`SalaryStructure`, `StatutoryRate`, `PayrollRun`, `Payslip`) to explicit `$fillable` allowlists that exclude `tenant_id`, `status`, and computed amounts. |
| I-015 | low | Payroll finalize allows draft -> finalized without approval | Intentional single-operator shortcut (the UI offers both Approve and Finalize on a draft). If a four-eyes control is required for payroll, change `finalizeRun` to accept only `approved` status. |
| I-016 | info | PCB / income tax is manual entry, not auto-calculated | By design this milestone (avoids encoding the LHDN progressive MTD table + reliefs). HR enters the monthly PCB per payslip. A future enhancement could add an auto-MTD calculator behind the editable rate config. |

## Phase E+ — Payroll exports (deferred, non-blocking)

| id | severity | title | notes |
|----|----------|-------|-------|
| I-017 | low | Bank file is a generic CSV | Outputs a generic No./Name/Account/Amount CSV (common denominator). Bank-specific bulk-payment formats (Maybank2u, CIMB BizChannel, RHB, DuitNow batch) are a future per-bank exporter. |
| I-018 | resolved | NRIC is encrypted at rest | Verified: 'nric' => 'encrypted' cast on both Employee and SalaryStructure — stored as ciphertext, decrypted only on read. HR-only statutory export already enforced. Closed 2026-07 (audit AK-DOC-01). |
