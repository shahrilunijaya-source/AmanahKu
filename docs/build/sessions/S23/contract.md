# S23 / CR-28 Victory Bell — contract

Mirrors S22 CR-24 Big Deal Alert structurally. All shapes below are fixed by
`tests/Acceptance/CR28Test.php` and the `## QA / CR-28` entry in `docs/build/OPEN.md`
(already decided by QA before this session — this session matches them, does not
redesign them).

## Schema changes

- Migration `<ts>_add_is_milestone_to_work_items_table.php`: `work_items.is_milestone`
  boolean, default false, after `priority`.
- Migration `<ts>_create_victory_bell_tables.php`: mirrors
  `2026_09_09_133702_create_big_deal_tables.php`.
  - `victory_bells`: id, tenant_id (indexed), work_item_id (fk work_items),
    project_id (nullable fk projects), rung_by (fk employees), line (nullable text),
    rung_at (timestamp), timestamps.
  - `victory_bell_reactions`: id, tenant_id, victory_bell_id (fk victory_bells),
    employee_id (fk employees), reaction (string 40), timestamps; unique
    (victory_bell_id, employee_id).

## Files touched

- `app/Models/VictoryBell.php` (new) — mirrors `BigDeal`, `isActive()` = now() within
  `rung_at->addHours(24)`.
- `app/Http/Controllers/WorkItemController.php`:
  - `update()`: gate `is_milestone` same shape as `reviewer_id` (ASSIGNER_ROLES via
    `Permissions::effectiveRole`, 403 for others including the card's own employee).
  - `move()`: on a fresh transition to `done`, compute `bell` key: milestone + no
    existing `victory_bells` row for the work item -> `{work_item_id, prompt: 'Ring
    the bell?'}`, else `null`.
  - new `ring(Request, WorkItem)` action: tenant check, owner-or-PM authorize (403),
    422 if not milestone / not done / already rung / project already has 3 bells this
    calendar month (skip cap when `project_id` is null); creates `victory_bells` row,
    `AuditLog::record('victory_bell.rung', "victory_bell:{id}")`.
  - `cardPayload()`: add `is_milestone`, `can_set_milestone`.
  - `show()`: add `can_set_milestone` alongside `can_set_reviewer`.
- `app/Http/Controllers/VictoryBellController.php` (new) — mirrors `BigDealController`:
  `react()` (CR-30 toggle against `victory_bell_reactions`), `reactPartial()`,
  `screenData()`.
- `app/Support/DashboardBands.php`: `victoryBellMoments(iterable $bells, CarbonImmutable
  $today)` mirrors `bigDealMoments()`. `art` null (no generic `uj-db-art` branch).
- `app/Http/Controllers/Concerns/BuildsDashboardWidgets.php`: fetch active (within 24h)
  `victory_bells` with relations, build moments, merge `reactHtml` per bell, append to
  `$moments`.
- `app/Http/Controllers/AppController.php`: `'wins'` screen merges `BigDealController`
  and `VictoryBellController` screenData, interleaved newest-first.
- `resources/views/partials/dash/bands.blade.php`: new `$isVictoryBell` branch,
  `data-victory-bell`, bell icon (swing anim, omitted plain), `.uj-bd-team` avatars
  `data-victory-bell-member`, optional line, `.uj-vb-meta`, reaction partial, confetti
  (omitted plain).
- `resources/views/partials/dash/victory-bell-react.blade.php` (new) — mirrors
  `big-deal-react.blade.php` (own partial, avoids the shared tally regex trap).
- `resources/views/screens/wins.blade.php`: interleave Big Deal + Victory Bell rows,
  `data-win-bell="<id>"`.
- `routes/web.php`: `POST /app/board/{workItem}/bell` -> `WorkItemController::ring`,
  named `work.bell`; `POST /app/victory-bells/{bell}/react` -> `VictoryBellController::react`,
  named `victory-bells.react`.
- `resources/views/partials/work-card.blade.php`: small bell badge for milestone cards.
- `resources/views/partials/work-drawer.blade.php`: Milestone checkbox chip row
  (PM+ settable via `can_set_milestone`, read-only badge otherwise); "Ring the bell"
  button when Done + milestone + unrung (any viewer who is owner or PM+, matches server
  gate); shows `bell.prompt` toast trigger data.
- `resources/js/work-board.js`: `setMilestone(checked)` via `commitField('is_milestone',
  ...)`; ring-the-bell toast on `move()`/`persistMove()` response's `bell` key
  (POST to `/app/board/{id}/bell`); `ringBell()` drawer action.
- `resources/css/app.css`: `.uj-db-band[data-kind="victory-bell"]`, `.uj-vb-bell`
  (+ `uj-vb-swing` keyframes), `.uj-vb-meta`, `.uj-vb-prompt` toast (verbatim from
  `docs/build/sessions/S23/mockup/README.md`).

## Acceptance mapping (CR28Test.php)

| # | Test | Covered by |
|---|------|-----------|
| 1 | Non-milestone card: no prompt on Done, employee 403 on is_milestone | `update()` gate, `move()` bell=null |
| 2 | Milestone card Done -> prompt -> ring -> dashboard celebration, avatars, WE HAVE MOVEMENT, confetti loud/none plain | `move()` bell key, `ring()`, `DashboardBands::victoryBellMoments`, `bands.blade.php` |
| 3 | Reactions on the moment | `VictoryBellController::react`, `victory-bell-react.blade.php` |
| 4 | Owner-or-PM ring gate, 422s (not milestone/not done/already rung/3-per-month cap, null-project uncapped) | `ring()` |
| 5 | 24h window then Wins page, `data-win-bell` | `VictoryBell::isActive()`, `wins.blade.php`, `AppController` merge |
| always | due date immutable, audit immutable, dashboard unchanged on quiet day, keep-it-plain | reused framework checks, no new dashboard cards outside `moments` slot |

## OPEN.md additions (this session)

- Ring prompt placement: toast, bottom-right (per mockup), not the drawer-only —
  drawer also keeps a persistent "Ring the bell" button for later.
- Ringing does not post to the card's activity/comment log — no such requirement in
  spec or test; keeps the change surface small and reversible.
- Bell moments append after Big Deal moments in the moments array (arbitrary,
  reversible — no ordering requirement in spec or test).
