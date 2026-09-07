# Contract: audit log

Frozen by S00. S01 builds it; every later session writes to it.

## What exists today

`audit_logs` (`database/migrations/2026_06_23_000009_create_handbook_admin.php`): `tenant_id, user_id, actor_name, action, target, created_at`. Written only through `AuditLog::record(string $action, ?string $target)` (`app/Models/AuditLog.php`), plus five direct `AuditLog::create` calls on the super-admin side. Read on the Admin screen, latest 50. No update or delete path exists anywhere, but nothing enforces that. No before/after, no reason, no source.

Coverage today: leave, claim, overtime, payroll, attendance admin, timesheet submit/recall, staff archiving, and every MCP board tool. Not covered: every board write made through the web UI (`WorkItemController` update / move / assign / archive / restore).

## What S01 adds, and every session honours

Extend the same table, do not create a second ledger:

| Column | Type | Rule |
|---|---|---|
| `subject_type`, `subject_id` | nullable morph | the row that changed |
| `field` | nullable string | column or logical field name (`due_at`, `status`, `employee_id`, ...) |
| `old_value`, `new_value` | nullable text | JSON-encoded scalars; null when not a field change |
| `reason` | nullable text | required where the spec says "reason" (due-date-related cancellations, overrides, reversals) |
| `source` | string, default `ui` | `ui`, `api`, `mcp`, `job`, `sync` |

API: `AuditLog::change(Model $subject, string $field, mixed $old, mixed $new, ?string $reason = null, string $source = 'ui')` beside the existing `record()`. `record()` keeps working unchanged. A model event or a small `AuditsChanges` trait may drive it, but the write path must stay `AuditLog`.

Append-only, enforced: `AuditLog` model refuses `update`, `delete`, `forceDelete` (throw), and the migration adds no route that touches rows. Retention: nothing prunes the table. Export: HR and management tier, CSV, tenant-scoped.

Timestamps stored UTC as the app already does; displayed and exported in `Asia/Kuala_Lumpur`.

## Changes that must produce an entry (Global Clause)

Work item: due date (set at creation only after S02), priority, `employee_id`, participants and their role, `reviewer_id`, status, completion, archive/restore, cancellation, reassignment, nudge, subtask add/remove. Attendance and clock records: every admin edit or reversal (clock punches themselves stay in `attendance_records` + `attendance_attempts`, and S01 records a single `attendance.clock` audit line per successful punch). Timesheet: line save, submit, recall, approve, reject. Leave / claim / overtime: verify, approve, reject, cancel (already done). Nominations, manual award selections, award overrides. Project master versions, recurring schedules, event reschedules. Comment push/withdraw once ports exist.

The super-admin observer exception in `record()` stays.
