# Amanahku — Product Requirements

What the product is, who uses it, and what is in scope. For *how* it is built see
[ARCHITECTURE.md](ARCHITECTURE.md); for what is planned see [ROADMAP.md](ROADMAP.md).

## Problem

Unijaya runs HR on paper, WhatsApp and spreadsheets. Leave balances are recalculated by
hand, approvals have no record, and attendance is trusted rather than measured. The company
is small enough that no commercial HR suite is worth its per-seat price, and Malaysian
statutory rules (EPF, SOCSO, EIS, PCB) rule out most non-local products.

## Users

| Persona | Who | Needs |
|---------|-----|-------|
| **Employee** | All staff | Clock in/out, apply leave, submit a timesheet, file a claim, see own payslip |
| **Manager** | Line manager | Everything an employee has, plus **verify** their direct reports' requests |
| **Management / Director** | Company leadership | **Final approval** on verified requests, company-wide reporting |
| **HR** | HR + finance | Staff records, leave setup, payroll runs, company settings; final approval |
| **Super-admin** | Platform operator | Provision companies, set entitlements, suspend accounts. Not a company role |

`director` is a strict super-set of `management` — it inherits every management permission
and adds nothing that management lacks except a board seat. See
[RULES.md](RULES.md#approval-chain).

## Delivered scope

Cut to six features on 2026-07-28. Everything else is built but shipped off.

**Core surfaces — always on, not toggleable:** dashboard, my-work (T.A.A. board + weekly
timesheet), people directory, attendance, administration, security.

**Toggleable modules that ship on:**

| Module | Screens |
|--------|---------|
| `module.leave` | leave, calendar |
| `module.claims` | claims, claim-approvals |
| `module.knowledge` | knowledge-bank, tot, tot-roster |
| `module.documents` | documents |
| `module.reports` | reports |
| `module.messages` | messages |

**Off by default:** 24 modules — roster, overtime, events, bookings, payroll, loans, petty
cash, benefits, wellness, performance, onboarding, probation, offboarding, compliance,
recruitment, cases, learning, expenses, helpdesk, assets, surveys, shared resources,
profile test, and AI workforce intelligence.

Off is a **default, not a lock**. A super-admin switches any of them on per company from the
platform matrix. Two of those modules need more than a flag — see
[ROADMAP.md](ROADMAP.md#reviving-a-parked-module). The authoritative list is
`Features::OFF` in [app/Support/Features.php](../app/Support/Features.php); this document
will drift, that constant will not.

## Multi-tenancy

One deployment serves many companies. Unijaya is the first; Shell Seremban 2 and Petron
Tg Lumpur exist as additional tenants. A user can belong to several companies with a
different role in each.

Companies are provisioned by a super-admin, who assigns a **category** (Stage 1 / 2 / 3)
that seeds which modules the company gets. Stage 1 is basic HR, Stage 2 adds the HR-ops
suite, Stage 3 adds AI. The seeded package is only a starting point — the resolved
per-company entitlement is the source of truth after that.

## Non-goals

- Not a payroll bureau. Payroll computes and exports; it does not file with LHDN or pay anyone.
- Not an accounting system. Claims and expenses stop at approval and export.
- Not a recruitment ATS beyond a referral log.
- No per-tenant database. One database, row-level scoping.
- No mobile app. Responsive web only.

## Constraints

- Malaysian statutory rules (EPF, SOCSO, EIS, PCB) must be correct and configurable, because
  the published rates change without notice.
- NRIC is PII and is encrypted at rest.
- Shared hosting: no long-running processes, no Node on the host, cron only through hPanel.
  These shape the deploy design — see [RULES.md](RULES.md#operational-rules).
- Bilingual English / Bahasa Melayu.
