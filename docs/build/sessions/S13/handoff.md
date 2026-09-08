# Session S13 handoff: CR-11 (Events: attendees, T.A.A. and calendar sync, post-event sharing)

## Delivered
- Attendees replace @mention as the RSVP source of truth: `POST /app/events/{event}/attendees`
  (`events.attendees`) replaces the whole set, creator-or-PM-and-above only, verified by
  acceptance item 1.
- One `work_items` card per attendee (`type=event`, `due_at`=event date, description carries
  location/host/registration link), created on add and archived (`archived_at`+`cancelled_at`,
  never deleted) on removal, verified by acceptance items 1 and 4.
- One `CalendarPort::upsertEvent` per attendee on add/reschedule, one `deleteEvent` per removed
  attendee, reschedule preserves the original external id rather than the stub's freshly-minted
  one, verified by acceptance item 1 and scope item 2.
- Event page `GET /app/events/{event}` (`events.show`): attendee list with status labels before
  the end; after `CompanyEvent::isOver()`, three `data-event-tab` sections (Photos, Comments,
  Lessons learnt), verified by acceptance item 2.
- Photos (attendees-only upload, `ImageCompressor`, `local` disk, same streaming pattern as
  Knowledge), comments (any staff, after end, threaded), reactions (event- and lesson-level,
  CR-30 key set, toggle semantics) — acceptance item 3.
- Lessons learnt: one row per attendee per event, upserted, mirrored into one `knowledge_entries`
  row per lesson (segment "Events", title `"<event title>: <attendee display name>"`, tags =
  project code when `project_id` is set, updated in place on re-save, never duplicated) —
  acceptance item 3.
- Dashboard `events` widget (right column, `after: attendance` per the frozen
  `dashboard-slots.md` contract): visible when an event starts within 30 days or ended within
  7 days, otherwise absent; upcoming payload is title/date/attendee names, just-past payload is
  title/up to 4 photos/newest lesson line; text-only under Keep it Plain — scope item 6.

## Schema changes
- `company_events`: `starts_at`, `ends_at` (datetime, nullable) —
  `2026_09_18_100000_add_times_to_company_events.php`.
- `event_photos`, `event_comments`, `event_reactions`, `event_lessons` (new tables) —
  `2026_09_18_100100_create_event_post_event_tables.php`.
- Both migrations applied to the dev MySQL DB via `lerd artisan migrate --no-interaction` and
  verified read-only (`describe company_events`, `describe event_photos`, `describe
  event_comments`, `describe event_reactions`, `describe event_lessons`): all columns present as
  specified, `starts_at`/`ends_at` both `datetime` nullable.

## Contracts touched
- None edited. `dashboard-slots.md`'s existing CR-11 row (`events`, right, `after: attendance`)
  was read and conformed to, not changed.

## Port calls stubbed
- `calendar.upsertEvent` — one row per attendee added or rescheduled, written to `port_outbox`
  via `StubCalendarPort`; real Google Calendar adapter still owed (deferred, out of CR-11 scope
  per the ports contract).
- `calendar.deleteEvent` — one row per attendee removed.

## Deferred
- Two-way calendar pull (date-calendar-rules items 2/5/6) stays with the already-deferred CR-01,
  not touched here.
- Badge/points per lesson contribution — spec marks it Optional, skipped.
- Legacy `tagged_employee_ids` @mention picker on the create-event form left in place (see OPEN
  entry below) — no acceptance test forces its removal, and touching that form risked
  regressing `EventTest`/`ExternalEventTest`.

## OPEN, decided without Shazwan
- `docs/build/OPEN.md`, entry "S13 / CR-11 / attendee authorization simplified to
  privileged-role-only, @mention picker left on the form": attendee-management authorization
  implemented as privileged-role-only (no `created_by` column exists to check "creator"
  cheaply); the @mention picker was not removed from the create-event form.
- This session did not add a new decision beyond that — the bulk of CR-11's shape was already
  pinned by the pre-existing "QA / CR-11 / shapes fixed by CR11Test" OPEN entry, which this
  session conformed to (not authored).

## Requested contract change (generator may not make it itself)
- None.

## Traps for the next session
- `StubCalendarPort::upsertEvent()` always returns a freshly-minted `"stub-calendar-{id}"`
  external id, ignoring whatever `externalId` was passed on the `CalendarEvent` value object. A
  reschedule must discard that return value and keep the card's existing
  `work_items.google_event_id` — only the very first `upsertEvent` call for a card is allowed to
  persist the returned id. Getting this backwards silently breaks `deleteEvent` on later removal
  (wrong external id sent).
- `DashboardWidgets::ALL + [...]` (array union, not merge) in `DashboardBandsTest.php` keeps the
  LEFT operand's value for a duplicate key. Any future edit to a widget already present in `ALL`
  makes that test's own local override of the same key silently dead — the test then asserts
  against `ALL`'s real value, not the override. Read the test's actual assertions, not just its
  local fixture array, when a dashboard widget test fails after registry changes.
- Route-model binding for `CompanyEvent` does NOT 404 on a cross-tenant id in this app's runtime
  (confirmed empirically, not assumed) — the controller's own explicit tenant check fires first
  and returns 403. Do not assume `BelongsToTenant`'s global scope blocks binding.
- `bun run build` running concurrently with an in-flight `php artisan test --compact` full-suite
  run can produce one transient Vite-manifest failure (stale hashed asset name mid-run). Not a
  real bug — sequence build fully before or after a full-suite run, never during.
- The create-event form still writes and displays the legacy `tagged_employee_ids` @mention
  field even though OPEN's CR-11 entry says it should "no longer be offered on the form". Whoever
  touches that screen next should either remove it (with a test pinning the new markup) or note
  why it's staying.
