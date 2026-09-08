# Session S16 handoff: CR-34 (Track: Friday 3 PM management-meeting reminder — internal half)

## Delivered

- `management:meeting-tasks`, scheduled daily 08:00: on the tenant's meeting day (default
  Friday, moved to the working day before it when that day is a `public_holidays` row, never
  for a plain weekend) creates one `'Update Track for management meeting'` task card per
  recipient (active PM/PE on a live project, plus active employees whose tenant role is in
  the configured attendee roles), due at the meeting date, tagged `project_id` = the tenant's
  `'URSB : Management meeting'` project, label `system`, `source = 'management_meeting'` /
  `source_ref = <due date>`. Idempotent per person per day. Verified by acceptance item 1 and
  the new cross-tenant/archived-person governance tests.
- `management:meeting-reminder`, scheduled daily 15:00 (DEFERRED half): one `MailPort::send`
  intent per tenant per trigger day to the same recipient set, fixed subject/body from the
  spec, one `app_notifications` row per recipient, one audit row per send, idempotent per
  tenant per day. Verified by acceptance item 2 and the new cross-tenant/archived-person
  governance tests.
- Card closing: no new authorization code needed — the existing `BoardRules::authorizeAccess()`
  gate already 403s an unrelated employee closing someone else's card. Verified by acceptance
  item 3.
- `ManagementExceptions::overdue()` now also lists a `management_meeting` card on its own due
  date once `now() >=` the tenant's meeting time ("0 days overdue" that evening), on top of
  the unchanged "due_at < today" rule for every other card. Verified by acceptance item 4.
- `POST /app/admin/management-meeting` (`meeting_day`, `meeting_time`, `reminder_time`,
  `task_time`, `attendee_roles`, `paused_until`), `Permissions::FINAL_APPROVAL_ROLES` only
  (manager 403), each change audited, one settings row per tenant with defaults when absent.
  Both commands skip entirely while `paused_until` covers today. A minimal settings screen at
  `GET /app/management-meeting` (nav: Administration > Management Meeting, gated the same as
  the POST route) lets HR/management/director edit all six fields and see the current values.
  Verified by acceptance item 5 and the new settings-screen governance test.
- Item 6 (Track AI) left `markTestIncomplete` by the frozen test itself — nothing built,
  Track-side and out of scope for this session.

## Schema changes

- `work_items` gains `source` and `source_ref` (both nullable string(40), null on every
  existing/manual card) — the CR-19/CR-14a marker.
- New table `management_meeting_settings`: `id, tenant_id (FK, cascade, unique), meeting_day
  (unsignedTinyInteger, default 5), meeting_time (string 5, default '17:00'), reminder_time
  (string 5, default '15:00'), task_time (string 5, default '08:00'), attendee_roles (json,
  nullable), paused_until (date, nullable), timestamps`.
- Migration `database/migrations/2026_09_21_100000_create_management_meeting_tables.php`,
  applied to the dev MySQL DB via `lerd artisan migrate --no-interaction`, verified read-only
  with `mysql -h127.0.0.1 -uroot amanahku -e 'describe work_items; describe
  management_meeting_settings'`.

## Contracts touched

- none

## Port calls stubbed

- `MailPort.send`, 1 `port_outbox` row per tenant per trigger day this session's tests wrote
  (`kind = management_meeting_reminder`), real mail adapter still owed (unchanged from
  earlier sessions — stub-only for the whole run).

## Deferred

- Scope 5 (Track's own meeting pack, the AI-generated agenda/summary inside Track) — Track-side,
  untouched, out of this session's scope per the CR spec's own "Run status" field.
- Acceptance item 6 (Track AI answering "what were last week's blockers") — Track-side,
  `TrackPort` has no read-updates method per the frozen ports contract; `markTestIncomplete`
  in the frozen test itself, nothing to build against.

## OPEN, decided without Shazwan

- `tests/TestCase.php`'s S15 schedule warm-up doubled every scheduled command (not just
  CR-34's two new ones) for whichever test genuinely runs first in the PHPUnit process —
  traced into a Laravel internal double-construction of `Illuminate\Console\Application`
  inside that one warm-up call, fixed by de-duplicating `Schedule::class`'s events by
  `command|expression` right after the warm-up. See `docs/build/OPEN.md` entry
  "S16 / CR-34 / tests/TestCase.php: the S15 schedule warm-up itself doubles every scheduled
  command for the one test that runs first".

## Requested contract change (generator may not make it itself)

- none

## Traps for the next session

- `tests/TestCase.php::setUp()` now does a dedup pass on `Schedule::class`'s events right
  after the S15 warm-up call. It is currently a no-op unless a test is the literal first one
  to run in the whole PHPUnit process — do not delete it without first running the full ordered
  acceptance-test command line (CR34Test.php first) to confirm the underlying Laravel
  double-construction is actually gone.
- The management-meeting settings screen lives at `GET /app/management-meeting`
  (`resources/views/screens/management-meeting.blade.php`), wired into `AppController::screen()`
  and `Amanahku.php`'s nav the same way `recurring` is — same file, same pattern, easy to spot
  if another screen needs the same treatment later.
- `ManagementMeeting::recipients()` is the single source of truth for who gets the card and
  the mail (PM/PE on a live project, plus active employees whose tenant role is in the
  attendee roles). CR-19's auto-done rule and CR-14a's award exclusion both need to read
  `work_items.source = 'management_meeting'` — the column exists and is populated by this
  session, but nothing in this session's scope reads it back except the CR-17 overdue panel
  extension; a future session should not need to touch `ManagementMeeting` itself.
- To trigger locally: `lerd artisan management:meeting-tasks` / `lerd artisan
  management:meeting-reminder` on the dev DB, or wait for the two new `bootstrap/app.php`
  schedule entries (`0 8 * * *`, `0 15 * * *`).
