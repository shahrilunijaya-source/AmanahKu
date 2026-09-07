# Global Clause – Audit & Data Integrity

Applies to EVERY CR. This is a cross-cutting section of the tracker, not a numbered CR.

**Run status:** built in session S01, before any feature work. Contract file: `contracts/audit-log.md`.

## Immutable audit log
All changes to Event schedules (work-item due dates are locked and cannot change), priority, assignee / Primary Owner, tagged roles, status, completion date, attendance and clock records, timesheet lines and approvals, nominations, approvals, deletions, archiving, cancellations, reassignments, nudges, comment push/withdraw, project master versions, recurring schedules and manual award selections must be written to an **append-only** audit log recording:

- previous value
- new value
- actor
- timestamp (Asia/Kuala_Lumpur)
- reason (where required)
- source (UI / API / sync job)

Audit entries cannot be edited or deleted by any role; retention minimum 7 years; exportable by HR/Director.

## Frozen award data
Award calculations (CR-14) use approved data only, frozen as a snapshot at 11:59 PM on the final calendar day of the month. Data approved or changed after the freeze applies to the next month, not retroactively. Any recalculation or manual override of results must be authorised (Director), recorded with reason and visible on the Awards page ('Result adjusted – <reason>').

## Ownership
Every task, subtask, event and recurring schedule has one accountable Primary Owner (person or role). Roles resolve to the current holder; off-boarding routes open items via HR.

## Acceptance
1. Change a due date, audit shows old date, new date, actor, time, reason; entry cannot be edited.
2. Approve a timesheet day on 1 Oct for September, it counts in October's freeze, not September's.
3. Director overrides an award result, Awards page shows the adjustment note and audit entry.
