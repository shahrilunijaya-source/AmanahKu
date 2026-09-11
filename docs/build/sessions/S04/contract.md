# Session S04 contract: CR-32 (dashboard placement rule)

## Files to touch
- `app/Support/DashboardBands.php`: `managementSlot()`, `awardsSlot()`, `awardsWindowOpen()` (first working day of the month to the 7th inclusive).
- `app/Support/DashboardWidgets.php`: registry entry `friday` (left, `after: tasks`, everyone, core) and `fridaySignOffOpen()` (Friday 15:00 up to, not including, Monday 09:00).
- `app/Http/Controllers/Concerns/BuildsDashboardWidgets.php`: `dashboardData()` drops `friday` from the available ids outside its window; `dashboardBands()` fills the management slot for `Permissions::FINAL_APPROVAL_ROLES` and the awards slot inside its window; `dashboardWidget()` gains a `friday` payload.
- `resources/views/partials/dash/bands.blade.php`: the management and awards slots render as `<section class="uj-db-band" data-band="…">` where the placeholder comment sits, text only, same kicker / title / sub spans as a moment.
- `resources/views/partials/dash/widgets/friday.blade.php`: new, the sign-off slot body (text only; CR-29 fills it in S25).
- `resources/css/app.css`: tint for the two bands (none when `data-plain`).
- `public/build/*` rebuilt.
- `docs/build/OPEN.md`, `docs/build/sessions/S04/handoff.md`.

Not touched: `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*`, every existing widget id, title or default order.

## Schema changes
None. No migration.

## Acceptance items and how each is verified
1. Quiet Tuesday as staff, identical to today: `CR32Test` item 1 (no `data-band`, left and right ids in registry order, no `friday` / `events`); browser as Shazwan with the dev clock at 2026-09-08 10:00 against `docs/build/baseline/dashboard.png`.
2. Same day as director, only the management band: `CR32Test` item 2 (one band, `data-band="management"`, above `.uj-dw-grid`, hr sees it, manager and staff do not, plain keeps it as text); browser as Shahril.
3. 1 Oct awards band, cards unchanged below: `CR32Test` item 3 (window 1st working day to the 7th, 1 Nov Sunday case, director sees management before awards, plain keeps it); browser as Shazwan with the clock at 2026-10-01.
4. Friday 15:00 sign-off card after Pending tasks, gone Monday 09:00: `CR32Test` item 4 (left order with `friday` fourth, right unchanged, Saturday and Monday 08:59 present, Monday 09:00 and Thursday absent); browser with the clock at 2026-09-11 15:00 and 2026-09-14 09:00.
5. Appendix B layout: human check in `/qa grade`, allowing for the differences listed in `contracts/dashboard-slots.md`.

Retrofit check (CR-13, CR-15, CR-20, CR-23 predate the rule): confirm each already sits in its contract slot; report in the handoff, no code unless one is out of place.
