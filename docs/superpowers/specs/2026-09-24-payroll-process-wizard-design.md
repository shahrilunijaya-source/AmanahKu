# Process Payroll wizard (Worksy match) + real Mid Month cycle

Date: 2026-09-24. Source of truth: Worksy screenshots in `.playwright-mcp/worksy-step*.png` of the main checkout
(Process Payroll at `app.worksy.io/#/payroll/process/monthly`). Shazwan's rule: Worksy is the spec.

## Goal

Replace the one-card "New payroll run" form on Pay & Benefits → Process → Monthly tab with Worksy's
4-step wizard (Period → Condition → Selected Employees → Results), and make the Mid Month cycle work for real.

Options AmanahKu has no data or logic for are **shown, greyed out, with a "not available yet" hint**
(Shazwan picked "layout now, real later").

## What stays

KPI cards, the Monthly / Bonus / Payroll Control tabs, the "Payroll runs" list (moves below the wizard).
All payroll maths, the readiness gate, audit entries and the one-POST-creates-the-run flow stay as they are.

## Request contract (POST `payroll.runs.create`)

Existing fields keep working unchanged. New / changed:

| field | rule | meaning |
|---|---|---|
| `kind` | `monthly`, `mid_month`, `bonus`, `final` | UI labels: Month End (ME), Mid Month (MM), Bonus (Bonus), Final Pay |
| `include_employee_ids[]` | optional, ints, tenant employees | when present, the run covers only these people; every other eligible person is added to `excluded_employee_ids` (the column the run already has) so the readiness gate and audit note keep working |
| `exclude_employee_ids[]` | unchanged | |
| `remarks` | nullable string max 1000 | new `payroll_runs.remarks` column |
| `mid_month_basis` | `cutoff` or `percentage`, required when kind=mid_month | |
| `mid_month_value` | int; cutoff day 1–28 (default 15) or percent 1–100 (default 50) | |
| `pull_*` | unchanged | the four real tickboxes |

On success, redirect to `app.screen` `payroll-process` with `tab=monthly`, `step=results`, `run={id}` plus the
existing flash message. On validation failure, `back()->withInput()` as today (wizard reopens on step 1 with old input).

## Mid Month (real)

Worksy setting observed: "Mid Month Salary Calculation Based On: **Cutoff**" (alternative "Percentage of Full
Salary"), "Enable Statutory Consolidation", "Last Cycle of Period = Month End". So:

- **MM run** (`kind = mid_month`): one per tenant+period. Each payslip pays basic salary only:
  - cutoff basis: `salary × employed days in [1st .. cutoff day] / days in month` (calendar days, as Worksy "Calendar Month").
  - percentage basis: `salary × value / 100`.
  - Under either basis, people with zero employed days in [1st .. cutoff day] are skipped (cutoff day is 15 for
    the percentage basis).
  - No allowances, claims, overtime, unpaid leave, no EPF/SOCSO/EIS/PCB/HRDF, no zakat, no CP38. Gross = net = advance.
  - Stored `mid_month_basis` / `mid_month_value` on the run.
- **ME run** (`kind = monthly`) and **final pay** for the same period: computed exactly as today on the full month
  (statutory consolidated at month end), then net is reduced by the employee's MM advance for that period, shown as a
  payslip line "Mid-month advance". The advance must not change gross, EPF/SOCSO/EIS/PCB bases.
- Every place that recomputes a payslip (payslip edit/recompute paths) must keep deducting the advance.
- Ordering rules:
  - MM cannot be created if a `monthly` run for that period already exists.
  - A `monthly` run cannot be created while that period's MM run is still `draft` ("approve or delete the mid-month run first").
  - An MM run cannot be deleted while a `monthly` run exists for the period.
- **Totals that count remuneration must skip MM payslips** (their pay is already inside the ME payslip gross):
  PCB year-to-date, EA form, Form E / CP8D, statutory reports and files (EPF, SOCSO, EIS, PCB, HRDF), payroll
  YTD reports. Bank file / payment lists DO include MM runs (money really moves mid-month).
- Labels everywhere a run kind is shown: Mid Month / Pertengahan Bulan; run label "September 2026 mid month".

## Wizard UI (Alpine, one page, no reload between steps)

**Step 1 Period**: Payroll Period (month), Payroll Cycle select (placeholder "Select Payroll Cycle"; Bonus (Bonus),
Mid Month (MM), Month End (ME), Final Pay), Payment Date (defaults today), MM basis + value fields shown only for MM,
leaver picker shown only for Final Pay. Tickboxes in Worksy order: Pull Attendance Data*, Pull Monthly
Allowance/Deduction, Pull Monthly Overtime Allowance, Pull Claim Data, Pull Unpaid Data, Pull Absent As Unpaid*,
Pull None-Shift As Unpaid*, Overwrite Pulled Transactions* (*greyed). Default **unticked** like Worksy. Tickboxes
hidden for bonus/MM (they pull nothing). Payroll Policies collapsible dual list: Daily*, Hourly*, Monthly;
Next is blocked until Monthly is included. Remarks textarea. Next.

**Step 2 Condition**: Back / Next, four method tiles: All Available Users, Teams*, Manual Selections (jumps to step 3
with an empty selection, like Worksy), Import Selections*. Filter Options column with search and Worksy's 33 buttons
in Worksy order; working ones open a "Filter Added" panel: list filters use Available / Included dual list with
search, Select All, "n selected", Move to; range filters (Service Year, Age, Basic Salary) min/max; date filters
from/to. Clear per filter, Clear All. Greyed: No of Children, Date Offered, Custom Fields, Company, Primary Location,
Cost Centres, Schedules.

Filter → employee field: Gender `gender`, Marital Status `marital_status`, Service Year from `joined_at`, Age from
`date_of_birth`, Date Hired `joined_at`, Date Confirmed `confirmed_at`, Date Resigned `resigned_at`, Basic Salary
`salary`, Pay Mode `pay_mode`, Payment Method `payment_method`, Employment Type `employment_type_id`, Employee Status
`status`, Department `department_id`, Grade `job_grade`, Position `position_id`/`position`, Direct Report
`reports_to_id`, Location `work_site_id`, Branches `branch_id`, Categories `category`, Divisions `division`,
Nationalities `nationality`, Races `race`, Religions `religion`, Lines `line`, Sections `section`.

**Step 3 Selected Employees**: "Your Selection Summary" tiles (Method, Your Selection count, Additional Selection
count), Back, **Process Payroll** (submits). "Your Selections (n)" table: avatar/initials, name + position, employee
number (`staff_id`), company (tenant name), department, include toggle per row plus header Exclude/Include toggle,
search, 50 per page. Readiness: blocking gaps as red pills, warnings amber, company gaps as a banner; Process Payroll
disabled while any switched-on person has a blocking gap (same rule as today; server gate still decides).
"Additional Selections (n)": Select Employee dropdown + Add Employee. Eligible set per cycle: monthly/MM = currently
employed with salary structure (as today); bonus = people with a bonus queued; final = the chosen leaver only.

**Step 4 Results**: shown when `step=results&run=` is present: stepper all done, cycle + period, payslip count, gross,
net, employer cost, remarks, flash message, buttons Review payroll (payroll-review individual tab for the run) and
Process another (back to step 1).

## Testing

Feature tests: MM cutoff + percentage amounts and zero statutory; ME deducts advance without changing statutory;
ordering rules; YTD/EA exclude MM; include_employee_ids narrowing + readiness still enforced; remarks saved;
redirect to results; wizard renders its steps and data. Browser pass through all 4 steps on the worktree vhost.
