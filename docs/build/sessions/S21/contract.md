# S21 contract: CR-31 (dashboard easter eggs)

## Files touched

**Schema**
- `database/migrations/2026_09_25_100000_create_easter_eggs_table.php` — `easter_eggs`
  (tenant_id, kind, text_en, text_ms, suggested_by nullable, approved_at, timestamps).
  Seeds every existing tenant via `EasterEggBank::seed()`.
- `database/migrations/2026_09_25_100100_create_easter_egg_views_table.php` —
  `easter_egg_views` (tenant_id, employee_id, kind, shown_on date, timestamps), unique on
  (employee_id, kind, shown_on) — the once-a-day gate.

**Models**
- `app/Models/EasterEgg.php` — `BelongsToTenant`, `approved()` scope, `suggestedBy()`.
- `app/Models/EasterEggView.php` — `BelongsToTenant`, `shown_on` cast `date:Y-m-d`
  (plain `date` stores `Y-m-d H:i:s` in sqlite and breaks the unique-key lookup
  `firstOrCreate` relies on — found while running the acceptance test, see handoff).

**Support**
- `app/Support/EasterEggBank.php` — `KINDS`, `DEFAULTS` (3 approved EN+BM lines per
  kind, includes the CR's own four quoted lines), `seed()`, `pick()`, `showOnce()`
  (pick-then-record order, so an empty/pending bank never burns the day's quota).

**Controllers**
- `app/Http/Controllers/EasterEggController.php` — `store`/`update`/`delete`, same shape
  as `GreetingLineController`, `management|hr` gate, explicit tenant check on the bound
  model.
- `app/Http/Controllers/WorkItemController.php::move()` — `boardMoveEgg()` private
  helper; JSON response gains `egg`; wrapped in try/catch so a bank/view failure can
  never fail the move.
- `app/Http/Controllers/Concerns/BuildsDashboardData.php` — `dashboardEgg()` private
  helper (late_night > friday_late > holiday_eve priority).
- `app/Http/Controllers/Concerns/BuildsDashboardWidgets.php` — wires `egg` into
  `dashboardData()`.
- `app/Http/Controllers/Concerns/BuildsSettingsData.php` — `easterEggs`, `easterEggKinds`
  for the Company Settings card.
- `app/Http/Controllers/Concerns/BuildsPeopleData.php` — `keepItPlain` (own profile only).
- `app/Http/Controllers/SuperAdmin/CompanyController.php` — seeds the bank alongside
  `GreetingBank::seed()` on tenant creation.

**Routes** (`routes/web.php`): `POST /app/admin/eggs`, `POST /app/admin/eggs/{easterEgg}`,
`POST /app/admin/eggs/{easterEgg}/delete`.

**Views**
- `resources/views/screens/dash.blade.php` — `.uj-egg` element inside `.uj-dw-head`,
  exact markup from the approved mockup.
- `resources/views/screens/settings.blade.php` — "Dashboard easter eggs" card, copy of
  the CR-33 greetings card.
- `resources/views/screens/profile.blade.php` — "Keep it plain" checkbox, own profile
  only, posts to the existing `/app/dashboard/prefs`.
- `resources/views/layouts/app.blade.php` — `<body data-plain>` from
  `DashboardPrefs::forUser()`; the CR-31 tab-collector script before `</body>`.

**CSS/JS**
- `resources/css/app.css` — `.uj-egg*` rules (verbatim from the mockup README),
  `body[data-plain]` guards for `.uj-fade`, `.uj-dw-tile-in`, `.kb-pulse-ring`.
- `resources/js/work-board.js` — `persistMove()` and the drawer's `setStatus()` toast the
  `egg` key from the move response.

**Tests**
- `tests/Feature/EasterEggTest.php` — bank defaults/seed idempotency/pick, the
  once-a-day gate (including the "don't burn the quota on an empty bank" case), the
  board egg (fires / doesn't fire / never for an Event), Keep it plain, and the
  settings routes' role + tenant checks.

## Acceptance items → verification

1. Friday 17:05 egg, once/day, both languages, per-user — `CR31Test::test_acceptance_1`.
2. Clearing the last overdue card returns `inbox_zero` once — `CR31Test::test_acceptance_2`
   and `EasterEggTest::test_moving_the_last_overdue_card_to_done_returns_an_inbox_zero_egg`
   (+ the "not overdue" / "Event" negatives).
3. Keep it plain removes every egg/confetti and shows the same switch on profile —
   `CR31Test::test_acceptance_3`.
4. Late-night egg with the overtime shortcut, never judgemental, once/day —
   `CR31Test::test_acceptance_4`.
5. HR curates the bank, staff get 403, only approved lines show —
   `CR31Test::test_acceptance_5`, `EasterEggTest`'s role/tenant tests.
6. Tab collector — human check, `markTestIncomplete` (frozen test, left as-is).
