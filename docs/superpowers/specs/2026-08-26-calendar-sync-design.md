# Work item → Google Calendar sync

Branch: `feature/profile-work-items-calendar` (off `dev`).

## Scope

Originally two ideas: (1) reuse the task board's look in profile's Work &
Tasks tab, (2) link a work item card to Google Calendar. (1) was dropped —
the profile tab stays exactly as it is
(`resources/views/screens/profile.blade.php:271-330`). This spec covers only
(2), and only the real-sync version: a work item's due date syncs to the
assignee's Google Calendar as a real event, not a static "Add to Calendar"
link.

## Decisions

- **Target calendar:** the work item's assignee (`WorkItem::employee()` →
  `Employee::user()`), not whoever is viewing the page.
- **Trigger:** automatic. A queued job fires when a work item's `employee_id`
  or `due_at` changes (create, update, assign, reassign).
- **Direction:** one-way, app → Google Calendar. Edits made directly in
  Google Calendar are never read back.
- **Event lifecycle:**
  - `due_at` set and assignee has a connected calendar → event created/updated.
  - `due_at` cleared, work item archived (`archived_at` set) or done
    (`status = done` / `done_at` set), or work item deleted → event deleted.
  - Assignee changes → event deleted from the old assignee's calendar,
    created fresh on the new assignee's calendar (different Google account,
    can't "move" an event across calendars via the API without both parties'
    tokens in the same request; delete+recreate is simpler and correct here).
  - No connected calendar for the assignee → job is a no-op, nothing queued
    to retry later. Sync happens automatically next time the item changes.
  - Assignee has no `user_id` (`Employee::user()` is nullable — an employee
    without a login) → same no-op path.
- **Event shape:** all-day event on `due_at`, summary = work item title,
  description links back to the item (`route('work-items.show', $item)` or
  equivalent). `due_at` is a `date` cast (no time component), so an all-day
  event is the correct fit, not a timed one. Google all-day events use an
  **exclusive** end date — `end.date` must be `due_at + 1 day`, not `due_at`
  itself, or the API rejects/mis-renders the event.
- **Connecting an account:** a user opts in from their own profile/settings
  page with a "Connect Google Calendar" button — separate from the existing
  SSO login (`app/Services/OidcClient.php`), which only requests
  `openid email profile` and is not Google-specific (generic OIDC, could
  point at any provider). This feature needs a dedicated Google OAuth client
  with `https://www.googleapis.com/auth/calendar.events` scope and
  `access_type=offline` (to get a refresh token). Only the connecting user's
  own tokens are ever stored/used — the connect callback validates `state`
  and writes the row against `auth()->id()`, not any ID from the request.
- **Credentials:** Shazwan creates the OAuth client in Google Cloud Console
  (enable Calendar API, configure consent screen, add the
  `calendar.events` scope) and supplies `GOOGLE_CLIENT_ID` /
  `GOOGLE_CLIENT_SECRET`. Until then the feature is built and tested against
  `Http::fake()` but can't be exercised end-to-end locally or on staging.
- **No new Composer dependency.** Raw HTTP via Laravel's `Http` facade
  against Google's OAuth token endpoint and the Calendar v3 REST API — same
  approach the existing OIDC client already uses for a third-party OAuth
  flow, no `google/apiclient` needed.
- **Queue:** dispatched on the existing `database` queue connection
  (`QUEUE_CONNECTION=database`, already relied on for invite/digest mail per
  `docs/RULES.md`). Prod currently has a known, unrelated queue bug (jobs
  failing with an eval'd-Closure error since 2026-07-31) — out of scope
  here, staging is the real test target for this feature per this repo's
  release process.

## Data model

**New table `google_calendar_connections`**
| column | type | notes |
|---|---|---|
| `user_id` | FK → `users`, unique | one connection per user |
| `access_token` | text, `encrypted` cast | |
| `refresh_token` | text, `encrypted` cast | |
| `expires_at` | timestamp | access token expiry |

**`work_items` gets one new column:**
| column | type | notes |
|---|---|---|
| `google_event_id` | string, nullable | Google's event ID; null = not synced |

## Components

- `App\Models\GoogleCalendarConnection` — belongs to `User`.
- `App\Services\GoogleCalendarClient` — thin wrapper: `refreshTokenIfNeeded()`,
  `createOrUpdateEvent(WorkItem $item, User $user)`, `deleteEvent(string $eventId, User $user)`.
  Talks to Google via `Http::` calls only.
- `App\Http\Controllers\GoogleCalendarConnectionController` — `redirect()`
  (build consent URL with signed `state`), `callback()` (validate `state`,
  exchange code for tokens, store row against `auth()->id()`),
  `destroy()` (disconnect).
- `App\Observers\WorkItemObserver` (new, or add to an existing observer if
  one already exists for `WorkItem` — check before creating) — on
  `updated`/`created`/`deleted`, if `employee_id` or `due_at` changed, or the
  item was archived/done/deleted, dispatch `SyncWorkItemCalendarEventJob`.
  **Dispatch scalars captured at observer time, not the model**: the job
  must not take a `WorkItem` instance. On delete, or on reassignment, the
  model may be gone or already carrying the new `employee_id` by the time
  the job runs. Pass `work_item_id`, the *old* `google_event_id` (to delete),
  the *old* assignee's `user_id` (via `$item->getOriginal('employee_id')` →
  `Employee::find(...)->user_id`), and the resolved action
  (`create`/`update`/`delete`). Same reason queued mail jobs don't hold
  Eloquent models that might not exist by the time the job runs.
- `App\Jobs\SyncWorkItemCalendarEventJob` — the actual create/update/delete
  decision tree described under Event lifecycle above, operating on the
  scalar payload above (re-fetching the current `WorkItem` and current
  assignee only when the action is `create`/`update`). Idempotent: safe to
  run twice for the same state. Tenant context: check how the existing
  queued invite/digest mail jobs resolve tenant (`BelongsToTenant` on
  `WorkItem` means tenant reads fail-open per this repo's known gotcha) and
  follow the same pattern here.

## Testing

- `Http::fake()` for both the token endpoint and the Calendar v3 endpoints —
  no real network calls in tests.
- Feature test: changing `due_at` or `employee_id` on a `WorkItem` dispatches
  the job (`Bus::fake()` / `Queue::fake()`).
- Unit tests on the job: create path, update path (existing `google_event_id`),
  delete path (due date cleared / archived / done / deleted / reassigned),
  no-op path (assignee has no connection).
- Connection controller test: callback stores the row against the
  authenticated user only, `state` mismatch is rejected.

## Out of scope

- Two-way sync (reading changes back from Google Calendar).
- Syncing anything other than the due date (no reminders config, no
  attendees, no recurring events).
- The profile Work & Tasks tab visual redesign (dropped, see Scope).
- Fixing the pre-existing prod queue bug.
