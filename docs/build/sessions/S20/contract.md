# Session S20 contract: CR-33 (Creative Greeting Line)

## Files to touch

- `app/Models/GreetingLine.php` — remove `overdue`/`not_clocked_in` from `TRIGGERS`; add
  `early`, `wednesday`, `saturday`, `month_start`, `all_clear`, `long_weekend`,
  `anniversary`, `back_from_leave`, `rain` with bucket + EN/MS labels.
- `app/Support/GreetingBank.php` — remove the 8 `overdue`/`not_clocked_in` default lines;
  add >=3 EN+BM lines each for the 9 new triggers; reword the pre-existing `birthday`
  line containing "later" and the `late`-trigger lines containing "late"/"lewat" so no
  approved line anywhere in the bank can trip the CR's forbidden-word list.
- `app/Http/Controllers/Concerns/BuildsDashboardData.php` — rewrite
  `activeGreetingTriggers()`: drop the `overdue`/`not_clocked_in` blocks, add `early`
  (<08:00), split day-of-week into monday/wednesday/friday/saturday/weekend(Sunday only),
  add `all_clear`, `month_start`, `long_weekend`, `back_from_leave`, `anniversary`. Update
  `meHead()` to stamp the two new session markers (`greeting.month_seen`,
  `greeting.dash_last_load`) used by `month_start`/`back_from_leave`.
- New migration `database/migrations/2026_09_09_100000_cr33_greeting_bank_refresh.php` —
  per tenant: delete `overdue`/`not_clocked_in` rows, insert the new default lines for any
  tenant whose bank doesn't already have that `text_en` (idempotent, safe to re-run).
- `config/services.php` — add `weather.enabled` flag (mirrors the CR-19 `auto_done`
  pattern exactly).
- `.env.example` — add `AMANAHKU_WEATHER_ENABLED=false` near `AMANAHKU_AUTO_DONE`.
- `docs/build/OPEN.md` — append decisions (session-marker design, all_clear definition,
  long_weekend definition, rain left unwired, the birthday/CR33Test collision).
- New test file `tests/Feature/GreetingTriggersTest.php` — one test per new trigger, plus
  a test for the deletion migration.

No Blade/CSS/JS changes: the settings trigger picker already reads `GreetingLine::TRIGGERS`
(`BuildsSettingsData.php` line 43), so no view or asset rebuild is needed.

## Schema / migration changes

No column changes. One data migration (`cr33_greeting_bank_refresh`) that deletes rows by
trigger and inserts new default rows, tenant by tenant. Runs on dev DB via
`lerd artisan migrate --no-interaction` after tests pass.

## Verification plan (CR33Test.php acceptance items)

1. **Tuesday morning rotation, no repeats, never about performance** — bank has 60+
   approved lines both languages; all `SPEC_TRIGGERS` exist; `overdue`/`not_clocked_in`
   gone. KNOWN COLLISION: this test also asserts the trigger is only `morning`/`tuesday`
   on 2026-09-15, which is Yati's DOB month/day — since `birthday` is unconditional
   (matches CR33Test's own test 3, which requires unconditional birthday-wins-all on the
   same date), this assertion cannot pass. Implemented birthday per spec/test 3, will
   report this test's failure honestly with root cause, logged to OPEN.md.
2. **Friday 4pm shows friday/evening** — verified by `all_clear` requiring an open,
   non-archived, non-cancelled, non-event work item to exist AND none overdue; Yati has
   zero cards in this test so `all_clear` cannot fire, letting `friday` win the day bucket.
3. **Birthday wins over every bucket** — `activeGreetingTriggers()` keeps birthday
   unconditional (pure month/day match), matching this test exactly.
4. **Keep it plain** — no changes to `plainGreeting()`, re-verified by running the test.
5. **BM line served with the page** — no changes to the MS text-pairing mechanism;
   reworded the one existing line that could fail `assertCleanLine` if picked.

Always-checks (due date lock, audit immutability, dashboard unchanged, keep-it-plain
honoured) — no code in this CR touches those paths; re-verified by running the suite.
