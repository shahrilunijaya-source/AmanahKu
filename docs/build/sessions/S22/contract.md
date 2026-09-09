# Session S22 contract: CR-24 Big Deal Alert

Shapes are pinned by `tests/Acceptance/CR24Test.php` and the OPEN entry
"QA / CR-24 / shapes fixed by CR24Test". This file records how each numbered
acceptance item is verified and what gets touched.

## Files touched

- `database/migrations/2026_09_09_120000_create_big_deal_tables.php` — new tables
  `big_deals`, `big_deal_photos`, `big_deal_members`, `big_deal_reactions`.
- `app/Models/BigDeal.php` — new. Tenant-scoped (`BelongsToTenant`), `isActive()`
  (now < published_at + 3 days). Photos/members/reactions stay raw `DB::table`
  access (award_reactions/award_comments convention), no extra models.
- `app/Http/Controllers/BigDealController.php` — new. `store()` (raise),
  `photo()` (stream a photo), `react()` (CR-30 toggle), `screenData()` (Wins).
- `app/Support/DashboardBands.php` — add `bigDealMoments()` builder, one Moment
  array per active deal (team payload, split story, photo ids, meta line).
- `app/Http/Controllers/Concerns/BuildsDashboardWidgets.php` — `dashboardBands()`
  fetches active `BigDeal`s and appends `DashboardBands::bigDealMoments()` to
  `$moments`.
- `resources/views/partials/dash/bands.blade.php` — a `$isBigDeal` branch in the
  existing moments loop: `data-big-deal="<id>"`, team avatars, "What it took"
  story block, photo strip, reaction region. Reuses the existing `.uj-db-art`
  block (already gated on `$plain`) for the confetti-drift ornament — no new
  plain-mode branching needed there.
- `resources/views/partials/dash/big-deal-react.blade.php` — new. Reaction
  picker (`partials.reaction-picker`) + a small custom tally (NOT the shared
  `partials.reaction-tally`, see trap below), fetch-and-swap like
  `partials/dash/birthday-wishes.blade.php`.
- `resources/views/screens/wins.blade.php` — new. The Playground chrome (guide
  panel), one card per deal, `[data-win="<id>"]`.
- `app/Support/Amanahku.php` — nav entry `wins` under "The Playground", `page()`
  entry for breadcrumb/title.
- `app/Http/Controllers/AppController.php` — `screenData()` match arm
  `'wins' => app(BigDealController::class)->screenData($request, $employee)`.
- `routes/web.php` — `POST /app/big-deals`, `GET /app/big-deals/{deal}/photos/{photo}`,
  `POST /app/big-deals/{deal}/react`. `/app/wins` needs no new route, it rides
  the existing `/app/{screen}` catch-all.
- `resources/views/partials/ts-project-row.blade.php` + `ProjectController::screenData()`
  (+ `screens/projects.blade.php` include list) — "Mark as Big Deal" ghost
  button and inline form for PM-and-above (`canRaiseBigDeal`), same pattern as
  the existing Variations panel.
- `resources/css/app.css` — the `.uj-bd-*` rules from the approved mockup,
  appended near the existing `.uj-db-*` band rules (~4988-5060).

## Schema

One migration, four tables (mirrors `2026_09_22_100000_create_award_tables.php`
/ `..._create_award_engagement_tables.php` bundling):

- `big_deals`: id, tenant_id, type, title, story (text, nullable), raised_by
  (employees.id), project_id (nullable, projects.id), work_item_id (nullable,
  work_items.id), track_ref (nullable string), client_contact (nullable
  string), names_approved (bool, default false), source_path (nullable
  string), published_at (datetime), timestamps.
- `big_deal_photos`: id, big_deal_id, path, timestamps.
- `big_deal_members`: id, big_deal_id, employee_id, timestamps.
- `big_deal_reactions`: id, tenant_id, big_deal_id, employee_id, reaction
  (string 40), timestamps, unique(big_deal_id, employee_id, reaction) — same
  shape as `award_reactions`; the controller still does delete-then-insert for
  one-row-per-person semantics, the unique index is a race guard only.

## Roles

"PM and above ... Director can also raise" (CR text) = roles.md's "PM and
above" = `manager`, `hr`, `management`, `director` — matches the OPEN shapes
entry and CR24Test (employee 403, manager/director 200).

## Acceptance items → verification

1. **PM raises with 3 photos, director can too, 4th photo refused.**
   `BigDealController::store()`: `authorizeTenantRole` against the four roles
   (403 for employee), validates `type` in the fixed list, `photos` max 3,
   stores photos to `local` disk (`big-deals/photos`), members to
   `big_deal_members`, writes `AuditLog::record('big_deal.raised', "big_deal:{id}")`.
   `photo()` streams via `Storage::disk('local')->response()`, tenant-checked.
2. **Banner on every dashboard, team avatars, photos, story, grid untouched,
   other tenants see nothing.** `dashboardBands()` fetches
   `BigDeal::with(['*'])` where active, appends to `$moments`; tenant isolation
   is free (BelongsToTenant global scope). `bands.blade.php` renders the
   `$isBigDeal` block inside the existing `uj-db-moments` wrapper, above the
   widget grid, no `data-band="..."` attribute (that marker is reserved for
   the management/awards slots).
3. **Reactions.** `big-deal-react.blade.php` uses the real CR-30 picker
   (`data-reaction-pick`) restricted to `Reaction::active()`, and a custom
   tally markup (digit as the first text node — the shared
   `reaction-tally.blade.php`'s nested `<span aria-hidden>` before the count
   fails the test's `[^<]*2` regex, see trap below). `react()` mirrors
   `AwardController::react()`/`BirthdayWishController::react()` exactly
   (delete-then-insert, one row per person, no notification).
4. **3-day window, then Wins.** `BigDeal::isActive()` = `now() <
   published_at->addDays(3)` (matches the test's 09:59/10:01 edge exactly).
   `dashboardBands()` only includes active deals. Wins
   (`BigDealController::screenData`) lists every deal ever raised, newest
   first — no window filter, so it survives a month later (test's last
   assertion) — see OPEN entry below for the "still-active deals on Wins too"
   default.
5. **Fixed types, compliment needs source, names hidden until approved, card
   origin recorded.** Validation `type` in the 7-value enum; `source`
   `required_if:type,client_compliment`; `client_contact` only rendered in the
   banner meta line when `names_approved` is true; `work_item_id` stored
   as-is when passed instead of `project_id`.
6. **Keep it plain.** No new plain-mode branching: the big-deal moment reuses
   `.uj-db-art` (already stripped under `data-plain`) for its only ornament,
   never emits `<canvas>`/`<audio>`/`uj-db-confetti` (those are
   birthday-specific), so the existing `$plain` guards in `bands.blade.php`
   cover it for free.

## Contracts touched

None. Dashboard slot rule honoured: one more `moments` entry, no new band, no
existing card moved.

## Port calls

None — nothing external (Track ref is a free-text field, no Track call).
