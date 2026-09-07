# S01 contract: global-clause (audit log)

## Files

- `database/migrations/2026_09_07_200000_add_change_columns_to_audit_logs.php` (new)
- `app/Models/AuditLog.php`: `change()`, append-only guard, new fillable/casts
- `app/Support/AuditContext.php` (new): request-scoped `reason` and `source`
- `app/Models/Concerns/AuditsChanges.php` (new): model trait, writes one row per audited field on create/update/delete
- `app/Models/WorkItem.php`: use the trait, list audited fields
- `app/Models/AttendanceRecord.php`: use the trait for admin edits and punches
- `app/Http/Controllers/WorkItemController.php`: accept `reason` on update; audit participant sync
- `app/Http/Controllers/AttendanceController.php`: one `attendance.clock` line per successful punch
- `app/Http/Controllers/TimesheetController.php`: audit draft line saves (submit and recall already logged)
- `app/Mcp/Tools/*` : set `AuditContext::source('mcp')` in the shared MCP base, nothing else
- `app/Http/Controllers/AppController.php` + `routes/web.php`: CSV export for HR / management tier
- `tests/Feature/AuditChangeTest.php` (new): unit-level cover for the trait and guard

## Schema

`audit_logs` gains: `subject_type` (nullable string), `subject_id` (nullable unsignedBigInteger), `field` (nullable string), `old_value` (nullable text), `new_value` (nullable text), `reason` (nullable text), `source` (string, default `ui`). Index on (`subject_type`, `subject_id`). No row is touched.

## Verification per acceptance item

1. Due date set / priority change / web move + archive: `GlobalClauseTest` items 1, 1b, 1c assert a row with subject, field, JSON old/new, actor, timestamp, reason, `source = ui`, and that the row refuses update and delete.
2. Timesheet freeze month: incomplete until S17 (see OPEN). S01 does nothing for it.
3. Director award override: incomplete until S18. S01 does nothing for it.

Always checks called from this session: audit immutable, dashboard unchanged, Keep it plain.
