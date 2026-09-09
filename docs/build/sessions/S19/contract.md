# S19 contract — CR-19 Auto-Done Rules for System-Generated T.A.A. Cards

Written before code, per RULES.md. Shapes are fixed by `tests/Acceptance/CR19Test.php`
and its docblock (`docs/build/OPEN.md` / "QA / CR-19 / shapes fixed by CR19Test"), which
wins over the CR-19 spec prose wherever the two disagree (e.g. audit `source` values).

## Files touched

Schema:
- `database/migrations/2026_09_24_000000_add_auto_closed_at_to_work_items.php` — adds
  `work_items.auto_closed_at` (nullable timestamp, `after('cancelled_at')`). No other
  schema change: `source`/`source_ref` already exist (CR-34), `work_item_comments.employee_id`
  is already nullable.

New:
- `app/Support/AutoDone.php` — the one place every auto-close/-archive/-cancel goes
  through: sets the marker, saves the model (so `AuditsChanges` fires and produces the
  audit row the test checks for), writes the `work_item_comments` system row
  (`employee_id` null, body `"Closed automatically – <reason>"`, en dash).
- `app/Console/Commands/BoardAutoDone.php` — `board:auto-done`. Flag
  `config('services.auto_done.enabled')` (env `AMANAHKU_AUTO_DONE`, default false). Off:
  touches nothing, prints a line containing "dry-run" naming what it would have done. On:
  (a) notifies the organiser once per over event with a still-pending attendee (dedupe key
  `event-attendance-<event id>`), (b) archives (not Done) any open `source='awards'`
  `*-nominate` card whose `due_at` has passed.

Edited:
- `app/Models/WorkItem.php` — `auto_closed_at` cast (datetime, appended to `casts()`);
  new `isPendingAttendance(): bool` (event card, event over, RSVP still
  going/registered/maybe, not already auto-closed/archived/cancelled).
- `app/Http/Controllers/WorkItemController.php`:
  - `move()` — moving to a non-`done` status clears `auto_closed_at` (reopen).
  - `cardPayload()` — add `auto_closed` (bool), `auto_closed_label` (date string, for the
    drawer subline), `pending_attendance` (bool).
  - `show()` — the JSON response also carries `auto_closed`/`pending_attendance` at the
    top level (not only nested under `card`), matching `CR19Test`'s `show()` test helper,
    which reads `->json()` straight off the response root.
  - `commentPayload()` — a null-`employee_id` comment gets `author = 'Amanahku'`,
    `is_system = true`, distinct initials/color for the drawer's system mark.
- `app/Http/Controllers/EventController.php`:
  - `RESPONSE_LABELS` — add `'did_not_attend' => 'Did not attend'`.
  - `rsvp()` — `attended` closes the card Done (`AutoDone::done`, reason "Attended");
    `did_not_attend` archives it, not Done (`AutoDone::archived`, reason "Did not attend").
  - `archiveEventCard()` (withdrawn attendee) — route the archive+cancel through
    `AutoDone::cancelled()` (reason "Invitation withdrawn") instead of a bare `update()`.
- `app/Models/CompanyEvent.php` — `RESPONSES` const gains `'did_not_attend'`.
- `resources/views/screens/event-show.blade.php` — local `$responseLabel` array gains
  `'did_not_attend' => 'Did not attend'`.
- `app/Http/Controllers/AwardController.php` — `closeCard()` rewritten to load the
  matching `WorkItem` rows and close each through `AutoDone::done()` (reason "Nomination
  submitted" from `nominate()`, unused from `select()` — see below) instead of a bulk
  query-builder `update()` that skips the model events.
- `app/Console/Commands/AwardsPublish.php` — after `AuditLog::record('awards.published', ...)`,
  close every still-open `source='awards'` `*-select` card for the published month via
  `AutoDone::done()` (reason "published"), for every employee, not only whoever actually
  picked — matches the test (`select` card closes on `awards:publish` even though nobody
  called `AwardController::select()` for it).
- `app/Support/Awards.php` — `creditableCards()` exclusion `reject()` gains
  `|| $card->auto_closed_at !== null`.
- `app/Support/ManagementExceptions.php` — `overdue()` query gains
  `whereNull('auto_closed_at')` and `whereNull('archived_at')` (the latter was missing
  entirely).
- `bootstrap/app.php` — register `board:auto-done` in `withSchedule()`,
  `->everyFifteenMinutes()->withoutOverlapping()->onFailure(...)`, same pattern as the
  neighbouring `awards:*` entries.
- `config/services.php` — new `auto_done` block: `'enabled' => env('AMANAHKU_AUTO_DONE', false)`.
- `.env.example` — `AMANAHKU_AUTO_DONE=false` added.
- `resources/views/partials/work-card.blade.php` — `data-auto-closed="1"` /
  `data-pending-attendance="1"` on `[data-card]`; the "Auto" chip in `.wc-top` (mockup
  README §1); the "Pending Attendance" badge in the `.wc-when-badge` slot for an event
  card (mockup README §2).
- `resources/views/partials/work-drawer.blade.php` — meta subline gains
  " · ⚡ Closed automatically <date>" (mockup README §3); the comment loop renders a
  28px system mark instead of an avatar when `c.is_system`.
- `resources/js/work-board.js` — `cardPayload`/`drawer.card` already forwards whatever
  the JSON carries, no shape change needed there; `subline()`/drawer template read
  `card.auto_closed_label` directly (added by `cardPayload()`).
- `resources/css/app.css` — `.wc-auto`, `.wc-when-badge.wc-when--pending`,
  `.wd-cmt--system .wd-cmt-mark`, `.wd-meta-auto` — pasted verbatim from the mockup
  README.

Not touched (per rule 4 / scope): dashboard, `docs/build/contracts/*`, `tests/Acceptance/*`.

## Per-acceptance-item verification plan

1. **Event attendance (test_acceptance_1)** — `CR19Test::test_acceptance_1_...`. Covers:
   scheduler registration + flag default, Pending Attendance badge/JSON before and after
   the event ends, one organiser notification (deduped across two scheduler runs),
   `attended` → Done + Auto trail, `did_not_attend` → archived not Done + Auto trail,
   attendee removal → cancelled + archived + Auto trail, reopen clears `auto_closed_at`
   and is never re-closed by a later scheduler run.
2. **Google Calendar deletion** — `markTestIncomplete` in the frozen test (DEFERRED, no
   port). No code change; left as the human check the test already names.
3. **Awards nominate/select (test_acceptance_3)** — nominate card closes Done on
   `nominate()` with an Auto trail and stays out of To Do; a flagged-off scheduler run only
   dry-run-reports; flagged on, an unsubmitted nominate card is archived (not Done) once
   its `due_at` passes; the select card only closes on `awards:publish`, Done, with an Auto
   trail whose reason contains "published".
4. **Auto-closed cards never score (test_acceptance_4)** — `Awards::creditableCards()`
   (called `Awards::cards()` in the spec prose) excludes every `auto_closed_at`-set card
   from `done_and_dusted`/`deadline_who`; `ManagementExceptions::overdue()` excludes an
   auto-archived unsubmitted-nominate card while still listing a genuinely late manual one.
5. **Manual cards never auto-closed by date (test_acceptance_5)** — two flagged-on
   scheduler runs on an overdue manual card and an overdue `recurring`-labelled card leave
   both untouched (no status/archived/cancelled/auto_closed_at change, no auto-close
   comment anywhere); only a person's own `move()` closes it, and that never sets
   `auto_closed_at`.

Always: `test_always_checks` (due-date lock, audit-log immutability, dashboard unchanged,
keep-it-plain) — unaffected by this CR's files, run as part of the acceptance file.

## Open questions resolved without asking (logged to `docs/build/OPEN.md`)

- Audit `source` literal ('system'/'sync job' in the CR prose) vs. the frozen
  `audit-log.md` enum (`ui/api/mcp/job/sync`): rely on `AuditContext::source()`'s existing
  auto-detection (yields `job` for the console-run scheduler), never hand-write a literal
  that isn't in the contract's enum.
- `awards:publish` closes select cards for every employee who has one open that month, not
  only whoever actually picked — confirmed by the acceptance test itself (Kussairi's card
  closes on publish though he never calls `select()`).
