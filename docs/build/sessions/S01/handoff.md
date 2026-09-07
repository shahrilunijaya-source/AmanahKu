# Session S01 handoff: global-clause (audit log)

## Delivered
- Field-level audit rows with subject, old/new value (JSON), actor, timestamp, reason, source, verified by acceptance items 1, 1b, 1c.
- `audit_logs` rows refuse update and delete at the model (`AuditLog::booted()`), verified by the always check.
- Every `WorkItem` create, update of an audited field, and delete writes a row through `App\Models\Concerns\AuditsChanges`, from web, MCP (`source = mcp`, set in `ConfirmWriteTool`) and jobs alike. Archive and restore now update subtasks one by one so they are audited too.
- Participant sync on a card writes one `participants` row with before and after id lists.
- `PATCH /app/board/{id}` accepts an optional `reason`; it lands on every row that update produces.
- One `attendance_record.clock_in` / `clock_out` row per successful punch. Timesheet draft saves write `timesheet.entries` with old and new line counts; submit and recall keep their existing lines.
- CSV export at `GET /app/audit/export` (`audit.export`), HR and management tier only, tenant-scoped, times in Asia/Kuala_Lumpur, with an Export CSV button on the Audit Logs screen for those roles.
- Audit Logs screen shows `field: old → new` and `Reason: …` under each field-level row (`AppController::auditLogsData`, `AuditLog::displayValue`, `screens/audit.blade.php`). Added after QA grade F1. Legacy action-only rows render as before.
- Items 2 and 3 remain incomplete by design (award freeze S17, Awards page S18).

## Schema changes
- `audit_logs`: `subject_type`, `subject_id`, `field`, `old_value`, `new_value`, `reason`, `source` (default `ui`), index on subject. Migration `2026_09_07_200000_add_change_columns_to_audit_logs.php`. Not yet run on the dev database.

## Contracts touched
- none.

## Port calls stubbed
- none.

## Deferred
- Timesheet approve/reject audit: no approve or reject path exists in the app today (`decided_by_id` is only ever set by seeders), so there is nothing to hook. Whichever session builds the approval flow (CR-02 is done and read-only, so likely CR-17) must write `timesheet.status` rows.
- Attendance admin edits keep their existing `AuditLog::record` lines (action + target). Converting them to field-level rows was not needed for the gate; do it when S02 or an attendance CR touches that controller.
- Nudges, nominations, award overrides, project master versions, recurring schedules, event reschedules, comment push: their features do not exist yet; each owning session calls `AuditLog::change()` or uses the trait.

## OPEN, decided without Shazwan
- none new this session.

## Requested contract change (generator may not make it itself)
- none.

## Traps for the next session
- `AuditContext` is static and request-scoped. Set `reason` or `source` inside a try/finally with `AuditContext::reset()`, or the next write in the same process inherits it (queue workers, tests).
- Query-builder updates (`$query->update([...])`, `WorkItem::where(...)->update`) fire no model events and so write no audit rows. Update models, or call `AuditLog::change()` by hand.
- `AuditsChanges` skips silently when the model has no `tenant_id` and no `CurrentTenant`; a seeder that forgets `tenant_id` audits nothing rather than failing.
- Any test that counts `audit_logs` rows for a work item now sees one row per audited field per save, plus a `created` row.
- `DatabaseSeeder` backdates demo audit rows at creation now; a second `save()` on an audit row throws.
- The dev database still needs `lerd artisan migrate` before the audit screen or the board will work on this branch.
