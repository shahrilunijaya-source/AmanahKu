# Session S23 handoff: CR-28 Victory Bell

## Delivered
- `work_items.is_milestone` flag, settable only via the existing `PATCH /app/board/{workItem}`
  by manager/management/director (`Permissions::effectiveRole` + `BoardRules::ASSIGNER_ROLES`,
  same gate shape as `reviewer_id`) — an employee gets 403 even on their own card
  (`WorkItemController::update()`) — acceptance items 3, 4.
- `POST /app/board/{workItem}/move {status: done}` answers a `bell` JSON key —
  `{work_item_id, prompt: 'Ring the bell?'}` for an unrung Milestone card just landed on
  Done, else `null` — computed from whether a `victory_bells` row already exists for the
  card, not from "was this a fresh transition", so a card that leaves Done and comes
  back never re-offers a bell it already rung (`WorkItemController::move()`) —
  acceptance items 1, 3, 4.
- `POST /app/board/{workItem}/bell {line?}` (`WorkItemController::ring()`): the card's
  owner or a PM-and-above rings it (others 403); 422 when not a Milestone, not Done,
  already rung, or the card's project already has 3 bells in the calendar month of
  `now()` — a card with no project is never capped. Writes `victory_bells` and
  `AuditLog::record('victory_bell.rung', "victory_bell:<id>")` — acceptance item 4.
- Celebration rendered as one more Moment in the existing moments band (never a new
  band, never an existing card moved): `data-kind="victory-bell" data-victory-bell="<id>"`,
  kicker `WE HAVE MOVEMENT`, `"<title> is officially Done."`, `[data-victory-bell-member]`
  avatars for the card's owner plus tagged participants, the optional line, a meta line,
  confetti (omitted when plain), built by `DashboardBands::victoryBellMoments()` and
  stitched into `BuildsDashboardWidgets::dashboardBands()` — acceptance items 1, 2.
- CR-30 reaction picker + tally on the celebration and on Wins, one-off partial
  `partials/dash/victory-bell-react.blade.php` (copied from `big-deal-react.blade.php`
  to keep the count as the chip's own first text node — the shared
  `partials/reaction-tally.blade.php` fails CR28Test's tally regex the same way it
  would have failed CR24Test's), `POST /app/victory-bells/{bell}/react`
  (`VictoryBellController::react()`), standard CR-30 toggle semantics — acceptance
  item 2.
- 24-hour dashboard window: `VictoryBell::isActive()` (`now() < rung_at + 24 hours`)
  gates the moment — acceptance item 4.
- `GET /app/wins` now interleaves Big Deals and Victory Bells, newest first, by each
  row's own timestamp (`AppController::winsData()`); a bell row is `[data-win-bell="<id>"]`
  with the card title, team avatars, the line and reactions — no 24-hour filter on Wins,
  it is an archive not a window (same rule CR-24's Wins listing already used) —
  acceptance item 4.
- "Keep it plain": the celebration still renders text-only — no `uj-db-confetti`, no
  `uj-db-art` (moment's `art` is left null so the shared art-box branch never fires), no
  bell-swing animation, no `<canvas>`/`<audio>` — acceptance item 5.
- Board-side UI (none of this is exercised by CR28Test, all built to the approved
  mockup): a 🔔 badge on a Milestone card's face; a Milestone checkbox chip in the
  drawer (PM+ only, read-only badge otherwise, `can_set_milestone`); a bottom-right
  "Ring the bell?" toast triggered off the move response's `bell` key
  (`resources/views/screens/board.blade.php`, `work-board.js` `ringPrompt`/
  `ringFromPrompt()`); a persistent drawer "Ring the bell" button for later
  (`ringBell()`).

## Schema changes
- `work_items.is_milestone`: boolean, default false. Migration
  `database/migrations/2026_09_09_142040_add_is_milestone_to_work_items_table.php`.
- `victory_bells`: tenant_id, work_item_id, project_id (nullable), rung_by (employees),
  line (nullable), rung_at, timestamps.
- `victory_bell_reactions`: tenant_id, victory_bell_id, employee_id, reaction,
  timestamps, unique (victory_bell_id, employee_id, reaction) — named
  `victory_bell_reactions_unique` explicitly; MySQL's default generated name for that
  index (`victory_bell_reactions_victory_bell_id_employee_id_reaction_unique`) is over
  MySQL's 64-character identifier limit and the migration fails without it.
- Migration `database/migrations/2026_09_09_142041_create_victory_bell_tables.php`, run
  on the dev DB via `lerd artisan migrate --no-interaction` (confirmed clean after
  dropping the two tables the first, over-long-index-name attempt had partially created).

## Contracts touched
- none. `dashboard-slots.md` respected: the celebration is a Moment inside the existing
  `moments` slot's rotation, no new band, no existing card moved, renamed or reordered.

## Port calls stubbed
- none. Track WBS milestones are out of scope for this session (spec and OPEN.md both
  say so) — no Track port call anywhere in this diff.

## Deferred
- The mockup's "bells left this project this month" counter on the toast prompt was not
  built (`ponytail:` comment in `work-board.js`'s `ringFromPrompt()`) — the server
  already 422s past the cap, the counter is decoration the test does not exercise, and
  it would need a new field on the move response. Cheap to add if a later session wants
  it: extend `bell` with a count from the same query `ring()` already runs.

## OPEN, decided without Shazwan
- Ring prompt placement (toast, with a persistent drawer button as a second door),
  ringing does not post to the card's activity log, moments ordering (bell after Big
  Deal), and the Wins-page merge mechanism — see `/OPEN.md` "S23 / CR-28 / ring prompt
  placement, activity log, moments ordering, Wins interleave".

## Requested contract change (generator may not make it itself)
- none.

## Traps for the next session
- MySQL's 64-character identifier limit bites a 3-column unique index name built from
  the two tables' full column names — name it explicitly (see Schema changes above)
  rather than letting Laravel derive one, on any new multi-column unique/index whose
  table and column names are already long.
- `partials/reaction-tally.blade.php` still cannot be reused for a per-item CR-30 tally
  needing an exact "digit is the first text node" regex match — third time this has come
  up (CR-24, CR-28); if a fourth reaction surface needs one, consider fixing the shared
  partial itself instead of a fourth one-off copy.
- `DashboardBands::*Moments()` builders that want the shared `uj-db-s` span for
  something other than a birthday greeting / big-deal one-liner (here, the optional
  ring line) can reuse it via the moment's `sub` key — `bands.blade.php`'s render of
  that span is now gated on `$m['sub']['en'] !== ''` so an unused sub disappears
  cleanly instead of leaving an empty span.

## Test results
- `php artisan test --compact tests/Acceptance/CR28Test.php`: 6/6 passed, 147
  assertions.
- Related feature tests (`--filter="Board|WorkItem|Dashboard|BigDeal|Wins|Reaction"`):
  434 collected, all passing (1 skipped, 2 incomplete, neither related to this CR).
- Full suite `php artisan test --compact`: 2976 tests, 2964 passed, 22853 assertions,
  5 skipped, 19 incomplete, 2 failed + 5 errored. All 7 non-passing are pre-existing and
  outside this session's scope: `LeaveScreenTabsTest::test_cancelling_an_approved_replacement_refunds_the_quota`
  (the documented known-acceptable failure) and all six `CR25Test` cases — CR-25 (Plot
  Twist) is session S24, not yet built in this worktree (`plot_twist_*` tables and
  routes do not exist yet), so its acceptance test fails on a missing schema regardless
  of this session's changes. Neither this diff nor any prior session's diff touches
  Plot Twist.
