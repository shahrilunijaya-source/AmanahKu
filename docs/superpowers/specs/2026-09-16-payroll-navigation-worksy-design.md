# Payroll navigation and workflow, matched to Worksy

Date: 2026-09-16. Decided with Shazwan after a full read-only crawl of Worksy's Payroll menu (65 pages, screenshots and page captures in `~/mockups/worksy-payroll-walk/`), and a clickable HTML mockup approved at `~/mockups/payroll-nav-mockup/index.html`.

## 1. Goal

HR is used to Worksy. AmanahKu's payroll screen should be laid out the way Worksy lays out its Payroll module: the same six groups in the sidebar, the same page names as tabs, the same order of work (enter transactions, process a month, review each slip, pay out and produce files, fill statutory forms). This phase changes navigation and workflow only. Calculation engines, routes, models and existing tests stay as they are.

Decisions made:

- Navigation and workflow match first. Full feature parity (loans, saving funds, festival bonus, Singapore, Censof, IRB audit files) is out of scope.
- Pay cycles: Month End and Bonus only. No Mid Month.
- Every Worksy page gets its slot in the nav. Pages that have a backend today work. Pages that do not show a "not yet available" card that names the follow-up spec feature (the F1 to F16 list in `2026-09-02-payroll-features-design.html`).

## 2. Navigation

`app/Support/Amanahku.php`: the `payroll` sidebar entry under Pay & Benefits gains `children`, the way `perf` and `offboarding` already do. It keeps `'landing' => true` so clicking "Payroll" itself opens a page rather than only toggling the accordion.

| Screen id | Label (EN / MS) | Who |
|---|---|---|
| `payroll` (parent, landing) | Payroll / Gaji | redirects: HR and management to `payroll-process`, everyone else to `payroll-my` |
| `payroll-my` | My Payroll / Gaji Saya | every logged-in staff member, own data only |
| `payroll-transaction` | Transaction / Transaksi | HR and management |
| `payroll-process` | Process / Proses | HR and management |
| `payroll-review` | Payroll Review / Semakan Gaji | HR and management |
| `payroll-payment` | Payment / Pembayaran | HR and management |
| `payroll-form` | Form / Borang | HR and management |

"HR and management" means the gate PayrollController already uses: `ADMIN_ROLES = ['management', 'hr']`, with director collapsing into management through `Permissions::effectiveRole`. Employees and managers who open an HR screen get 403, the same as today's payroll screen for its privileged tabs.

Feature gating: `Features::MODULES['module.payroll']` lists `['payroll']` today. It lists all seven ids after this change, so a tenant with the module off sees none of them (404, sidebar hides them) and a tenant with it on sees all. `ShippedScopeTest::OUT_OF_SCOPE` and `AllScreensRenderTest` learn the six new ids.

Screen titles and crumbs go in the same `Amanahku.php` screen map as today (`'payroll' => ['title' => …, 'crumb' => …]`), crumb `['Payroll', '<group>']`.

The wide page measure (`$wideScreens` in `layouts/app.blade.php`) gets the six new ids; the current payroll screen already uses it.

## 3. Screens and tabs

Each screen is one blade under `resources/views/screens/`, with a tab row in the same style as today's payroll tabs (Alpine `tab` state, `?tab=` deep link, tab id in the URL via `history.replaceState` so a redirect after a POST can land on the right tab). Each tab's content is a partial under `resources/views/partials/payroll/<group>/<tab>.blade.php`. Today's `screens/payroll.blade.php` is cut into those partials; nothing is rewritten that does not have to move.

Stub tabs all use one partial, `partials/payroll/stub.blade.php`, taking a title, one sentence on what the page will do, and a spec reference pill ("Spec F6", "Follow-up").

### 3.1 My Payroll (`payroll-my`)

| Tab | Content | Source today |
|---|---|---|
| Payslip | Own finalized payslips, newest first, one expanded with earnings, deductions, net, statutory bases, Download PDF. **Acknowledge** button: sets `payslips.acknowledged_at` (new nullable timestamp) for the viewer's own slip once; after that shows "Acknowledged on <date>". | non-privileged branch of `payrollData` (`myPayslips`, `selectedPayslip`), `payroll.payslips.pdf` |
| EA Form | Own EA form per year with View and PDF | `payroll.ea-form.show` and `.pdf`, restricted to own employee id |
| Personal Tax Relief (TP1) | stub, Spec F8 | none |

### 3.2 Transaction (`payroll-transaction`)

A one-line note at the top: "Bank account, EPF, SOCSO and tax numbers are on each staff member's profile, Bank & Statutory tab." with a link to the directory.

| Tab | Content | Source today |
|---|---|---|
| Fixed Transaction | Staff picker on the left (search by name or ID), the chosen person's fixed transactions on the right: add, edit, end | fixed-transaction block inside today's Salary structures tab, `payroll.fixed-transactions.*` |
| Individual Transaction | As today | Individual transactions tab |
| CP38 | stub, Spec F9 | none |
| Tax Rebate | stub, follow-up (zakat and levy offsets) | none |
| Payroll Figures Take On | As today's "Previous employment (TP3)" tab, renamed to Worksy's label. Sub-line explains "opening figures from previous employer this year". | Opening tab, `payroll.opening` |
| Personal Tax Relief (TP1) | stub, Spec F8 | none |
| Payroll Items | As today | Payroll items tab, `payroll.items.*` |

The Salary structures tab is retired. Its salary box went in the one-salary-field change; its bank and statutory fields are on the profile Bank & Statutory tab; its fixed transactions move to the Fixed Transaction tab. `PayrollController::storeSalary` and the `payroll.salary` route stay because the profile Bank tab posts to them.

### 3.3 Process (`payroll-process`)

Four stat cards at the top as today (latest run, net payout, employer cost, headcount).

| Tab | Content | Source today |
|---|---|---|
| Monthly | New-run card: period, payment date, pull ticks (approved overtime, unpaid leave, approved claims), then Create. Below it the runs list (period, cycle, status, staff, net, links to Review and Payment). | Runs tab create form and runs list, `payroll.runs.store` |
| Bonus | stub, Spec F10 | none |
| Payroll Control | stub, follow-up (lock or unlock a month, remark, attachment, activity log) | none |

The cycle column shows "Month End" for every run today; `payroll_runs.kind` (F10) will add Bonus later.

The "Selected Employees" step of Worksy's wizard is folded into Create: a run takes every active staff member with a salary, as today. Worksy's filter drawer is not built.

### 3.4 Payroll Review (`payroll-review`)

| Tab | Content | Source today |
|---|---|---|
| Individual Payroll | Run picker (defaults to the latest run), staff picker on the left, the chosen person's payslip on the right with line edits, add transaction, statutory bases, net. | Active-run payslip editor in the Runs tab, `payroll.payslips.update` |
| Batch Remove Payslip | stub, follow-up | none |
| EA Form | Staff list with View and PDF per person for a chosen year | `payroll.ea-form.show`, `.pdf` |
| Bulk EA Form | Year and company, Generate | `payroll.export.ea-forms` |

### 3.5 Payment (`payroll-payment`)

| Tab | Content | Source today |
|---|---|---|
| Payout Management | Year picker, runs of that year as rows: month, status, staff, net, payment date, actions Approve, Finalize, Delete (same gates and confirms as today), link to files. | Run actions in the Runs tab, `payroll.runs.approve`, `.finalize`, `.delete` |
| Bank/Statutory Submission | Run picker, totals row (net, EPF, SOCSO, EIS, PCB, total), download buttons: Bank file, Statutory report. Disabled buttons for KWSP Form A, PERKESO 8A, LHDN CP39 with the F6 pill. | `payroll.runs.bank-file`, `payroll.runs.statutory-report` |
| Individual Pay Slip | Run picker, staff rows with net and PDF | `payroll.payslips.pdf` |
| Bulk Pay Slip | Run picker, Download all | `payroll.runs.payslips-pdf` |
| LHDN CP8D | Year, Generate | `payroll.form-e.cp8d` |
| IRB Audit Files | stub, follow-up | none |

### 3.6 Form (`payroll-form`)

| Tab | Content |
|---|---|
| LHDN Form E | Year, View, PDF (`payroll.form-e.show`, `.pdf`) |
| EPF Borang A, Perkeso Borang 8A, LHDN CP39 | stubs, Spec F6 |
| CP21, CP22, CP22A, Borang SIP 2 | stubs, Spec F11 |
| PCB II, Zakat | stubs, follow-up |
| HRDF | stub, Spec F7 |

## 4. Redirects and links

- Every `redirect()->route('app.screen', 'payroll')` in PayrollController and friends changes to the group screen that owns the action, with `?tab=` set: run create, approve, finalize, delete to `payroll-payment?tab=payout` (create to `payroll-process?tab=monthly`), payslip update to `payroll-review?tab=individual&run=…&payslip=…`, fixed and individual transaction writes to `payroll-transaction?tab=…`, opening to `payroll-transaction?tab=takeon`, items to `payroll-transaction?tab=items`, salary (from the profile) stays on the profile as today.
- Profile Money tab links that point at `/app/payroll?payslip=` point at `payroll-my?payslip=`.
- Dashboard or search links to `/app/payroll` keep working through the landing redirect.

## 5. Data changes

One migration: `payslips.acknowledged_at` nullable timestamp. Nothing else.

## 6. Bilingual

Every new label has EN and MS through the `$L` helper. MS labels: Gaji Saya, Transaksi, Proses, Semakan Gaji, Pembayaran, Borang; tab names keep Worksy's English form names (Borang A, CP39, EA Form) in both languages since those are the official names.

## 7. Audit

Acknowledge writes an audit row "Acknowledged payslip". Every other write already audits; moving a form does not change that.

## 8. Testing

Existing payroll tests keep passing untouched except where they assert a redirect target; those assertions change to the new screen and tab.

New tests, `tests/Feature/PayrollNavigationTest.php`:

- HR opens each of the six screens: 200, and each tab's heading text is present.
- Employee opens `payroll-my`: 200 with own slips; opens the five HR screens: 403.
- Manager opens the five HR screens: 403.
- Parent `payroll` redirects HR to `payroll-process` and an employee to `payroll-my`.
- Every stub tab renders the "Not yet available" card with its spec pill.
- Acknowledge sets `acknowledged_at` once, refuses a second post, refuses another person's slip.
- Tenant with `module.payroll` off: all seven ids 404 and the sidebar shows none of them.

`ShippedScopeTest` and `AllScreensRenderTest` lists updated.

## 9. Out of scope

Loans, saving funds, festival bonus, custom period, Singapore twins, Censof, Pay Slip Integration, Bermaz freeze pages, Worksy's 30-criteria filter drawer, Mid Month cycle, and the backend behind every stub. Each stub is its own follow-up in the F1 to F16 order.
