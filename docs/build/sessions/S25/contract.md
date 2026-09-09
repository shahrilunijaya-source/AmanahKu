# Session S25 contract: CR-29 Friday Sign-Off

Shapes are frozen by `tests/Acceptance/CR29Test.php` (see `docs/build/OPEN.md`
"QA / CR-29 / shapes fixed by CR29Test"). This session fills the existing
`friday` dashboard widget (S04 slot). No new screen, no new widget.

## Files touched

- `database/migrations/<ts>_create_friday_tables.php` — new: `friday_moods`,
  `friday_receipts`, `friday_wins`.
- `app/Models/FridayWin.php` — new Eloquent model (moods/receipts stay
  `DB::table`, same as `plot_twist_votes`/`plot_twist_receipts` — no identity
  to hang a model relationship off of).
- `app/Http/Controllers/FridayController.php` — new: `signOff()` (the POST
  handler) and `widgetData()` (payload for the dashboard widget, called from
  `BuildsDashboardWidgets`).
- `app/Support/DashboardWidgets.php` — add `fridayWeekOf(CarbonImmutable $now): string`
  next to the existing `fridaySignOffOpen()`. No change to the registry entry
  (S04 already shaped it right) or to `fridaySignOffOpen()` itself.
- `app/Http/Controllers/Concerns/BuildsDashboardWidgets.php` — line 244's
  `'friday' => [...]` case calls `FridayController::widgetData()` instead of
  the placeholder array.
- `resources/views/partials/dash/widgets/friday.blade.php` — replace the
  placeholder body with the real markup (mockup `fr-widget.js`/README, class
  prefix `uj-fr-`), Alpine `x-data` posting to `/app/friday-signoff` and
  swapping state in place (no reload), matching the "no full-page reloads"
  house rule.
- `resources/css/app.css` — append the `.uj-fr-*` rules from the mockup
  README, same section as the `.uj-pt-*` (Plot Twist) rules.
- `routes/web.php` — one route: `Route::post('/app/friday-signoff', [FridayController::class, 'signOff'])->name('friday.signoff');`
  next to the Plot Twist block. No GET route anywhere with "friday" in the URI
  (test item 4 checks the whole route table for this).
- `tests/Feature/FridaySignOffTest.php` — new.
- `public/build/**` — rebuilt assets.
- `docs/build/sessions/S25/contract.md`, `handoff.md` — this session's paperwork.

Not touched: `tests/Acceptance/CR29Test.php`, any `docs/build/contracts/*`,
`docs/build/RULES.md`, the board, `PlotTwistController.php` (read only, for
precedent).

## Schema

- `friday_moods`: `id`, `tenant_id`, `week_of` (date), `mood` (string),
  `created_at` (no `updated_at`). No identity column, ever.
- `friday_receipts`: `id`, `tenant_id`, `week_of` (date), `receipt` (char 64).
  No `mood`, no `created_at`. Unique on `(tenant_id, week_of, receipt)` — the
  same "insert both in one transaction, let the unique index catch a race"
  pattern as `plot_twist_receipts`.
- `friday_wins`: `id`, `tenant_id`, `week_of` (date), `employee_id`, `text`,
  `shared` (bool), `timestamps()`.

Receipt = `hash_hmac('sha256', "{$employee->user_id}:{$weekOf}", config('app.key'))`,
`$weekOf` the `Y-m-d` string from `DashboardWidgets::fridayWeekOf()` — same
shape as `PlotTwistController::receiptFor()`, different input.

## How each acceptance item is verified

1. **Prompt appears Friday 15:00, gone before / after the window, `POST` 422
   outside the window** — `DashboardWidgets::fridaySignOffOpen()` already
   gates the widget's presence (S04); `FridayController::signOff()` calls the
   same gate before validating anything else. Covered by
   `test_acceptance_1_friday_3pm_prompt_appears`.
2. **Tap once, done; double-submit and bad mood refused; audit is
   anonymous; Saturday still counts as the same week** — the receipt-exists
   check (422 on a repeat), `mood` validated against the four keys,
   `AuditLog::create()` direct call (bypasses `record()`'s `Auth::id()`
   capture) with `user_id` null and `actor_name` "Anonymous", exactly the
   `plot_twist.voted` pattern. `fridayWeekOf()` maps Saturday back to the
   Friday just gone. Covered by `test_acceptance_2_tap_once_done`.
3. **5 PM reveal, 5+ responses, gone with the card Monday 09:00** —
   `widgetData()` computes `showMood` as `now >= 17:00 of week_of AND total >= 5`;
   the card itself is already absent from Monday 09:00 (S04 gate). Covered by
   `test_acceptance_3_5pm_company_mood_shows_percentages`.
4. **No per-person mood anywhere, no GET route, no export** — `friday_moods`/
   `friday_receipts` carry no identity column (schema-asserted directly);
   every audit row from `signOff()` is anonymous; the widget never prints a
   name next to a mood (only next to a *shared win*, which is a different,
   consented disclosure); the only route is the `POST`. Covered by
   `test_acceptance_4_director_view_has_no_per_person_mood`.
5. **Under 5 responses, mood hidden** — same `showMood` gate as item 3.
   Covered by `test_acceptance_5_with_4_responses_mood_is_hidden`.
6. **Shared vs private win** — `friday_wins.shared`; the widget renders a
   shared win as `[data-friday-win="<id>"]` with the author's name for
   everyone once `showMood` is true, and the viewer's own win (shared or not)
   as `[data-friday-my-win]` always, dropped from the shared list to avoid
   double-listing. `AuditLog::record('friday.win_shared', ...)` only when
   `shared` is true — this one is *not* anonymous, the CR says the name is
   posted deliberately. `win` validated `max:160`. Covered by
   `test_acceptance_6_my_win_shared_or_private`.
7. **Keep it plain** — `DashboardPrefs::forUser()['plain']` toggles labels
   ("Suspiciously Peaceful" → "Peaceful") and drops `uj-fr-art`/animation
   classes, no `uj-db-confetti`/`<canvas>`/`<audio>` anywhere (none are used
   here regardless of `plain`). Covered by `test_acceptance_7_keep_it_plain`.

## Decisions carried from the approved mockup (not new OPEN entries)

- The bar-only label swap "Refusing to Elaborate" (non-plain) / "I Survived"
  (plain) for the `survived` mood's *results* row, while the tile itself
  always reads "I Survived" — `docs/build/sessions/S25/mockup/README.md`
  already resolves this (left open by the QA docblock, closed by the
  approved mockup).
- Own win shown immediately in the "done" state (before 17:00), shared wins
  only join the list from 17:00 — matches the mockup's state 2/3 split.

## New OPEN.md entries this session expects to add

- Percentage rounding reuses the same largest-remainder method as
  `PlotTwistController::percentages()`, duplicated rather than extracted —
  extracting a shared helper would touch `PlotTwistController.php`, outside
  this CR's files (RULES.md rule 3, "no refactoring outside the files this CR
  touches").
