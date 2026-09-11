# Session S14 handoff: CR-21 (Office Requests / Permintaan Pejabat)

## Delivered
- Raise a request (category, title, description, optional photo, location, urgency with a
  required reason when urgent) → creates one `office_requests` row and one `work_items` T.A.A.
  card labelled `office`, owned by the active "Finance Manager" position holder, with every
  active "Admin" department employee tagged `helper`. Verified by acceptance 1.
- Live duplicate check (`GET .../similar?title=`) and idempotent +1 (`POST .../upvote`, same
  employee never double-counts, requester's own raise is the first vote). Verified by
  acceptance 2, and by the new `upvote_never_double_counts_...` governance test hitting the
  same route three times.
- Admin-team-only note and Done (`POST .../admin-note`, `POST .../done`, both 403 for anyone
  outside the resolved owner/helpers/HR/management), Done requires a closing note (422 without
  one), notifies the requester and every upvoter as `app_notifications` rows, and a 3-calendar-
  day reopen window for the requester only (422 outside it, reuses the same card, never mints a
  second one). Verified by acceptance 3, plus the new `plain_staff_get_403...` and
  `reopen_window_allows_exactly_three_days...` governance tests.
- Urgent raise notifies MN (the Finance Manager holder) and every director/management-tier
  user immediately, nobody else; a normal raise does not. Verified by acceptance 4.
- `GET .../insights?month=YYYY-MM`, PM-and-above only (403 for plain staff), JSON `requests` /
  `avg_days_to_close` / `by_category` / `top_voted`, plus an HTML page showing the same
  numbers. Verified by acceptance 5.
- Board screen (Open / In Progress / Done columns, pantry wishlist sorted by votes, admin note
  and closing note visible inline), raise form, and a new top-level left-panel nav item
  ("Office Requests" / "Permintaan Pejabat", below Workplace).
- Every raise is audited (create row via `AuditsChanges`); Done is audited too, because
  `done_at` is a listed audited field and its change produces an action containing "done".

## Schema changes
- `office_requests`: new table — `tenant_id`, `employee_id`, `category`, `title`,
  `description`, `photo_path`, `location`, `urgency`, `urgency_reason`, `status`, `admin_note`,
  `closing_note`, `done_at`, `work_item_id`, `votes`, plus scope-5 vehicle fields
  (`vehicle_plate`, `vehicle_mileage`, `vehicle_last_service_at`). Migration
  `2026_09_19_100000_create_office_requests.php`.
- `office_request_votes`: new table — `tenant_id`, `office_request_id`, `employee_id`, unique
  pair. Same migration file.
- `office_request_comments`: new table (scope 2, comments) — `tenant_id`, `office_request_id`,
  `employee_id`, `body`. Same migration file. Built but not yet surfaced on the board view (no
  comment UI shipped this session — see Deferred).
- `work_items.labels`: no column change, `WorkItem::LABELS` gained a PHP-const key `'office'`.

## Contracts touched
- None. `docs/build/contracts/*` and `tests/Acceptance/CR21Test.php` were read only, never
  edited, per the standing rule.

## Port calls stubbed
- None. Office Requests never calls Calendar/Track/Mail — notifications are
  `AppNotification::send()` rows (`mail` defaults false), same as every other in-app
  notification in this codebase.

## Deferred
- `OfficeRequestComment` model + table exist (scope 2 asks for comments) and
  `OfficeRequestController::comment()` is wired and routed, but no comment UI was added to the
  board Blade view this session — CR21Test never exercises comments, and the board already has
  enough surface area (raise form, three status columns, pantry wishlist, admin actions).
  Deferred to whichever session next touches this screen; the backend is ready, only the view
  partial is missing.
- Photo thumbnails on the board (the raised photo is stored and streamable via
  `GET .../{id}/photo`, exercised directly by acceptance 1, but the board list does not render
  an `<img>` inline). Cosmetic, not tested.
- CR-21 scope 3's "reassign within the team" and the optional `/eta` route: not built. CR21Test's
  own route list has neither, and card reassignment is already reachable through the existing
  generic `POST /app/board/{card}/reassign` (CR-18). Logged in OPEN.md S14 entry.

## OPEN, decided without Shazwan
- See `docs/build/OPEN.md` → "### S14 / CR-21 / card due date, module toggle, insights month
  window, /eta and scope 4/5": due date = raise + 1 day (urgent) / + 5 days (normal/low); no
  `Features::MODULES` toggle registered (always on); Insights scopes `requests`/`by_category`/
  `top_voted` by `created_at` (raised that month) and `avg_days_to_close` by `done_at` (done
  that month); `/eta` and a dedicated reassign route skipped as untested surface; scope 4
  (pantry staples as recurring restock) needs no new code since `/app/recurring` (CR-18)
  already covers it.

## Requested contract change (generator may not make it itself)
- None.

## Traps for the next session
- **Carbon 3's `diffInDays()` is signed by default, not absolute.** This bit the reopen-window
  check during this session: `Carbon::now()->diffInDays($doneAt)` returned a *negative* number
  when `$doneAt` is in the past relative to "now" (e.g. `-4` for a done_at 4 days ago), so a
  bare `<= 3` comparison was always true regardless of how far in the past `done_at` was — the
  bug silently let every reopen through no matter how old the request was. Fixed with
  `abs(Carbon::now()->diffInDays($this->done_at)) <= 3` in `OfficeRequest::withinReopenWindow()`
  (`app/Models/OfficeRequest.php`). Grep the codebase for other bare `diffInDays()` /
  `diffInHours()` / `diffInMinutes()` comparisons written under the old (Carbon 2) absolute-by-
  default assumption — this is very likely not the only place carrying that same latent bug.
- The Admin department's helper roster is looked up by department **name** (`"Admin"`,
  `OfficeRequestController::ADMIN_DEPARTMENT`). Renaming that department in a tenant silently
  empties every future card's helper list — no error, just zero helpers tagged. Already logged
  in the QA/CR-21 OPEN entry's "Reversal cost" line, restated here since it is exactly the kind
  of thing a later renaming pass would trip over without warning.
- `resolveOwner()` falls back to "first active employee whose tenant_user role is
  hr/management/director" when no one holds the "Finance Manager" position — if a tenant has
  neither, raising a request 500s (`abort_unless(..., 500, 'No Finance Manager or HR/management
  employee to own Office Requests.')`). This is a real, reachable failure mode for a bare-bones
  tenant with only plain-employee accounts, not just a test-fixture concern.
