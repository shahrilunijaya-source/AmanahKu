# Contract: dates

Frozen by S00. S02 builds the lock; every session obeys it.

## Vocabulary

Work item types are `work_items.type`. Everything that is not an Event is "work" (Task, Assignment, Adhoc, Subtask via `parent_id`). Events are `type = 'event'`; today the app has no Event type on the board, S13 (CR-11) introduces it and this contract already covers it.

## Rule 1: work due dates lock on first save

- `work_items.due_at` for any non-Event row: writable while the row is being created (`assign()`, `create_card`, subtask add). After the row exists with a non-null `due_at`, every write path must reject a different value: `WorkItemController::update`, `UpdateCardTool`, subtask edit, future imports. Reject means a validation error (`422`, message names the rule), never a silent ignore.
- Null to value is allowed once (a card created without a date gets one). Value to null is already refused on shared cards (`BoardRules::assertDueDateRetained`); after S02 it is refused on every card.
- The guard lives in one place, `BoardRules::assertDueDateLocked(WorkItem $item, mixed $incoming)`, called by every writer. A model-level `saving` guard backs it so a stray `$item->update()` cannot slip past.
- Due date is mandatory for work rows created after S02. Existing rows without one keep null until someone sets it.
- The only way to "move" work is: close as Cancelled with a reason (audit entry), create a new card.

## Rule 2: Event dates reschedule freely

`start_at`, `end_at` on Events change without restriction, each change audited with old and new values. Events are excluded from overdue counts and from every task-completion award.

## Rule 3: calendar

Work rows may appear on a calendar for visibility only. A calendar-side move never changes `due_at`; the next sync restores the calendar entry and writes an audit line. Handled through `CalendarPort` (see `ports.md`); the existing one-way `SyncWorkItemCalendarEventJob` is the adapter seed.

## Rule 4: TOT Tindakan

Creates a work row, never an Event. Sasaran editable until first save, then locked with the Task due date.

## Clock and zone

Business dates are `Asia/Kuala_Lumpur`. "Today" for a rule comes from the app clock helper the dashboard already uses (the dev clock override drives tests and the browser ticker), never `now()` inline.
