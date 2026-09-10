# Session S28 contract: CR-22 Amanahku Wrapped

Read RULES.md, contracts/dashboard-slots.md, contracts/roles.md, CR-22.md, CR-14.md,
global-clause.md, date-calendar-rules.md, culture-pack-preamble.md, S27 handoff, OPEN.md
(incl. "QA / CR-22 / shapes fixed by CR22Test"), `tests/Acceptance/CR22Test.php` in full
(docblock + all 5 acceptance tests + `AlwaysChecks` trait), S28 mockup. Shapes below are
exactly what CR22Test and its docblock fix; nothing here is a new decision except where
marked OPEN.

## Schema

Migration `create_wrapped_tables`:

- `wrapped_stories`: id, tenant_id (FK, cascade), month (date), employee_id (nullable FK
  employees, cascade), cards (json — numeric stats only, no free text), arc_title
  (nullable string 120), shared_at (nullable datetime), built_at (datetime), timestamps.
  Index (tenant_id, month, employee_id) — not unique, because a unique index does not stop
  a second NULL-employee_id (company) row; idempotency for both cases is an existence
  check in `wrapped:build`, not the schema.
- `wrapped_arcs`: id, tenant_id (FK, cascade), title (string 120), rule (string 20 — one
  of firefighter/helper/closer/quiet/steady), active (bool default true), timestamps.
- `wrapped_reactions`: id, tenant_id (FK, cascade), wrapped_story_id (FK
  wrapped_stories, cascade), employee_id (FK employees, cascade), reaction (string 40),
  timestamps. Unique (wrapped_story_id, employee_id) — one reaction per person per story.

Eloquent models: `WrappedStory`, `WrappedArc` (both `BelongsToTenant`, `$guarded = []`,
`cards` cast to array, `shared_at`/`built_at` cast to datetime) — route-model binding
needs both (`{story}`, `{arc}`). `wrapped_reactions` stays `DB::table()` only, same
convention as `victory_bell_reactions`/`big_deal_reactions` (no route parameter binds it).

## `wrapped:build` (scheduled `0 8 * * *`)

Per-tenant loop (`Tenant::query()->get()` + `CurrentTenant::set()`, same shape as
`AwardsPublish`), guarded by a duplicated `isFirstWorkingDayOfMonth()` helper (S25/S27
precedent: duplicated per command, not extracted). On the first working day only, target
month = previous calendar month.

1. **Seed arcs** if the tenant has none: 30+ defaults, at least 6 per rule (firefighter,
   helper, closer, quiet, steady) so the 3-per-rule floor survives any single retirement.
2. **Skip if already built**: `wrapped_stories` row exists for tenant+month+any employee_id
   (personal) or tenant+month+NULL (company) → that half is not rebuilt. Existence checked
   separately for "at least one personal row" and "the company row", so a first run
   inserting both then a second run adds nothing (CR22Test's row-count assertion).
3. **Per active employee with a user** (`Employee::where('status','active')
   ->whereNotNull('user_id')`):
   - `cards_closed` = `award_snapshots` `done_and_dusted` value for (tenant, month,
     employee), else 0.
   - `high_priority` = `chief_firefighter` value, else 0.
   - `lessons_shared` = `walking_wikipedia` value, else 0.
   - `best_day` = weekday name (English, `l` format) with the most `Awards::
     creditableCards()` rows for this employee completed inside the month; ties broken by
     earliest weekday name in `[Monday..Sunday]` order for determinism; empty string when
     no cards completed that month.
   - `helped_people` = distinct `employee_id` (owner) of `creditableCards()` rows where
     this employee appears in `helpers` and `completed_at` falls in the month.
   - Rule engine, in order, first match: `high_priority >= 3` → firefighter;
     `helped_people >= 3` → helper; `cards_closed >= 10` → closer; `cards_closed == 0` →
     quiet; else → steady. Title picked deterministically among that rule's active arcs
     (`crc32("{employee_id}-{month}-{rule}") % count`) — OPEN: any deterministic pick
     satisfies the spec ("picked... by simple rules"); this one just needs to be stable
     across the idempotent re-run guard (moot in practice, since a re-run is skipped, but
     keeps `wrapped:build --pretend`-style debugging reproducible).
   - Insert one `wrapped_stories` row, `cards` = `{cards_closed, high_priority,
     helped_people, lessons_shared, best_day}`, `arc_title` set, `built_at = now()`.
4. **Company row** (`employee_id` NULL):
   - `cards_closed` = count of `creditableCards()` company-wide with `completed_at` in
     the month.
   - `fires` = same, filtered `priority === 'high'`.
   - `urgent` = count of `creditableCards()` company-wide with `priority === 'high'` and
     `created_at` in the month (spec: "high-priority cards created that month" — see OPEN
     entry on `test_acceptance_3`'s `urgent` value, which this reading cannot satisfy).
   - `lessons_shared` = sum of `walking_wikipedia` snapshot values for the month, all
     employees.
   - `cards` = `{cards_closed, lessons_shared, fires, urgent}`, `arc_title` null.

## Controller `WrappedController`

- `screenData(Request, ?Employee $employee)`: own story only. `?month=` (else latest
  built for that employee) selects which `wrapped_stories` row to show; `?emp=` is never
  read. HR/director (`hasTenantRole(['hr','director'])`, i.e. `Permissions::
  effectiveRole()` collapses director→management, so gate on the raw+effective set the
  same way `Controller::hasTenantRole` already does) additionally get the active arc list.
- `share(Request, WrappedStory $story)` / `unshare(...)`: `assertSameTenant()` 404 first
  (route binding is not tenant-scoped), then 404 if `employee_id` is null (company story),
  then 403 unless the acting employee owns it. Sets/clears `shared_at`; audit
  `wrapped.shared`/`wrapped.unshared`, target `wrapped_story:<id>`.
- `react(Request, WrappedStory $story)`: `assertSameTenant()` 404 first (covers the
  cross-tenant `postJson(...)->assertNotFound()` case), then CR-30 toggle semantics
  against `wrapped_reactions` (copy of `VictoryBellController::react()`, `23xxx`
  `QueryException` swallow for the race), 422 on an unknown reaction key.
- `addArc(Request)` / `retireArc(Request, WrappedArc $arc)`: `authorizeTenantRole(['hr',
  'director'])` (403 otherwise, matches CR22Test: hr passes, director passes via
  `authorizeTenantRole`'s effective-role check, manager/employee 403). `addArc` validates
  `title` (required, string, max 120) and `rule` (required, in the 5 values — session
  error key `rule`). Audit `wrapped.arc_added` / `wrapped.arc_retired`, target
  `wrapped_arc:<id>`.

## Routes

`routes/web.php`, inside the existing `app` middleware group, near the other Playground
POST routes (big-deals/victory-bells):

```
Route::get('/app/wrapped', [AppController::class, 'screen'])->defaults('screen', 'wrapped');
Route::post('/app/wrapped/arcs', [WrappedController::class, 'addArc']);
Route::post('/app/wrapped/arcs/{arc}/retire', [WrappedController::class, 'retireArc']);
Route::post('/app/wrapped/{story}/share', [WrappedController::class, 'share']);
Route::post('/app/wrapped/{story}/unshare', [WrappedController::class, 'unshare']);
Route::post('/app/wrapped/{story}/react', [WrappedController::class, 'react']);
```

registered **before** the generic `/app/{screen?}` catch-all (line ~750) so the GET route
resolves through the same `AppController::screen()`/`screenData()`/`wrapScreen()` shell
every other Playground screen uses, while still producing a literal `app/wrapped` URI —
required because `test_acceptance_5`'s route-list assertion filters
`str_contains($uri, 'wrapped')` and expects the literal string `'app/wrapped'` in the
result, which the catch-all's own `uri()` (`app/{screen?}`) would never produce. `{story}`
and `{arc}` are exactly those parameter names (nothing else may match `wrapped` in any
route's `uri()`, per the `assertEqualsCanonicalizing` with exactly 6 entries).

`AppController::screenData()` match gets `'wrapped' =>
app(WrappedController::class)->screenData($request, $employee),`. `winsData()` merges
`app(WrappedController::class)->screenData($request, $employee)['storiesForWins']` (rows
`['kind' => 'wrapped', 'story' => WrappedStory, 'at' => shared_at]`, shared only) into the
existing `$rows` collect+sort. `app/Support/Amanahku.php` gets a sidebar entry (`The
Playground` group, `id => 'wrapped'`, label `Wrapped`) and a `page('wrapped')` entry.

## Dashboard moment

`DashboardBands::wrappedMoment(?object $companyStory, CarbonImmutable $today, callable
$isWorkingDay, string $reactHtml): ?Moment` — null unless `awardsWindowOpen($today,
$isWorkingDay)` and a company story exists for last month. `kind => 'wrapped'`,
`wrapped_story_id` carried through for the `data-wrapped-company` attribute. Kicker
`{$MONTH}, WRAPPED` (uppercase, English month name — `awardsSlot()`'s ms-locale pattern
for the Malay side). Sentence rendered as **flat, unstyled text** (no `<b>` around the
numbers) because `test_acceptance_3` asserts `assertStringContainsString('9 cards
closed', $moment)` etc. directly against raw HTML with no `strip_tags` — any tag between
the number and the following word breaks that contiguous-substring check. This deviates
from the mockup's "each number bold"; OPEN entry recorded. The `[data-wrapped-stat]`
carriers the `stat()` helper needs are separate `hidden` spans in the same `<section>`
holding only the bare number. `bands.blade.php` gets an `$isWrapped` branch alongside
the existing `$isBirthday`/`$isBigDeal`/`$isVictoryBell` ones; reaction region copies
`victory-bell-react.blade.php`'s count-first chip pattern (new
`partials/dash/wrapped-react.blade.php`) for the same reason that partial exists (nested
icon `<span>` before the count breaks `test_acceptance_3`'s
`data-reaction-count="legend"[^>]*>[^<]*1` regex). Plain mode: no `uj-db-art`, no
`uj-db-confetti` (there already isn't one for this kind), text unchanged.

`app/Http/Controllers/Concerns/BuildsDashboardWidgets.php`'s `dashboardBands()` appends
the wrapped moment after the bell moments, passing the `WrappedController::reactPartial()`
render-and-swap HTML the same way big-deal/victory-bell moments do.

## Wins wall

`resources/views/screens/wins.blade.php` gains a third `@if ($row['kind'] === 'wrapped')`
branch: `.uj-card[data-win-wrapped="<id>"]` with a red left border, name, month
(`F Y`, e.g. "September 2026" — literal `September` substring required, case-sensitive),
arc title, numbers line.

## Verification (per acceptance item)

1. `test_acceptance_1`: nothing before build, `wrapped:build` idempotent (6 rows: 5
   people + company, second run adds 0), personal page renders 6–8 cards with the 6 stat
   keys and the nav entry.
2. `test_acceptance_2`: private by default, share/unshare 403/404/audit, Wins listing
   appears only while shared.
3. `test_acceptance_3`: dashboard moment shape, window (gone after the 7th, absent for
   another tenant), CR-30 reaction toggle semantics. **One sub-assertion cannot be made
   green**: `urgent` is asserted as `'4'` (line 215) and "only 4 \"urgent\" tasks" (line
   219), but the fixture's own high-priority `created_at` values (via the
   `finishedCard()` helper's `doneAt->subDay()` pattern) put only 3 of the 5 high-priority
   cards' creation timestamps inside September — Yati's first high card is done on 1 Sep,
   so its created_at (31 Aug) falls in August despite the docblock's "2 + 2 = 4" comment
   assuming otherwise. See OPEN.md and handoff for the full trace; this is judged a
   frozen-test arithmetic defect (S26/S27 precedent), not something a build session may
   fix by editing `CR22Test.php`.
4. `test_acceptance_4`: plain mode swaps the deck for one paragraph, drops `uj-wr-art`/
   `<canvas`/`<audio`; dashboard moment stays text-only.
5. `test_acceptance_5`: own-story-only, no `?emp=`, no cross-person names, no ranking
   language, numbers-only (`zebra-quokka-9000` never reaches a story), snapshot as
   source, 30+ curated arcs with 3+ per rule, HR/director-only CRUD, exactly the 6 listed
   routes and no others.

Plus `tests/Feature/WrappedTest.php`: other-tenant 404 on a bound story/arc, a
zero-cards-closed person still gets 6–8 cards including a populated `quiet` arc, and a
second `wrapped:build` run within the same month adds no rows.
