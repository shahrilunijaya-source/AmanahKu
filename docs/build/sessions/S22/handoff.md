# Session S22 handoff: CR-24

## Delivered
- "Mark as Big Deal" ghost button + inline form on the Projects screen row
  (`partials/ts-project-row.blade.php`), PM-and-above only, posting multipart to
  `POST /app/big-deals` — acceptance items 1, 2.
- `BigDealController::store()` validates `type` (7 fixed values), `title`, `story`,
  optional `project_id`/`work_item_id`/`track_ref` (free text, no Track call),
  `team[]` employee ids, `photos[]` (max 3), and for `client_compliment` a required
  `source` file plus optional `client_contact`/`names_approved`; writes
  `AuditLog::record('big_deal.raised', "big_deal:<id>")` — acceptance items 1, 2, 5.
- Big Deal Alert banner rendered as a Moment in the existing moments band
  (`data-kind="big-deal" data-big-deal="<id>"`, `BIG DEAL ALERT` kicker, team avatars
  `[data-big-deal-member]`, story, up to 3 photos via
  `GET /app/big-deals/{deal}/photos/{photo}`), built by
  `DashboardBands::bigDealMoments()` and stitched in
  `BuildsDashboardWidgets::dashboardBands()` — no new band, no existing card moved —
  acceptance items 1, 3, 6.
- CR-30 reaction picker + tally on the banner and on Wins, custom partial
  `partials/dash/big-deal-react.blade.php` (fetch-and-swap, no full reload),
  `POST /app/big-deals/{deal}/react` with standard CR-30 toggle semantics —
  acceptance item 3.
- 3-day dashboard window: `BigDeal::isActive()` (`now() < published_at + 3 days`) gates
  the moment; after 3 days the deal drops off the dashboard — acceptance item 4.
- `GET /app/wins` (screen `wins`, added to `Amanahku::nav()`/`page()`, no new route
  needed) lists every Big Deal ever raised, newest first, same team/story/photos/
  reactions markup as the banner — acceptance item 4.
- Client-compliment name gating: `client_contact` is only rendered (dashboard and Wins)
  when `names_approved` is true, gated once inside `DashboardBands::bigDealMoments()` so
  neither view has to know the rule — acceptance item 5.
- "Keep it plain": the big-deal moment reuses the existing `.uj-db-art` block already
  gated on `! $plain`, emits no confetti/canvas/audio (those are birthday-only), so
  plain mode is satisfied without new plain-mode code — acceptance item 6.

## Schema changes
- `big_deals`: tenant_id, type, title, story, raised_by (employees), project_id
  (nullable, projects), work_item_id (nullable, work_items), track_ref, client_contact,
  names_approved, source_path, published_at, timestamps.
- `big_deal_photos`: big_deal_id, path, timestamps.
- `big_deal_members`: big_deal_id, employee_id, timestamps.
- `big_deal_reactions`: tenant_id, big_deal_id, employee_id, reaction, timestamps,
  unique (big_deal_id, employee_id, reaction).
- Migration `database/migrations/2026_09_09_133702_create_big_deal_tables.php`, run on
  the dev DB via `lerd artisan migrate --no-interaction` (confirmed clean, one migration
  ran).

## Contracts touched
- none. `dashboard-slots.md` respected: the banner is a Moment inside the existing
  `moments` slot's rotation (`x-data="{ i, n }"` cycler in `bands.blade.php`), no new
  band, no existing card moved, renamed or reordered.

## Port calls stubbed
- none. Track ref is a free-text field only, no outbound call. No email, no SDK.

## Deferred
- Board card drawer `...` menu "Mark as Big Deal" entry point (present in the mockup)
  was not built — only the Projects-row entry point was, see OPEN.md entry below. Cheap
  to add later, same `route('big-deals.store')` target.

## OPEN, decided without Shazwan
- Wins page lists every Big Deal regardless of the 3-day window (archive, not a filter),
  see `/OPEN.md` "S22 / CR-24 / Wins lists every deal, in-window or not".
- Raise form built only on the Projects row, not the board card drawer, see `/OPEN.md`
  "S22 / CR-24 / Raise form lives only on the Projects row, not the board card drawer".
- Client-name approval is self-attested by the raiser (a checkbox on the raise form),
  no separate approval workflow, see `/OPEN.md` "S22 / CR-24 / Client-name approval is
  self-attested by the raiser, no separate approval workflow".

## Requested contract change (generator may not make it itself)
- none.

## Traps for the next session
- `partials/reaction-tally.blade.php` cannot be reused for the big-deal count: its
  markup nests `<span aria-hidden>{icon}</span>` before `<b>{{n}}</b>`, and
  `CR24Test`'s regex `data-reaction-count="respect"[^>]*>[^<]*2` requires the digit to be
  the tag's first text node with no intervening `<` — PCRE's `[^<]*` cannot cross a `<`.
  `partials/dash/big-deal-react.blade.php` is a deliberate one-off with the count emitted
  first; do not "simplify" it back onto the shared partial without re-checking that regex.
- `$employees` passed into `ts-project-row.blade.php` (and its siblings) is an array of
  arrays (`$e['id']`, `$e['display_name']`), not Eloquent models — caught the hard way
  when the team-picker `<select>` first used `$emp->id` and 500'd every
  `ProjectScreenTest`/`ProjectMasterTest`/`ProjectVariationTest` case that renders the row.
- Two adjacent inline `@if (...) ... @endif@if (...) ... @endif` directives on one line
  with no whitespace between `@endif` and the next `@if` fail to compile (Blade leaves
  the second `@if` as literal text, PHP parse error at `endif` further down) — this bit
  `wins.blade.php`'s track-ref/client-contact line; fixed with a literal space between
  them. Watch for this pattern anywhere else two conditionals are chained inline.

## Test results
- `php artisan test --compact tests/Acceptance/CR24Test.php`: 7/7 passed, 176 assertions.
- Related feature tests (Dashboard*, Project*, AwardsTest): 130/130 passed, 641
  assertions.
- Full suite `php artisan test --compact`: 2954/2960 passed, 22674 assertions, 5 skipped,
  19 incomplete. The one failure is the known pre-existing
  `LeaveScreenTabsTest::test_cancelling_an_approved_replacement_refunds_the_quota`,
  unrelated to this CR.
