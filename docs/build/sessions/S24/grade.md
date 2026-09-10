# S24 grade: CR-25 Plot Twist

Verdict: **PASS** (after QA fixes F1, F2, F3). Graded 2026-09-09 in the browser at
`http://worktree-change-request-tracker.amanahku.localhost` (Playwright), dev DB, dev clock.

## Defects found and fixed by QA

- **F1 A poll not yet open rendered with live vote buttons.** With only a future poll published, the screen showed "THIS WEEK'S PLOT TWIST" and clickable options; a click got a 422 and the page silently did nothing. Fix: `screenData` now passes `upcoming`, and the blade renders a read-only "NEXT PLOT TWIST · OPENS MON 14 SEP" hero (`data-poll-upcoming`, no `data-poll-option`) until `opens_on` (`grade-cr25-fix-upcoming.png`).
- **F2 Named person was a raw numeric id input.** The mockup shows a person picker. Fix: `<select name="named_employee_id">` fed by `Employee::active()` (`people`, publishers only) (`grade-cr25-fix-person-select.png`).
- **F3 Opt-out strip only for the "current" poll, and "current" was the newest poll even if not open.** Publishing next week's poll hid this week's poll and results, and a named person lost their opt-out the moment a later poll existed. Fix: `currentPoll()` prefers the latest poll whose `opens_on` has passed, else the earliest upcoming; `optOutPolls` lists every open who-poll naming the viewer that is still before its opening day, each with its own strip (`grade-cr25-fix-optout-strip.png`). Shazwan opted out of poll 2 in the browser: status `withdrawn`, strip gone.
- Feature tests for all three in `tests/Feature/PlotTwistTest.php` (`test_qa_f1_*`, `test_qa_f2_*`, `test_qa_f3_*`), 7/7 green. Pint and phpstan clean, assets rebuilt.
- QA also fixed two defects in its own `tests/Acceptance/CR25Test.php` (item 4): the receipt "no digit" assertion was unsatisfiable for a hex digest (now asserts a 64-char hex, not the bare user id), and the voter-name sweep scanned the director's own clock-in widget (now scoped to the poll's markup). See the OPEN entry.

## Acceptance items (tests/Acceptance/CR25Test.php, 7/7 green, 204 assertions)

| # | Item | How verified | Result |
|---|------|--------------|--------|
| 1 | Screen in The Playground, HR/director publish, others 403 | Hidayah (HR): sidebar "Plot Twist", empty state (`grade-cr25-0-empty-hr.png`), publish form question/kind/person/opens_on/6 options (`grade-cr25-1-publish-form.png`), published poll 1 "Unijaya's unofficial national food?" (`grade-cr25-1-published.png`). Employee/manager 403 covered by the acceptance test. | PASS |
| 2 | One vote per person, window enforced | Shazwan, Kussairi, Yati at 2026-09-15: vote buttons, one click each, hero flips to "You voted" (`grade-cr25-2-monday-open.png`, `grade-cr25-2-voted.png`); second vote 422; before Monday no vote accepted (`grade-cr25-2-before-monday.png`, F1 fixed the rendering). | PASS |
| 3 | Friday 15:00 reveal on Notice board + screen | Dev clock 2026-09-18 15:05: Notice board row `[data-plot-twist]` with bars 67/33/0 and "3 voted · nobody can see who picked what" (`grade-cr25-3-notice.png`, `grade-cr25-3-dash.png`); screen shows the same (`grade-cr25-3-screen-results.png`); `/results` before reveal 403. | PASS |
| 4 | Absolute anonymity | `plot_twist_votes` has no identity column; `plot_twist_receipts` holds a 64-char hex per poll only; audit `plot_twist.voted` rows have `user_id NULL`, actor "Anonymous"; no voter name in the notice, screen or results JSON. | PASS |
| 5 | Suggestions, who templates, opt-out, social feed | Shazwan suggested a question, row in `plot_twist_questions` with audit (`grade-cr25-5-suggest.png`); who-question not in the template bank refused; poll 2 named Shazwan, opt-out strip shown and used (`grade-cr25-5-optout.png`, `grade-cr25-fix-optout-strip.png`). Social poll 3: 2 votes at 21 Sep, reveal 25 Sep 15:05 as Yati (`grade-cr25-7-social-reveal.png`), comment "Plot Twist result: Bowling (100%)" on card 422 "Plan next social activity" (recurring), `idea_fed_at` set once. | PASS |
| 6 | Keep it plain | prefs `plain:true`: no dice, no cheeky kicker, "Weekly poll" text only on notice and screen (`grade-cr25-6-plain.png`, `grade-cr25-6-plain-screen.png`). | PASS |

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR25Test.php` | 7 passed, 204 assertions |
| Due date change `PATCH /app/board/422 {due_at}` as Kussairi | 422 "Due dates are locked after the first save" |
| Audit row edit and delete via tinker | `RuntimeException` on both, rows append-only |
| Dashboard, Shazwan, 2026-09-15 09:00 | baseline order, only the pre-existing Holiday Eve moment (`grade-cr25-dashboard.png`); clock reset to real |
| Keep it plain | no animation, no cheeky text (item 6) |
| Outbound SDK / HTTP in S24 diff | none |
| OPEN entries name Alternatives + Reversal cost | yes (all 8 S24 / CR-25 entries) |
| Protected files untouched by session | yes (only QA's own `tests/Acceptance/CR25Test.php` changed, by QA) |

## Screenshots
grade-cr25-0-empty-hr, -1-published, -1-publish-form, -2-before-monday, -2-monday-open, -2-voted, -3-notice, -3-dash, -3-screen-results, -5-suggest, -5-optout, -6-plain, -6-plain-screen, -7-social-reveal, -fix-optout-strip, -fix-upcoming, -fix-person-select, -dashboard (all `.png`).
