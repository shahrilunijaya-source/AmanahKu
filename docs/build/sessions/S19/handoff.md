# Session S19 handoff: CR-19 (Auto-Done rules for system-generated T.A.A. cards)

## Delivered

- `App\Support\AutoDone` — the one place every automatic close/archive/cancel goes
  through (`done()`, `archived()`, `cancelled()`), saving the `WorkItem` model directly (so
  `AuditsChanges` fires) and leaving a `work_item_comments` activity line ("Closed
  automatically – <reason>", en dash, `employee_id` null).
- `work_items.auto_closed_at` marker, cleared on a manual reopen to a non-Done status
  (`WorkItemController::move()`). Reopened card is a normal card again — never re-closed
  by the scheduler. Verified by acceptance item 1's reopen step and item 5.
- Event attendee cards (CR-11): once the event is over and an RSVP is still
  going/registered/maybe, the card shows "Pending Attendance" (board HTML
  `data-pending-attendance="1"` + JSON `pending_attendance`) and the scheduler prompts the
  organiser once (`app_notifications` dedupe `event-attendance-<event id>`), never closes
  it by time. `POST /app/events/{event}/rsvp` with `attended` closes the card Done;
  `did_not_attend` (new RSVP value) archives it, never Done; dropping the attendee cancels
  + archives it. Verified by acceptance item 1.
- Awards tasks (CR-14b): a nominate card still closes Done on `nominate()`
  ("Nomination submitted"); `board:auto-done` archives (not Done) a still-open nominate
  card once its due date passes ("the nomination window closed"); a select card closes
  Done on `awards:publish` for every employee who has one open that month, not only
  whoever actually picked ("the awards were published"). Verified by acceptance item 3.
- `board:auto-done`, `*/15 * * * *`, ships FLAGGED OFF
  (`config('services.auto_done.enabled')`, env `AMANAHKU_AUTO_DONE`, default `false`).
  Flag off: touches nothing, prints a line containing "dry-run" naming what it would have
  done. Flag on: notifies + archives as above. Verified by acceptance items 1, 3, 5.
- `Awards::creditableCards()` (the CR-19 spec's "`Awards::cards()`") rejects every card
  with `auto_closed_at` set. `ManagementExceptions::overdue()` now also excludes
  `auto_closed_at`/`archived_at`. Verified by acceptance item 4.
- Manual cards (no `source`, no `system`/`recurring` label, not an event) are never
  touched by the scheduler however overdue — only a person's own `move()` can close one,
  and that path never sets `auto_closed_at`. Verified by acceptance item 5.
- "Auto" chip on a closed card face (`.wc-auto`, `data-auto-closed="1"`, title carries the
  full reason), the drawer meta line's " · ⚡ Closed automatically <date>" suffix, and the
  auto-close activity line rendered with a system mark (`.wd-cmt--system .wd-cmt-mark`)
  instead of an avatar — `docs/build/sessions/S19/mockup/README.md` §1 and §3, pasted
  verbatim.
- Item 2 (Google Calendar deletion → "Cancelled (calendar)") is `markTestIncomplete` in
  the frozen test itself — DEFERRED, no Calendar inbound-sync port exists yet
  (`docs/build/RULES.md`). No code written for it.

## Schema changes

- `work_items`: `auto_closed_at` (nullable timestamp, `after('cancelled_at')`), migration
  `database/migrations/2026_09_24_000000_add_auto_closed_at_to_work_items.php`. Applied to
  the dev DB via `lerd artisan migrate --no-interaction`.

## Contracts touched

- none

## Port calls stubbed

- none — nothing in this CR calls out; the scheduler only reads existing tables and
  writes `app_notifications`/`work_item_comments`/`work_items` rows.

## Deferred

- Acceptance item 2 (Google Calendar deletion syncing to "Cancelled (calendar)") — no
  Calendar inbound-sync port exists yet; the frozen test itself carries this as a human
  check (`markTestIncomplete`). Already tracked in `docs/build/RULES.md`.
- CR-19's Parent-card-with-subtasks and Track-WBS-linked rules — out of this session's
  tested scope (`CR19Test` does not exercise either); `docs/build/OPEN.md`'s pre-existing
  "known open questions" entry for CR-19 already records the Track WBS card as "not
  auto-Done, deferred with the rest of the Track binding".

## OPEN, decided without Shazwan

- Audit `source` value for an auto-close, and whether `awards:publish` closes every open
  select card for the month or only the picker's own — see `docs/build/OPEN.md`
  "S19 / CR-19 / audit `source` value and who closes a select card on publish".

## Requested contract change (generator may not make it itself)

- none

## Traps for the next session

- **`AutoDone::done()`/`archived()`/`cancelled()` are not idempotent against a second call
  with a different outcome on the same card.** E.g. an organiser who marks someone
  `attended` and later corrects it to `did_not_attend` gets a card with `status = 'done'`
  AND `archived_at` set (both `AutoDone::done()`'s and `AutoDone::archived()`'s writes
  land, in sequence) — nothing in `CR19Test` exercises a correction after the fact, so this
  was not built out. If a future session needs correction support, `AutoDone` is the one
  place to add an "undo the previous outcome first" step.
- **`WorkItem::isPendingAttendance()` and the "Auto" chip's title both run one extra query
  per matching card** (`work-card.blade.php`, marked `# ponytail:`) — an RSVP lookup and a
  system-comment lookup respectively. Fine at normal board size; eager-load
  `companyEvent`/an RSVP map and the latest system comment if a board ever renders enough
  event/auto-closed cards for this to show up in a profile.
- **`AwardController::closeCard()`'s signature changed** (`$reason` is now a required third
  argument) — any other call site added later must pass one; the two existing callers
  (`nominate()`, `select()`) already do.
- **`board:auto-done` never touches a select card** — that close lives only in
  `AwardsPublish::publishTenant()`. If the awards publish schedule or command name ever
  moves, the select-card sweep moves with it, not with the scheduler.
- **The full suite is red on `dev` independent of this session** —
  `LeaveScreenTabsTest::test_cancelling_an_approved_replacement_refunds_the_quota` fails
  the same way on a clean checkout with no CR-19 changes. Whoever runs the suite next will
  see 1 failure out of the box; it is not something S19 broke. See `docs/build/OPEN.md`.

## Test run

- `php artisan test --compact tests/Acceptance/CR19Test.php` — 6 tests, 143 assertions,
  1 incomplete (item 2, the deferred human check), 0 failures.
- `php artisan test --compact tests/Acceptance/CR11Test.php tests/Acceptance/CR14aTest.php tests/Acceptance/CR14bTest.php tests/Acceptance/CR17Test.php tests/Acceptance/CR34Test.php tests/Feature/AwardsTest.php tests/Feature/BoardCardTest.php`
  — 137 tests, 1156 assertions, 5 incomplete, 0 failures.
- `php artisan test --compact tests/Feature/DashboardExternalEventTest.php tests/Feature/EventTest.php tests/Feature/ExternalEventTest.php tests/Feature/SyncWorkItemCalendarEventJobTest.php tests/Feature/EventAttendeesTest.php tests/Feature/EventPostEventTest.php tests/Feature/ManagementExceptionsTest.php tests/Unit/WorkItemGoogleEventIdTest.php`
  — 64 tests, 209 assertions, 0 failures.
- `php artisan test --compact tests/Acceptance/GlobalClauseTest.php tests/Acceptance/DateCalendarRulesTest.php` — 15 tests, 118 assertions, 8 incomplete, 0 failures.
- `php artisan test --compact tests/Acceptance/` (whole acceptance suite) — 137 tests,
  2556 assertions, 18 incomplete, 0 failures.
- Full suite: `php artisan test --compact` — 2908 tests, 2902 passed, 21800 assertions,
  5 skipped, 18 incomplete, **1 failure**:
  `Tests\Feature\LeaveScreenTabsTest::test_cancelling_an_approved_replacement_refunds_the_quota`
  (line 732, "Failed asserting that 1.0 matches expected 0.0."). Pre-existing, not caused by
  CR-19 — no S19 file touches leave/quota code, and the same failure reproduces identically
  on a detached worktree at clean `HEAD` with no S19 changes present. See
  `docs/build/OPEN.md` "S19 / pre-existing / LeaveScreenTabsTest replacement-refund failure
  not caused by CR-19".
