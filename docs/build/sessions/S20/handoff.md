# Session S20 handoff: CR-33 (Creative Greeting Line)

## Delivered
- Removed `overdue`/`not_clocked_in` from `GreetingLine::TRIGGERS`, `GreetingBank::DEFAULTS`
  and `activeGreetingTriggers()` — verified by acceptance item 1 (bank no longer carries
  them) and item 2 (Friday afternoon no longer loses to a fake "not clocked in" line).
- Added the 9 new spec-bucket triggers (`early`, `wednesday`, `saturday`, `month_start`,
  `all_clear`, `long_weekend`, `anniversary`, `back_from_leave`, `rain`) to
  `GreetingLine::TRIGGERS`, each with >=3 EN+BM default lines in `GreetingBank::DEFAULTS`
  (79 lines total, up from 60, all clean of the forbidden-word list) — verified by
  acceptance item 1's SPEC_TRIGGERS existence loop and by
  `tests/Feature/GreetingTriggersTest.php` (one test per new trigger).
- Wired all 9 new triggers into `activeGreetingTriggers()` using only real signals already
  in the schema (public_holidays for `long_weekend`, leave_requests for `back_from_leave`,
  work_items for `all_clear`, `employees.joined_at` for `anniversary`, session markers for
  the two "first load" triggers). `rain` is deliberately never added to the active list —
  see OPEN.md.
- Time-of-day thresholds updated exactly as specified: early <08:00, morning <12:00,
  afternoon <18:00, evening <22:00, late after — verified by
  `tests/Feature/GreetingTriggersTest.php::test_early_trigger_fires_before_8am` and
  acceptance items 1/2/4/5 (unchanged morning/afternoon/evening boundaries).
- Settings-screen trigger picker: no code change needed, it already reads off
  `GreetingLine::TRIGGERS` (`BuildsSettingsData.php` line 43), so the 9 new labels (EN+BM)
  show up automatically — spot-checked via `GreetingLineTest::test_settings_screen_renders_the_greetings_card_for_hr`
  (unchanged, still green).
- Deletion + backfill migration for existing tenants, idempotent — verified by
  `tests/Feature/GreetingTriggersTest.php::test_deletion_migration_removes_overdue_and_not_clocked_in_and_backfills_new_lines`
  and applied to the dev DB.
- Reworded a handful of pre-existing bank lines that could trip the forbidden-word check
  once the personal bucket became reachable together with `assertCleanLine` (the birthday
  "inbox later" line, the `late`-trigger's own "late"/"lewat" wording, two "clean slate"
  lines whose "slate" contains "late" as a substring) — see OPEN.md for the reasoning.

## Schema changes
- No new columns. One data migration:
  `database/migrations/2026_09_09_100000_cr33_greeting_bank_refresh.php` — deletes
  `overdue`/`not_clocked_in` rows tenant-wide, then inserts any of the new default lines a
  tenant doesn't already have (matched by exact `text_en`). Idempotent, ran clean on the
  dev DB (`lerd artisan migrate --no-interaction`).

## Contracts touched
- None. Dashboard slot (`BuildsDashboardData::meHead()`) unchanged in shape; still one
  `<h1>`/`h1_ms` pair per `docs/build/contracts/dashboard-slots.md`.

## Port calls stubbed
- None. `rain` has no weather port and never fires on its own — see OPEN.md
  ("rain ships wired to a flag but not to any signal").

## Deferred
- A real weather signal behind `services.weather.enabled` — no port exists in
  `docs/build/contracts/ports.md` yet, and RULES forbids calling any real external
  service. Deferred to whichever future session adds a weather port.
- Resolving the CR33Test item-1/item-3 birthday collision (see OPEN, below) — this needs a
  human call on which test wins, since fixing it any other way means guessing at an
  undocumented gate.

## OPEN, decided without Shazwan
- Birthday is unconditional (plain DOB month/day match), matching test 3 and the CR text
  exactly; this makes acceptance test 1 fail on the same calendar date used for Yati's
  DOB. See OPEN.md "S20 / CR-33 / birthday is unconditional and collides with CR33Test's
  own Tuesday-morning test".
- `month_start`/`back_from_leave` "first load" tracked via two session keys, not a new
  column. See OPEN.md "S20 / CR-33 / month_start and back_from_leave 'first load' tracked
  in session, not a column".
- `all_clear` requires an open card AND none overdue (not just "nothing overdue"). See
  OPEN.md "S20 / CR-33 / all_clear requires an open card, not just 'nothing overdue'".
- `long_weekend` = a public holiday on the Friday before or Monday after the coming
  Saturday/Sunday. See OPEN.md "S20 / CR-33 / long_weekend definition and a sqlite
  date-storage trap" (also documents a real `whereIn` vs `whereDate` bug found and fixed
  while building this).
- `rain` ships behind a flag with no wiring, by design. See OPEN.md "S20 / CR-33 / rain
  ships wired to a flag but not to any signal".
- Reworded a few pre-existing (not newly added) bank lines to actually satisfy the
  forbidden-word rule. See OPEN.md "S20 / CR-33 / reworded a handful of pre-existing bank
  lines...".

## Requested contract change (generator may not make it itself)
- None.

## Traps for the next session

- **`tests/Acceptance/CR33Test.php::test_acceptance_1_...` fails, and it is expected to
  fail with the code as delivered.** It is not a bug in this session's code — it is a
  genuine contradiction inside the frozen test file itself: `setUp()` gives Yati
  `date_of_birth = '1995-09-15'`, test 1 runs on `2026-09-15 10:00` and requires the
  trigger to be `morning`/`tuesday` (never `birthday`), while test 3 runs on the SAME
  calendar date, `2026-09-15 09:00`, and requires the trigger to be `birthday` on every
  load. Both cannot pass under a plain, spec-correct birthday check, and this session
  implemented birthday exactly as the CR text and test 3 require. Do not try to "fix" this
  by adding a birthday gate (time-of-day, once-per-session, etc.) — that would satisfy
  test 1 by contradicting test 3 and the CR's own "birthday wins over every other bucket"
  rule. This needs Shazwan (or whoever owns `/qa`) to either change the fixture's DOB/date
  in a later revision of the frozen test, or confirm test 1's date choice was simply a
  mistake.
- `all_clear` is the one new trigger that can silently interact with any OTHER feature
  test that puts open, non-overdue work items on someone's board and then asserts on the
  dashboard `<h1>` — it now outranks every day/time line for that person. None of the
  named regression tests hit this (verified by the full-suite run), but a future session
  adding dashboard tests with open cards should know this exists.
- The pre-existing `late` (after-10pm) trigger's lines were reworded in this session even
  though `late` isn't one of CR-33's named triggers — purely because they used the literal
  word "late"/"lewat", which the CR's own forbidden-word rule bans from any shown line.
  No behaviour changed, only wording.
- `greeting.month_seen`/`greeting.dash_last_load` are new session keys living alongside
  the existing `greeting.last`. If a future session moves greeting state out of the PHP
  session (e.g. to a database-backed "last seen" record), all three need to move together.

## Test results

```
php artisan test --compact tests/Acceptance/CR33Test.php tests/Feature/GreetingLineTest.php \
  tests/Unit/DashHeadingTest.php tests/Feature/HolidayEveGreetingTest.php \
  tests/Feature/DashboardBandsTest.php tests/Feature/DashboardRenderedQueueTest.php
```
63 tests, 62 passed, 1 failed (the birthday collision above, expected).

```
php artisan test --compact tests/Acceptance/
```
146 tests, 145 passed, 1 failed (same collision), 18 incomplete (pre-existing, unrelated).

```
php artisan test --compact   (full suite, background)
```
2928 tests, 2921 passed, 2 failed:
- `Tests\Feature\LeaveScreenTabsTest::test_cancelling_an_approved_replacement_refunds_the_quota`
  — pre-existing, unrelated to CR-33 (documented in OPEN.md from S19, confirmed again this
  session by the same test failing on files this CR never touches).
- `Tests\Acceptance\CR33Test::test_acceptance_1_...` — the birthday collision above.

New test file added: `tests/Feature/GreetingTriggersTest.php` — 11 tests, all passing, one
per new trigger plus the deletion/backfill migration.

Dev DB migration applied: `lerd artisan migrate --no-interaction` ran
`2026_09_09_100000_cr33_greeting_bank_refresh` clean.
