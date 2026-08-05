# Merge Feedback into Helpdesk

## Problem

Feedback (bug/idea reports) has no way for the reporter to see whether their
report was answered. `FeedbackItem` has only a status enum (open/reviewing/
resolved/declined) — no reply, no resolution text, no "my submissions" view.
The separate Helpdesk/Ticket module already has everything feedback lacks:
assignee, resolution text field, and a `myTickets` view so the raiser can
track their own tickets. Goal: fold feedback into the ticket system so bug/
idea reports get the same trackable, answerable lifecycle as support
tickets, instead of building a parallel reply mechanism.

## Data model changes

**`tickets` table** (migration adds two columns + two new enum values):
- `category` enum gains `Bug`, `Idea` (existing: IT, Facilities, HR, Other).
- `page_url` — nullable string, max 500. Auto-captured client-side, only
  meaningful for Bug/Idea category; null for the other four.

**New `ticket_attachments` table** (mirrors the retiring
`feedback_attachments` table):
- `id`, `ticket_id` (FK, cascade delete), `tenant_id`, `path`, `name`,
  `mime`, `size`, timestamps.
- Same private-disk storage pattern as feedback attachments: stored on the
  `local` disk under a `ticket-attachments` prefix, streamed back only
  through an auth-gated controller action (never a public URL).
- Same caps as feedback had: max 6 files per ticket, mimes
  `jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv`, 8 MB per file.

**Retired**: `FeedbackItem`, `FeedbackAttachment` models; their two
migrations' tables (`feedback_items`, `feedback_attachments`) dropped after
the data migration (below) runs. `FeedbackController`, its three routes
(`feedback.store`, `feedback.status`, `feedback.attachment`),
`resources/views/screens/feedback.blade.php`, and
`resources/views/partials/feedback.blade.php` deleted.

## Submission (`HelpdeskController::store`)

- `category` validation list grows to include `Bug`, `Idea`.
- When `category` is `Bug` or `Idea`:
  - `priority` is not accepted from the request — server sets it to
    `medium` regardless of what's posted. The priority field is hidden in
    the modal for these two categories.
  - `page_url` accepted (nullable string, max 500), populated client-side
    from the current page location, same as feedback's auto-capture did.
  - `attachments` accepted per the caps above; each valid file is stored
    and a `ticket_attachments` row created after the ticket exists (same
    ordering feedback used, so validation failure can never leave an
    orphan file).
- For the other four categories, behavior is unchanged: priority required
  from the submitter, no `page_url`, no attachments.
- Still requires an employee profile (`abort_unless($employee, 403, ...)`),
  unchanged from today's ticket rule. Confirmed via `FeedbackItem::whereNull
  ('employee_id')->count()` = 0 in the current dataset — no existing
  feedback submitter lacks an employee profile, so this isn't a regression
  for anyone with data on record. Super-admin is a view-only triage seat and
  never submits feedback, so it isn't affected either.

## Visibility and triage for Bug/Idea tickets

Bug/Idea gets a narrower rule than every other ticket category, and it
can't be expressed with the existing `hasTenantRole` helper alone: a
superadmin acting as an unlisted "observer" seat resolves to the
`management` role through `User::roleIn()` (`app/Models/User.php:113-117`)
whenever they open a tenant they aren't an actual member of — so any
`hasTenantRole($request, ['management'])` check already passes for
superadmin with zero extra code. That collapse is exactly what lets
superadmin see Bug/Idea tickets alongside real director/HR members, but it
cannot on its own express "superadmin may act, director/HR may only look."

- **View** (`HelpdeskController::screenData`): the filter applies only to
  the privileged inbox (`grouped`, `counts`) — Bug/Idea rows are excluded
  from those for anyone whose `hasTenantRole($request, ['management',
  'hr'])` is false. This covers director (collapses to `management`) and
  HR by role, and superadmin by the observer-collapse above, with no
  separate superadmin branch needed for viewing. `manager` and everyone
  else (including org-chart superiors) get zero visibility into Bug/Idea
  tickets **in the inbox** — no porting of feedback's org-chart-superior
  read grant; it doesn't apply here.
  `myTickets` (the raiser's own submitted tickets, built separately in
  `screenData()`) is untouched by this filter — every employee, regardless
  of role, must still see the Bug/Idea tickets *they personally raised* in
  `myTickets`, status and resolution included. That's the actual mechanism
  that closes the "can I tell if my feedback was answered" gap; filtering
  it out of `myTickets` too would defeat the whole feature.
- **Act** (`HelpdeskController::update`): restricted to
  `$request->user()->isSuperAdmin()` checked directly — NOT
  `hasTenantRole`, because that would also let real director/HR tenant
  members through via the same role match that lets them view. A real
  director or HR user hitting `update()` on a Bug/Idea ticket gets 403;
  only the superadmin account can assign/resolve them. Non-Bug/Idea
  categories keep today's unchanged `manager`/`management`/`hr` triage
  rule.
- Resolution: superadmin triage still writes `resolution` text + moves
  `status`, exactly as tickets do today. This is what gives feedback
  reporters an actual answer instead of a bare status flip.
- **Audit trail note** (inherited system behavior, not introduced by this
  change): `AuditLog::record()` (`app/Models/AuditLog.php:29-38`) silently
  skips writing any row when the actor `isObserverIn()` the tenant — true
  for a superadmin acting on a tenant they aren't a member of. So
  superadmin's Bug/Idea triage actions (assign, resolve, status change)
  will not appear in that tenant's audit log, same as every other write
  the observer seat already makes invisible. Flagging this here so it
  isn't mistaken for a gap introduced by this feature — it's the existing
  d8fca94 behavior applying to a new action.
- Submitter tracking: the existing `myTickets` collection in
  `screenData()` already surfaces status + resolution to the raiser — no
  new code needed here, this is what closes the original "can I tell if my
  feedback was answered" gap.

## Feature flag

- Remove `'module.helpdesk'` from `Features::OFF`. Default becomes ON for
  every tenant (matching feedback's old always-on behavior). Any tenant
  that explicitly toggled `module.helpdesk` off keeps that override — this
  only changes the registry default, not stored per-tenant state.

## Sidebar entry point

- The pinned "Send feedback" button in `partials/sidebar.blade.php` stays.
  Its `$dispatch('feedback-open')` is repointed to open the ticket-raise
  modal (the same modal the Helpdesk screen's "Raise ticket" button opens),
  pre-filled with `category = Bug`. Label/copy on the button can stay as
  "Send feedback" / "Maklum balas" since that's the recognizable one-click
  entry point users already know.
- The modal itself needs the category-conditional field behavior described
  above (hide priority, show page_url capture + attachments when category
  is Bug/Idea) built once and shared between the sidebar shortcut and the
  Helpdesk screen's own "Raise ticket" button — same modal, same component,
  just opened from two places.

## Data migration

One-off migration (Laravel migration file, runs once, forward-only):

1. For each `FeedbackItem` row: create a `Ticket` with
   `employee_id = feedback.employee_id`, `category = ucfirst(feedback.type)`
   (`bug` → `Bug`, `idea` → `Idea`), `priority = 'medium'`,
   `subject = feedback.title`, `description = feedback.description ?? ''`,
   `page_url = feedback.page_url`, `status` mapped open→open,
   reviewing→in_progress, resolved→resolved, declined→closed,
   `resolution = null` (feedback had no resolution text to carry over),
   `created_at`/`updated_at` preserved from the original row.
2. For each `FeedbackAttachment` on that item: create a matching
   `ticket_attachments` row pointing at the same file path (files are not
   moved on disk, only the DB row's owning table changes) — `path`, `name`,
   `mime`, `size` copied as-is.
3. Verify counts match (`tickets` gained rows == old `feedback_items`
   count, `ticket_attachments` gained rows == old `feedback_attachments`
   count) before dropping `feedback_items` and `feedback_attachments` in
   the same migration's `up()`. `down()` is not required to be reversible
   beyond re-creating the empty tables — this is a one-way data fold.

## Testing

- Feature test: submitting a Bug/Idea ticket ignores posted priority,
  always lands as medium.
- Feature test: manager and a plain employee do NOT see Bug/Idea tickets
  in the privileged inbox (`grouped`), but director/HR/superadmin do.
- Feature test: the raiser of a Bug/Idea ticket sees it in their own
  `myTickets` regardless of their role.
- Feature test: director and HR get 403 on `update()` for a Bug/Idea
  ticket despite having view access; superadmin gets 200. Non-superadmin
  management/hr/manager still get 200 on `update()` for
  IT/Facilities/HR/Other (unchanged rule).
- Feature test: `myTickets` shows resolution text once superadmin resolves
  a Bug ticket.
- Feature test: attachment upload/stream/auth-gate on `ticket_attachments`,
  mirroring the existing feedback attachment test coverage.
- Migration test or Tinker-verified run against a seeded copy of the
  current feedback data: row counts match pre/post, no orphaned attachment
  files.
