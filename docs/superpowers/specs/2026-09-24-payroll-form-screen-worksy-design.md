# Payroll Form screen (Worksy match)

Date: 2026-09-24. Source of truth: Worksy screenshots in `.playwright-mcp/wf-*.png` of the main checkout
(Payroll → Form at `app.worksy.io/#/payroll/form/<id>`). Shazwan's rule: Worksy is the spec.

The Worksy account used had no company in the left-hand list, so the company-level forms (EPF A, BBCD, 8A,
Zakat, HRDF) were only seen empty. Re-capture them filled in before building phase 1's on-screen preview.

## Goal

Pay & Benefits → Payroll → Form (`resources/views/screens/payroll-form.blade.php`) has 12 tabs, and 9 of them
show a "Not yet available" stub. Most of the logic behind them already exists in other places. Make every tab
work the way its Worksy page works, in three phases, cheapest first.

## Worksy's 13 forms

| Worksy menu item | Worksy route id | Page pattern (below) |
|---|---|---|
| Borang SIP 2 | `sip-form` | E, staff picker wizard |
| EPF Borang A | `epf-form` | A, company + month |
| EPF BBCD Form | `bbcd-form` | A, company + month |
| LHDN CP21 | `cp21-form` | B, staff + year |
| LHDN CP22 | `cp22-form` | B, staff + year, plus Batch Export Text Files |
| LHDN CP22A | `cp22a-form` | B, staff + year, plus Edit, Batch Export PDF, Batch Export Text Files |
| LHDN Form E | `e-form` | C, year + company form, plus Edit |
| LHDN PCB II Form | `pcb2-form` | B, staff + year, plus Edit |
| LHDN CP39 | `cp9d-form` | D, generator |
| Perkeso Monthly SIP (EIS) Form | `perkeso-sip-form` | D, generator |
| Perkeso Borang 8A | `socso-form` | A, company + month |
| Zakat Form | `zakat-form` | A, company + month, plus a zakat authority dropdown |
| HRDF Form | `hrdf-form` | A, company + month, shown in a PDF viewer |

### Page patterns

- **A. Company + month.** Company search list on the left (collapsible), `‹ ›` month arrows and a month
  picker, the form drawn on screen for that month. A `⋮` menu top-right with **Print**. Empty state:
  "There is no record for Sep 2026."
- **B. Staff + year.** Staff list on the left: All / My Staff tabs, "Search by Employee Name, ID", a
  collapsible FILTER panel at the bottom. `‹ ›` year arrows. The official LHDN form drawn on screen with the
  selected employee's data filled into the boxes. Header buttons vary per form (see table).
- **C. Year + company form.** Same as A but by year, drawing the official Borang E.
- **D. Generator.** One card: Company dropdown, Payroll Period (month picker), a **Generate** button that
  downloads the file.
- **E. Staff picker wizard.** Step 1 Conditions: All Available Users / Teams / Manual Selections / Import
  Selections, a long filter list (gender, dates hired or resigned, department, grade, and so on). Step 2
  Selected Employee. Then generate.

## What AmanahKu has today

| Tab | Today | Where the logic lives |
|---|---|---|
| LHDN Form E | Built | `partials/payroll/form/form-e.blade.php`, `EaFormData` |
| Statutory Notices | Built (not a Worksy tab) | `partials/payroll/form/notices.blade.php`, `PayrollNoticeController`, `LifecycleNotices` (cp22, cp22a, cp21, socso_form2, kwsp_registration) |
| EPF Borang A | Stub | `Statutory/KwspFormA`, downloaded from Payment → Submission. `verified(): false` |
| Perkeso Borang 8A | Stub | `Statutory/PerkesoBorang8A` (SOCSO and EIS in one file). `verified(): false` |
| LHDN CP39 | Stub | `Statutory/LhdnCp39`. `verified(): true` |
| HRDF | Stub | `Statutory/HrdCorpLevyFile`. `verified(): false` |
| CP21 / CP22 / CP22A | Stub | Opened and filed through Statutory Notices, no form view |
| PCB II | Stub | `payroll.notices.pcb2ii`, only reachable from a CP22A notice |
| Borang SIP 2 | Stub | Nothing |
| Zakat | Stub | `payslips.zakat` per run, `salary_structures.zakat_monthly`, TP1 zakat. No zakat authority per employee |
| EPF BBCD | No tab | Nothing |
| Perkeso Monthly SIP (EIS) | No tab | EIS is already inside `PerkesoBorang8A` |

## Tab list after this work

Worksy's order, with Statutory Notices kept at the end (it is where hires and leavers are tracked and it has
no Worksy equivalent):

`sip2`, `borang-a`, `bbcd`, `cp21`, `cp22`, `cp22a`, `form-e`, `pcb2`, `cp39`, `perkeso-sip`, `borang-8a`,
`zakat`, `hrdf`, `notices`. Labels use Worksy's text ("LHDN CP21", "Zakat Form", "HRDF Form", ...).
Old `?tab=` values keep working because the keys don't change, except for the new ones.

A tab not built yet keeps the existing `partials/payroll/stub` card, so the order can ship in phase 1.

## Phase 0: Company Settings, statutory numbers reachable and complete

Every agency file prints the employer's numbers from `tenants` (`employer_tin`, `epf_employer_no`,
`socso_employer_code`, `hrdf_registration_no`), and on the live data all four are empty. That's hard to fix today
because:

- **Company Settings (`settings` screen) has no sidebar link.** `App\Support\Amanahku` removed it on purpose as a
  one-time config screen reached through Company Setup. But the only ways in are the individual wizard steps' Edit
  buttons (modules, profile, work week, branches, and so on) or typing `/app/settings`. After setup is finished
  nothing points at it.
- **The statutory fields are buried.** "Statutory registration" is a sub-heading at the bottom of the Workspace
  profile card, saved by the same "Save changes" button as the logo and welcome message.
- **No setup step asks for them,** so a new company can finish setup and run payroll with blank employer numbers.
  The first sign of trouble is PERKESO or LHDN rejecting the file.

Build this before phase 1, since phase 1 puts these files on screen.

### 0.1 Company Setup links to Company Settings for good

- The Company Setup screen (`setup`) gets a **"Company Settings"** card at the top, always shown, **before and
  after** setup is finished. It lists the setting areas as links (Workspace profile, Statutory & tax, Work week,
  Branches, Departments, Staff levels, Employment types, Features, Dashboard touches), each opening
  `settings` at that card (`?section=<key>`, the same way `section=work_week` works today).
- The sidebar stays as it is (no new row). "Company Setup" is the one place admin config lives, and it stays reachable
  after launch because its sidebar row already is.

### 0.2 New setup step: company statutory numbers

- New `SetupController` step `statutory` in the `payroll` domain: label "Company statutory numbers" /
  "Nombor berkanun syarikat". Screen `settings`, query `section=statutory`, `auto: true`, shown when the payroll
  module is on. Built with `critical: false`: a critical step is listed under "Staff are locked out until these are
  done", which isn't true for this one, and a payroll run already refuses to start without these numbers
  (`PayrollReadiness::employerGaps`, which the step reuses).
- Done when `employer_tin`, `epf_employer_no` and `socso_employer_code` are all filled, plus
  `hrdf_registration_no` when the HRDF feature is on.
- Guide text: "On Company Settings, open Statutory & tax, fill in your LHDN E number, KWSP employer number and
  PERKESO employer code, and click Save statutory details."

### 0.3 Company Settings screen polish

- Move "Statutory registration" out of Workspace profile into its own card, **Statutory & tax**
  (`section=statutory`), with its own **Save statutory details** button. It saves through a separate action so a
  statutory save can't wipe profile fields, and the reverse. Writes an audit entry listing which numbers changed.
- Fields, following Worksy's Company → Statutory tab (one block per agency):

  | Block | Field | Existing column | Notes |
  |---|---|---|---|
  | LHDN | Employer number (E) | `employer_tin` | shown with a fixed `E` prefix, as the forms print it |
  | LHDN | Employer category, status | `employer_category`, `employer_status` | already on the form, move here |
  | KWSP | Employer number | `epf_employer_no` | |
  | PERKESO | Employer code (SOCSO & EIS) | `socso_employer_code` | e.g. `B3200012345Z` |
  | HRD Corp | Registration / MyCoID | `hrdf_registration_no` | block only shown when HRDF is on |
  | Zakat | Employer number | **new** `tenants.zakat_employer_no` nullable string(40) | moved up from phase 3 |
  | Forms | Signatory | **new** `tenants.statutory_signatory_employee_id` nullable FK | the name and designation printed on CP21 / CP22 / CP22A / PCB II (Worksy: "Signature Name") |
  | Forms | Employer address, phone | read-only copy of Workspace profile | with an "Edit in Workspace profile" link. LHDN forms and text exports read the profile, so there's one address, not one per agency (see the CP22A finding) |

- Checks: keep today's rules (letters, digits and dashes, max 40), strip spaces and uppercase. Show a soft
  warning, not an error, when a number doesn't look like the usual shape (E number: 10 digits; KWSP: 9 digits;
  PERKESO: 12 characters starting with a letter). Formats vary for older registrations and a wrong block would
  stop payroll.
- Settings screen header text changes from "Workspace profile, branches and departments." to one that mentions
  statutory details.

### 0.4 Missing numbers stop a file, not the upload

- On Payment → Submission (and the phase 1 tabs), a statutory file whose employer number is empty shows its button
  disabled with "Set your PERKESO employer code in Company Settings", linking to `settings?section=statutory`.
  Same for KWSP (`epf_employer_no`), CP39 (`employer_tin`) and HRD Corp (`hrdf_registration_no`).
- `PayrollExportController::statutoryFile` refuses the download with a 422 and the same message, so a direct URL
  can't produce a file with a blank employer column.

### Tests

The setup step turns done only when the required numbers are there (and HRDF only counts when the feature is on).
Saving statutory details leaves profile fields untouched and writes an audit entry. The Company Settings card on
Company Setup shows after setup is completed. Each statutory download is refused with 422 while its employer number
is empty. Only HR and Management can save. Tenant isolation.

## Phase 1: wire the four monthly files (pattern A and D)

Scope: EPF Borang A, Perkeso Borang 8A, HRDF (pattern A) and LHDN CP39 (pattern D). No new maths.

- One shared partial `partials/payroll/form/monthly-file.blade.php` takes a `StatutoryFile` key.
  - Pattern A: month arrows and picker (reuse the `?period=` style from Payment → Submission), then an
    on-screen table of the rows the file would contain (employee, IC, wage, employee share, employer share,
    total), with a totals row. The `⋮` menu has **Print** (browser print of the table with a print
    stylesheet) and **Download file** (the existing `payroll.export.statutory-file` route).
  - Pattern D (CP39): Payroll Period picker plus **Generate**, which calls the same download route.
  - AmanahKu has one company per tenant, so the Worksy company list is left out (one line: "Worksy's company
    list only matters for multi-company groups").
- Month to run: the finalized monthly run for that period, merged the same way Payment → Submission merges
  it (`Statutory/MergedPayslips`). No finalized run for the month: Worksy's empty text, "There is no record
  for <Mon YYYY>."
- The rows come from a new `rows(Collection $payslips): array` method on `StatutoryFile` so the screen and
  the file can't disagree. Each of the four classes already builds these values inside `render()`; pull that
  into `rows()` and have `render()` use it.
- `verified(): false` files show the same "check layout" pill they show on Payment → Submission.
- Perkeso Monthly SIP (EIS): gets its tab in phase 1 using pattern D, generating the same `PerkesoBorang8A`
  file, because ASSIST takes SOCSO and EIS in one upload. Research findings confirm it is the same combined file.
- Roles: same as Payment → Submission (hr and management). Download already writes an audit entry, keep it.

Tests: one feature test per tab (renders rows for a finalized month, empty state for a month without a run,
tenant isolation), and a unit test that `rows()` totals equal the numbers in `render()` for each file.

## Phase 2: staff forms (pattern B)

Scope: CP21, CP22, CP22A, PCB II.

- Shared partial `partials/payroll/form/staff-form.blade.php`: staff list on the left (All / My Staff, search
  by name or staff ID, reuse `transaction/staff-picker.blade.php` if it fits), `‹ ›` year arrows, the selected
  employee's form on the right.
- Which staff show up per form:
  - CP22: hired in the selected year.
  - CP22A / CP21: last working day in the selected year.
  - PCB II: anyone with a CP22A in the selected year (Worksy lists everyone; we can list everyone too and
    show "No CP22A for this person" when there's nothing to print).
- The form on the right is the official LHDN layout drawn in HTML (boxes for IC, employer number and dates, as
  in `wf-cp22a-form.png`), filled from the employee record, the tenant's statutory profile and, for CP22A and
  PCB II, `EaFormData`. The official PDFs go into `docs/statutory/` before building (see Needs from HR).
- Buttons, as Worksy has them:
  - **Edit** (CP22A, PCB II): lets HR type over fields the system can't know (for example, reason for leaving
    or new address). Stored per employee per form per year in one new `payroll_form_overrides` table
    (`tenant_id`, `employee_id`, `form`, `year`, json `fields`). Changing one writes an audit entry.
  - **Batch Export PDF** (CP22A): one PDF with a page per listed employee, using the existing payslip PDF
    renderer.
  - **Batch Export Text Files** (CP22, CP22A): only if LHDN's e-CP22 / e-CP22A text layout is available
    (see Needs from HR). Otherwise the button shows greyed out with "not available yet", the same way the
    process wizard does.
- Statutory Notices stays as the tracker (due dates, filed on, LHDN clearance). Each notice row gets a
  "View form" link to its tab with the employee selected.

Tests: per form, list filtering by year, filled fields for a sample employee, the Edit override taking
priority and being audited, tenant isolation, Batch Export PDF page count.

## Phase 3: forms that don't exist yet

### Zakat Form (pattern A plus authority dropdown)

- The company's zakat employer number comes from phase 0 (`tenants.zakat_employer_no`).
- New nullable `salary_structures.zakat_authority` (one of the 14 state bodies in Worksy's dropdown: Johor,
  Kedah, Kelantan, Kuala Lumpur, Labuan, Malacca, Negeri Sembilan, Pahang, Penang, Perak, Perlis, Sabah,
  Sarawak, Selangor, Terengganu, Putrajaya). Set on the staff statutory profile next to `zakat_monthly`.
- Screen: month plus authority dropdown, table of staff with zakat deducted that month for that authority
  (`payslips.zakat` from the finalized run), total, Print. Staff with zakat but no authority are listed under
  "No zakat authority set" so nobody is missed.
- The file layout differs by authority. First version is the on-screen listing and a CSV. Real per-authority
  files wait for their specs (see Research findings).

### EPF BBCD Form (pattern A)

Needs KWSP's BBCD layout before it can be built (see Research findings). Stays a stub until then.

### Borang SIP 2 (pattern E)

- Step 1 Conditions: All Available Users / Manual Selections (Teams and Import greyed out, "not available
  yet"). Filters: date hired range, department, employment type. Step 2 Selected Employee, then a PDF of
  Borang SIP 2 per person, filled from the employee record (SIP 2 is EIS registration, see Research findings).
- Form is in `docs/statutory/perkeso-borang-sip-2-pendaftaran-pekerja.pdf` (Dec 2017).

## Out of scope

- A company list or multi-company picker.
- Uploading anything to KWSP, PERKESO, LHDN or HRD Corp. All files are downloaded and uploaded by hand.
- Changing any payroll maths.


## Research findings (2026-09-24)

Official documents were downloaded into `docs/statutory/`. What they change in this spec:

### Urgent, outside this screen: PERKESO file changes on 1 October 2026

`perkeso-text-file-format-v2.1-2026-02.pdf` (13 Feb 2026) is the one combined SOCSO + EIS + SKBBK upload file
for ASSIST 2.0. `perkeso-faq-text-file-format-en.pdf` says the old one-Act-per-file formats are accepted
only until **30 September 2026**. From **1 October 2026** this format is the only one accepted.

- The layout is fixed width, 278 characters per line:
  - employer code 1-12, MyCoID/SSM 13-32, IC 33-44, name 45-194 (150 chars), month `MMYYYY` 195-200, wages in cents 201-214;
  - SOCSO employer 215-220, SOCSO employee 221-226, EIS employer 227-232, EIS employee 233-238, SKBBK employee 239-244 (each 6 digits, cents, right justified);
  - blank filler 245-278.
- `PerkesoBorang8A` today writes about 120 characters in a layout of our own: 45-char name, 8-digit wages, no SKBBK. The September 2026 payroll gets submitted in October, so it must go out in the new layout.
- **Done 2026-09-24:** `PerkesoBorang8A` rewritten to v2.1, golden file hand-typed, `verified()` true. Contribution month is now the wage month (it was the month after). The spec only calls it "contribution month", so check the first real upload.
- The FAQ says SKBBK gets added into the SOCSO employee share, but the spec gives it its own field 11. The spec wins: fill field 11 from `payslips.skbbk_employee`.
- The spec PDF says "confidential, not to be redistributed without PERKESO's consent", and this repo is public. Keep that PDF out of git (it's listed in `.gitignore`), and describe the layout in `docs/statutory/README.md` in our own words.

### Forms that changed meaning

- **EPF BBCD** is "Borang Bayaran Caruman Bulanan – Disket" (KWSP 6A), the cover form for sending Form A on diskette. Contributions now go through i-Akaun, so it's a legacy form. The BBCD tab shows a printable KWSP 6A-style cover sheet (employer number, month, total employee count and amount) once the form is in `docs/statutory/`. Until then it's a stub.
- **Borang SIP 2** is EIS *employee registration*, not a loss-of-employment notice (`perkeso-borang-sip-2-pendaftaran-pekerja.pdf`; SIP 2A is the notice form). When someone leaves, the stop date is keyed into ASSIST. That means phase 3's SIP 2 wizard lists **new hires** (date hired in range), not leavers. The SIP 2 form fills from the employee record. This overlaps with the `socso_form2` notice that `LifecycleNotices` already opens on hire, so link that notice to the tab.
- **Perkeso Monthly SIP (EIS)** is the same combined v2.1 file. Its tab generates `PerkesoBorang8A` (settled, no longer an open question).
- **HRD Corp** has no employee upload file. The levy is paid online in eTRiS by picking the month and payment type. `HrdCorpLevyFile` is an internal worksheet, so the HRDF tab shows it as a printable levy summary (headcount, wages, levy). Label the download "Levy worksheet", not an upload file.

### LHDN notices are online-only

- CP22 goes through e-CP22 on MyTax (since 1 Sep 2024). CP21, CP22A and CP22B go through e-SPC on MyTax (since 1 Jan 2024). Paper forms aren't accepted.
- Phase 2's on-screen forms (`cp21-2025.pdf`, `cp22-2021.pdf`, `cp22a-2023.pdf`, `pcb-2ii-2012.pdf`) are for **records and for copying into MyTax field by field**. Keep them in phase 2, since Worksy has them. The batch **text export** is what saves HR the typing.
- **CP22A is only required when the leaver is not on PCB.** `LifecycleNotices` opens one for every leaver. Change it to open one only if the leaver had no PCB deducted in their final year of pay, and show everyone else as "not required, on PCB". This is a small fix, done with phase 2.
- The e-CP22 and e-SPC upload layouts are only visible inside MyTax after an employer login. Until HR supplies them, the text export buttons show greyed out with "not available yet".

### Form E / CP8D

`cp8d-information-layout-2025.pdf` (Pin.2025, has a text layer) replaces the scanned older copy as the source for
the CP8D text file: pipe-delimited `.txt`, 22 fields, file named `P<E-number>_<year>.txt`. Check `Cp8dData`
against it with Form E work; out of scope here.

### Zakat

There's no standard file. Johor (MAIJ) wants a list of name, old and new IC, and amount, sent with payment. Kedah (LZNK)
wants an Excel of name, IC, amount, phone and employer address, emailed or sent through MyMajikan. Selangor (LZS e-Majikan) and
WP (PPZ MyMajikan) have templates behind an employer login. Phase 3's listing plus CSV (name, IC, amount, authority)
covers Johor and Kedah as is. LZS and PPZ get their own column order once HR sends the templates.

## Found in Worksy (2026-09-24, second pass)

- **Employer numbers:** Worksy holds URSB's LHDN, KWSP, PERKESO, HRDF (levy 1%) and zakat employer numbers, the
  paying bank account, and the signatory for the LHDN forms. They're left out of this file because the repo is
  public. HR has them, and they go into Company Settings → Statutory & tax on each environment.
  In the local dev DB, `tenants.employer_tin`, `epf_employer_no`, `socso_employer_code` and `hrdf_registration_no` are all
  null, so every exporter prints a placeholder today. HR enters these in AmanahKu's statutory settings (don't seed prod
  from here). AmanahKu has no field for a zakat employer number yet, so add one with phase 3.
- **Why the monthly forms looked empty:** Worksy has two companies (URSB and a DEMO one). Setting → Payroll → Payroll
  Payment Submission gives each company its own admin list, and the login used isn't one of URSB's admins. Someone who
  is an admin there would see the filled-in forms.
- **CP22 / CP22A Batch Export Text Files:** a side drawer with Year, then File Type **"Text File A"** or **"Text File B"**.
  Next goes to the same Condition → Selected picker as SIP 2 (All Available Users / Teams / Manual / Import, plus
  filters), then **Download**. So Worksy produces two text layouts. Mirror that drawer in phase 2. Downloading the sample
  failed because the automated browser crashed on the Download click, so the file layout is still unseen.

- **CP22A Text File A export, tried by hand:** the `.txt` came out empty, plus an `error__CP22A.txt` listing every
  employee and the fields they're missing. Every row failed, because the company's **address and postcode are blank** in
  Worksy's Statutory settings. The error list is the field set Worksy's CP22A text file needs. Build phase 2's export
  on this list: check it before writing, and hand back the same kind of "who is missing what" report instead of a broken file.
  - Employer: `employer_num_tax` (E number), `employer_address_1`, `employer_postcode`, `employer_abroad_indicator_tax`.
  - Employee: `identification_type_tax`, `full_name`, `tax_num` (TIN), `date_of_birth`, `date_resigned`,
    `marital_status_tax`, `phone_num`, `email`, `address_1`, `postcode`, `abroad_indicator`.
  - Spouse, only when married: `spouse_identification_type_tax`, `spouse_ic_num`.
  - Most staff have no TIN or email in Worksy. So HR also has to collect staff tax numbers before any CP22A file can be
    produced, in either system.
  - The error file names real staff, so it stays out of git (kept in `~/Downloads`).

- **Filled-in staff forms** (screenshots `.playwright-mcp/ws-{cp21,cp22,cp22a,pcb2}-form-filled.png`, gitignored
  because they show real IC and tax numbers):
  - All four have an employee header above the form: photo, name, phone, position, status pill, schedule, and "Currently Reporting To".
  - **CP21 and CP22A** are drawn in HTML (boxes per character for E number, dates, IC). Filled from the company profile
    (name, address, phone), the E number, and the employee record (name, IC, start date, address). Leaving date and
    reason stay blank until HR fills them through **Edit**.
  - **CP22** is different: the **official LHDN CP22 PDF (Pin.1/2021, 2 pages) filled in**, shown in an embedded PDF
    viewer with download and print. Sections A (employer) and B (employee: TIN, IC, citizenship, gender, date of birth, marital
    status, phone, both addresses, start date, designation) come from the records.
  - **PCB II** is the "Penyata Bayaran Cukai oleh Majikan" letter, PCB 2(II)-Pin 2012, dated today. It shows the year,
    name, IC, tax file number, staff number and E number, then a January to December table with PCB and CP38 amounts
    and, for each, **receipt/slip/transaction number and receipt date**. In Worksy those are blank.
    AmanahKu's `PayrollSubmission` (spec F12) already records PCB filings, so fill the receipt columns from it.
- **Employer address is known:** the forms print it from the company profile. The Statutory tab's own address fields
  are what's blank, and the CP22A text export reads those. In AmanahKu, have the text export fall back to the company
  address so HR doesn't have to type it twice.

## Needs from HR

0. ~~Company address~~: on Worksy's company profile, only blank in its Statutory tab (see above).
1. ~~**Company numbers**~~: found in Worksy (Setting → General → Company → URSB → Statutory), see
   "Found in Worksy" above. HR only has to confirm them and enter them in AmanahKu.
2. **MyTax (employer login):** the e-CP22 and e-SPC (CP22A/CP22B/CP21) bulk upload text layouts.
3. **KWSP:** "Easy Guide Preparing CSV File for e-Caruman" (June 2020) and the KWSP 6A (BBCD) form. They're
   public, but kwsp.gov.my blocks scripts. Opening these in a normal browser gets them:
   `https://www.kwsp.gov.my/documents/d/guest/easy-guide_preparing-csv-file-for-e-caruman` and
   `https://www.kwsp.gov.my/documents/d/guest/kwsp_6a_1-2`. This lets `KwspFormA` be verified.
4. **Zakat portals:** the LZS e-Majikan and PPZ MyMajikan upload templates, if the company pays those bodies,
   and which state bodies the company deducts for.
5. **One real accepted upload file from each portal** (KWSP, PERKESO, LHDN CP39), numbers can be blanked, to
   check our exporters against.
6. **Worksy:** a login or company in Worksy that has payroll data, to capture the filled-in forms.

## Build status (2026-09-24)

All four phases are built on `dev`, uncommitted. Tests: `EmployerStatutoryIdentityTest`, `PayrollFormMonthlyFilesTest`,
`PayrollFormStaffFormsTest`, `PayrollFormZakatSip2Test`, plus updates to `PayrollExportTest`, `EaFormPdfTest` and
`PayrollNavigationTest`. The full suite passes.

Where the build differs from the plan above:

- **Phase 0:** the Setup wizard's statutory step is not critical (a payroll run is already blocked without the numbers).
- **Phase 2:**
  - PCB II lists everyone paid in the year, not only people with a CP22A.
  - Edit and Batch Export PDF are on all four forms, not only CP22A and PCB II.
  - An edit keeps only the values that differ from the records (`payroll_form_overrides`), so later record changes
    still come through on fields nobody typed over.
  - The batch PDF uses its own `pdf.staff-forms` view (the same `lhdn-form` partial the screen draws), not the
    payslip renderer.
  - Batch Export Text Files is greyed out, waiting on LHDN's e-CP22 / e-SPC layouts.
  - CP22A now opens only for a leaver with no PCB deducted in their final year of pay. Anyone else shows
    "Not required, on PCB" on the CP22A tab.
  - Statutory Notices rows link to the matching form. PERKESO Form 2 links to the SIP 2 tab.
- **Phase 3:**
  - Zakat authority is a new `salary_structures.zakat_authority`, set in the profile's Bank & Statutory form.
    There are 16 bodies, the ones Worksy lists.
  - The Zakat tab gives the listing, Print, and a CSV (`payroll.export.zakat`, audited).
  - SIP 2 lists active staff hired in the date range, with ten to a page in a landscape PDF (`payroll.sip2.pdf`).
    Teams and Import are greyed out.
  - BBCD stays a stub until the KWSP 6A form is in `docs/statutory/`.
