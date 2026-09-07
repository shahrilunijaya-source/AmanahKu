# Session S06 contract: CR-18 recurring task engine and the social activity schedule

## Files to touch
- `database/migrations/2026_09_11_100000_create_recurring_tasks.php` (new)
- `app/Models/RecurringTask.php`, `app/Models/RecurringTaskOccurrence.php` (new)
- `app/Console/Commands/CreateRecurringWorkItems.php` (new, `work:recurring`)
- `bootstrap/app.php` (one scheduler line)
- `app/Http/Controllers/RecurringTaskController.php` (new: screen data, store, skip, pause, resume)
- `app/Http/Controllers/WorkItemController.php` (`linkEvent`, `reassign`, done rule call in `move`)
- `app/Support/BoardRules.php` (`assertLinkedEventSatisfiesDoneRule`)
- `app/Models/WorkItem.php` (label `recurring`, `companyEvent()` and `recurringOccurrence()` relations)
- `app/Models/CompanyEvent.php` (status constants)
- `app/Support/Amanahku.php` (page title + Administration nav entry for `recurring`)
- `app/Http/Controllers/AppController.php` (screen gate + screen data line)
- `routes/web.php`
- `resources/views/screens/recurring.blade.php` (new)
- `resources/views/partials/work-drawer.blade.php` (link-event row, shown only on a card that belongs to a schedule)
- `tests/Feature/RecurringTaskTest.php` (new)
- `docs/build/OPEN.md`, `docs/build/sessions/S06/handoff.md`

## Schema
Migration `2026_09_11_100000_create_recurring_tasks.php`, run on the dev DB only:
- `recurring_tasks`: id, tenant_id, title (160), frequency (weekly | monthly | every_n_months | yearly), interval (unsigned small, default 1), start_on (date), owner_employee_id (nullable FK employees), owner_position_title (nullable string), tagged_employee_ids (json), project_id (nullable FK projects), priority (default medium), lead_days (default 0), subtasks (json), min_attended (default 1), paused_at (nullable), created_by_employee_id (nullable FK employees), timestamps.
- `recurring_task_occurrences`: id, tenant_id, recurring_task_id (FK), period (date), work_item_id (nullable FK work_items), skipped_reason (nullable text), timestamps, unique (recurring_task_id, period).
- `work_items.company_event_id` (nullable FK company_events).
- `company_events`: status (default draft), approved_at (nullable datetime), approved_by_employee_id (nullable FK employees), evidence_note (nullable text).
- `event_rsvps.response` is an unconstrained string already; `attended` needs no schema change.

## Verification per acceptance item
1. `CR18Test` item 1: engine run on 31 Oct creates nothing, on 1 Nov creates the card owned by the Finance Manager (Position title lookup), due 30 Nov, label `recurring`, four helpers, occurrence row, audit row. Browser: create the schedule as Hidayah, set the dev clock to 2026-11-01, run `lerd artisan work:recurring`, open the owner's board.
2. Item 2: five subtasks in order with the parent's due date; `link-event` 422 without everyone on the event, 200 with everyone, ticks 'Create Event', 403 for a stranger.
3. Item 3: `move` to done is 422 under each missing condition and 200 with all five.
3b. Item 3b: archived owner, new Finance Manager gets January; HR `reassign` 200 (staff 403), audit `employee_id`, due date unchanged.
3c. Item 3c: skip 403 / 403 / 422 / 200 with occurrence row and audit; skipped period never created; pause and resume with audit; no back-fill.
3d. Item 3d: holiday and weekend defer creation to the next working day; a double run makes one card.
4. Item 4: scheduler carries `work:recurring` daily; January card created with the November one untouched; even months skipped.
5. Item 5: `/app/recurring` 403 for staff, lists schedules for HR; store 422 on title only, 200 for the fire drill, audited; the drill fires in Oct and Jan, the social schedule not in Oct.
Always: the four cross-cutting checks in `test_always_the_four_cross_cutting_checks`.
