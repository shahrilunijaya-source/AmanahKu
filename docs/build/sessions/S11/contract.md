# Session S11 contract: CR-09 (TOT sessions: chair, attendance, slots, Tindakan)

Shapes are frozen by `docs/build/OPEN.md` "QA / CR-09 / shapes fixed by CR09Test". This
session implements exactly those shapes; nothing here is a new decision.

## Files touched

### Migrations (new)
- `database/migrations/2026_09_16_100000_add_chair_and_nota_to_tot_sessions.php` — `tot_sessions` gains `chair_employee_id` (FK employees, nullOnDelete, nullable), `nota_url` (string 500, nullable), `next_agenda` (text, nullable).
- `database/migrations/2026_09_16_100100_create_tot_slots_tables.php` — creates `tot_slots` and `tot_slot_presenter`; backfills one slot (position 1, kind `pembentangan`) for every session with a non-null `title` and no slot yet, copying `tot_session_presenter` rows across as slot presenters (support = false).
- `database/migrations/2026_09_16_100200_add_slot_id_to_tot_comments_and_reactions.php` — nullable `slot_id` FK `tot_slots` cascade on `tot_comments` and `tot_reactions`.
- `database/migrations/2026_09_16_100300_create_tot_attendance_table.php` — `tot_attendance` table.
- `database/migrations/2026_09_16_100400_create_tot_actions_table.php` — `tot_actions` table.

### Models
- `app/Models/TotSlot.php` (new) — `BelongsToTenant`, `session()`, `presenters()` (pivot `tot_slot_presenter`, `support` pivot column), `presenterList()`/`presenterLabel()` mirroring `TotSession`, `comments()`, `reactions()`, `actions()`.
- `app/Models/TotAttendance.php` (new) — `BelongsToTenant`, `session()`, `employee()`.
- `app/Models/TotAction.php` (new) — `BelongsToTenant`, `session()`, `slot()`, `owner()`, `workItem()`.
- `app/Models/TotSession.php` (edit) — add `chair()`, `slots()`, `attendance()`, `actions()` relations, `attendanceSummary()` and `previousAgenda()` helpers.
- `app/Models/TotComment.php`, `app/Models/TotReaction.php` (edit) — add `slot()` relation.

### Controller / routes
- `app/Http/Controllers/TotController.php` (edit) — extend `screenData()` to eager-load slots/attendance/actions/previous agenda; extend `update()` to accept `chair_employee_id`, `nota_url`, `next_agenda` (gated to `canManageSession()`); add `storeSlot`, `updateSlot`, `destroySlot`, `slotComment`, `slotComments`, `slotReact`, `storeAttendance`, `storeAction`, `createActionCard`, plus private helpers `canManageSession()` (PRIVILEGED_ROLES + `manager` + session chair) and `canCreateTaaCard()` (`manager`/`hr`/`management`/`director` + tindakan owner) and `assertSlotBelongsToSession()`.
- `routes/web.php` (edit) — add `tot.slots.store`, `tot.slots.update`, `tot.slots.delete`, `tot.slots.comment`, `tot.slots.comments`, `tot.slots.react`, `tot.attendance`, `tot.actions.store`, `tot.actions.card`, all under the existing `/app/tot` prefix (module gate unchanged).

### Views
- `resources/views/screens/tot.blade.php` (edit) — pass new drawer props.
- `resources/views/partials/tot-drawer.blade.php` (edit) — chair + attendance summary block, dedicated "Nota Perbincangan" section reading `nota_url`, slots list with per-slot discussion, tindakan table, "Agenda dari bulan lepas" block.
- `resources/views/partials/tot-edit-form.blade.php` (edit) — "Edit slot" retitled "Edit session" for privileged/manager/chair, `chair_employee_id`/`nota_url`/`next_agenda` fields added (gated to `canManageSession`); a new "Add slot" affordance lives in the drawer, not this form.
- New partials: `resources/views/partials/tot-slot-form.blade.php` (add/edit slot form), `resources/views/partials/tot-attendance-form.blade.php` (tick list + reason boxes), `resources/views/partials/tot-actions-table.blade.php` (Tindakan table + Create T.A.A. task button + add-tindakan form).
- `resources/js/tot-slot-thread.js` (new, small) — per-slot discussion thread Alpine component (list + composer), registered in `resources/js/app.js`.
- `resources/js/tot-action.js` (new, small) — "Create T.A.A. task" button Alpine component (POST, toast, disable after success).

### Tests
- `tests/Feature/TotSessionSlotsTest.php` (new) — slot update/delete/reorder, per-slot reaction, legacy backfill, cross-session 404.

## Schema changes summary

| Table | Change |
|---|---|
| `tot_sessions` | + `chair_employee_id`, `nota_url`, `next_agenda` |
| `tot_slots` | new |
| `tot_slot_presenter` | new |
| `tot_comments` | + `slot_id` nullable |
| `tot_reactions` | + `slot_id` nullable |
| `tot_attendance` | new |
| `tot_actions` | new |

## Acceptance verification map

1. **Recreate the Aug 2026 session (4 slots, 20 attendance rows, 4 actions, one page)** — `CR09Test::test_acceptance_1_august_session_has_four_slots_twenty_attendance_rows_and_four_actions_on_one_page`. Verified by: slot store creates ordered rows with presenter/support pivot; attendance store writes 20 rows keyed by employee; action store writes 4 rows; `GET /app/tot?year=2026` renders chair name, "19 hadir"/"1 tidak hadir" + reason, nota filename, every slot title in order, every action text, "Bulan hadapan"; plain staff 403 on slots/attendance/actions write endpoints; chair (non-privileged manager) may add a tindakan; an absentee with blank reason 422s and leaves the table untouched; no `attendance_records` row is written.
2. **Each slot has its own discussion thread and Nota link section** — `test_acceptance_2_...`. Verified by: `tot.slots.comment`/`tot.slots.comments` scoped by `slot_id`, session-level `tot.comments` thread stays at count 0, cross-session slot access 404s, blank body 422s, the page renders "Nota Perbincangan" and the `nota_url`, and HR can update `nota_url` through `tot.update` (invalid URL 422s).
3. **Create T.A.A. task on Tindakan 1** — `test_acceptance_3_...`. Verified by: `tot.actions.card` gated to PM-and-above + the tindakan owner (plain staff 403), creates one `WorkItem` (`type=task`, `status=todo`, `employee_id=owner`, `due_at`=next TOT Saturday, `due_label='Bulan hadapan'` when no target date was given, or the real `target_date` with no such label otherwise), stores `work_item_id` back on the action, 201 JSON `{ok, work_item: {id, due_at}}`, a second call 422s, an ownerless action 422s, the due date is locked afterwards (existing `BoardRules`/model guard, untouched), no `company_events` row.
4. **September shows August's carried agenda** — `test_acceptance_4_...`. Verified by: nothing copied into September's `description`/`next_agenda`; the year screen reads the previous month's `next_agenda` live (a later edit to August shows through immediately) under the label "Agenda dari bulan lepas", only when the current month's `description` is empty, preserving line breaks; absent when the previous month has none.

## Cross-cutting

- Every write above (`storeSlot`, `updateSlot`, `destroySlot`, `storeAttendance`, `storeAction`, `createActionCard`, the widened `update()`) calls `AuditLog::record()`; card creation additionally gets its own row for free from `WorkItem`'s `AuditsChanges` trait.
- No Event row, no `attendance_records` row, no outbound call.
- `Keep it plain`: new markup carries no animation/confetti; per-slot thread mirrors the existing session thread's plain style.
