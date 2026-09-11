# Session S24 contract: CR-25 This Week's Plot Twist

Shapes are fixed by `tests/Acceptance/CR25Test.php` and `docs/build/OPEN.md`
(`## QA / CR-25`). This file only records the file list and how each
acceptance item is verified.

## Files

New:
- `database/migrations/<ts>_create_plot_twist_tables.php` — five tables.
- `app/Models/PlotTwistPoll.php`, `app/Models/PlotTwistOption.php`, `app/Models/PlotTwistQuestion.php`
  (votes and receipts are anonymous rows, no Eloquent model — same choice
  Victory Bell made for `victory_bell_reactions`, written through `DB::table`).
- `app/Http/Controllers/PlotTwistController.php`
- `resources/views/screens/plot-twist.blade.php`

Changed:
- `routes/web.php` — 5 routes (store, suggest, vote, opt-out, results); the
  screen itself rides the existing `/app/{screen}` catch-all.
- `app/Http/Controllers/AppController.php` — `screenData()` switch, one line.
- `app/Support/Amanahku.php` — sidebar entry under The Playground, `page()` entry.
- `app/Http/Controllers/Concerns/BuildsDashboardData.php` — `newsRows()` prepends
  one Plot Twist row (built by `PlotTwistController::noticeRow()`) when a poll
  is revealed.
- `resources/views/partials/dash/widgets/notices.blade.php` — special-cases a
  `kind === 'plot_twist'` row with its own markup (bars, plain-mode swap).
- `resources/css/app.css` — `/* CR-25 Plot Twist */` block, `.uj-pt-*` classes
  from the approved mockup.

## Schema

- `plot_twist_polls`: tenant_id, question, kind (fun|who|social), named_employee_id
  (nullable FK employees), status (draft|open|withdrawn), opens_on (date),
  reveals_at (datetime), idea_fed_at (nullable datetime), created_by (nullable FK
  employees), timestamps.
- `plot_twist_options`: poll_id FK, label, sort_order, timestamps.
- `plot_twist_votes`: poll_id FK, option_id FK, created_at only — no identity column.
- `plot_twist_receipts`: poll_id FK, receipt char(64), created_at only — no option
  column; unique (poll_id, receipt) as a DB-level backstop against a double vote race.
- `plot_twist_questions`: tenant_id, text, kind, template (bool), suggested_by
  (nullable FK employees), approved (bool), timestamps.

## Acceptance items → verification

1. Monday poll appears (only HR/director publish, options 2–6, opens_on must be
   a Monday, reveals_at = that Friday 15:00, screen shows question/options with
   no results before reveal) — `PlotTwistController::store()` +
   `tests/Acceptance/CR25Test::test_acceptance_1_monday_poll_appears`.
2. Yati votes, cannot vote twice; window enforced; cross-poll option rejected —
   `PlotTwistController::vote()` + receipt table + `test_acceptance_2_*`.
3. Friday 3 PM results as percentages, largest-remainder rounding, zero-vote
   wording, one row that hands off to the next revealed poll —
   `PlotTwistController::currentPoll()`/`renderResults()`,
   `BuildsDashboardData::newsRows()` + `test_acceptance_3_*`.
4. Anonymity: schema has no identity column on votes, no option column on
   receipts, no audit row ties a person to a choice — the vote audit entry is
   written with `AuditLog::create()` directly (bypassing `record()`'s
   `Auth::id()` capture) so it never carries the voter's identity —
   `test_acceptance_4_*`.
5. Question bank (suggest, HR-only visibility), who-template + named-person +
   opt-out, social poll feeds CR-18's card once via `idea_fed_at` —
   `PlotTwistController::suggest()/optOut()/feedSocialIdea()` +
   `test_acceptance_5_*`.
6. Keep it plain: numbers stay, "PLOT TWIST" → "Weekly poll", no `uj-pt-art`,
   no canvas/audio — `test_acceptance_6_*`.

## Deliberate scope cuts (cheap to add later, noted in OPEN.md)

- No admin UI to promote a suggestion into the approved template bank — QA's
  own OPEN entry already left this open; `plot_twist_questions` rows are
  approved only by direct DB/seed action this session.
- No "save as draft" button wired on the publish form — the `status` column
  supports it, the form always publishes as `open` (matches every test call).
- No factories for the three new models — Big Deal and Victory Bell (S22/S23)
  set the same precedent, tests build fixtures with plain `::create()`.
