# Session S11 handoff: CR-09

## Delivered
- Session gains a chair, a Nota Perbincangan link and a next-month agenda, verified by acceptance item 1 (chair/nota_url) and item 4 (next_agenda carried into the following month).
- Ordered slots (Pembentangan/Demonstrasi/Sambungan) with their own presenter team, format, status and summary, replacing the old one-title-one-presenter session, verified by acceptance item 1 (four slots in order, PostgREST slot's team/format/status/presenters).
- Attendance replaces the whole list per save (present/absent with a required reason), verified by acceptance item 1 (20 rows, 19/1 split, "Cuti sakit").
- Each slot has its own discussion thread (comment + react), independent of the session-level thread and of every other slot's thread, verified by acceptance item 2.
- Tindakan (tot_actions) rows with an owner and an optional target date; "Create T.A.A. task" makes one work_items card for the owner, due on the target date or the next TOT Saturday when defaulted ("Bulan hadapan"), locked afterwards by the existing due-date guard, verified by acceptance item 3.
- Legacy backfill: every session with a title and no slot yet gets one Pembentangan slot carrying its title/description/presenters, verified against the CR09Test fixtures and against the dev database (below).
- Roles: session/slot/attendance/tindakan management is `PRIVILEGED_ROLES` (management, hr) plus `manager` plus the session's own chair (`TotSession::isManagedBy()`); "Create T.A.A. task" is PM-and-above (manager, hr, management, director) plus the tindakan's own owner (`TotAction::canCreateCardBy()`). Plain staff get 403 on every write route, verified by acceptance item 1's governance block and by `TotSessionSlotsTest`.
- `GET /app/tot?year=YYYY` renders chair, attendance summary, every slot in order and every tindakan row on the one existing screen — no new screen, verified by acceptance item 1.

## Schema changes
- `tot_sessions`: `chair_employee_id` FK employees nullOnDelete nullable, `nota_url` string(500) nullable, `next_agenda` text nullable — migration `2026_09_16_100000_add_chair_and_nota_to_tot_sessions`.
- `tot_slots` (new): id, tenant_id, session_id FK tot_sessions cascade, position unsignedSmallInteger, title string(200), kind string(14), format string(8) nullable, status string(8) nullable, presenter_mode string(4) default solo, summary text nullable, timestamps, index(session_id, position). `tot_slot_presenter` (new): slot_id FK cascade, employee_id FK cascade, support boolean default false, timestamps, unique(slot_id, employee_id). Same migration backfills one slot per legacy titled session (logic lives in `TotSlot::backfillLegacySessions()`, called from the migration and reused directly by `tests/Feature/TotSessionSlotsTest.php`) — migration `2026_09_16_100100_create_tot_slots_tables`.
- `tot_comments` and `tot_reactions` gain a nullable `slot_id` FK tot_slots cascade; null keeps answering the old session-level thread. `tot_reactions` also gets a second unique `(slot_id, employee_id, emoji)` for the slot thread's own race guard, alongside (not replacing) the original `(session_id, employee_id, emoji)` — migration `2026_09_16_100200_add_slot_id_to_tot_comments_and_reactions`.
- `tot_attendance` (new): id, tenant_id, session_id FK cascade, employee_id FK cascade, present boolean, reason string(300) nullable, timestamps, unique(session_id, employee_id) — migration `2026_09_16_100300_create_tot_attendance_table`.
- `tot_actions` (new): id, tenant_id, session_id FK cascade, slot_id FK tot_slots nullOnDelete nullable, position unsignedSmallInteger, action string(300), owner_employee_id FK employees nullOnDelete nullable, target_date date nullable, work_item_id FK work_items nullOnDelete nullable, timestamps, index(session_id, position) — migration `2026_09_16_100400_create_tot_actions_table`.
- All five migrations applied to the dev database with `lerd artisan migrate --no-interaction`. Verified: 6 of the dev DB's sessions have a non-null title, and exactly 6 `tot_slots` rows now exist, one per session (`select count(*) from tot_slots` = 6, `select count(distinct session_id) from tot_slots` = 6).

## Contracts touched
- None. `docs/build/contracts/*` was read, not edited.

## Port calls stubbed
- None. CR-09 makes no outbound call; "Create T.A.A. task" only writes a `work_items` row through the existing WorkItem model, the same path every other card creation in the app already uses.

## Deferred
- Editing/cancelling a Tindakan after it is created, syncing its status with the work item, and the "last month" block are explicitly CR-10's (S12) scope per `docs/specs/CR-10.md`'s boundary note — nothing here builds any of it. `tot_actions.work_item_id` is the join column CR-10 will read.
- The Blade "Edit slot" panel (now relabelled "Edit session") still edits only the legacy session-wide title/description/links/times — CR-09 did not fold slot-level material editing into it; slot editing has its own inline form in the new `partials/tot-slots.blade.php`.

## OPEN, decided without Shazwan
- Attendance stays in the Learning module rather than the Attendance module (tracker default) — already decided in the pre-existing "QA / CR-09 / shapes fixed by CR09Test" OPEN entry, not this session's own decision, just followed.
- `tot_reactions` keeps its original 3-column unique `(session_id, employee_id, emoji)` untouched and gets a second slot-scoped unique alongside it, rather than widening the original to 4 columns (which would reopen a NULL-multiplicity gap for two session-level reactions) — see OPEN.md entry "S11 / CR-09 / slot-thread reaction collides with an identical session-thread reaction". Known, logged, untested-by-CR09 consequence: reacting with the same emoji on both a session thread and one of its slots collides and the second reaction is silently dropped.
- A plain manager or the session's chair reaches `POST /app/tot/{session}` (the existing `tot.update` route) only for the CR-09 fields (chair_employee_id/nota_url/next_agenda); a request also touching the legacy title/presenter/status fields still needs the pre-CR-09 checks — see OPEN.md entry "S11 / CR-09 / manager and chair reach tot.update only for the CR-09 fields, not the legacy ones". Needed because simply OR-ing the new manage-session check into the endpoint's top-level gate silently regressed two pre-existing `TotAssignPermissionTest` cases (a manager without the tot.assign override went from 403 to a silently-ignored 302 instead of staying 403).

## Requested contract change (generator may not make it itself)
- None.

## Traps for the next session
- `TotController::authorizeSlotEdit()` grants a manager/chair access to `tot.update()` only when the request body's keys are a subset of `['chair_employee_id', 'nota_url', 'next_agenda', 'year', 'month', '_token']`. Any new CR-09/CR-10-era field that should also be manager/chair-editable through that same endpoint needs adding to that allow-list, or it will silently 403 for a manager/chair (though not for a privileged role, who bypasses this check entirely via `canAssignPresenter()`).
- `tot_reactions` has two unique constraints now: the original `(session_id, employee_id, emoji)` and the new `(slot_id, employee_id, emoji)`. A person reacting with the same emoji on both the session thread and a slot thread of the same session hits the first one and the second post is silently dropped (caught as a 23xxx "race" and swallowed) — this is a real, if narrow, bug, not just an edge case in a test. If CR-10 or a later session touches slot reactions, this is the first thing to fix properly (4-column unique plus a documented decision on what "the same NULL slot_id" should mean for two session-level rows).
- `TotSlot::backfillLegacySessions()` is idempotent (checks `whereNotIn('id', ...)` against existing `tot_slots.session_id`s) and is called both from the `2026_09_16_100100_create_tot_slots_tables` migration and directly from `tests/Feature/TotSessionSlotsTest.php`. If a future migration needs to re-run a backfill-shaped operation, prefer this pattern (a static model method the migration calls) over inlining SQL in the migration closure again — it is what made this session's backfill test possible without touching any migration state.
- The Blade multi-select presenter/support pickers in `partials/tot-slots.blade.php`'s "Edit slot" form submit no field at all when nothing is selected (standard HTML `<select multiple>` behaviour), and `TotController::updateSlot()` only touches presenters when the request carries a `presenters` or `support` key at all — so deselecting every presenter in that form does nothing rather than clearing the team. Not covered by any test; matches the same "an absent field is left alone, not treated as empty" rule the session-level presenter picker already uses (`carriesPresenters()`), so it is consistent with existing behaviour, just worth knowing before "fixing" it.
- `app/Models/TotAttendance.php` needed an explicit `protected $table = 'tot_attendance'` — Eloquent's default pluralization from the class name is `tot_attendances`, which does not exist. Caught immediately by CR09Test (a 500), but worth remembering if another CR-09-adjacent model gets added against a table whose name does not pluralize predictably from its class name.

## Test counts
- `tests/Acceptance/CR09Test.php`: 5/5 passed, 180 assertions.
- `tests/Feature/TotSessionSlotsTest.php` (new, this session): 8/8 passed, 64 assertions — slot update (partial-field vs full), reorder, delete + governance, per-slot reaction independence and toggle, cross-session 404 on every slot route and on the tindakan card route, and the legacy backfill (titled/untitled/already-slotted sessions, idempotent re-run).
- Related pre-existing TOT/reaction files run together (`CR09Test`, `TotSessionSlotsTest`, `TotTest`, `TotLiveActionsTest`, `TotAssignPermissionTest`, `TotHistorySeederTest`, `TotReminderTest`, `TotSaturdayTimesheetTest`, `CR30Test`): 193/193 passed, 812 assertions.
- Full suite (`php artisan test --compact`): 2778 tests, 2773 passed, 0 failed, 5 skipped, 12 incomplete (pre-existing, unrelated to CR-09).

## Commit
- Formatted with `vendor/bin/pint --dirty --format agent` (fixed import ordering/spacing in `TotController.php`).
- Assets rebuilt (`lerd artisan view:clear && lerd artisan view:cache && bun run build`); `public/build` committed alongside the code change.
- Committed on this worktree's branch as `feat(S11): CR-09 TOT sessions with chair, attendance, slots and tindakan`. Not merged, not pushed, `dev` untouched.
