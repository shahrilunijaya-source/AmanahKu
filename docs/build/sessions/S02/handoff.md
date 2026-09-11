# Session S02 handoff: date-calendar-rules (the lock engine)

## Delivered
- Work due dates lock after first save on every write path: `PATCH /app/board/{id}` (a different date or null is 422 on `due_at`; the same date re-sent is a no-op), subtasks through the same route, MCP `update_card` at preview and at confirm, and a model `saving` guard that throws `RuntimeException` for any stray `$item->update(['due_at' => ...])`. One rule, `BoardRules::assertDueDateLocked()`. Verified by acceptance item 4 and `AlwaysChecks::assertDueDateLocked`.
- First set (null to a date) stays legal and is audited (S01 trait). Verified by item 4.
- Due date is mandatory at creation: `POST /app/board` (top-level and subtask) and MCP `create_card` require `due_at`; top-level web cards now persist it (before S02 the field was silently dropped for parents). Verified by item 4 and `tests/Feature/DueDateLockTest.php`.
- Cancel-and-recreate: `POST /app/board/{id}/cancel` with a required `reason` stamps `cancelled_at` and `archived_at` on the card and its subtasks, writes audit rows for both fields carrying the reason, the card leaves the board and appears in the archived list marked Cancelled with no Reopen button; `restore` refuses a cancelled card. Verified by item 4.
- Overdue excludes `type = event` and cancelled rows everywhere it is computed: `WorkforceInsights::overdueItems()`, the team board per-person counter (`BuildsWorkData`), the dashboard greeting trigger (`BuildsDashboardData`), and the card marker `wc-when--over` in `work-card.blade.php`. An Event row's date still changes and is audited. Verified by item 7.
- UI: "+ Add a card" opens a one-line composer that takes the due date before the card exists; the drawer's date picker is disabled once a date is set with a lock hint; a "Cancel card (with reason)" action in the drawer menu (prompt for the reason); subtask add rows require a date. Assets rebuilt, `public/build` updated.
- Items 1, 2, 3, 5, 6 (Google leg, Events) and 8 (TOT Tindakan) are incomplete by design: S13 with the S07 CalendarPort stub, and S12.

## Schema changes
- `work_items`: `cancelled_at` nullable timestamp; `type` enum gains `event` (MySQL `ALTER ... MODIFY`, sqlite untouched). Migration `2026_09_08_100000_add_date_rule_columns_to_work_items.php`. Not yet run on the dev database.

## Contracts touched
- none.

## Port calls stubbed
- none. The existing one-way `SyncWorkItemCalendarEventJob` is unchanged; it never writes `due_at`.

## Deferred
- Calendar snap-back of a moved work entry (rule 3 of `contracts/dates.md`): S13 through `CalendarPort::pullChanges`, nothing to hook until S07 exists.
- Event `start_at` / `end_at` columns and any Event UI: S13. S02 only adds the enum value so the overdue exclusion is testable.
- TOT Tindakan and Sasaran lock: S12.
- Award exclusions for Events: S17, nothing to exclude from yet.

## OPEN, decided without Shazwan
- Subtasks are not cancelled on their own (`cancel` returns 422 "A subtask is cancelled with its parent"); a subtask whose date moved is deleted and re-added. See OPEN entry "S02 / date-calendar-rules / cancel granularity".
- A cancelled card cannot be reopened, from the API or the archived list. Same entry.

## Requested contract change (generator may not make it itself)
- none.

## Traps for the next session
- `POST /app/board` and MCP `create_card` now refuse a card without `due_at`. Any seeder, fixture, test or tool that creates a card through those paths must send one. Direct model creates (`WorkItem::create`, `$employee->workItems()->create`) still allow null; the frozen `AlwaysChecks::card()` relies on that. Do not add `required` at the model level.
- The `saving` guard throws `RuntimeException`, not a validation error. Any new writer must call `BoardRules::assertDueDateLocked()` first if it wants a 422; the guard is the backstop, not the message.
- `type = 'event'` rows are exempt from the lock and from overdue but have no UI, no `start_at`/`end_at`, and the web `update` route still validates `type` as `assignment,task,adhoc`. S13 owns all of that.
- Cancelled cards are also archived (`archived_at` set). Anything that lists archived cards must read `cancelled_at` if it needs to tell them apart; `restore` already refuses them.
- The board "+ Add a card" composer POSTs `due_at` from `addingDue`; the old one-click "Untitled card" flow is gone. `team-board.js` never created cards and is unchanged.
- Pint reformatted quote style in `tests/Acceptance/DateCalendarRulesTest.php` (one string literal, double to single quotes) when run with `--dirty` right after QA wrote it. No assertion changed. Run pint on named paths, not `--dirty`, while a fresh acceptance file is untracked.
- 12 existing Feature tests were updated to send `due_at` or to assert the 422 instead of a moved date: `BoardCardTest`, `CoreWritePathsTest`, `WorkItemCardHtmlTest`, `WorkItemChildTest`, `Mcp/AmanahkuWriteToolsTest`, `AuditChangeTest`.
