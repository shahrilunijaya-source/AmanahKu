# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Employee** (all staff): clock in/out, apply leave, submit a weekly timesheet, file a claim, see their own payslip.
- **Manager**: everything an employee has, plus *verifying* their direct reports' requests.
- **Management / Director**: *final approval* on verified requests, company-wide reporting.
- **HR** (HR + finance): staff records, leave setup, payroll runs, company settings.
- **Super-admin**: platform operator who provisions companies and sets entitlements. Not a company role.

## Product Purpose

Amanahku replaces HR run on paper, WhatsApp and spreadsheets for Malaysian SMEs. Leave balances, approvals and attendance become measured records instead of hand-kept ones. Success is a small company running its whole HR day (attendance, leave, timesheets, claims) without a spreadsheet on the side.

## Positioning

Built for Malaysian statutory rules (EPF, SOCSO, EIS, PCB) and small-company prices, where commercial HR suites are either too expensive per seat or not local.

## Operating Context

- Multi-tenant: one deployment, many companies, row-level scoping. Unijaya Resources is the first tenant; a user can belong to several companies with a different role in each.
- Approval chain: a manager verifies, then a director gives final approval.
- Attendance is geofenced. Expected hours come from the employee's branch (office), client site, or the company WFH policy.
- Unijaya-specific: the first Saturday of each month is a half working day (the TOT Saturday).

## Capabilities and Constraints

- Every visible string is bilingual, English and Bahasa Melayu.
- Responsive web only, no native app. Verified down to 390px.
- Shared hosting: no long-running processes, no Node on the host, cron only through hPanel.
- NRIC is PII and is encrypted at rest.
- Modules toggle per company; `Features::OFF` in `app/Support/Features.php` is the authoritative list.

## Evidence on Hand

Real production data exists (Unijaya). No testimonials, case studies or published benchmarks; do not invent them.

## Product Principles

1. Records over trust: anything that affects pay or leave leaves an audit trail.
2. Defaults that fit a small Malaysian company, overridable per company.
3. One clear action per screen.
