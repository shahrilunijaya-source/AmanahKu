# Session S13 contract: CR-11 (Events: attendees, T.A.A. and calendar sync, post-event sharing)

Shapes are already fixed by OPEN "QA / CR-11 / shapes fixed by CR11Test" — this contract is
the concrete file list and per-item verification, not a re-decision.

## Files touched

### Migrations (new)
- `database/migrations/2026_09_18_100000_add_times_to_company_events.php` — `company_events.starts_at`,
  `ends_at` (datetime, nullable).
- `database/migrations/2026_09_18_100100_create_event_post_event_tables.php` — `event_photos`,
  `event_comments`, `event_reactions`, `event_lessons`.

No change needed to `company_events.type` or `event_rsvps.response` — both are already plain
`string` columns (not MySQL enums), so widening the accepted values is application-level only.

### Models
- `app/Models/CompanyEvent.php` — add `starts_at`/`ends_at` casts, `isOver()`, `RESPONSE_REGISTERED`
  const, relations to photos/comments/lessons.
- `app/Models/EventPhoto.php` (new)
- `app/Models/EventComment.php` (new)
- `app/Models/EventReaction.php` (new)
- `app/Models/EventLesson.php` (new)

### Controllers
- `app/Http/Controllers/EventController.php` — extend `store`/`update`/`rsvp`; add `attendees`,
  `show`, `storePhotos`, `photoShow`, `storeComment`, `react`, `lessonReact`, `storeLesson`.
- `app/Http/Controllers/AppController.php` — extract the tail of `screen()` (the `view(...)`
  assembly) into a private `wrapScreen()` helper so `screen()` and a new `eventShow()` share
  identical page chrome; add `eventShow(Request, CompanyEvent): ViewContract` for `events.show`.

### Views
- `resources/views/screens/event-show.blade.php` (new) — attendee list + status labels;
  post-event Photos/Comments/Lessons learnt sections gated by `CompanyEvent::isOver()`.
- `resources/views/partials/dash/widgets/events.blade.php` (new)

### Dashboard
- `app/Support/DashboardWidgets.php` — add `events` registry row (right column,
  `after: attendance`, `screen: events`).
- `app/Http/Controllers/Concerns/BuildsDashboardWidgets.php` — filter `events` out of
  `$available` unless an event starts within 30 days or ended within 7 days (mirrors the
  `friday` conditional-presence pattern); add `eventsWidget()` builder + `match` case.

### Routes (`routes/web.php`, inside the existing tenant-scoped group, near the current
`/app/events` block)
- `POST /app/events/{event}/attendees` → `events.attendees`
- `POST /app/events/{event}/photos` → `events.photos.store`
- `GET /app/events/photos/{photo}` → `events.photos.show` (registered before the `{event}`
  wildcard GET, same caution as the existing `rsvp` comment)
- `POST /app/events/{event}/comments` → `events.comments.store`
- `POST /app/events/{event}/react` → `events.react`
- `POST /app/events/{event}/lessons` → `events.lessons.store`
- `POST /app/events/{event}/lessons/{lesson}/react` → `events.lessons.react`
- `GET /app/events/{event}` → `events.show` (`AppController::eventShow`)

### Tests (new, `tests/Feature/`)
- `EventAttendeesTest.php` — governance (creator/PM-and-above vs plain staff), idempotency,
  cross-tenant 404/403, card lifecycle on add/remove.
- `EventPostEventTest.php` — photos/comments/lessons governance (attendees-only, after-end
  only), Knowledge mirror update-in-place, dashboard widget visibility window + plain mode.

## Verification per acceptance item (`tests/Acceptance/CR11Test.php`)

1. `test_acceptance_1_...` — `attendees()` creates RSVP rows (`going`), one `work_items` card
   per attendee (`type=event`, `due_at`=event date, description carries location,
   `google_event_id` set from the stub's `PortResult`), one `port_outbox` row per attendee
   (`calendar.upsertEvent`, payload `for_employee_id`/`title`/`starts_at`, `subject_id` = the
   card id), board visibility via the existing `employee_id = me` board query, idempotent
   re-post, `rsvp()` accepting `employee_id` override from HR for `attended`, `AuditLog::record`
   with "attendee" in the action text.
2. `test_acceptance_2_...` — `CompanyEvent::isOver()` (on `ends_at`, app clock) gates the three
   `data-event-tab` markers and the `lessons.store` 422 pre-end.
3. `test_acceptance_3_...` — `storePhotos()` (attendees-only, `ImageCompressor`, `local` disk),
   `storeLesson()` upsert + Knowledge mirror (segment "Events", found-or-created once), search
   `?q=` finds it, `storeComment()` threading, `react()`/`lessonReact()` CR-30 key validation
   and JSON shape, lesson audit row.
4. `test_acceptance_4_...` — `attendees()` removal path: RSVP row deleted, card
   `archived_at`+`cancelled_at` stamped (never deleted), one `deleteEvent` port_outbox row
   carrying the card's `google_event_id`, board visibility drops for the removed attendee only.
5. `test_scope_2_...` — `update()` reschedule: `event_date` derived from `starts_at`, every
   linked card's `due_at` moved (allowed — `BoardRules::assertDueDateLocked` already exempts
   `type=event`), one `upsertEvent` per still-current attendee carrying the **existing**
   `google_event_id` as `external_id` in the payload, `work_items.google_event_id` itself left
   unchanged (the stub always mints a fresh synthetic id — only the first creation call is
   allowed to persist it), a plain `PATCH /app/board/{card}` on an Event card still succeeds.
6. `test_scope_6_...` — dashboard `events` widget: present within the 30-day-upcoming /
   7-day-past window and absent outside it (quiet-day baseline in `AlwaysChecks` stays green
   because no fixture event exists on `2026-09-08`), attendee names, link to `events.show`,
   text-only under `plain`.

## Not built (logged to OPEN, not new — already covered by the QA shape entry)
- Badge/points per contribution (spec marks it Optional).
- The create-event form's own picker stays legacy `tagged` @mention text; the new Attendees UI
  lives on the event-show page only, where `events.attendees` actually posts from. Touching
  `screens/events.blade.php`'s create form is out of this session's tested scope and risks
  regressing `tests/Feature/EventTest.php`/`ExternalEventTest.php`, which must stay green.
