# Session S14 contract: CR-21 (Office Requests / Permintaan Pejabat)

Shapes are already fixed by OPEN "QA / CR-21 / shapes fixed by CR21Test" — this contract is the
concrete file list and per-item verification, not a re-decision. A few gaps that entry leaves
open (due date for the T.A.A. card, the exact insights month-window semantics, the module
toggle) are decided below and logged to `docs/build/OPEN.md`.

## Files touched

### Migrations (new)
- `database/migrations/2026_09_19_100000_create_office_requests.php` —
  - `office_requests`: `tenant_id`, `employee_id` (requester), `category` (string 20:
    facilities|vehicle|pantry|it|cleaning|other), `title` (string 160), `description` (text),
    `photo_path` (nullable string), `location` (string 160), `urgency` (string 10, default
    normal: low|normal|urgent), `urgency_reason` (nullable string), `status` (string 20,
    default open: open|in_progress|done), `admin_note` (nullable text), `closing_note`
    (nullable text), `done_at` (nullable timestamp), `work_item_id` (nullable FK work_items,
    null on delete), `votes` (unsigned int, default 0), plus scope-5 vehicle fields
    `vehicle_plate` (nullable string 20), `vehicle_mileage` (nullable unsigned int),
    `vehicle_last_service_at` (nullable date), timestamps.
  - `office_request_votes`: `tenant_id`, `office_request_id`, `employee_id`, timestamps,
    unique (`office_request_id`, `employee_id`).
  - `office_request_comments` (scope 2): `tenant_id`, `office_request_id`, `employee_id`,
    `body` (text), timestamps.

No change to `work_items` schema — `WorkItem::LABELS` gets a new `office` entry (a PHP const,
not a migration).

### Models (new)
- `app/Models/OfficeRequest.php` — `BelongsToTenant`, `AuditsChanges` +
  `HasAuditedFields::audited()` = `['category','urgency','status','admin_note','closing_note',
  'done_at']` (creation itself is always audited by the trait; `done_at` changing is what
  makes the "done" audit row's action contain "done").
- `app/Models/OfficeRequestVote.php` — `BelongsToTenant` only (no audit row; Global Clause
  marks upvote optional).
- `app/Models/OfficeRequestComment.php` — `BelongsToTenant` only.

### Controller (new)
- `app/Http/Controllers/OfficeRequestController.php` — `screenData()` (board), `store()`
  (raise), `similar()`, `upvote()`, `comment()`, `adminNote()`, `done()`, `reopen()`,
  `photoShow()`, `insightsData()` (shared by the JSON and HTML branches).

### AppController (existing file, extended — same pattern S13 used for `eventShow`)
- `screenData()` match: add `'office-requests' => app(OfficeRequestController::class)
  ->screenData($request, $employee),`.
- New `officeRequestInsights(Request): ViewContract|JsonResponse` action, mirroring
  `eventShow()`: gates PM-and-above (`Permissions::effectiveRole` in manager/hr/management),
  calls `OfficeRequestController::insightsData()`, returns JSON when `wantsJson()`, else
  `wrapScreen(..., 'screens.office-requests-insights')`.

### Views (new)
- `resources/views/screens/office-requests.blade.php` — board (Open / In Progress / Done),
  raise form (category, title, description, photo, location, urgency + reason, live `/similar`
  duplicate check), pantry wishlist sorted by votes, admin-note/done/reopen actions via
  `fetch()` (no full-page reload), bilingual labels via `$store.ui.lang` per existing screens.
- `resources/views/screens/office-requests-insights.blade.php` — requests / avg days to close /
  by-category / top-voted, PM-and-above only (route-gated).

### Nav / support
- `app/Support/Amanahku.php` — new nav row: a new top-level section "Office Requests" /
  "Permintaan Pejabat" (single item `id => 'office-requests'`), positioned right after the
  Workplace section's last row (`projects`) and before Pay & Benefits — the spec's own words
  are "new top-level left-panel item (below Workplace)", i.e. a new section, not a Workplace
  child. `sectionIcon()` gets a matching wrench glyph. `page()` gets an `'office-requests'`
  entry (title/sub/crumb, EN+BM).
- `app/Models/WorkItem.php` — `LABELS` gains `'office' => ['Office Request', '<colour>']`.

### Routes (`routes/web.php`, inside the existing tenant-scoped write-paths block, alongside
the `events` block — GET board itself needs no new route, it rides the existing
`/app/{screen?}` catch-all with `$screen = 'office-requests'`)
- `POST /app/office-requests` → `office-requests.store`
- `GET /app/office-requests/similar` → `office-requests.similar` (registered before the
  `{officeRequest}` routes, same static-segment-first caution as `events.photos.show`)
- `GET /app/office-requests/insights` → `office-requests.insights` (`AppController
  ::officeRequestInsights`)
- `GET /app/office-requests/{officeRequest}/photo` → `office-requests.photo`
- `POST /app/office-requests/{officeRequest}/upvote` → `office-requests.upvote`
- `POST /app/office-requests/{officeRequest}/comments` → `office-requests.comments.store`
- `POST /app/office-requests/{officeRequest}/admin-note` → `office-requests.admin-note`
- `POST /app/office-requests/{officeRequest}/done` → `office-requests.done`
- `POST /app/office-requests/{officeRequest}/reopen` → `office-requests.reopen`

Route-model binding is NOT tenant-scoped (`SubstituteBindings` runs before `ResolveTenant` —
see the standing memory note and `EventController`'s own explicit checks): every action that
receives an `OfficeRequest` via binding re-checks `$officeRequest->tenant_id === app
(CurrentTenant::class)->id()` itself, same as `EventController::photoShow`.

### Tests (new)
- `tests/Feature/OfficeRequestTest.php` — 403s (non-admin-team note/done, non-requester
  reopen), urgency-reason requirement, duplicate-vote idempotency, reopen window boundary,
  tenant isolation on the photo/upvote/admin-note/done/reopen routes.

## Decisions this session adds to OPEN.md (beyond the frozen CR21Test shape)

1. **Card due date.** The frozen shape is silent on `work_items.due_at` for the T.A.A. card a
   request creates. Dates contract Rule 1 makes it mandatory for new work rows but only
   DB-nullable, and CR21Test never asserts on it. Decided: `due_at` = raise date + 1 day
   (urgent) or + 5 days (normal/low), mirroring the recurring engine's "compute a due date at
   creation" pattern rather than leaving it null.
2. **Module toggle.** Not registered in `Features::MODULES` — Office Requests is always on,
   like the other un-toggleable core surfaces, because no acceptance item exercises a toggle
   and adding one un-tested would be scope with no test behind it. Reversal is a one-line
   registry entry.
3. **Insights month window.** `requests`, `by_category` and `top_voted` are scoped by
   `created_at` (requests **raised** that month); `avg_days_to_close` is scoped by `done_at`
   (requests **done** that month), per the OPEN shape entry's own wording ("avg_days_to_close
   over requests done in that month"). All twelve of CR21Test's fixture rows are raised and
   closed within the same September window, so this split is untestable from the acceptance
   test alone; it is the literal reading of the frozen text.
4. **`/eta` and reassign routes: not built.** The session brief lists `POST /{id}/eta` as
   optional (scope 3) and CR-21 scope 3 mentions admin reassigning within the team, but the
   frozen CR21Test route list (the actual contract for this session) includes neither, and no
   acceptance or the brief's own test list exercises them. Skipped rather than inventing an
   untested surface; admin reassignment of the underlying card is already reachable through the
   existing generic `POST /app/board/{card}/reassign` (CR-18, HR/management) without new code.
5. **Scope 4 (recurring pantry staples): no new code.** The brief's own text says a pantry
   staple "can be created as a `recurring_tasks` row through the existing CR-18 admin screen"
   — that screen (`/app/recurring`) already exists and needs nothing added for this to work.

## Verification per acceptance item (`tests/Acceptance/CR21Test.php`)

1. `test_acceptance_1_...` — `store()` creates the `office_requests` row (category, status
   open, `photo_path` set via `ImageCompressor`), the first vote row, one `work_items` card
   (`employee_id` = the Finance Manager position holder, `labels` contains `office`,
   `archived_at` null), admin-department employees attached as `helper` participants, the card
   visible on the owner's and a helper's `/app/board`, the raise itself audited
   (`AuditsChanges` on `OfficeRequest::create`), and the board screen + sidebar showing both
   `Office Requests` and `Permintaan Pejabat`.
2. `test_acceptance_2_...` — `similar()` case-insensitive substring match on open requests
   returning `{id, title, votes}`; `upvote()` idempotent via `firstOrCreate` on
   `office_request_votes` + a guarded `increment('votes')`; no second `office_requests` row.
3. `test_acceptance_3_...` — `adminNote()`/`done()` 403 for a non-admin-team member (not the
   card owner, not a helper, not HR/management); `done()` requires a `note` (422 without one),
   sets `status=done`, `done_at`, `closing_note`, mirrors the card to `status=done`, notifies
   every voter (`office_request_votes` → `employees.user_id`) via `AppNotification::send`,
   audited with `action` containing `done`; `reopen()` 403 for a non-requester, `422` outside
   the 3-day window (`now()->diffInDays($done_at) > 3`), reuses the same `work_item_id` (no
   second card).
4. `test_acceptance_4_...` — `store()` `urgency_reason` `required_if:urgency,urgent` → 422 with
   the field named; urgent card created at `priority=high`; `AppNotification::send` to the
   resolved owner and every `tenant_user` row with role in `Permissions::MANAGEMENT_TIER`, and
   nobody else; a normal-urgency raise sends none of that (director's notification count stays
   at 1 from the earlier urgent raise).
5. `test_acceptance_5_...` — `AppController::officeRequestInsights` 403s a plain employee,
   200s `manager`/`director`; JSON shape `requests` (12), `avg_days_to_close` (1.8 ± 0.01,
   `created_at`→`done_at` in days), `by_category` (2 each across the 6 categories),
   `top_voted[0]` = the 3-vote row; the HTML branch renders the same three numbers/strings.
   Final assertion (`WorkItem::where('status','done')->whereJsonContains('labels','office')
   ->count() === 12`) needs no extra code — it is the ordinary Done & Dusted read over
   `work_items`, already satisfied by `done()` writing `status=done` on the card.

`test_always_checks` (the four `AlwaysChecks` cross-cutting probes) needs no CR-21-specific
code: the due-date lock and audit-immutability guards live on `WorkItem`/`AuditLog` already;
the dashboard-unchanged and keep-it-plain checks pass as long as CR-21 adds no dashboard widget
(it does not) and no new elements collide with `uj-db-band`/`uj-db-confetti`.
