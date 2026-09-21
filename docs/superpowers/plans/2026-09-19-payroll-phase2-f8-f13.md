# Payroll Phase 2 (F8 to F13) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Monthly payroll operations: TP1 claims and the yearly exemption cap, CP38 notices, bonus and final runs, lifecycle notices (CP22 / CP22A / SOCSO Form 2), the statutory deadline calendar with a submission log, and the publish step with a complete payslip.

**Architecture:** Same shape as Phase 1. New tables are small tenant-owned records the existing calculators read (`PcbYearToDate`, `PayrollController::createRun/finalizeRun/destroyRun`). Pure logic goes in `App\Services\Payroll\*` with unit tests; UI goes into the existing payroll partials and tabs. Phase 1 plan (`docs/superpowers/plans/2026-09-18-payroll-phase1-f1-f7.md`) shows the code patterns, test setup and commit routine to copy.

**Tech Stack:** Laravel 13, PHP 8.5, Blade + Alpine, PHPUnit (sqlite), Larastan level 5.

**Spec:** `docs/superpowers/specs/2026-09-02-payroll-features-design.html` sections F8 to F13, section 6 (data model), section 7 (rules not to tidy). The spec text for each feature is the requirement; this plan fixes names, order and decisions.

## Global Constraints

- Commit on `dev`, no worktree, no push. One commit per task.
- Per task: failing test first, implement, pass. Before commit: `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse --memory-limit=512M` clean with no ignores or baseline entries.
- Tests: `php artisan test --compact <file>`. NEVER `migrate:fresh`, never inline env vars before artisan, never `vendor/bin/phpunit --no-configuration` (they wipe the dev MySQL). Dev DB migrations only via `lerd artisan migrate`.
- After Blade changes: `lerd artisan view:clear && lerd artisan view:cache && bun run build`; commit `public/build` if changed. bun only.
- Every new table has `tenant_id` and uses `BelongsToTenant`; every controller action with a bound model checks `tenant_id` against `app(CurrentTenant::class)->id()`. Roles: management/hr via the controller's existing `authorizeAdmin`.
- Every state change writes `AuditLog::record(...)`. Never delete audit rows. No real email leaves the app in tests (`Mail::fake()` / `Notification::fake()`).
- Finalized runs stay immutable except `paid_at`, `published_at` and the CP22A hold release, each through its own action with `forceFill`, never mass assignment.
- Statutory caps and lists live in code with the tax year they apply to. Never tenant-editable.
- Every UI string EN + MS using the pattern already in the partial being edited.
- Never edit `tests/Acceptance/*`, `docs/build/contracts/*`, `CLAUDE.md`. `tests/Acceptance/CR32Test` pins the management LEFT dashboard column: new dashboard content goes in the right column or inside the existing `payroll` widget.
- Migration filenames continue from `2026_10_01_100700`: use `2026_10_02_1000NN_*`.

---

### Task 1: F7 leftovers from Phase 1

**Files:** `app/Services/Payroll/PayrollReadiness.php`, `resources/views/partials/payroll/process/readiness.blade.php`, `tests/Unit/PayrollCalculatorTest.php`, `tests/Feature/PayrollReadinessTest.php`

- [ ] Unit test from the spec: basic 3,000 + fixed allowance 300 (both `hrdf_liable`) + 500 overtime (not liable) + 2 unpaid days at `hrdf_rate` 0.01 gives levy **30.69** (base 3,300 − 2 × 3,000/26 = 3,069.23). Fix the calculator only if it fails.
- [ ] `PayrollReadiness::companyWarnings(Tenant): list<string>`: when `payroll.hrdf` is `off` and the tenant has 10 or more currently employed staff whose structure nationality is `citizen`, return one warning "HRD Corp levy is off but the company has N Malaysian employees; registration is mandatory at 10." Non-blocking. Show it in the readiness panel in amber. Test both sides of the threshold (9 and 10).
- [ ] Payslip PDF must not print an HRD Corp line for employees (it is employer cost); assert the PDF view data has none. Commit: `feat(payroll): HRD Corp registration warning at ten Malaysian employees, levy spec case pinned`.

---

### Task 2: F9 CP38 notices

**Files:** migration `create_payroll_cp38_notices_table`, `app/Models/PayrollCp38Notice.php`, `app/Services/Payroll/Cp38Notices.php`, `PayrollController` (`createRun`, `updatePayslip`, `finalizeRun`, `destroyRun`, `storeSalary`), new `app/Http/Controllers/PayrollCp38Controller.php`, routes, `resources/views/partials/profile/bank-tab.blade.php` (replace the CP38 RM/month input with a notices list + add form), test `tests/Feature/PayrollCp38NoticeTest.php`.

**Interfaces:**
- Table `payroll_cp38_notices`: `tenant_id`, `employee_id`, `reference` nullable string 60, `notice_date` date nullable, `total_amount` decimal(12,2) nullable (null = open ended), `monthly_instalment` decimal(12,2), `first_period` char(7), `last_period` char(7) nullable, `remaining_balance` decimal(12,2) nullable, `status` string (`active`, `completed`, `cancelled`), `created_by_id`, timestamps.
- `Cp38Notices::instalmentFor(Employee $e, string $period): float` = sum over active notices covering the period of `min(monthly_instalment, remaining_balance ?? monthly_instalment)`.
- `Cp38Notices::applyFinalized(Payslip $p): void` decrements balances oldest notice first by the payslip's `cp38`, marks `completed` at zero. `Cp38Notices::reverseFinalized(Payslip $p): void` restores it. Record what was applied per notice in a json column `payslips.cp38_applied` (`{notice_id: amount}`) so reversal is exact.
- Routes: `payroll.cp38.store` POST `/app/payroll/cp38-notices`, `payroll.cp38.cancel` POST `/app/payroll/cp38-notices/{notice}/cancel`.

**Decisions:**
- Migration moves every `salary_structures.cp38_monthly > 0` into a notice with no reference, no total, `first_period` = current month, open end. Keep the `cp38_monthly` column but stop reading and writing it (drop from `storeSalary` validation and the form); add a column comment "dead since 2026-10, see payroll_cp38_notices". Do not drop the column in this phase.
- `createRun` and `updatePayslip` use `instalmentFor()` in place of `$structure->cp38_monthly`.
- Balance moves only at finalize; deleting a finalized run calls `reverseFinalized` in `destroyRun`'s reversal block. A draft never touches balances, so deleting a draft has nothing to restore (the spec test "deleting a draft run restores the balance" passes trivially; assert the balance is unchanged).

- [ ] Feature test: RM1,000 notice at RM300 from 2026-06 produces cp38 of 300, 300, 300, 100, 0 over five finalized monthly runs and ends `completed`; deleting the fourth finalized run (management tier, typed period) restores balance to 100 and status `active`; migrated open-ended notice keeps deducting; other tenant's notice id is refused; manager role 403.
- [ ] Commit: `feat(payroll): CP38 notices with instalments and a running balance replace the flat monthly figure`.

---

### Task 3: F8 yearly exemption cap on Payroll Items

**Files:** `app/Services/Payroll/PcbYearToDate.php`, `PayrollController::buildPcbInputs`, `app/Services/Payroll/ExemptionCap.php`, tests `tests/Unit/ExemptionCapTest.php`, `tests/Feature/PayrollExemptCapTest.php`.

**Interfaces:**
- `ExemptionCap::taxableThisMonth(float $amountThisMonth, float $usedEarlierThisYear, ?float $yearlyCap): float` (pure). Null cap = fully taxable when `pcb_taxable`, as today.
- `PcbYearToDate::exemptUsed(Employee $e, string $period, PayrollItem $item): float` = sum of that item's `payslip_lines.amount` on finalized runs earlier in the same year, plus `payroll_opening_figures.exempt_allowances` for that year (counts toward the travel cap, the only capped seed item).
- `buildPcbInputs` reduces the month's taxable gross by the exempt part of each capped line. Read how it derives taxable gross today and subtract there; do not touch EPF/SOCSO bases.
- Store the month's exempt total on the payslip: `payslips.pcb_exempt_amount` decimal(12,2) default 0, so EA Part F can sum it. `EaFormData` Part F reads the sum for the year (check its current source first and keep opening figures included).

- [ ] Unit: cap 6,000, RM500 a month → taxable 0 for months 1 to 12; with 6,000 already used, 500 taxable; month that crosses the cap is split (used 5,800, amount 500 → 300 taxable).
- [ ] Feature: employee with a RM500 travel-allowance Fixed Transaction has the same PCB as one without it while under the cap, and `pcb_exempt_amount` = 500; with opening figures `exempt_allowances` 6,000 the allowance is fully taxable and PCB is higher.
- [ ] Commit: `feat(payroll): yearly tax-exempt cap on Payroll Items applied in PCB, exempt amount kept for Form EA`.

---

### Task 4: F8 TP1 monthly claims

**Files:** migration `create_payroll_tp1_claims_table`, `app/Models/PayrollTp1Claim.php`, `app/Support/Tp1Reliefs.php`, `PcbYearToDate`, `PayrollController::buildPcbInputs`, `app/Http/Controllers/PayrollTp1Controller.php`, routes, new tab partial `resources/views/partials/payroll/transaction/tp1.blade.php` registered beside the Individual Transactions tab (find how `transaction/individual.blade.php` is registered in the screen and `Amanahku.php`), `BuildsWorkData::payrollData`, tests `tests/Unit/Tp1ReliefsTest.php`, `tests/Feature/PayrollTp1ClaimTest.php`.

**Interfaces:**
- Table: `tenant_id`, `employee_id`, `year` smallint, `month` tinyint, `relief_code` string 40 nullable, `amount` decimal(12,2) default 0, `zakat_amount` decimal(12,2) default 0, `note` string 255 nullable, `created_by_id`, timestamps. One row per relief line; a zakat-only row has null `relief_code`.
- `Tp1Reliefs::YEAR = 2026` and `Tp1Reliefs::LIST`: `code => ['label', 'label_ms', 'cap' => float]`. Transcribe codes and caps from the TP1 / optional-deductions list in `docs/statutory/spesifikasi-kaedah-pengiraan-berkomputer-pcb-2026.pdf` (`pdftotext -layout`, search "TP1", "potongan pilihan", "optional deduction"). Each entry carries a comment with the PDF page. If the PDF's list cannot be read reliably, include only the entries you can cite and say so in the report; do not invent caps.
- `Tp1Reliefs::allowed(string $code, float $claim, float $claimedEarlierThisYear): float` trims to the remaining cap.
- `PcbYearToDate::forPeriod` adds: claims for months before the period (capped) into `optionalDeductions` (∑LP), TP1 zakat for earlier months into `zakatZ`; the current month's capped claim and zakat go into the current-month fields of `PcbInputs` (read `PcbInputs` for the exact property names for current-month optional deductions and zakat; add them if the calculator has none, following spec section D).
- Routes: `payroll.tp1.store` POST `/app/payroll/tp1-claims`, `payroll.tp1.delete` POST `/app/payroll/tp1-claims/{claim}/delete`. Both refuse (422) when the period's monthly run is finalized: "enter it in the next month".

- [ ] Unit: claim over the cap is trimmed to the cap remainder; second claim after the cap is used gives 0.
- [ ] Feature: a TP1 medical claim lowers that month's PCB versus no claim; TP1 zakat lowers net MTD and the excess carries to the next month per the Z rule; store for a finalized month is 422; cross-tenant employee refused; draft payslip recompute picks up a claim added after the run was created.
- [ ] C.P.8D / Form EA relief fields: read `Cp8dData`/`EaFormData`; where they expose relief or zakat totals, include TP1 amounts. Add one assertion per form.
- [ ] Commit: `feat(payroll): TP1 monthly relief and zakat claims feed PCB with yearly caps held in code`.

---

### Task 5: F11 lifecycle notices

**Files:** migration `create_payroll_notices_table`, `app/Models/PayrollNotice.php`, `app/Services/Payroll/LifecycleNotices.php`, an Employee model observer or hooks at the existing create / last-working-day write sites (`EmploymentRecordService::resign`, `OffboardingService`, employee creation; grep `last_working_day` and `joined_at` writers), `app/Http/Controllers/PayrollNoticeController.php`, routes, partial `resources/views/partials/payroll/form/notices.blade.php` as a tab on the `payroll-form` screen, PCB 2(II) view `resources/views/pdf/pcb2ii.blade.php` + controller action, `payroll` dashboard widget payload, test `tests/Feature/PayrollNoticeTest.php`.

**Interfaces:**
- Table: `tenant_id`, `employee_id`, `type` (`cp22`, `cp22a`, `cp21`, `socso_form2`, `kwsp_registration`), `due_on` date, `filed_on` date nullable, `reference` string 80 nullable, `filed_by_id` nullable, `note` string 255 nullable, `cleared_on` date nullable (LHDN clearance, CP22A only), timestamps. Unique (`employee_id`, `type`, `due_on`).
- `LifecycleNotices::onHired(Employee)`: CP22 due joined_at + 30 days, `socso_form2` and `kwsp_registration` due joined_at + 30 days. Idempotent (`firstOrCreate`).
- `LifecycleNotices::onLastWorkingDaySet(Employee)`: CP22A due `max(today, last_working_day − 30 days)`; if last working day is cleared or moved, the unfiled CP22A is updated, a filed one is left alone.
- `LifecycleNotices::prefill(PayrollNotice): array<string,string>` name, NRIC, TIN, address, joined or last day, monthly salary.
- `PayrollNotice::isOpen()`, `isOverdue()`; `LifecycleNotices::holdsFinalPay(Employee): bool` = has a CP22A with no `cleared_on` and fewer than 90 days since `filed_on` (or unfiled). Task 9 reads this.
- Routes: `payroll.notices.file` POST `/app/payroll/notices/{notice}/file` (filed_on, reference), `payroll.notices.clear` POST `.../clear` (cleared_on), `payroll.notices.cp21` POST `/app/payroll/employees/{employee}/cp21` (HR opens a CP21 by hand, due 30 days before last working day), `payroll.notices.pcb2ii` GET `/app/payroll/notices/{notice}/pcb2ii` (PDF via the same PDF stack `PayrollPdfController` uses; figures from `PayslipYearToDate` / the EA data source; audited because it carries NRIC).

- [ ] Feature: employee created with joined_at 2026-03-01 gets CP22 due 2026-03-31 and a Form 2 reminder; setting last_working_day 2026-06-30 on 2026-05-01 (`travelTo`) creates CP22A due 2026-05-31; setting it 10 days out makes it due today; mark filed stores date, reference, filer and audit row and removes it from the dashboard widget's open count; tenant check; PCB 2(II) returns 200 for CP22A and 404 for other types.
- [ ] Dashboard: extend the existing `payroll` widget payload with `openNotices` (count) and `overdueNotices`; render one line. No new card.
- [ ] Commit: `feat(payroll): CP22, CP22A, CP21, SOCSO and KWSP registration notices opened from hire and leaving dates, with prefilled data and PCB 2(II)`.

---

### Task 6: F12 submissions log, Deadlines tab, digest

**Files:** migration `create_payroll_submissions_table`, `app/Models/PayrollSubmission.php`, `app/Services/Payroll/StatutoryCalendar.php`, `PayrollController::finalizeRun` + `destroyRun`, `PayrollExportController` (stamp downloads), `app/Http/Controllers/PayrollSubmissionController.php`, routes, Deadlines tab partial `resources/views/partials/payroll/payment/deadlines.blade.php` on `payroll-payment`, `app/Console/Commands/PayrollDeadlineDigest.php`, a mailable + view, `bootstrap/app.php` schedule, test `tests/Feature/PayrollSubmissionTest.php`.

**Interfaces:**
- Table: `tenant_id`, `payroll_run_id` nullable, `year` smallint nullable, `agency` (`epf`, `socso_eis`, `pcb`, `hrdcorp`, `ea`, `form_e`), `due_on` date, `downloaded_at`, `submitted_at`, `submitted_by_id`, `receipt_reference` string 80, `amount_paid` decimal(12,2), timestamps. Unique (`tenant_id`, `payroll_run_id`, `year`, `agency`).
- `StatutoryCalendar::monthlyDueDate(string $period): CarbonImmutable` = 15th of next month, never shifted. `::annualDueDate(string $agency, int $year)`: EA = last day of February of year+1, Form E = 31 March of year+1.
- `StatutoryCalendar::openFor(PayrollRun $run): void` creates epf, socso_eis, pcb, and hrdcorp only when `payroll.hrdf` is on. Spec's test says "five submissions": the fifth is the bank payment? No: keep to agencies. With HRD Corp on that is four monthly rows; the annual `ea` and `form_e` rows are created once per year when the December run (or the first run of the following year) is finalized. Write the test for four (levy on) and three (levy off), and note this reading in the report.
- `PayrollSubmission::state(): string` one of `not_started`, `file_ready`, `submitted`, `overdue`.
- Download stamping: map statutory file keys `kwsp-form-a`→epf, `perkeso-8a`→socso_eis, `cp39`→pcb, `hrdcorp`→hrdcorp in `PayrollExportController::statutoryFile`; stamp `downloaded_at` once (first download).
- Routes: `payroll.submissions.submit` POST `/app/payroll/submissions/{submission}/submit` (receipt_reference required, amount_paid nullable).
- Command `payroll:deadline-digest`, scheduled `dailyAt('08:00')`: on the 10th and 14th it mails each tenant's hr/management users one digest listing unsubmitted rows due on the 15th; on 20 Feb and 20 Mar the annual rows. Reuse how `digest:weekly` finds recipients and sends. Nothing sent when the list is empty.
- `destroyRun` deletes the run's unsubmitted submission rows; a run with any submitted row cannot be deleted (422, "already filed with an agency").

- [ ] Feature: finalize August 2026 with levy on creates four rows due 2026-09-15; CP39 download stamps `downloaded_at` and state becomes `file_ready`; submit stores receipt, user, audit; `travelTo('2026-09-14 08:00')` digest lists the three not yet submitted and mails HR once; on the 13th nothing is sent; weekend 15th is not shifted; tenant checks.
- [ ] Commit: `feat(payroll): statutory deadlines tab, submission log with receipts, and HR reminder digest before the 15th`.

---

### Task 7: F13 publish step, complete payslip, acknowledgement setting

**Files:** migration `add_published_at_to_payroll_runs_table`, `PayrollRun`, `PayrollController` (`finalizeRun` gains `publish_now`, new `publishRun`, `acknowledgePayslip`), `BuildsWorkData::payrollData` (employee list = published only), `PayrollPdfController` (employee access needs published), a `PayslipPublished` mailable, `Features::SETTINGS` `payroll.payslip_acknowledgement`, `PayslipPdfData`, `resources/views/pdf/payslip.blade.php`, `payment/payout.blade.php`, `my/payslip.blade.php`, review list, test `tests/Feature/PayrollPublishTest.php`.

**Interfaces:**
- `payroll_runs.published_at` timestamp nullable; `PayrollRun::isPublished(): bool`. Migration backfills `published_at = finalized_at` for already finalized runs so nothing disappears for staff.
- Route `payroll.runs.publish` POST `/app/payroll/runs/{run}/publish`: finalized only, once only, stamps `published_at`, sends the existing `AppNotification` plus one queued email per employee with a user account. Move the "Payslip ready" notification out of `finalizeRun` into publish. `finalizeRun` with `publish_now=1` calls the same publish code.
- Employee visibility everywhere (`payrollData` `myPayslips`, `selectedPayslip`, `PayrollPdfController::show`, `acknowledgePayslip`) switches from `status === 'finalized'` to `isPublished()`. HR and management still see everything.
- Setting `payroll.payslip_acknowledgement` bool, tenant scope, default false. When off the Acknowledge button is hidden and the action returns 422; when on the run review lists who has not acknowledged.
- PDF additions via `PayslipPdfData`: monthly rate of pay, days employed / days in month, ordinary daily rate (basic ÷ 26) and hourly rate (÷ 8) when overtime or unpaid leave is present, overtime hours × multiplier per group (from `payslip_lines` source `overtime`), unpaid leave days, leave taken in the period (approved leave requests overlapping the period, grouped by type), payment date, and employer EPF and SOCSO numbers in the footer.

- [ ] Feature: employee gets 403 on a finalized unpublished payslip PDF and it is absent from their list; after publish 200 and listed; publish queues exactly one mail and one notification per employee and a second publish is 422; `publish_now` on finalize does both; backfilled runs stay visible; PDF data contains day counts, pay date, EPF and SOCSO employer numbers; acknowledgement off → 422, on → stamps and review shows the outstanding names.
- [ ] Existing tests that assert employee access right after finalize: add a publish step to their setup in this commit.
- [ ] Commit: `feat(payroll): publish step before staff can see payslips, full pay statement particulars, optional acknowledgement`.

---

### Task 8: F10 run kinds and the bonus run

**Files:** migration `add_kind_to_payroll_runs_and_is_bonus_to_individual_transactions`, `PayrollRun`, `IndividualTransaction`, `PayrollController::createRun` (split per kind), `process/monthly.blade.php` (kind picker, Cycle column shows the kind instead of hardcoded "Month End"), `transaction/individual.blade.php` (bonus tick shown for bonus-type items), tests `tests/Unit/PcbBonusTest.php`, `tests/Feature/PayrollBonusRunTest.php`.

**Interfaces:**
- `payroll_runs.kind` string default `monthly` (`monthly`, `bonus`, `final`); `payroll_runs.employee_id` nullable (final runs). Uniqueness rule in `createRun`: one `monthly` run per tenant and period; bonus and final runs may share a period. If a DB unique index on (tenant_id, period) exists, replace it with a plain index.
- `individual_transactions.for_bonus_run` bool default false. Monthly runs pull only `false`, bonus runs only `true`.
- Bonus run payslip: earnings = the flagged Individual Transactions only, basic 0, no Fixed Transactions, claims, overtime or unpaid leave. EPF on the bonus through the item's `epf_liable`; SOCSO, EIS and HRD Corp zero (pass `perkeso_liable`/`hrdf_liable` false for the run regardless of item flags, with a comment citing PERKESO's bonus exclusion). PCB: `PcbInputs` normal remuneration = the same period's monthly payslip figures when a monthly run exists for that employee, else projected from current salary and Fixed Transactions exactly as `createRun` would compute them; the bonus goes in the additional-remuneration field; store the result's `additionalMtd` in `pcb_additional` and 0 in `pcb`. When the monthly run for the period is finalized first, the bonus run's year-to-date must include it, and the reverse: `PcbYearToDate` must include finalized bonus payslips of earlier months as additional remuneration already taxed.
- F2 readiness gate, F4 guards, F5 pay date and F12 submissions apply to bonus runs unchanged. Submissions for a bonus run in a period that already has a monthly run reuse the monthly run's rows (no duplicates): `StatutoryCalendar::openFor` looks up by tenant + period + agency.

- [ ] Unit: PCB on a RM5,000 June bonus for an employee on RM4,000 equals `PcbCalculator` run with normal 4,000 and additional 5,000 and the same year-to-date; hand-work the expected figure using the Exhibit method in the PDF and pin it to the sen, showing the working in a comment.
- [ ] Feature: bonus run creates payslips only for employees with flagged transactions; SOCSO/EIS zero, EPF present; monthly run in the same period ignores the flagged transaction and still creates; second monthly run for the period is refused but a second bonus run is allowed.
- [ ] Commit: `feat(payroll): bonus runs taxed as additional remuneration, separate from the one monthly run per period`.

---

### Task 9: F10 final pay run, CP22A hold, merged CP39

**Files:** migration `add_held_for_cp22a_to_payslips_table`, `PayrollController` (`createRun` final branch, new `releaseHold`), `PayrollExportController` (`bankFile` skips held payslips; `statutoryFile` merges runs), `Statutory` exporters' callers, `Employee` (paid-out marker), views (final run form on `process/monthly.blade.php`: employee picker limited to staff with a last working day; hold pill and Release button on payout/review), test `tests/Feature/PayrollFinalRunTest.php`.

**Interfaces:**
- `payslips.held_for_cp22a` bool default false, `payslips.hold_released_at` timestamp nullable, `hold_released_by_id`. `employees.final_pay_run_id` nullable (marks the employee as paid out at finalize; a monthly run for a later period skips them, and the monthly run for the same period skips an employee who already has a final run).
- Final run: `kind=final`, requires `employee_id` with a `last_working_day` inside the period; one payslip; basic prorated by `Proration` to the last working day; pulls Fixed Transactions (prorated per their flag), unpaid leave, overtime, claims and all unflagged Individual Transactions of the period (encashed leave and termination amounts are entered there). Pay date defaults to the last working day (EA s.20) and the seven-day check uses the last working day instead of the period end.
- Hold: at finalize, `held_for_cp22a = LifecycleNotices::holdsFinalPay($employee)`. `payroll.payslips.release-hold` POST `/app/payroll/payslips/{payslip}/release-hold` (finalized run allowed, reason required, audited, stamps release fields and clears the flag). Bank file rows exclude `held_for_cp22a = true`; the audit line names how many were withheld.
- Merged month: `statutoryFile` for any run gathers payslips from every finalized run of that tenant and period (monthly + bonus + final), summing per employee for CP39, Form A, 8A and HRD Corp. The exporters stay pure: the controller passes a collection of per-employee merged rows. Implement the merge as `App\Services\Payroll\Statutory\MergedPayslips::forPeriod(Tenant, string $period): Collection<int, Payslip>` returning unsaved `Payslip` instances with summed amount columns and the `employee` relation set, so exporter signatures do not change.

- [ ] Feature: leaver with last working day 2026-06-15 on RM3,000 gets a final run payslip with basic 1,500 and an encashed-leave Individual Transaction included; monthly June run skips them; with an unfiled CP22A the payslip is held, absent from the bank file, and present after release with an audit row; with CP22A filed and cleared it is not held; CP39 for June sums the monthly, bonus and final runs into one file whose header balances.
- [ ] Full gate: `php artisan test --compact` and phpstan green. Commit: `feat(payroll): final pay run to the last working day, CP22A hold on the bank file, one merged agency file per month`.

---

## Self-review notes

- Coverage: F8 Tasks 3 and 4; F9 Task 2; F10 Tasks 8 and 9; F11 Task 5; F12 Task 6; F13 Task 7; Phase 1 F7 leftovers Task 1.
- Order matters: Task 5 before 9 (`holdsFinalPay`), Task 6 before 8 (`openFor` reuse), Task 7 before 8/9 so new run kinds are born with the publish step.
- Deliberate readings to report back: F12 "five submissions" is read as one row per agency file (three, or four with HRD Corp); `cp38_monthly` column kept but dead; TP1 list limited to what the LHDN PDF in the repo states.
