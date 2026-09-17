# Google Calendar: tagged people, and a control on the task board

Date: 2026-09-17. Approved in chat, mockup at `docs/build/design/calendar-sync/index.html`.

## Why

Today a card is mirrored only into its owner's Google Calendar. Helpers and FYI people
never see it. The connect button lives on Profile, failures surface there only after
five retries (about 80 minutes), a card already on the board only reaches a freshly
(re)connected calendar once it is edited, and an access Google has revoked (Testing-mode
apps lose it after 7 days) still shows as "connected".

## What changes

1. **Tagged people get the card too.** Owner plus every participant with role `helper` or
   `fyi`, each only if they have a live connection. Reviewer is not included unless also
   tagged. Company-event attendee cards are untouched (EventController already mirrors
   one card per attendee).
2. **Tagged copies are read-only from Google.** A tagged person moving their entry snaps
   it back to the card's date; deleting it re-creates it. The card never changes from a
   tagged person's calendar. The owner's behaviour is unchanged.
3. **Board control.** A "Google Calendar" pill at the top right of the personal T.A.A.
   board, with a dropdown panel. States: not connected, connected (last synced),
   syncing (n of m), finished (result line + toast), some cards failed (red pill with
   count, list with Retry), connection expired (red "Reconnect"), cooling down.
   The Profile section is removed. Connect/Reconnect returns to the board and starts a
   full sync by itself.
4. **Sync now.** Sends every syncable card the person owns or is tagged on, then pulls
   changes from Google. Progress is shown while it runs.
5. **Rate limit.** One sync per person per 2 minutes, enforced server-side
   (`RateLimiter`, key per user). Sync now and Retry share the limit. A blocked request
   returns 429 with the seconds left; the button shows a live countdown.
6. **Expired access is visible.** A refresh that Google answers with `invalid_grant`
   marks the connection `revoked_at`. While revoked: pushes and pulls stop quietly (no
   retries burnt), the pill shows "Reconnect". Reconnecting clears it.

## Design

### Data

- Owner's calendar entry stays on `work_items` (`google_event_id`, `calendar_version`,
  `calendar_sync_error`). Acceptance tests (CR11) read those columns; they do not move.
- New table `work_item_calendar_copies`: `id`, `tenant_id`, `work_item_id` (cascade),
  `employee_id` (cascade), `google_event_id` nullable, `calendar_version` nullable,
  `sync_error` nullable(500), timestamps, unique (`work_item_id`, `employee_id`).
  One row per tagged person per card. Model `WorkItemCalendarCopy`.
- `google_calendar_connections`: add `revoked_at` nullable timestamp.

### Who is a recipient

`CalendarMirror::taggedRecipients(WorkItem)` returns participants with role
`helper`/`fyi`, excluding the owner. One place, used by the observer, the full sync and
the tag hook.

### Push path

- `SyncWorkItemCalendarEventJob` gains optional `employeeId` (the recipient). Null means
  the owner, exactly as today. For a tagged recipient it reads and writes the copy row
  instead of the card columns. `delete` for a tagged recipient deletes the Google event
  and the copy row. `uniqueId` includes the recipient.
- `connected()` also requires `revoked_at IS NULL`.
- `failed()` writes `sync_error` on the copy row for tagged recipients.
- `WorkItemObserver::saved`: after the existing owner dispatch, dispatch the same action
  for each tagged recipient (upsert when syncable, delete for rows holding an event id
  when not). `deleted`: also delete every copy that holds an event id.
- Tag changes: `participants()` uses a custom pivot `WorkItemParticipant` whose
  `created` event dispatches an upsert for that person (if the card is syncable and the
  role is helper/fyi) and whose `deleted` event dispatches a delete for their copy. This
  covers all seven places that write the pivot (board, ask-for-help, reassign, TOT x2,
  office requests, recurring tasks, MCP) without touching them.

### Pull path

`CalendarReconciler::reconcile` looks the change up on the owner's card first (as now).
If not found, it looks for a copy row with that `employee_id` + `google_event_id`:
same version is our echo (skip); cancelled means re-create (clear the copy's event id,
re-push); anything else means snap back (re-push). No card edit, no history line. Only
then does an unknown event become an imported Event card.

### Full sync

- `CalendarFullSync` job for one user: collects every syncable card the person owns
  (per tenant they belong to) or is tagged on, pushes each synchronously through
  `CalendarPort`, then runs the pull. Progress `{total, done, failed, pulled, state}` is
  kept in cache under the user's id for 10 minutes.
- Dispatched by Sync now, by the OAuth callback, and never twice at once
  (`ShouldBeUnique` on user id).

### Endpoints (all under `auth`, JSON, no page reloads)

- `GET /app/calendar-sync/status`: connection state (`off|connected|expired`),
  last synced, number of cards mirrored, issues (owner cards + copies with an error,
  title + message + id), current progress, seconds until sync allowed.
- `POST /app/calendar-sync/sync`: rate limited; 202 with progress, or 429 with
  `retry_after`.
- `POST /app/calendar-sync/retry/{workItem}`: rate limited; ownership checked explicitly
  (owner or tagged on it, same tenant) because route binding is not tenant-scoped.
- Existing connect / callback / disconnect routes stay; callback and disconnect return
  to the board (`?calendar=connected` opens the panel). Disconnect deletes the stored
  access only, as today; entries already in Google stay, and the stored event ids stay
  so a reconnect updates them instead of making duplicates.

### UI

Blade partial `partials/board/calendar-sync.blade.php` included in the board's first
filter row, right aligned. Alpine component polls status every 2 s only while a sync is
running. Toast on finish. Countdown driven by `retry_after`. Bilingual EN/BM like the
rest of the board. Only the personal board, not the team board.

## Errors

- Google down during a full sync: that card counts as failed and gets its error, the
  rest continue; result line says "10 of 12 sent".
- Revoked during a full sync: stop, state becomes `expired`.
- Not configured on the install (no client id): the pill is hidden, routes 404, as the
  connect routes do today.

## Tests

- Tagging a helper/fyi pushes a copy; untagging deletes it; reviewer-only gets nothing.
- Card title change re-pushes owner and copies; done/archived removes all.
- Tagged person's moved entry snaps back without changing `due_at`; deleted entry is
  re-created; owner behaviour unchanged (existing tests stay green).
- Full sync pushes owned + tagged cards across tenants and records progress.
- Rate limit: second sync inside 2 minutes is 429; retry shares it.
- `invalid_grant` marks revoked; revoked connections are skipped; reconnect clears it.
- Retry refuses a card the person is not on.
- Board renders the pill when configured, not when unconfigured; Profile no longer has
  the section.
- Existing CR11 acceptance test untouched and green.

## Out of scope

Showing which Google account is connected (needs an extra permission). Team board.
Reviewer copies. The prod `APP_URL` pointing at staging (devops `.env`).
