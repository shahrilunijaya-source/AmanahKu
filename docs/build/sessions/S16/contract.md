# Session S16 contract: CR-34 (internal half — Friday T.A.A. task, deferred reminder)

Shapes below follow `docs/build/OPEN.md`'s frozen "QA / CR-34 / shapes fixed by CR34Test" entry
verbatim. This session builds scope items 1, 2 (as a deferred MailPort intent), 3b, 4, 5 and 6
(deferred, human check). Scope 5 (Track's own meeting pack) is Track-side and untouched.

## Files touched

- `database/migrations/2026_09_21_100000_create_management_meeting_tables.php` — new
- `app/Models/ManagementMeetingSettings.php` — new
- `app/Support/ManagementMeeting.php` — new (shared: settings, recipients, meeting-date maths)
- `app/Console/Commands/CreateManagementMeetingTasks.php` — new (`management:meeting-tasks`)
- `app/Console/Commands/SendManagementMeetingReminder.php` — new (`management:meeting-reminder`)
- `app/Http/Controllers/ManagementMeetingController.php` — new (settings screen + POST)
- `resources/views/screens/management-meeting.blade.php` — new (minimal settings form)
- `app/Models/WorkItem.php` — add `LABELS['system']`
- `app/Support/ManagementExceptions.php` — extend `overdue()` for the 5 PM same-day rule
- `bootstrap/app.php` — register the two new scheduled commands
- `routes/web.php` — `POST /app/admin/management-meeting`, `GET` screen wiring
- `app/Http/Controllers/AppController.php` — screen gate + screenData case for `management-meeting`
- `app/Support/Amanahku.php` — nav entry + screen title/crumb, gated `['management', 'hr']`
  (director covered by `Controller::hasTenantRole()`'s `effectiveRole()` fallback)
- `tests/Feature/ManagementMeetingTest.php` — new, governance paths CR34Test doesn't cover

## Schema changes

One migration, `2026_09_21_100000_create_management_meeting_tables.php`:
- `work_items` gains `source` (nullable string 40) and `source_ref` (nullable string 40),
  both null on every existing/manual card.
- New table `management_meeting_settings`: `id, tenant_id (FK, cascade, unique), meeting_day
  (unsignedTinyInteger, default 5), meeting_time (string 5, default '17:00'), reminder_time
  (string 5, default '15:00'), task_time (string 5, default '08:00'), attendee_roles (json,
  nullable — null means the default set), paused_until (date, nullable), timestamps`. One row
  per tenant, created lazily on first settings write; `ManagementMeetingSettings::forTenant()`
  returns an unsaved instance with defaults when no row exists yet, so every command and the
  overdue-panel read work correctly before HR ever visits the settings screen.

Applied to the dev DB via `lerd artisan migrate --no-interaction`; verified read-only with
`mysql -h127.0.0.1 -uroot amanahku -e 'describe work_items; describe
management_meeting_settings'`.

## Acceptance items — how each is verified

1. **Friday 08:00, one card per manager, due 5 PM, own board only, idempotent.**
   `management:meeting-tasks` registered `0 8 * * *`. `ManagementMeeting::recipients()`
   (active, non-archived employees who are `pm_id`/`pe_id` on a live project, plus active
   employees whose tenant-membership role is in `attendee_roles`) each get one `WorkItem`
   (`type=task, status=todo, priority=medium, due_at=<meeting date>,
   project_id=<'URSB : Management meeting'>, labels=['system'], source='management_meeting',
   source_ref=<meeting date>, assigned_by_id=null, no participants`). `source_ref` +
   `employee_id` unique per run (checked before insert), so a second run same day, or later
   that day, creates nothing new. Card creation audit comes free from `WorkItem`'s existing
   `AuditsChanges` trait (`created` event). Own-board visibility is the existing board query
   (`employee_id = me`), unchanged. Verified by `CR34Test::test_acceptance_1_*`.
2. **Friday 15:00, one generic email via MailPort, deferred.**
   `management:meeting-reminder` registered `0 15 * * *`. One `MailPort::send()` per tenant
   per trigger day (idempotency: a `port_outbox` row already carrying today's
   `management_meeting_reminder` for the tenant skips the run, mirrors `ManagementDigest`).
   `to` = the same recipient set's emails, fixed subject/body from the spec, `Open Track`
   text, BM body filled, one `app_notifications` row per recipient
   (`AppNotification::send`), one `AuditLog::record()` whose action contains "meeting".
   Verified by `test_acceptance_2_*`.
3. **Ahmad closes his own card; Nurin's stays open, on her board only, nobody else may close
   it.** No new authorization code: `WorkItemController::move()`'s existing
   `BoardRules::authorizeAccess()` already 403s an unrelated employee (no `employee_id`
   match, no participant, no reviewer, not a manager/canSeeAll over the owner) — Emysha has
   none of those relations to Nurin's card. Verified by `test_acceptance_3_*`.
4. **After 5 PM, Nurin's card is overdue on the Director panel at "0 days overdue" that
   evening, "1 days overdue" the next morning; the marker survives; a manual card never
   carries it.** `ManagementExceptions::overdue()` gains one more clause: cards whose
   `source = 'management_meeting'` and whose `due_at` is *today* are included once
   `now() >=` the tenant's `meeting_time` (read from `ManagementMeetingSettings::forTenant()`)
   — every other card keeps the existing "due_at < today" rule untouched. `days_overdue`'s
   existing formula (`today->diffInDays(due_at->startOfDay())`) already yields 0 for a
   same-day card and 1 the next day, so no change needed there. Verified by
   `test_acceptance_4_*`.
5. **Friday public holiday moves both to Thursday; HR (not a manager) can pause; meeting day
   is Director-editable.** `ManagementMeeting::meetingDateForWeek()` computes the nominal
   date for `settings.meeting_day` in today's week, walks it back one working day at a time
   while it's a `public_holidays` row (never for a plain weekend — only a holiday shifts it,
   per the spec's own wording), and a command only fires when today equals that resolved
   date. Both commands skip entirely while `today <= settings.paused_until`.
   `POST /app/admin/management-meeting` is `Permissions::FINAL_APPROVAL_ROLES` only
   (`management`, `director`, `hr` — a plain `manager` 403s), each change audited. Verified
   by `test_acceptance_5_*`.
6. **Track AI, deferred.** Untouched — `CR34Test` leaves this `markTestIncomplete` itself;
   nothing to build, `TrackPort` has no read-updates method per the frozen ports contract.

## Cross-cutting

- `MailPort`/`port_outbox` only, no `Http::`/`Mail::` outside the port (ports.md).
- Due-date immutability, audit-log append-only and dashboard-slots untouched — no new band,
  no widget moved; the overdue panel is the existing `management` slot content, just fed one
  more row shape it already knew how to render (`data-card`, `N days overdue`).
- Roles: `Permissions::FINAL_APPROVAL_ROLES` for settings; recipients read the existing
  `tenant_user.role` pivot, no second role model.
