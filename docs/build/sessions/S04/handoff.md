# Session S04 handoff: CR-32 (dashboard placement rule)

## Delivered
- The management band slot: `DashboardBands::managementSlot()` fills `bands['management']` for `Permissions::FINAL_APPROVAL_ROLES` (management, director, hr) every day; employee and manager never get it. Text only until CR-17 (S15) puts the lateness and overdue panels in it. Verified by acceptance item 2 and `s04-management.png`.
- The awards band slot: `DashboardBands::awardsWindowOpen()` opens `bands['awards']` from the month's first working day (weekend and `PublicHoliday` rows are not working days) through the 7th, for everyone; `awardsSlot()` is the text until CR-14 (S17/S18) hangs the carousel in it. Verified by item 3 and `s04-awards.png`.
- Both slots render from `partials/dash/bands.blade.php` as `<section class="uj-db-band uj-db-<slot>" data-band="management|awards">` in contract order after the moments block, with the same kicker / title / sub spans a moment uses, so the CSS already there applies. The wrapper now carries `data-plain` only when Keep it plain is on (it rendered the attribute always before, so every band looked plain for everyone). Verified by items 2 and 3 and the plain assertions; `s04-management-plain.png`.
- The Friday sign-off slot: registry widget `friday` (left, `after: tasks`, everyone, core), listed only while `DashboardWidgets::fridaySignOffOpen()` is true (Friday 15:00 up to, not including, Monday 09:00). Outside the window it is absent from the layout and the picker, not empty. Body `partials/dash/widgets/friday.blade.php` is one text line until CR-29 (S25). Verified by item 4 and `s04-friday.png`.
- Nothing existing moved: item 1 and `AlwaysChecks::assertDashboardUnchanged()` hold on a quiet Tuesday (`s04-staff-quiet.png`).
- Retrofit check on the four CRs built before this rule: CR-13 birthday and CR-20 holiday eve are moments in the `moments` slot; CR-15 working style is `style` after `work`, right column (the contract says right stands); CR-23 flowers is `flowers` after `notices`. All already in their contract slots; no code change.
- `tests/Feature/DashboardBandsTest::test_a_director_gets_no_band_yet_on_an_ordinary_day` said the opposite of item 2 and was rewritten to assert the management band (plus the `data-plain` regression). Full suite green: 2679 passed, 5 skipped, 9 incomplete.

## Schema changes
- none.

## Contracts touched
- none.

## Port calls stubbed
- none.

## Deferred
- Management band content (lateness today, overdue by Primary Owner) to S15 CR-17; awards carousel to S17/S18 CR-14; Friday sign-off form and 17:00 mood check to S25 CR-29; `events` widget to the CR-11 session; Plot Twist inside notices to the CR-25 session. Each replaces the placeholder text in its slot and keeps the `data-band` / `data-widget` markup.

## OPEN, decided without Shazwan
- Placeholder wording in the slots and the Friday card leaving the picker outside its window, see OPEN entry "S04 / CR-32 / slot placeholders and the Friday card outside its window".
- The markup and windows themselves were fixed by QA, see "QA / CR-32 / shapes fixed by CR32Test".

## Requested contract change (generator may not make it itself)
- none.

## Traps for the next session
- `dashboardBands()` only fills the slots when `$employee !== null`; a user with no employee row gets no bands at all, as before.
- The `$role` given to `dashboardBands()` is the raw tenant role, not `effectiveRole()`; the gate is `in_array($role, Permissions::FINAL_APPROVAL_ROLES)` and `director` is in that list directly.
- `awardsWindowOpen()` walks from the 1st to the first working day but stops at the 7th; a month whose first seven days are all non-working shows no band.
- `friday` is filtered out of `$available` in `dashboardData()`, before `DashboardWidgets::layout()`. A saved drag order that contains `friday` is harmless outside the window (layout drops unknown ids) and honoured inside it. `dashboardWidgetPartial()` already 404s for it because it has no period unit.
- Filling a slot: return a richer array from `managementSlot()` / `awardsSlot()` and extend the `@foreach (['management','awards'])` block in `bands.blade.php`; keep `data-band` and keep the text-only path when `$plain`.
- `POST /app/dashboard/prefs` with only `plain` resets the caller's drag order to empty (`order` defaults to `[]` in `updateDashboardPrefs`). The dashboard's own picker always sends `hidden`, `order` and `plain` together, so this only bites scripts. Shahril's dev-database order was reset this way during the browser check and put back to the registry default.
