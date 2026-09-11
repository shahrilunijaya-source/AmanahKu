# QA grade: S13 / CR-11 (Events: attendees, T.A.A. and calendar sync, post-event sharing)

**Verdict: PASS**, after ten fixes (F1–F10) made in this grade commit. The S13 build passed
`CR11Test` but a human could not reach or drive most of it; every fix below was found by
clicking, not by reading the diff. Re-driven after fixing.

Graded on `http://worktree-change-request-tracker.amanahku.localhost` with the quick-login
accounts. Two dev-environment notes shaped the drive:

- `tenant_features.module.events` was `0` for the dev tenant, so `/app/events` 404'd. Set to
  `1` for the grade (left on; later sessions need it too).
- The dev clock (`/dev/clock`) cannot go **backwards**: the session cookie's expiry is computed
  from the fake `now()`, so a fake date in the real past makes the browser drop the session on
  the next request (looks like a logout to `/tenant`). Pre-existing tooling limit, not CR-11's.
  So the HPE event was rescheduled to **10 Sep 2026 09:00–15:45** for the browser drive and the
  clock set to 9 Sep (upcoming) / 10 Sep 15:00 (before end) / 10 Sep 16:00 (after end). The
  acceptance test keeps the spec's 27 Aug 15:45.

## Defects found and fixed

| # | Where | What a human hit | Fix |
|---|---|---|---|
| F1 | `resources/views/screens/events.blade.php`, `partials/event-past-row.blade.php` | No link anywhere to `/app/events/{id}`; attendees could only be set by typing the URL | Event title links to `events.show` on the upcoming card and the past row |
| F2 | `resources/views/screens/events.blade.php`, `partials/event-edit-buttons.blade.php` | Create/edit form had only free-text `start_time`; `starts_at`/`ends_at` unreachable, so post-event unlock was end-of-day | Two `datetime-local` inputs, prefilled on edit |
| F3 | `app/Http/Controllers/EventController.php`, `docs/build/OPEN.md` | Attendee gate was privileged-only; S13's OPEN entry claimed no creator column exists (`created_by_employee_id` does, and `store()`/`update()` use it) | `canManageAttendees()` = privileged OR creator; OPEN entry corrected in place |
| F4 | `EventController::attendees()`, `screens/event-show.blade.php` | Plain form post showed raw `{"ok":true}`; empty selection 422'd "must be present" | Redirect back with a flash for non-JSON; hidden blank filtered before validation |
| F5 | `screens/event-show.blade.php` | No way to mark Registered / Attended on the page (scope 1) | Per-attendee status select posting `events.rsvp` with `employee_id`, for whoever may manage attendees |
| F6 | `partials/work-card.blade.php` | Event card on the T.A.A. board carried a "Task" chip | `'event' => ['Event', …]` in the type chips |
| F7 | `screens/event-show.blade.php` | Reaction buttons did nothing (no script behind `data-react-url`) | Inline fetch handler that redraws the counts |
| F8 | `screens/event-show.blade.php` | No reply control although comments accept `parent_id` (scope 3) | Reply form per top-level comment |
| F9 | `partials/dash/widgets/events.blade.php`, `BuildsDashboardWidgets.php` | Just-past widget printed "3 photos", not the photo strip (scope 6) | Up to four thumbnails via `events.photos.show`; count text under Keep it Plain |
| F10 | `EventController::archiveEventCard()` | Removed attendee's card was archived but not cancelled, against the OPEN shape and the S13 handoff's own claim | Sets `cancelled_at` with `archived_at` |

Feature tests added for each: `EventAttendeesTest` (creator may set attendees; screen links and
time inputs; form redirect and status control; cancelled card) and `EventPostEventTest` (reply
form and reaction buttons present).

## Acceptance items

| Item | Result | Evidence |
|---|---|---|
| 1. Create HPE event, tag Kussairi and Syakir, both get a board card and calendar entry | PASS | Haryati (poster) edits the event with start/end (`grade-cr11-fix-f2-times-form.png`), opens it from the title link (`grade-cr11-fix-f1-events-list-link.png`), saves attendees 5 + 19 (`grade-cr11-1-attendees-form.png`, `grade-cr11-1-event-page-attendees.png`). DB: 2 `event_rsvps` going, cards 248/249 type `event` due 2026-09-10 with `google_event_id` stub-calendar-1/2, `port_outbox` rows 1–2 `calendar.upsertEvent` sent for employees 5 and 19. Kussairi's board shows the card with an "Event" chip (`grade-cr11-1-kussairi-board.png`, `grade-cr11-fix-f6-board-event-chip.png`). Syakir's board checked via DB only (his prod-copy account hits the profile gate). Re-saving the same set is a no-op with "Attendees saved." Syakir marked Registered from the page. |
| 2. After the end time the page shows Photos / Comments / Lessons learnt | PASS | 10 Sep 15:00: no `data-event-tab`, no "Lessons learnt" (`grade-cr11-2-before-end-no-tabs.png`); 16:00: three tabs in order (`grade-cr11-2-after-end-tabs.png`) |
| 3. Attendee uploads 3 photos and a lesson, visible to all staff, searchable in Knowledge | PASS | Kussairi (attendee) uploads three captioned photos, a lesson with how-to-use and a link, a comment (`grade-cr11-3-photos-lesson-comment.png`); reaction and reply work (`grade-cr11-fix-f7-f8-react-reply.png`). Shazwan (non-attendee) sees photos and lesson, gets a comment form but no upload/lesson form (`grade-cr11-3-shazwan-sees-lesson.png`). `/app/knowledge-bank?q=Guardrails+belong` lists the mirrored entry in segment Events (`grade-cr11-3-knowledge-search.png`). |
| 4. Remove Syakir: card and calendar event removed | PASS | Haryati deselects Syakir (`grade-cr11-4-syakir-removed.png`); RSVP row gone, card 249 `archived_at` set, `port_outbox` row 3 `calendar.deleteEvent` for employee 19 with external id stub-calendar-2, audit "Set event attendees" + `work_item.archived_at`. |
| Scope 2. Reschedule moves cards, same external id | PASS | Event card `due_at` PATCH 2026-09-11 then back accepted (Event exempt, dates Rule 2). `CR11Test::test_scope_2` covers the full reschedule. |
| Scope 5. Creator or PM and above set attendees | PASS (after F3) | Director Shahrilnizam sees the form and posts 200; feature test covers a plain-role creator. |
| Scope 6. Dashboard Events card | PASS (after F9) | Kussairi 9 Sep: widget in the right column after `attendance`, title, date, "Kussairi, Syakir", link to the event page (`grade-cr11-6-kussairi-dashboard-upcoming.png`). Shazwan 10 Sep 16:00: newest lesson line and three thumbnails (`grade-cr11-6-shazwan-dashboard-just-past.png`). Absent on 9 Sep once no attendees remain. |

## Every-session checks

| Check | Result |
|---|---|
| `php artisan test --compact tests/Acceptance/CR11Test.php` | 7/7 green |
| Task due-date change via API | Kussairi `PATCH /app/board/205 {due_at}` → 422 "Due dates are locked after the first save" |
| Audit row edit | tinker `AuditLog::first()->save()` → `RuntimeException: audit_logs rows are append-only` |
| Dashboard baseline (Shazwan, 2026-09-09, no attendees) | widgets summary, clock, tasks, leave, style, calendar, notices, flowers, claims, work; no band (`grade-cr11-dashboard-baseline.png`), same order as the S12 grade |
| Keep it plain | Kussairi: title "Good morning, Kussairi.", events widget text-only, no cheeky copy (`grade-cr11-plain-dashboard.png`). Only motion inside the widget is the shared `.uj-dw-notice` 0.14s hover transition every widget link has. |
| Outbound calls in the diff | none (grep for Http::, Guzzle, curl, Google\, Mail::send) |
| New OPEN entries | S13's entry corrected in place; this grade's entry "QA / CR-11 / grade fixes F1–F10" carries Alternatives and Reversal cost |
| Protected files | `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*` untouched (git diff empty) |
| Full suite | 2820 tests, 2815 passed, 0 failed, 5 skipped, 12 incomplete (pre-existing); re-run on the final code, see commit message |
| Assets | `view:clear`, `view:cache`, `bun run build` after every Blade change; `public/build` byte-identical, nothing to commit |

## Notes, not failures

- The photo form takes one caption per upload round; three captions meant three rounds.
- RSVP status changes, photo uploads and comments write no audit row; the Global Clause does
  not list them. "Set event attendees", lesson sharing and card archive/due changes do.
- The Events screen still keeps the legacy @mention picker for external-event reminders (S13's
  OPEN entry); CR-11's attendees live on the event page reached from the title.
- The event page is deliberately plain forms with reloads (S13's own note); it renders inside
  the app shell but with default browser styling for selects and buttons.
- Dev DB after the grade: HPE event rescheduled to 2026-09-10 with start/end set, all attendees
  cleared (card 249 archived before F10, card 248 archived+cancelled after it, four outbox rows, three photos, one lesson mirrored
  as `knowledge_entries` 17, one comment + reply, one reaction). Dev clock reset, Keep it plain off.
