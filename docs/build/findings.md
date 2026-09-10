# S00 findings, 2026-09-07

Survey of branch `worktree-change-request-tracker` at `5a462792`. No feature code written. Read this before letting the loop run.

## F1. CR-05 was built before CR-04. How far apart are they?

Further than "tagged helpers missing". The board has three people-columns and no reviewer:

| CR-04 role | Today |
|---|---|
| Assigned | `work_items.employee_id`, exists |
| Creator | `work_items.assigned_by_id`, exists (null = self-created) |
| Tagged Helper / FYI | `work_item_participant` pivot exists but has no `role`; every participant is "shared with", no credit split |
| Reviewer | nothing. No column, no gate. `move()` lets any owner/assigner/participant/covering manager move In Review to Done |

Subtasks are `work_items` rows with `parent_id` (`2026_09_03_120000_add_parent_id_to_work_items.php`), hidden by the `ParentOnly` global scope, status limited to todo/done, shown on the assignee's board by `BuildsWorkData::boardColumns()`. The two CR-05 rules that exist: parent auto-moves to In Review on last child done (`BoardRules::autoReviewParentOnLastChildDone`, called from controller and MCP), and parent cannot go Done with an open child (`BoardRules::assertChildrenDoneForStatus`). Counters in `teamBoardData()` count owner-only, top-level cards: subtasks and tagged cards are invisible to them.

**Verdict: S03 is additive, not a migration.** No competing role model was invented; CR-05 reused the owner column. S03 adds `reviewer_id`, a `role` on the pivot (existing rows become `helper`), the Reviewer-only Done gate in `move()` and `MoveCardTool`, and extends the counters. Estimate for S03 stays at one session. Contract: `contracts/roles.md`.

## F2. Were CR-13, 15, 20, 23 placed before CR-32 defined the slots?

No. CR-32 is already mostly in place on this branch (`d13437c5` onward): `DashboardBands::SLOTS = ['moments','management','awards']`, birthday and holiday eve are moments in **one rotating slot** with a pill to step through, `management` and `awards` are `null` placeholders with comments in the view. Flowers (CR-23) is a right-column widget anchored after Notice board, My working style (CR-15) anchored after My work summary, both via the registry's `after` key, and the greeting bank (CR-33, done early) feeds the existing H1.

**Verdict: S04 shrinks to "fill the two empty band slots' plumbing and confirm the plain text mode of each card", no retrofit of the four.** One real mismatch: the tracker's Appendix B puts My work summary and My working style in the left column, the code has them right. Code stands, logged in OPEN. Contract: `contracts/dashboard-slots.md`.

## F3. Does "Keep it plain" exist?

Yes. `DashboardPrefs` key `plain` in `users.dashboard_prefs`, checkbox in the dashboard picker labelled "Keep it plain", read by the bands view (no confetti, no art, no stamp) and by `meHead()` (static greeting instead of the bank). It also respects `prefers-reduced-motion`. What CR-31 still owes: the same switch on the profile screen bound to the same key, and the promise that every later moment honours it.

**Verdict: nothing to pull forward into S04 beyond a one-line profile toggle.** S21 keeps the easter-egg messages.

## F4. Audit log and locked due dates

**Audit log: exists, thin.** `audit_logs` has `action` + free-text `target` only, no old/new value, no reason, no source, and S01 must add them (`contracts/audit-log.md`). Coverage is wide for HR flows (leave, claim, overtime, payroll, attendance admin, timesheet submit/recall) and for every MCP board tool, but **zero for board writes through the web UI**: `WorkItemController` update, move, assign, archive, restore write nothing. Append-only holds today only because no update or delete path exists; S01 adds a hard guard on the model.

**Due dates: not locked anywhere.** `WorkItemController::update` and `UpdateCardTool` accept a new `due_at` at any time. The only guard is `BoardRules::assertDueDateRetained`, which stops clearing the date on a shared card. So CR-05 is not enforcing locked due dates; S02 builds the lock in `BoardRules` plus a model `saving` guard (`contracts/dates.md`). There is also no Event type on the board yet (`type` enum is assignment/task/adhoc); S13 adds it and the contract already reserves the rule.

## Other things the run should know

- **Google Calendar sync already exists** (one-way, `GoogleCalendarClient`, `SyncWorkItemCalendarEventJob`, per-user tokens). The tracker treats CR-01 as entirely unbuilt. S07 wraps the existing client as the real `CalendarPort` adapter rather than starting over; still unbound in the run.
- **No factories** for WorkItem, Timesheet, TimesheetEntry, AttendanceRecord, LeaveRequest, PublicHoliday. The S00 fixture seeder builds arrays by hand; `/qa write` tests should use it or add factories as they go (factories are test code, allowed).
- `BelongsToTenant` throws on create without a tenant context; seeders and jobs must pass `tenant_id` explicitly. Dev DB is single tenant, id 1.
- `timesheet` board prefill reads `work_item_progress_stints`, not status. Fixtures must write stints or the timesheet screen shows nothing to suggest.
- Roles are not a column on `users` or `employees`; they sit on the tenant membership row. Quick-login users: HR user 2 / employee 1, manager 6/5, senior manager 5/4, director 25/24, staff 27/26.
- Existing unique keys that bite re-seeding: `attendance_records(employee_id, date)`, `timesheets(employee_id, week_start)`.

## Effect on the session plan

| Session | Was | Now |
|---|---|---|
| S01 audit log | build | extend existing table + guard + cover web board writes |
| S02 date rules | build | build lock, plus the `event` type reservation |
| S03 CR-04 + migrate CR-05 | possible migration | additive |
| S04 CR-32 + retrofit | retrofit four features | plumbing for `management`/`awards` slots, profile plain toggle |
| S07 ports | three stubs | three stubs + wrap existing Calendar client |
