# S02 contract: date-calendar-rules (the lock engine)

## Files

- `database/migrations/2026_09_08_100000_add_date_rule_columns_to_work_items.php` (new)
- `app/Support/BoardRules.php`: `assertDueDateLocked(WorkItem $item, mixed $incoming)`, one guard for every writer
- `app/Models/WorkItem.php`: `saving` guard behind BoardRules, `cancelled_at` cast/fillable/audited, `isEvent()`, `isCancelled()`
- `app/Http/Controllers/WorkItemController.php`: `store` requires `due_at` and persists it on top-level cards; `update` calls the lock; new `cancel()`; `restore` refuses cancelled cards; `archived` marks cancelled rows
- `routes/web.php`: `POST /app/board/{workItem}/cancel` (`work.cancel`)
- `app/Mcp/Tools/UpdateCardTool.php`: lock at preview and at apply
- `app/Mcp/Tools/CreateCardTool.php`: `due_at` accepted and required for top-level and child cards
- `app/Support/WorkforceInsights.php`, `app/Http/Controllers/Concerns/BuildsWorkData.php`, `app/Http/Controllers/Concerns/BuildsDashboardData.php`, `resources/views/partials/work-card.blade.php`: overdue excludes `type = event` and cancelled rows
- `resources/views/screens/board.blade.php`, `resources/views/partials/work-drawer.blade.php`, `resources/views/partials/work-overview.blade.php`, `resources/js/work-board.js`, `resources/js/team-board.js`: date picked before a card or subtask is created, due date read-only once set with a lock hint, "Cancel card" action with a reason
- `public/build` rebuilt
- `tests/Feature/*`: existing tests that create cards without a date or move a date are updated to the new rule

## Schema

`work_items`: `cancelled_at` nullable timestamp after `archived_at`; `type` enum gains `event` (MySQL `ALTER ... MODIFY`; sqlite already stores a plain string). No row is touched.

## Verification per acceptance item

1, 2, 3, 5, 6. Google leg: incomplete by design until S13 (CalendarPort stub, S07). Nothing built here.
4. `DateCalendarRulesTest` item 4: first set allowed and audited; PATCH with another date or null is 422 on `due_at`; same date re-sent is a no-op; subtask locked through the same route; MCP `update_card` refused; model update throws; `POST /app/board` without `due_at` is 422; `POST /app/board/{id}/cancel` needs a reason, stamps `cancelled_at` and `archived_at`, audits with the reason, card leaves the board and shows in the archived list; a new card with the new date can be created.
7. Item 7: `WorkforceInsights::overdueItems()` and the board marker skip `type = event` and cancelled rows; an Event's date still changes and is audited.
8. TOT Tindakan: incomplete by design until S12.

Always checks: all four run from `test_always_checks_from_s02`.
