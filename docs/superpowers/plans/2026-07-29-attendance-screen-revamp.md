# Attendance screen revamp

Port the approved prototype at `public/_attendance-revamp.html` (gitignored scratch mock)
into the real attendance screen. Same workflow the dashboard revamp used: mock approved
first, then CSS ported into a namespaced block in `resources/css/app.css`, then the Blade
rewritten against those classes.

Namespace: `uj-at-` ("attendance"). Chosen so it cannot collide with the existing
`uj-dq-` (dashboard queue) or the shared `uj-card` / `uj-btn-*` vocabulary.

## What the revamp changes, and why

The current screen is a two-column split: a centred "clock card" on the left and a
"This week" list on the right. Findings that drove the redesign:

- The big 44px number is the **wall clock**, which the OS status bar and the sidebar
  dock (`.uj-sb-clock`, present on every screen) already show. It carries no
  shift information.
- The geofence verdict only appears **after** you submit, when the justification
  textarea turns red. There is no pre-flight warning.
- The week list is headed "This week" but the controller fetches **30 days**
  (`BuildsWorkData::attendanceData()`), so out-of-week rows appear under it.
- The card footer repeats `$site->label` and "location captured at check-in", both of
  which already appear in the expectation strip and the page subtitle.
- The always-open remarks textarea adds resting weight to what is a one-tap action.
- Week rows pack six elements at ≤13.5px with the times inside a run-on mono string,
  so no column scans.

The revamp answers three questions in order: where am I meant to be and am I there;
clock; how is my week going.

## Layout targets

- **Desktop reference width 1920.** The screen is a single centred column, max-width
  920px. A single-task screen must not spread the figure and the action button
  1600px apart.
- **Mobile ≤640px.** The shelf compacts, chips become a 2-up grid, and the clock
  action moves into a fixed bottom dock so it stays under the thumb.

## Contract that must not change

The POST contract is load-bearing and `AttendanceController::clock()` depends on it:

- `POST route('attendance.clock')`, `enctype="multipart/form-data"`, `@csrf`
- Fields: `action` (`in`|`out`), `latitude`, `longitude`, `photo`, `justification`
- Server round-trip state: `session('attendance_justify')`,
  `$errors->has('justification')`, `old('justification')`

Also preserved: the in-page webcam modal with its full `getUserMedia` error handling,
the `partials.guide` banner, bilingual EN/BM via `$store.ui.lang`, and Alpine as the
only client-side mechanism (no vanilla `<script>` blocks, no new dependencies).

---

## Task 1 — CSS: the `uj-at-` block

**Files:** `resources/css/app.css`

Append a namespaced block, following the structure and comment style of the existing
`uj-dq-` dashboard block at the end of the file. Port the rules from
`public/_attendance-revamp.html`, dropping everything under its "mock chrome" and
"app shell stub" comment banners — those are prototype scaffolding (`.mk-*`, `.shell`,
`.sb*`, `.hd*`, `.phone*`, `.main`) and must not be ported.

Classes to port, with their roles:

| Class | Role |
|---|---|
| `.uj-at-wrap` | centred column, `max-width:920px` |
| `.uj-at-shelf` | warm banner (`#ece9e1` on `#ddd9cf`) holding the whole clock action |
| `.uj-at-kicker` | "Attendance · Tuesday 28 July" |
| `.uj-at-fig` / `.uj-at-figsub` | 54px mono figure and its subline |
| `.uj-at-where` / `.uj-at-fence` | expectation sentence with inline geofence verdict |
| `.uj-at-acts` / `.uj-at-ghost` / `.uj-at-go` | selfie, remark, and the primary punch button |
| `.uj-at-note` | collapsing remark box (`grid-template-rows` 0fr→1fr) |
| `.uj-at-chips` / `.uj-at-chip` | the four figures |
| `.uj-at-list` / `.uj-at-listhd` / `.uj-at-seeall` | week heading and its links |
| `.uj-at-day` / `.uj-at-row` / `.uj-at-tile` / `.uj-at-rowmid` / `.uj-at-rowt` / `.uj-at-rows` / `.uj-at-rowend` / `.uj-at-status` / `.uj-at-chev` | the disclosure row |
| `.uj-at-panel` / `.uj-at-panel-in` / `.uj-at-quote` / `.uj-at-evid` / `.uj-at-shot` / `.uj-at-where-line` | the expanded day |
| `.uj-at-empty` | empty state |
| `.uj-at-dock` | **new**, not in the mock: mobile fixed bottom action bar |

Two rules carry a trap and must keep their prototype comments:

1. `.uj-at-note` animates `grid-template-rows` only. The 16px gap rides on the label's
   `padding-top`, **inside** the clipped child. Padding on the clipped `<div>` itself
   survives a `0fr` row and leaves a permanent 16px ghost.
2. `.uj-at-fence[data-f="wait"] i` is a transient loading pulse that stops once the
   geolocation call resolves. It is not a decorative infinite animation.

Colour tokens: add `--shelf:#ece9e1` and `--shelf-line:#ddd9cf` to `:root`. Everything
else must use existing tokens.

Also add a `@media (prefers-reduced-motion: reduce)` block matching the prototype's,
and a `@media (hover: none)` block that neutralises row hover backgrounds.

**Tests:** none. CSS has no test surface in this repo.

**Acceptance:**
- `bun run build` succeeds and emits a new `public/build/assets/app-*.css`.
- No existing selector in `app.css` is modified or removed.
- No `.mk-*`, `.shell`, `.sb-*`, `.hd-*`, `.phone`, `.ph-*` rule is ported.

---

## Task 2 — Controller: week slice and the four figures

**Files:** `app/Http/Controllers/Concerns/BuildsWorkData.php`,
`tests/Feature/AttendanceScreenDataTest.php` (new)

`attendanceData(?Employee $employee): array` currently returns `records` (30 days),
`today`, and `site`. Extend it to return, additionally:

- `weekRecords` — records whose `date` falls in the current week (Monday start),
  newest first. This is what the "This week" list renders, and it is the fix for the
  heading that currently lies.
- `earlierRecords` — the remaining fetched records, older than this week.
- `weekWorkedMinutes` — `int`, sum of `worked_minutes` over `weekRecords`.
- `weekBaselineDeltaMinutes` — `int`, `weekWorkedMinutes` minus the expected minutes
  for the **completed** days in `weekRecords`. `attendance_records.expected_min_hours`
  already stores the expectation the punch was judged against, so the expected figure
  is `round(expected_min_hours * 60)` summed over records that have a `clock_out`.
  Rows with a null `expected_min_hours` contribute nothing to either side. This is
  exact: no schedule needs re-resolving, and the delta is measured against the same
  expectation the flags were raised from.
- `lateThisMonth` — `int`, count of records in the current calendar month with
  `status === 'late'`.
- `offSiteThisMonth` — `int`, count of records in the current calendar month whose
  `flags` contain `out_of_radius_in` or `out_of_radius_out`.

Compute all of these from the already-fetched `$records` collection. Do not add
queries. When `$employee` is null, return zeros and empty collections.

**Tests to write** (`tests/Feature/AttendanceScreenDataTest.php`, PHPUnit):

- `test_week_records_exclude_days_before_this_week`: seed a record dated last week and
  one dated this week; assert `weekRecords` contains only the current-week one and
  `earlierRecords` contains the other.
- `test_week_worked_minutes_sums_only_current_week`: seed two current-week records and
  one older; assert the sum covers only the two.
- `test_late_this_month_counts_only_current_month_late_records`: seed a late record
  this month, a late record last month, and an on-time record this month; assert the
  count is 1.
- `test_off_site_this_month_counts_either_radius_flag`: seed one record flagged
  `out_of_radius_in`, one flagged `out_of_radius_out`, one with no flags; assert 2.
- `test_returns_zeros_for_employee_without_records`: assert every figure is 0 and both
  collections are empty.

**Acceptance:**
- `php artisan test --compact tests/Feature/AttendanceScreenDataTest.php` passes.
- `vendor/bin/pint --dirty --format agent` reports no remaining issues.
- The existing `records`, `today` and `site` keys still return exactly what they
  returned before. Other screens read them.

---

## Task 3 — Blade: rewrite the screen

**Files:** `resources/views/screens/attendance.blade.php`

Rewrite against the Task 1 classes and the Task 2 data. Structure, top to bottom:

1. `partials.guide` — unchanged, keep the existing EN/BM copy verbatim.
2. `.uj-at-wrap` containing the shelf and the list. The standalone
   `partials.see-all-btn` include is **removed**; its destination moves into the week
   heading as an "All staff attendance →" text link, still gated on `$qaCanSeeAll`.
3. **The shelf**, one Alpine component owning the whole punch:
   - Kicker: `Attendance · {{ now()->format('l j F') }}`.
   - Figure, three states driven by `$today`:
     - no `clock_in` → live wall clock; subline `shift starts HH:MM · you are Nm early/late`, computed live against `$site->workStart`.
     - `clock_in` and no `clock_out` → **live elapsed worked**, ticking; subline `worked · in since HH:MM, ends HH:MM`.
     - `clock_out` present → final worked total; subline `worked · HH:MM – HH:MM · done for today`.
   - Where-line: site type, label, work window, radius, plus the `.uj-at-fence` chip.
     The chip starts at `data-f="wait"` and resolves to `in` or `out` from a
     `navigator.geolocation` reading taken **on load**, using the existing `distM()`
     haversine already in the file. This is the pre-flight warning that does not
     exist today. When geolocation is unavailable or denied, leave the chip off
     entirely rather than claiming a verdict.
   - Actions: selfie ghost button (opens the existing webcam modal on desktop, the
     native file input on coarse pointers), remark ghost button, and the primary
     punch button. Keep the existing submitting spinner.
   - `.uj-at-note`: collapsed by default. Opens on the remark button, and opens
     itself with `data-req` when the fence reads `out` or the clock-out is early —
     the same two conditions the current `justify` flag already covers. Re-collapses
     when the requirement lifts and the textarea is empty.
   - Chips: the four Task 2 figures.
4. **The week list**: heading `This week` plus `· {worked} over {n} days`, the
   see-all link, then one `.uj-at-day` disclosure per `weekRecords` row. Row carries
   the tinted date tile, title, mono `in–out · worked · location`, and the status
   pill. The expanded panel carries a plain-English account of the day, the employee's
   note(s) in `.uj-at-quote`, the clock-in selfie thumbnail when `photo_url` is set,
   and a `.uj-at-where-line` sentence describing where the punch landed. An
   "Earlier days →" link follows when `earlierRecords` is non-empty.
5. **Empty state** when `weekRecords` is empty: `.uj-at-empty`, teaching the screen.
6. **Mobile dock**: below 640px the punch button and the selfie button move into
   `.uj-at-dock`, `position:fixed` at the bottom. `.uj-main` is deliberately not a
   container-query context, so a fixed descendant escapes to the viewport as intended.
   Keep its `z-index` below the off-canvas sidebar's 60 and the toast host's 120.

Every user-facing string needs its EN and BM pair through `$store.ui.lang`, matching
how the current file does it. Reuse the existing BM copy wherever a string survives.

**Tests to write** (`tests/Feature/AttendanceScreenTest.php`, new, PHPUnit):

- `test_screen_renders_the_shelf_and_week_list_for_an_employee`: assert the response
  contains `uj-at-shelf` and `uj-at-day`.
- `test_week_list_shows_only_current_week_rows`: seed a last-week record; assert its
  date label is absent from the rendered week list.
- `test_empty_week_shows_the_empty_state`: employee with no records this week; assert
  `uj-at-empty` renders.
- `test_see_all_link_hidden_without_permission`: assert the "All staff attendance"
  link is absent when `$qaCanSeeAll` is false and present when true.
- `test_clock_form_keeps_its_post_contract`: assert the form posts to the
  `attendance.clock` route and still carries `action`, `latitude`, `longitude`,
  `photo` and `justification` inputs.

**Acceptance:**
- `php artisan test --compact tests/Feature/AttendanceScreenTest.php` passes.
- `php artisan test --compact tests/Feature/AttendanceScreenDataTest.php` still passes.
- `vendor/bin/pint --dirty --format agent` reports no remaining issues.
- `bun run build` succeeds.
- No file outside the scope list is modified.

---

## Out of scope

- `attendance-admin.blade.php` and `attendance-report.blade.php`. Different screens,
  different task.
- Rendering a map tile per punch. The prototype deliberately replaced it with a
  sentence; a per-punch map costs a tile renderer and a network round trip to state
  one fact that a sentence already states.
- Any change to `AttendanceController::clock()`. The write path is unchanged.
