# Payroll Phase 1 Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close five gaps found when Phase 1 (F1 to F7) was re-read after Phase 2 (F8 to F13) shipped: a leaver paid twice, a SOCSO exemption switch that changes nothing, a readiness gate that a resigned leaver walks past, a carry-forward that loses money on a final run, and a dashboard card that can hide an unpaid run.

**Architecture:** No new classes, tables, columns, routes or views. Every fix is a guard or a query change inside the existing run lifecycle in `PayrollController`, one input on `PayrollCalculator`, one visibility change on `PayrollReadiness`, and one static finder on `PayrollRun`.

**Tech Stack:** Laravel 13, PHP 8.5, PHPUnit (sqlite), Larastan level 5.

**Spec:** `docs/superpowers/specs/2026-09-02-payroll-features-design.html` sections F2, F4, F5, F7 and F10. Earlier plans: `docs/superpowers/plans/2026-09-18-payroll-phase1-f1-f7.md`, `docs/superpowers/plans/2026-09-19-payroll-phase2-f8-f13.md`.

## Global Constraints

- Commit on `dev`, no worktree, no push. One commit per task.
- Format PHP before each commit: `vendor/bin/pint --dirty --format agent`. Larastan must stay green: `vendor/bin/phpstan analyse --memory-limit=2G`.
- Tests run on the host against sqlite: `php artisan test --compact <file>`. No migration is needed by any task.
- No Blade, CSS or JS changes, so no asset rebuild.
- Route-model binding is NOT tenant-scoped. Keep every existing `assertTenant` call.
- Never delete or rewrite an audit row. Finalized runs stay immutable.
- Refusals in `createRun` return `back()->withErrors([...])->withInput()`, like the existing ones, so the form shows the message.
- Do not touch the three wage bases or the two proration divisors (spec section 7).

## Out of scope (needs a decision from Shazwan first)

These were deferred by the Phase 1 plan and never picked up. Each needs a product answer, so they are not in this plan:

- **Tax residency:** `salary_structures.tax_resident` is saved but PCB always treats staff as resident. The code comment at `PayrollController::pcbInputsFor` explains why (no days-in-Malaysia data). Flipping it means trusting HR's tick for the 30% flat rate.
- **Child relief split:** `child_relief_breakdown` (LHDN category × 100% / 50%) is saved, but PCB and Form EA read the single `children_relief_count`. Needs the ringgit value per category from the LHDN spec before units can be derived.
- **Voluntary EPF rates:** `epf_voluntary_employee_rate` / `epf_voluntary_employer_rate` were never built. The dead `epf_employee_rate_override` column is still there.

---

### Task 1: A leaver is paid by the monthly run or the final run, never both

**Problem proven on 2026-09-21:** create the June monthly run, then a June final run for a leaver whose last working day is 15 June. The leaver gets two payslips, each with RM1,500 basic. The monthly run only skips a leaver after their final run is *finalized* (`final_pay_run_id`), and the final run never looks for an existing monthly payslip.

**Files:**
- Modify: `app/Http/Controllers/PayrollController.php` `createRun()` (final-run checks near line 818, employee query near line 843)
- Test: `tests/Feature/PayrollFinalRunTest.php`

**Interfaces:**
- Consumes: `Payslip::payrollRun()` relation, `PayrollRun.kind`, `PayrollRun.period`.
- Produces: nothing new.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/PayrollFinalRunTest.php`, after `test_the_monthly_run_skips_an_employee_already_paid_out`:

```php
    public function test_a_final_run_is_refused_when_the_monthly_run_already_pays_the_leaver(): void
    {
        $leaver = $this->leaver();
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])
            ->assertSessionHasNoErrors();

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'final', 'employee_id' => $leaver->id])
            ->assertSessionHasErrors('employee_id');

        $this->assertSame(1, Payslip::where('employee_id', $leaver->id)->count());
        $this->assertSame(0, PayrollRun::where('kind', 'final')->count());
    }

    public function test_the_monthly_run_skips_a_leaver_whose_final_run_is_still_a_draft(): void
    {
        $leaver = $this->leaver();
        $stayer = $this->employee('Bakar');
        $this->createFinalRun($leaver);   // deliberately not finalized

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])
            ->assertSessionHasNoErrors();

        $monthly = PayrollRun::where('kind', 'monthly')->firstOrFail();
        $this->assertSame([$stayer->id], $monthly->payslips()->pluck('employee_id')->all());
    }
```

- [ ] **Step 2: Run them, expect both to fail**

Run: `php artisan test --compact tests/Feature/PayrollFinalRunTest.php`
Expected: the two new tests FAIL (no session error; monthly run holds both employees).

- [ ] **Step 3: Refuse the final run when a monthly payslip exists**

In `createRun()`, inside `if ($kind === 'final') { ... }`, directly after the "no last working day inside" check, add:

```php
            // One payout per leaver per month: if the monthly run already carries their
            // (prorated) payslip, a final run on top would pay the same days twice.
            $alreadyInMonthly = Payslip::where('employee_id', $leaver->id)
                ->whereHas('payrollRun', fn ($r) => $r->where('period', $data['period'])->where('kind', 'monthly'))
                ->exists();
            if ($alreadyInMonthly) {
                return back()->withErrors(['employee_id' => $leaver->name.' already has a payslip in the '.$data['period'].' monthly run. Delete that draft run and create it again after this final pay run, or pay them through the monthly run.'])->withInput();
            }
```

- [ ] **Step 4: Make the monthly run skip a leaver with any final run in the period**

In the same method, in the `Employee::active()` query, directly after `->whereNull('final_pay_run_id')`, add:

```php
            // A draft final run has not stamped final_pay_run_id yet, but it already holds
            // this month's payslip for that leaver.
            ->when($kind === 'monthly', fn ($q) => $q->whereNotIn('id', Payslip::whereHas('payrollRun',
                fn ($r) => $r->where('period', $data['period'])->where('kind', 'final'))->select('employee_id')))
```

- [ ] **Step 5: Run the file, expect all green**

Run: `php artisan test --compact tests/Feature/PayrollFinalRunTest.php`
Expected: PASS, including the existing `test_the_monthly_run_skips_an_employee_already_paid_out`.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/PayrollController.php tests/Feature/PayrollFinalRunTest.php
git commit -m "fix(payroll): a leaver is paid by the monthly run or the final run, never both in one month"
```

---

### Task 2: The SOCSO exemption switch actually stops SOCSO, EIS and SKBBK

**Problem proven on 2026-09-21:** with `salary_structures.socso_exempt` on, the payslip still deducts SOCSO 14.75 and EIS 5.90 on RM3,000. The switch is only read by the readiness check. `PerkesoBorang8A` already leaves out payslips whose SOCSO and EIS are all zero, so the file fixes itself once the amounts are zero.

**Files:**
- Modify: `app/Services/Payroll/PayrollCalculator.php` (input docblock near line 57, SOCSO/EIS block near line 193)
- Modify: `app/Http/Controllers/PayrollController.php` (`buildMonthlyPayslips` inputs near line 1028, `updatePayslip` `$baseInputs` near line 1278)
- Test: `tests/Unit/PayrollCalculatorTest.php`, `tests/Feature/PayrollFinalRunTest.php`

**Interfaces:**
- Produces: calculator input `socso_exempt?: bool`. When true, `socsoEmployee`, `socsoEmployer`, `eisEmployee`, `eisEmployer` and `skbbkEmployee` are all `0.0`. EPF, PCB and the HRD Corp levy are untouched.

- [ ] **Step 1: Write the failing unit test**

Open `tests/Unit/PayrollCalculatorTest.php`, find how an existing test builds the calculator and calls `compute()` (`grep -n "compute(" tests/Unit/PayrollCalculatorTest.php | head -3`), and add a test using that same construction:

```php
    public function test_socso_exempt_zeroes_socso_eis_and_skbbk_but_not_epf(): void
    {
        $base = ['basic' => 3000.0, 'statutory_category' => 1, 'skbbk_opt_in' => true];
        $normal = $this->calc->compute($base);
        $exempt = $this->calc->compute($base + ['socso_exempt' => true]);

        $this->assertGreaterThan(0, $normal->socsoEmployee);
        $this->assertSame(0.0, $exempt->socsoEmployee);
        $this->assertSame(0.0, $exempt->socsoEmployer);
        $this->assertSame(0.0, $exempt->eisEmployee);
        $this->assertSame(0.0, $exempt->eisEmployer);
        $this->assertSame(0.0, $exempt->skbbkEmployee);
        $this->assertSame($normal->epfEmployee, $exempt->epfEmployee);
    }
```

`$this->calc` is the calculator the file's `setUp()` already builds.

- [ ] **Step 2: Write the failing feature test**

Add to `tests/Feature/PayrollFinalRunTest.php` (it has the `employee()` helper this needs):

```php
    public function test_a_socso_exempt_employee_has_no_socso_or_eis_on_the_payslip_or_after_a_recompute(): void
    {
        $emp = $this->employee('Exempt');
        $emp->salaryStructure->forceFill(['socso_exempt' => true, 'socso_no' => null])->save();

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])
            ->assertSessionHasNoErrors();
        $slip = Payslip::where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame(0.0, (float) $slip->socso_employee);
        $this->assertSame(0.0, (float) $slip->eis_employee);
        $this->assertGreaterThan(0, (float) $slip->epf_employee);

        // Recompute through the payslip form keeps it at zero.
        $this->post(route('payroll.payslips.update', $slip), ['bonus' => 0])->assertSessionHasNoErrors();
        $this->assertSame(0.0, (float) $slip->fresh()->socso_employer);
        $this->assertSame(0.0, (float) $slip->fresh()->eis_employer);
    }
```

Check the payslip update route name first: `php artisan route:list --name=payroll.payslips`. Use the name it prints.

- [ ] **Step 3: Run both, expect failure**

Run: `php artisan test --compact tests/Unit/PayrollCalculatorTest.php tests/Feature/PayrollFinalRunTest.php`
Expected: the two new tests FAIL with non-zero SOCSO.

- [ ] **Step 4: Calculator**

In `PayrollCalculator::compute()`'s `@param` array shape, after the `skbbk_opt_in?: bool,` line add:

```php
     *     socso_exempt?: bool,
```

Then, directly after the line `$eisEmployer = $eisContribution['employer'];`, add:

```php
        // Spec F2: HR has marked this person outside PERKESO coverage (for example a
        // director who is not an employee under the Act), so none of SOCSO, EIS or SKBBK
        // applies. EPF, PCB and the HRD Corp levy are separate regimes and stay.
        if (! empty($inputs['socso_exempt'])) {
            $socsoEmployee = $socsoEmployer = $eisEmployee = $eisEmployer = $skbbkEmployee = 0.0;
        }
```

- [ ] **Step 5: Pass the switch from both places that build inputs**

In `buildMonthlyPayslips()`, after the `'skbbk_opt_in' => (bool) $structure->skbbk_opt_in,` line, add:

```php
                'socso_exempt' => (bool) $structure->socso_exempt,
```

In `updatePayslip()`'s `$baseInputs`, after `'skbbk_opt_in' => (bool) $structure?->skbbk_opt_in,`, add:

```php
                'socso_exempt' => (bool) $structure?->socso_exempt,
```

`buildBonusPayslips()` needs nothing: a bonus run already forces the PERKESO base to zero.

- [ ] **Step 6: Run, expect green; then the wider payroll set**

```bash
php artisan test --compact tests/Unit/PayrollCalculatorTest.php tests/Feature/PayrollFinalRunTest.php
php artisan test --compact --filter='Payroll|Statutory'
```
Expected: PASS.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Payroll/PayrollCalculator.php app/Http/Controllers/PayrollController.php tests/Unit/PayrollCalculatorTest.php tests/Feature/PayrollFinalRunTest.php
git commit -m "fix(payroll): SOCSO exempt switch now zeroes SOCSO, EIS and SKBBK on the payslip, not just the readiness check"
```

---

### Task 3: The readiness gate checks the leaver on a final run, whatever their status

**Problem:** `PayrollReadiness::employeeRows()` only lists staff whose status is `active`, `probation` or `on_leave`. A leaver already marked `resigned` has no row, so a final run for them is created even with no NRIC or bank details, and the agency files then reject.

**Files:**
- Modify: `app/Services/Payroll/PayrollReadiness.php` (`gapsFor` visibility, line 89)
- Modify: `app/Http/Controllers/PayrollController.php` `createRun()` readiness block near line 858
- Test: `tests/Feature/PayrollFinalRunTest.php`

**Interfaces:**
- Produces: `PayrollReadiness::gapsFor(Employee $e): array{blocking: list<string>, warnings: list<string>}` becomes `public`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/PayrollFinalRunTest.php`:

```php
    public function test_a_resigned_leaver_with_missing_details_cannot_get_a_final_run(): void
    {
        $leaver = $this->employee('Aida', 3000, ['last_working_day' => '2026-06-15', 'status' => 'resigned', 'nric' => null]);

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'final', 'employee_id' => $leaver->id])
            ->assertSessionHasErrors('readiness');

        $this->assertSame(0, PayrollRun::count());
    }
```

- [ ] **Step 2: Run it, expect failure**

Run: `php artisan test --compact tests/Feature/PayrollFinalRunTest.php --filter=resigned_leaver`
Expected: FAIL (no `readiness` error, a run exists).

- [ ] **Step 3: Make `gapsFor` public**

In `app/Services/Payroll/PayrollReadiness.php` change `private function gapsFor(Employee $e): array` to `public function gapsFor(Employee $e): array`.

- [ ] **Step 4: Check the leaver directly in `createRun()`**

Replace this block:

```php
        foreach ($readiness->blockingRows($tenant, $excluded) as $row) {
            if ($kind !== 'monthly' && ! in_array($row['employee']->id, $inThisRun, true)) {
                continue;
            }
            $problems[] = $row['employee']->name.': '.implode(', ', $row['blocking']);
        }
```

with:

```php
        if ($leaver !== null) {
            // A leaver may already be marked resigned, which drops them out of the
            // "currently employed" rows below, so check them directly.
            $leaverGaps = $readiness->gapsFor($leaver)['blocking'];
            if ($leaverGaps !== []) {
                $problems[] = $leaver->name.': '.implode(', ', $leaverGaps);
            }
        } else {
            foreach ($readiness->blockingRows($tenant, $excluded) as $row) {
                if ($kind !== 'monthly' && ! in_array($row['employee']->id, $inThisRun, true)) {
                    continue;
                }
                $problems[] = $row['employee']->name.': '.implode(', ', $row['blocking']);
            }
        }
```

- [ ] **Step 5: Run the file and the gate tests, expect green**

Run: `php artisan test --compact tests/Feature/PayrollFinalRunTest.php tests/Feature/PayrollRunReadinessGateTest.php tests/Feature/PayrollReadinessTest.php tests/Feature/PayrollBonusRunTest.php`
Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Payroll/PayrollReadiness.php app/Http/Controllers/PayrollController.php tests/Feature/PayrollFinalRunTest.php
git commit -m "fix(payroll): readiness gate checks a final run's leaver even when they are already marked resigned"
```

---

### Task 4: No carry-forward on a final run

**Problem:** "Carry to next month" zeroes a negative net and queues a deduction for the next period. A leaver paid out in a final run never gets another payslip, so the shortfall is written off with no trace beyond the audit line.

**Decision:** refuse it. HR lowers the deductions on the final payslip so net is zero or more, and recovers the rest outside payroll. Finalize already blocks a negative net, so the run cannot close until HR has done that.

**Files:**
- Modify: `app/Http/Controllers/PayrollController.php` `carryForward()` near line 1465
- Test: `tests/Feature/PayrollFinalRunTest.php`

- [ ] **Step 1: Write the failing test**

```php
    public function test_a_final_run_shortfall_cannot_be_carried_to_a_month_that_will_never_be_paid(): void
    {
        $leaver = $this->leaver();
        $this->queueOneOff($leaver, 5000, 'other-deduction');   // larger than the RM1,500 prorated pay
        $run = $this->createFinalRun($leaver);
        $slip = $run->payslips()->firstOrFail();
        $this->assertLessThan(0, (float) $slip->net_pay);

        $this->post(route('payroll.payslips.carry-forward', $slip))->assertStatus(422);

        $this->assertLessThan(0, (float) $slip->fresh()->net_pay);
        $this->assertSame(0, IndividualTransaction::where('employee_id', $leaver->id)->where('period', '2026-07')->count());
    }
```

Check the route name first: `php artisan route:list --path=carry`. Use the name it prints.

- [ ] **Step 2: Run it, expect failure**

Run: `php artisan test --compact tests/Feature/PayrollFinalRunTest.php --filter=shortfall_cannot_be_carried`
Expected: FAIL (302 instead of 422, a July transaction exists).

- [ ] **Step 3: Add the guard**

In `carryForward()`, directly after the `isEditable()` line, add:

```php
        abort_if($payslip->payrollRun->isFinal(), 422, 'A final pay run has no next month to carry into. Lower the deductions on this payslip until net pay is zero or more, and recover the rest from the employee directly.');
```

- [ ] **Step 4: Run the file and the guards test, expect green**

Run: `php artisan test --compact tests/Feature/PayrollFinalRunTest.php tests/Feature/PayrollDeductionGuardsTest.php`
Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/PayrollController.php tests/Feature/PayrollFinalRunTest.php
git commit -m "fix(payroll): carry-forward refused on a final pay run, where there is no next month to deduct from"
```

---

### Task 5: The HR dashboard card shows the run that most needs paying

**Problem:** `payrollWidget()` takes `PayrollRun::orderByDesc('period')->first()`. Since F10 a period can hold a monthly, a bonus and a final run, and the database picks one of them arbitrarily. A paid bonus run can sit on the card while the unpaid monthly run passes its seventh day with no red warning.

**Rule:** show the finalized, unpaid run with the earliest pay-by date. When nothing is waiting to be paid, show the newest run as before.

**Files:**
- Modify: `app/Models/PayrollRun.php` (new static finder beside `payByDate()`)
- Modify: `app/Http/Controllers/Concerns/BuildsDashboardWidgets.php` `payrollWidget()` near line 1188
- Test: `tests/Feature/PayrollPayDateTest.php`

**Interfaces:**
- Produces: `PayrollRun::forPayByCard(): ?PayrollRun`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/PayrollPayDateTest.php` (its `setUp` already creates `$this->run`, a draft August 2026 monthly run):

```php
    public function test_the_dashboard_card_shows_the_unpaid_run_not_a_paid_one_in_the_same_month(): void
    {
        $this->run->forceFill(['status' => 'finalized', 'finalized_at' => now(), 'payment_date' => '2026-09-05'])->save();
        $bonus = PayrollRun::forceCreate(['tenant_id' => $this->tenant->id, 'period' => '2026-08', 'kind' => 'bonus',
            'label' => 'August 2026 bonus', 'status' => 'finalized', 'finalized_at' => now(), 'payment_date' => '2026-08-20', 'paid_at' => now()]);

        $this->assertSame($this->run->id, PayrollRun::forPayByCard()?->id);

        // Once everything is paid, the newest run is shown.
        $this->run->forceFill(['paid_at' => now()])->save();
        $this->assertSame($bonus->id, PayrollRun::forPayByCard()?->id);
    }
```

- [ ] **Step 2: Run it, expect failure**

Run: `php artisan test --compact tests/Feature/PayrollPayDateTest.php`
Expected: FAIL with "Call to undefined method ... forPayByCard".

- [ ] **Step 3: Add the finder**

In `app/Models/PayrollRun.php`, directly after `payByDate()`:

```php
    /**
     * The run the HR dashboard card should show (spec F5): the finalized run still waiting
     * to be paid whose pay-by date comes first, so an unpaid monthly run is never hidden
     * behind a paid bonus run of the same month. With nothing waiting, the newest run.
     */
    public static function forPayByCard(): ?self
    {
        return self::where('status', 'finalized')->whereNull('paid_at')->get()
            ->sortBy(fn (self $run) => $run->payByDate()->getTimestamp())->first()
            ?? self::orderByDesc('period')->orderByDesc('id')->first();
    }
```

- [ ] **Step 4: Use it in the widget**

In `payrollWidget()` replace `$run = PayrollRun::orderByDesc('period')->first();` with:

```php
        $run = PayrollRun::forPayByCard();
```

Update the method's docblock first line from "the newest run's pay-by date" to "the pay-by date of the run that most needs paying".

- [ ] **Step 5: Run, expect green**

Run: `php artisan test --compact tests/Feature/PayrollPayDateTest.php`
Expected: PASS.

- [ ] **Step 6: Full gate, format and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=2G
php artisan test --compact
git add app/Models/PayrollRun.php app/Http/Controllers/Concerns/BuildsDashboardWidgets.php tests/Feature/PayrollPayDateTest.php
git commit -m "fix(payroll): HR dashboard card shows the unpaid run with the earliest pay-by date, not an arbitrary run of the newest month"
```

Expected: phpstan 0 errors, full suite green.

---

## Self-review notes

- Coverage: double pay Task 1; SOCSO exempt Task 2; resigned-leaver gate Task 3; final-run carry-forward Task 4; dashboard card Task 5. The three deferred fields are listed under "Out of scope" with the question each one waits on.
- Tasks are independent. Tasks 1, 3 and 4 all edit `PayrollController.php` and `PayrollFinalRunTest.php`, so run them in order rather than in parallel.
- Two route names (`payroll.payslips.update`, `payroll.payslips.carry-forward`) are assumed from the controller method names. Each task tells the implementer to confirm with `route:list` before using it.
- Uncommitted before this plan: the clock fix in `tests/Feature/Mcp/AmanahkuServerTest.php`. Commit it on its own before Task 1 so the full-suite gate in Task 5 is green.
