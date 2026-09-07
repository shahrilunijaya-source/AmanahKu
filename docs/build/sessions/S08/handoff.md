# Session S08 handoff: CR-03 (daily timesheet submission)

## Screens
- Capture (`resources/views/screens/timesheets.blade.php`, `resources/js/timesheet-capture.js`):
  the week-level lock is gone (`readonly` is always false); days lock one by one via
  `isFrozen()` (submitted/approved, or before `tsEarliestEditable` without an unlock).
  Header shows a per-day badge (Submitted / Approved / Returned with the manager's
  comment / Late / Resubmitted / locked hint / zero-hour reason). Week strip shows a
  check glyph for submitted, approved and returned days. Footer gains **Submit day**
  (`#ts-submit-day-btn`), which reads **Resubmit day** on a returned day and opens an
  inline reason box on a day with no lines; posts `submit_day` + `day_reason`, applies
  `body.days` in place, no reload. **Submit week** no longer waits for Friday; it is
  disabled once nothing editable is left, and an empty editable day blocks it with
  "… has no lines. Submit it on its own with a reason, or add a line."
- Report (`resources/views/partials/timesheet-weeks.blade.php`, `resources/js/timesheet-review.js`,
  `partials/timesheet-report/person-weeks.blade.php`): a per-day status strip under the
  week header (badge, Late submission, Resubmitted, Unlocked, return reason, zero-hour
  reason). When the viewer manages the person (`managesDays()`, passed as `manage`),
  each row shows Return (inline reason) / Approve / Unlock (inline reason) and the
  header shows Approve week. The manager strip lists every Monday to Friday of the
  week even without a saved row, so an unsaved old day can still be unlocked. The
  personal Review tab shows only days that have a row. A visually hidden
  "Late submission" list keeps the text in the server HTML.
- `resources/css/app.css`: approved / returned badge colours on `.uj-tr-status-badge`.

## Delivered
- `timesheet_days` table and `App\Models\TimesheetDay` (draft | submitted | approved
  | returned), migrated on the dev DB.
- `App\Timesheet\DayRules`: working-day arithmetic (Mon–Fri + first-Saturday TOT,
  minus public holidays), `deadlineFor()` (10:00 next working day), `earliestEditable()`
  (3 working days back), `weekWorkingDays()`, and `lineSignature()` used to tell
  whether a frozen day's resent lines actually changed.
- `config/manday.php`: `day_submit_deadline` (default `10:00`), `edit_window_working_days`
  (default 3).
- `Timesheet::refreshStatusFromDays()`: week status is now entirely derived from its
  days' statuses (submitted only once every candidate day is submitted/approved;
  approved only once every candidate day is approved).
- `WeekWriter::save()`: gained `submitDay`/`dayReason`. Frozen days (submitted,
  approved, or older than the edit window without an unlock) keep their stored lines
  when the grid omits them, and refuse a changed line with a message naming the day
  and the reason (locked vs. beyond window) — this now also strips anything the
  leave/holiday reconciler generated for a frozen day, so a leave approved after
  submission never rewrites what was already submitted. `submit_now` validates every
  unsubmitted working day up to today and is all-or-nothing. Every day-status change
  and every changed day's lines get an `AuditLog::change()` row on the `Timesheet`.
- `TimesheetController`: `store()` takes `submit_day`/`day_reason`; new
  `returnDay`/`approveDay`/`unlockDay`/`approveWeek` actions gated by
  `authorizeManagesDays()` (the employee's verifiers, or hr/management, never the
  employee themself); `recall()` puts submitted days back to draft and leaves
  approved days locked; `personWeeks()`/`buildWeekBlocks()`/`screenData()` carry
  per-day status for whatever UI a later pass builds.
- Routes: `POST /app/timesheets/{employee}/days/{date}/return|approve|unlock`,
  `POST /app/timesheets/{employee}/approve-week`.
- `AppController::quickActions()`: sidebar Timesheet % = approved days ÷ working
  days Monday-to-today.

## Tests
- `tests/Acceptance/CR03Test.php`: 8/8 passing.
- New `tests/Feature/TimesheetDayTest.php` (10 tests): `DayRules::deadlineFor` /
  `earliestEditable` around a holiday, unlock re-opening a frozen day, recall's
  submitted-vs-approved split, approve-week touching only submitted days, week
  status derivation.
- Existing tests updated to the CR-03 rules (old assumptions, not old bugs):
  - `TimesheetTest`: tests asserting a hard 422 now use `postJson()` (the old code
    forced 422 via `abort_if`; `ValidationException` now correctly 302s a plain form
    post and 422s JSON). Tests that set `Timesheet.status` directly without a
    `TimesheetDay` row now create one, so "locked" means what the new per-day model
    means. `submit_now`'s "every incomplete day at once" test now pins "today" to
    Wednesday (its class-level `setUp()` pinned Friday, which made all 5 weekdays
    submit_now candidates instead of the 3 the test seeds). The "submits when every
    day is 100" test switched from `submit_now` (now requires every working day up
    to today filled) to `submit_day`. The retired-category resave test moved its
    fixture date to "today" so it is not incidentally caught by the edit window. The
    dismissed-suggestions test did the same. The "submitted week never rewritten by
    later leave approval" test now posts against the actually-locked date and asserts
    200 (the day is silently kept as stored, not merged) instead of an outright 422 —
    a day fully covered by an approved leave is dropped from the grid before the
    frozen check ever sees it, so the request succeeds, but the frozen day guard
    still wins on what gets persisted.
  - `HalfDayLeaveTest`: the "half day leave, then fill the other half" test now uses
    `submit_day` for that single day instead of `submit_now` for a week with four
    other empty days.
  - `tests/Feature/Mcp/AmanahkuWriteToolsTest.php`: this file never pinned
    `Carbon::setTestNow()`, so its `2026-08-03` fixture week drifted further behind
    the real clock every day this repo ages, eventually landing outside the CR-03
    edit window. Pinned to `2026-08-05` (Wednesday of that week) in `setUp()`, so a
    first-then-second save against the same Monday stays inside the window; one test
    that touches the whole week through Friday overrides locally to `2026-08-07` so
    none of its dates read as "not happened yet".
- Full suite: 2733 tests, 2728 passed, 5 skipped, 9 incomplete (pre-existing), 0
  failures — same skip/incomplete counts as the 2715-test baseline before this
  session, net +18 tests (10 new + a few split/rewritten).
- `vendor/bin/pint --dirty --format agent`: clean.
- `vendor/bin/phpstan analyse app/Timesheet app/Models/TimesheetDay.php
  app/Models/Timesheet.php app/Http/Controllers/TimesheetController.php
  --no-progress`: 0 errors, no ignores added.
- `lerd artisan migrate --force`: `timesheet_days` created on the dev DB.

## Schema changes
- `timesheet_days` (new table, see contract.md for exact columns).

## Contracts touched
- none (read-only per standing rules).

## Port calls stubbed
- none.

## Deferred
- No mail or calendar side effects; CR-03 asks for none. A reminder before the 10:00
  deadline would go through `MailPort` in a later CR.

## OPEN, decided without Shazwan
- "S08 / CR-03 / edit window, first save, approved days": the window bites only on a
  saved week, approved days are returned by hr/management only, recall leaves approved
  days, a later leave never rewrites a submitted day. Shapes: "QA / CR-03 / shapes
  fixed by CR03Test".

## Requested contract change (generator may not make it itself)
- none.

## Traps for the next session
- `TimesheetController::dayStatuses()` returns `{status, late, resubmitted,
  zero_reason, return_reason, unlocked}` per ISO date; `screenData()` exposes it as
  `tsDays`, `buildWeekBlocks()` as each week's `dayStatuses` (distinct from the
  pre-existing `days` float-total key on the same array — do not conflate them).
- `authorizeManagesDays()` 403s the employee acting on their own days even if they
  happen to also be a manager elsewhere; a manager acting on someone else's day who
  is not in `verifierIds()` also 403s unless hr/management.
- `unlockDay` upserts both the `Timesheet` and `TimesheetDay` rows if neither exists
  yet (a manager can unlock a day nobody has ever saved anything against).
- `WeekWriter::save()`'s frozen-day guard only bites once `$timesheet->exists` —
  the very first save of a brand-new week is never frozen by the backdate window,
  only a second save against an already-persisted draft is.
- Day-total/blank-line validation for `submit_day`/`submit_now` uses
  `fullLinesForDate()` (user rows + generated locked rows), not `linesForDate()`
  (user rows only) — the latter stays reserved for "does this day have any real
  work logged" (a half-day leave alone must not count as that).
