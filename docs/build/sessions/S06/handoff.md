# Session S06 handoff: CR-18

## Delivered
- Recurring task engine: `recurring_tasks` (title, frequency weekly | monthly | every_n_months | yearly, interval, start_on, owner person or Position title, tagged people, project, priority, lead days, subtask template, min_attended, paused_at) and `recurring_task_occurrences` (one row per period, card or skipped reason, unique per schedule and period). `php artisan work:recurring` runs daily at 06:00 from the scheduler; it makes the card for the latest period whose creation day has come, once, as a task with label `Recurring`, the schedule's priority and project, tagged people as Helpers, due at the end of the period's month, subtasks in template order with the same due date. Verified by acceptance items 1, 2, 3d and 4, and in the browser (`s06-board-card.png`, card 317 on Kussairi's board on the dev DB after `lerd artisan work:recurring --on=2026-11-01`).
- Owner as a role: the Position title is resolved to its current holder at every creation, so an archived owner never receives a new card (item 3b). Fallback chain in `RecurringTask::resolveOwner()`.
- Skip a period with a reason, pause, resume, each audited (item 3c, `s06-recurring-paused.png`). A skipped period is never created; a paused stretch is not back-filled.
- Screen `/app/recurring` (Administration → Recurring Tasks, HR and management; staff 403): the list with holder, cadence, last periods, and the add form (position or person owner, helpers, subtasks one per line). Item 5 and `s06-recurring-list.png`.
- Event link: `POST /app/board/{card}/link-event {company_event_id}` for whoever may edit the card, 422 until every active employee has an RSVP on the event, stores `work_items.company_event_id` and ticks the "Create Event" subtask (item 2). The card drawer shows a "Linked event" row on a recurring card (`s06-link-refused.png` shows the refusal message inline).
- Done rule: `BoardRules::assertLinkedEventSatisfiesDoneRule()` in `move()`: a card with a linked event reaches Done only when the event is approved, Held, dated before today, has at least `min_attended` RSVPs marked `attended`, and carries `evidence_note` (item 3). Shared by the browser and, through the same BoardRules method, any future MCP move once that tool calls it (it does not today, see traps).
- Reassign: `POST /app/board/{card}/reassign {employee_id, reason}` for HR and management, audited as `employee_id` with the reason, due date untouched, open subtasks of the old owner handed over, new owner notified (item 3b).

## Schema changes
- `recurring_tasks`, `recurring_task_occurrences`: new, see contract.md.
- `work_items.company_event_id`: nullable FK to company_events.
- `company_events.status` (default draft), `approved_at`, `approved_by_employee_id`, `evidence_note`: the smallest CR-11 lifecycle.
- Migration `2026_09_11_100000_create_recurring_tasks.php`, run on the dev database.

## Contracts touched
- none

## Port calls stubbed
- none (in-app notifications only)

## Deferred
- CR-17 overdue panel: nothing done here; the card carries a locked due date and label `recurring`, which is all S15 needs.
- CR-11: event approval, Held, attendance and evidence have columns but no screen; `EventController` still validates RSVP as going | maybe | declined. S13 adds the UI on these columns (`CompanyEvent::STATUSES`, `RESPONSE_ATTENDED`).
- `App\Mcp\Tools\MoveCardTool` does not call `assertLinkedEventSatisfiesDoneRule()`; the MCP surface is outside this CR's files. One line when a session next touches that tool.

## OPEN, decided without Shazwan
- Due-date and deferral rules, the owner fallback chain, evidence as a note, who reassigns, no back-fill after resume: see "S06 / CR-18 / period maths, weekend starts, the owner fallback, and what "Held" needs today".

## Requested contract change (generator may not make it itself)
- none

## Traps for the next session
- 1 Nov 2026 is a Sunday. The engine deliberately creates on a weekend; only a public holiday defers creation. Do not "fix" that without reading CR18Test items 1 and 3d together.
- `recurring_task_occurrences.period` and `recurring_tasks.start_on` are cast `date:Y-m-d` so the stored value is a bare date; CR18Test compares the raw column to '2026-11-01'.
- The engine runs across every tenant with `CurrentTenant` set per tenant, like `birthday:notify`. `work:recurring --on=<date>` only works when `app()->isLocal()`.
- `RecurringTask::latestPeriodDueOn()` returns only the newest period; a run that was down for a whole period never creates the older one. That is the no-back-fill decision, not a bug.
- `AuditsChanges` writes the `work_item.created` rows for engine-made cards with actor "System" and source `job`.
- Dev DB state after this session: schedule 1 "Organise company social activity" (owner position Project Manager, so Kussairi, project AmanahKu, helpers 1, 2, 22, 23), card 317 with subtasks 318 to 322 for period 2026-11-01, period 2027-01-01 skipped with reason "Year-end close", schedule resumed. Two audit rows per action as designed (a field row and a plain-words row).
- `vendor/bin/phpstan analyse` on the whole app still reports the pre-existing errors outside this diff (`BuildsWorkData`, `ProfileWall`, older `WorkItemController` lines, the phpstan.neon ignore counts on `move()`); the new files pass on their own.
