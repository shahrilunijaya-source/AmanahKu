# Employee Record & Progression (Worksy parity) — Design

Date: 2026-09-15. Source: Worksy HR view crawled on 2026-09-15 (screenshots in the session scratchpad, field lists reproduced below). Decisions below were confirmed with Shazwan in the brainstorming session.

## Goal

Give HR a complete employee record inside AmanahKu, matching what Worksy's Employee Profile and Progression screens hold, so Worksy can be retired for staff records.

Two Worksy features replicated:

1. **Employee Profile** tabs: Personal, Family, Bank & Statutory, Work, Employment, Timeline, Experience, Attachment.
2. **Progression**: Confirmation, Update, Resignation, Rehire, Batch Progression Update, Batch Salary Adjustment.

## Decisions (fixed)

| Question | Decision |
|---|---|
| Tab scope | All eight Worksy tabs |
| Progression placement | Separate screen under People (not inside the profile) |
| Batch actions | Included (sub-project 5) |
| Visibility | HR / director / management edit. Employee reads their own record, salary fields hidden. `manager` role sees none of the new tabs. |
| Profile layout | New tabs merge into the existing single tab row on the profile screen |
| Timeline source | Auto-generated from progression rows only. No manual timeline entries. |
| Lookups Worksy has that Unijaya does not use (Category, Line, Job Grade, Division, Section, Company) | Free-text columns, no lookup tables. Company = tenant name, not a field. |

## What already exists (reuse, do not rebuild)

| Worksy concept | AmanahKu today |
|---|---|
| Profile screen with Edit modal | `resources/views/screens/profile.blade.php`, data from `BuildsPeopleData`, save via `EmployeeController::update` |
| Hire date, position, department, branch, reports-to, employment type, basic salary, status | `employees` columns |
| Personal: NRIC, gender, marital status, phone, address, emergency contact, date of birth | `employees` columns (added 2026-06-30) |
| Bank + EPF/SOCSO/tax/PCB reliefs | `salary_structures` (`SalaryStructure` model), edited on the Payroll screen |
| TP3 / previous employer figures | `payroll_opening_figures` (`PayrollOpeningFigure`), `payroll.opening` route |
| Attachments | `employee_documents` + Documents screen |
| Assets | `assets` table, profile "Assets & Training" tab |
| Skills | `employee_skills`, profile tab |
| Training | `training_records`, profile tab |
| Work sites | `work_sites` |
| Career timeline | `career_timeline` table: title + label only, dev-seeded, no real data. **Replaced** by progression rows. |
| Role gates | `Permissions::MANAGEMENT_TIER`, `hasTenantRole($request, ['director','hr'])` for salary |

## Sub-projects (build order)

Each is its own plan and session. Later ones depend on 1.

1. Employment tab + Timeline tab + Progression screen (core)
2. Personal + Family tabs
3. Bank & Statutory + Experience tabs (relocate existing forms, add previous employment / education / certificates)
4. Work + Attachment tabs
5. Batch Progression Update + Batch Salary Adjustment

Shared rules across all five are at the end.

---

## 1. Employment + Timeline + Progression

### Data

**New table `employee_progressions`** (append-only):

| column | type | notes |
|---|---|---|
| id, tenant_id, employee_id | | tenant-scoped, FK cascade |
| type | enum: hired, confirmed, updated, resigned, rehired | |
| effective_on | date | the date the change applies |
| snapshot | json | full employment state after the change (field list below) |
| changed_fields | json | keys that differ from the previous row's snapshot; empty for `hired` |
| remark | text nullable | |
| recorded_by_employee_id | FK nullable | who did it; null for backfill |
| timestamps | | |

Snapshot keys: `department`, `division`, `section`, `position`, `job_grade`, `category`, `line`, `branch`, `reports_to` (name + id), `employment_type`, `probation_months`, `probation_days`, `basic_salary`, `pay_mode`, `payment_term`, `payment_method`, `status`. Names stored as strings so the timeline still reads if a lookup is renamed later.

**New nullable columns on `employees`:**

`confirmed_at` date, `resigned_at` date, `last_working_day` date, `probation_months` tinyint, `probation_days` tinyint, `resign_notice_months` tinyint, `resign_notice_days` tinyint, `short_notice_months` tinyint, `short_notice_days` tinyint, `pay_mode` enum(monthly, daily, hourly) default monthly, `payment_term` enum(daily, weekly, biweekly, monthly) default monthly, `payment_method` enum(cash, bank, cheque) default bank, `division` string, `section` string, `job_grade` string, `category` string, `line` string, `employment_remark` text.

**Backfill migration:** one `hired` row per non-archived employee from `joined_at` and current columns. `recorded_by_employee_id` null.

**Drop:** `career_timeline` table, `CareerTimelineEntry` model, the Overview card that renders it, the dev seeder lines. Nothing in prod uses it.

### Service

`App\Services\EmploymentRecordService` with one public method per action: `hire`, `confirm`, `update`, `resign`, `rehire`. Each:

1. validates the transition (table below),
2. updates `employees` inside a transaction,
3. diffs new snapshot against the latest progression row, writes the row,
4. `AuditLog::record(...)`.

Both the profile Employment edit modal and the Progression screen call this service. An `update` with no changed fields writes no row.

| action | allowed from status | sets |
|---|---|---|
| confirm | probation | `confirmed_at`, status active |
| update | active, probation, on_leave | any snapshot field |
| resign | active, probation, on_leave | `resigned_at`, `last_working_day`, status resigned |
| rehire | resigned | `joined_at` = new hire date, clears `resigned_at`, `last_working_day`, `confirmed_at`; status probation |

`effective_on` must be on or after `joined_at`. Resign `last_working_day` must be on or after `resigned_at`. Resignation does **not** archive; archive stays the existing separate action.

### Employment tab (profile)

Read-only view of the Worksy Employment Information fields, two columns:

Hire Date, Probation Period (m/d), Confirmation Date, Resign Notice Period (m/d), Resigned Date, Short Notice Period (m/d), Branch, Department, Division, Position, Reporting To, Category, Job Grade, Line, Section, Basic Salary + Pay Mode, Employment Type, Payment Term, Payment Method, Remark.

Header strip (Worksy top-right): Date Hired, Years of Service (`joined_at` to today or `last_working_day`, "2Y 1M 13D"), Due for Confirmation (`joined_at` + probation), Probation Period.

Edit button for HR/director/management opens a modal with the same fields; submit goes through `EmploymentRecordService::update`. Salary input only for director/hr (existing rule). The existing generic Edit modal keeps name, nickname, email, staff ID, DOB, work arrangement, status; employment fields move out of it into this tab to avoid two forms writing the same columns.

### Timeline tab (profile)

Progression rows newest-first. Each entry: date pill (`Mon, 6th July 2026`), title (Hired / Confirmed / Updated / Resigned / Rehired), the snapshot in a two-column grid, changed fields highlighted for `updated`, footer "Last edited on {date} by {name}". Collapsible cards. Salary line hidden on self-view.

### Progression screen

New sidebar item `progression` under People, gated to HR/director/management (`Permissions::MANAGEMENT_TIER` + hr). Layout: left column staff list with search and All / My Staff toggle (reuse the directory list partial), right column the selected employee's header (photo, name, phone, position, status chip, Currently Reporting To) and four sub-tabs:

- **Confirmation**: Date Confirmed (default today), then Current vs New two-column form (Worksy's dashed arrow layout). New column pre-filled with current values. Only shown for `probation` staff; others get "Already confirmed on {date}".
- **Update**: same Current vs New form plus Effective Date and Remark.
- **Resignation**: Resigned Date, Last Working Day (default resigned date + notice period), Reason (select: resigned, contract ended, terminated, retired, other), Remark.
- **Rehire**: only for `resigned` staff. New Hire Date plus the New column of the form.

Each sub-tab lists that employee's past rows of that type under the form (Worksy shows "No Record Found" when empty).

### Tests (acceptance)

- Each action: happy path writes employee columns + one progression row + audit entry.
- Illegal transitions refused (confirm an active person, rehire a non-resigned person).
- `update` with no changes writes no row.
- Backfill migration produces one `hired` row per employee.
- Tab visibility: manager sees neither tab; employee sees own tabs without salary; HR sees all.
- Progression screen 403 for manager and employee.

---

## 2. Personal + Family

### Personal tab

Sections and fields (Worksy):

**Personal Information**: First Name, Last Name, Full Name per IC/Passport, Known Name (existing `nickname`), Religion, Birth Date (existing), Gender (existing), Marital Status (existing), Race, Nationality, Blood Type.

**Contact & Address**: Phone (existing), Personal Email (existing `email` is the login/work email; add `personal_email`), Address Line 1 / Line 2 (split existing `address` if it is one column: keep `address` as line 1, add `address_2`), Country, State, City, Postcode.

**Emergency contact**: existing name + phone, add `emergency_contact_relationship`.

**Identification**: NRIC (existing, encrypted), Passport No, Passport Expiry, Immigration/permit no (foreign workers), Permit Expiry.

New `employees` columns: `first_name`, `last_name`, `full_name_ic`, `religion`, `race`, `nationality`, `blood_type`, `personal_email`, `address_2`, `city`, `state`, `postcode`, `country` (default Malaysia), `emergency_contact_relationship`, `passport_no`, `passport_expiry`, `permit_no`, `permit_expiry`.

Option lists (Religion, Race, Nationality, Marital Status, Blood Type) as PHP constant arrays in `App\Support\PersonalOptions`, seeded from the Worksy lists (Malaysian races and the full nationality list). Not tenant-editable.

Saved via a new `PersonalRecordController::update` (own form, not the generic Edit modal). Employee may edit their **own** Personal tab except NRIC and passport (HR-only), matching the onboarding wizard's current self-service scope.

### Family tab

New table `employee_family_members`: `employee_id`, `relation` enum(father, mother, spouse, child, dependent), `name`, `phone`, `date_of_birth`, `nric`, `occupation` enum(student, unemployed, working), `employer_name` (spouse), `marriage_date` (spouse), `education` (child), `gender`, `nationality`, `deceased` bool, `address`, `remark`, timestamps.

Tab shows four sections: Parents (father + mother, one row each, inline form), Spouse, Children, Other Dependents. Add / edit / delete rows. Employee can edit own family. `children_relief_count` on `salary_structures` stays manual (Worksy's "Auto" count is out of scope).

### Tests

- Personal update persists every new column; NRIC/passport rejected from employee self-edit.
- Family CRUD, tenant scoping, employee cannot touch another person's family.

---

## 3. Bank & Statutory + Experience

### Bank & Statutory tab

Relocates the existing salary-structure form from the Payroll screen into the profile. Same `SalaryStructure` model, same validation, same HR/director gate. Payroll screen keeps a link "Edit on profile".

Sections: **Bank** (bank name select from the Worksy Malaysian bank list as a PHP constant, account no, custom holder name toggle + name), **Income Tax** (tax no, resident yes/no, employee status, tax category, dependent children with the four LHDN child categories at 100%/50%), **EPF** (EPF no, contribution scheme), **SOCSO/EIS** (SOCSO no, category), **Zakat / CP38 / SKBBK** (existing columns).

New `salary_structures` columns: `bank_holder_name`, `tax_resident` bool default true, `tax_category`, `employee_tax_status`, `child_relief_breakdown` json (four categories x two rates), `epf_scheme`, `socso_category`.

Employee self-view: read-only, bank account number masked to last four digits.

### Experience tab

**Previous Employment Figures / TP3**: relocates the existing `payroll_opening_figures` form (`payroll.opening`). Grid per year: Month, Gross, Income Tax, Employee EPF, SOCSO, EIS, Zakat. HR/director only.

**Previous Employment**: new table `employee_work_histories`: company, address, joined_on, joined_as, resigned_on, position_held, last_drawn_salary, salary_type enum(monthly, weekly, daily), industry, reason_to_leave.

**Education**: new table `employee_educations`: qualification_type enum(high_school, vocational, associate, bachelor, master, doctorate), major, institute, from_year, to_year, honours enum(first, second, third, none), cgpa, remark, attachment (employee_documents id).

**Certificates**: new table `employee_certificates`: name, category, awarded_on, expires_on, awarded_by, remark, attachment.

**Awards / Scholarship**: new table `employee_awards`: title, year, remark, attachment.

**Training** and **Skills**: already exist; render the existing lists here and drop "Assets & Training" tab's training half (assets stays).

**Language**: new table `employee_languages`: language, speaking / reading / writing each enum(basic, intermediate, fluent, native).

Employee can add own education, certificates, awards, languages, work history. HR can edit all.

### Tests

- Salary structure saves from the new location, payroll calculations unchanged (existing payroll tests stay green).
- TP3 figures round-trip.
- CRUD + scoping per new table.
- Bank number masked on self-view.

---

## 4. Work + Attachment

### Work tab

**Work Details**: Employee ID (existing `staff_id`), Attendance ID (new `attendance_id` string, for the clock device), Work Email (existing `email`), Work Phone (new `work_phone`), Schedule (existing work arrangement + work site work_start/work_end), Benefit Start Date (new `benefit_start_at` date, defaults to confirmation date).

**Work Location**: Primary Work Location = existing `work_site_id`. Include/Exclude list = new pivot `employee_work_site` (allowed sites for geofenced clock-in). Existing geofence check must consult the pivot when it has rows, else fall back to `work_site_id`.

**Costing**: skipped. No cost-centre model in AmanahKu; add when finance asks.

**Assets**: existing assets list moved here from "Assets & Training". Add fields Worksy has: `issued_at` (existing `assigned_at`), `returned_at`, `reference_no`, `remark`.

### Attachment tab

Existing `employee_documents` listed with upload (drag-and-drop, 4 MB, images + PDF), category, delete. Reuses the Documents screen upload endpoint. Employee sees and uploads own; HR sees all.

### Tests

- Work fields persist; geofence honours the pivot.
- Asset returned/reference fields round-trip.
- Attachment upload/list/delete scoped by tenant and role.

---

## 5. Batch Progression Update + Batch Salary Adjustment

Two sub-tabs on the Progression screen, HR/director/management.

**Batch Progression Update**: multi-select staff (filter by department, branch, position, status), pick Effective Date, then tick which fields to change and set one value per ticked field (department, branch, division, section, position, reports_to, employment_type, payment_term, payment_method). Preview table: name, current value, new value per field. Confirm runs `EmploymentRecordService::update` per person in one transaction; one `updated` progression row each; one audit entry per person plus one summary entry.

**Batch Salary Adjustment**: same selection, then mode: fixed amount or percentage, increase or set-to. Preview shows current, new, delta. Salary rule: director/hr only. Confirm writes per person through the same service (`basic_salary` changed field). Refuses if any new salary exceeds the position band `max_salary` unless "override band" is ticked (audit-logged).

Both: max 200 rows per run, all-or-nothing.

### Tests

- Batch update writes N rows, all-or-nothing on a validation failure mid-batch.
- Percentage and fixed maths, rounding to 2 dp.
- Band override refused without the tick.

---

## Shared rules (all sub-projects)

- **Roles**: edit = `hr`, `director`, `management` (via `Permissions::effectiveRole`). Salary write/view = `director`, `hr` only (existing `canSeeSalary`). Employee = read own, edit own Personal/Family/Experience/Attachment as noted. `manager` = no new tabs, no Progression screen.
- **Tenant scoping**: every controller re-checks `tenant_id` on the bound employee (route-model binding is not tenant-safe in this app).
- **Audit**: every write calls `AuditLog::record`. Progression rows are never edited or deleted.
- **No full-page reloads for in-tab saves**: forms post and the screen returns to the same tab (`?tab=` query param or Alpine state restored from `old()`), matching the existing profile modal behaviour.
- **Bilingual** labels (EN / BM) like every other screen.
- **Migrations**: additive only, dev DB via `lerd artisan migrate`, tests on sqlite. Enum columns as strings with app-level validation where sqlite parity matters.
- **UI**: reuse `uj-card`, `uj-btn-*`, existing modal pattern, existing tab row. A local HTML mockup of each new tab and the Progression screen is shown to Shazwan before the build session starts (frontend mockup approval rule).
- **Assets**: `lerd artisan view:clear && lerd artisan view:cache && bun run build`, commit `public/build`.
- **Out of scope**: Face Photos, Blacklist, Direct Hiring / QR invite, Job Description screen, Employee Chart (org chart exists), Costing, auto child-relief count, Assistant toggle.

## Worksy field reference (crawled 2026-09-15)

Employment: Hire Date · Probation Period (m/d) · Confirmation Date · Resign Notice Period (m/d) · Resigned Date · Short Notice Period (m/d) · Company · Branch · Department · Division · Position · Reporting To (+Assistant) · Category · Job Grade · Line · Section · Basic Salary + Pay Mode · Employment Type (Part Time, Full Time, Intern, Contract, Outsource, Foreign Worker, Expatriate) · Payment Term (Daily, Weekly, Bi-Weekly, Monthly) · Payment Method (Cash, Bank, Cheque) · Remark.

Timeline card (Hired): Company, Employment Type, Department, Division, Section, Position, Reporting To, Probation Period, Payment Method, Basic Salary, Payment Term, "Last edited on … by …".

Progression Confirmation: Date Confirmed, then CURRENT → NEW columns: Company, Department, Job Grade, Position, Branch, Category, Division, Line, Section.

Personal: First/Last Name, Full Name per IC, Known Name, Religion, Birth Date, Gender, Marital Status (Single, Married, Living Together, Widowed, Divorced), Race, Nationality, Blood Type; Phone, Personal Email, Address 1/2, Country, State.

Family: Father, Mother (name, phone, DOB, occupation, deceased, NRIC, address); Spouse (+ marriage date, employer, gender, nationality); Children (+ education, gender); Other Dependents.

Bank & Statutory: Bank Name, Account, custom holder name / identification toggles; Income Tax no, Resident, Employee Status, Tax Category, dependent children count with four LHDN categories at 100% / 50%.

Work: Employee ID, Attendance ID, Work Email, Schedule, Work Phone, Benefit Start Date; Primary Work Location; Include/Exclude work locations; Costing; Assets (type, issued, returned, reference, serial, remark).

Experience: TP3 grid (Year, Month, Gross, Income Tax, EPF, SOCSO, EIS, Zakat); Previous Employment; Education; Training; Awards/Scholarship; Certificate; Skills (level: Below Average, Highly Skilled, Expert); Language (speaking/reading/writing).

Attachment: upload, 4 MB, SVG/PNG/JPG/GIF.
