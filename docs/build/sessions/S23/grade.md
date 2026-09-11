# S23 grade: CR-28 Victory Bell

Verdict: **PASS** (after QA fix F1). Graded 2026-09-09 in the browser at
`http://worktree-change-request-tracker.amanahku.localhost` (Playwright), dev DB.

## Defects found and fixed by QA

- **F1 Ring prompt hidden behind the drawer.** Moving a Milestone card to Done from the drawer's status buttons rendered `.uj-vb-prompt` at `z-index:60`, the same level as `.wd-scrim` (60) and below `.wd` (61), so the toast was invisible and unclickable while the drawer stayed open (`grade-cr28-2-prompt.png` before the fix shows no toast). Fix: `.uj-vb-prompt` now sits at `z-index:63`, scoped to its own class per the board z-index rule (`resources/css/app.css` ~5073). Assets rebuilt. No PHPUnit test possible for stacking order; re-verified in the browser: toast visible over the open drawer, "Ring it" clickable.

## Acceptance items (tests/Acceptance/CR28Test.php, 6/6 green)

| # | Item | How verified | Result |
|---|------|--------------|--------|
| 1 | Milestone flag, PM+ only | Kussairi (manager): drawer shows Milestone checkbox, enabled (`grade-cr28-flag-drawer.png`); `PATCH /app/board/131 {is_milestone:true}` 200, card JSON `is_milestone:true`. Employee 403 covered by acceptance test. | PASS |
| 2 | Done triggers "Ring the bell?" | Shazwan (owner) presses Done in the drawer of card 131: `.uj-vb-prompt` "MILESTONE DONE / Ring the bell? / Add a line, or leave it blank / Ring it / Not now" (`grade-cr28-2-prompt.png`). Non-milestone card 152 moved to done: `bell: null`, ring endpoint 422. Second ring on 131: 422 "This card has already rung the bell." | PASS |
| 3 | Dashboard celebration | `victory_bells` row 1 (line saved), audit `victory_bell.rung` target `victory_bell:1`. `/app/dash` moment `[data-kind="victory-bell"][data-victory-bell="1"]`: kicker WE HAVE MOVEMENT, "2.0 Release - dark mode is officially Done.", member chip, line, "Rung by Shazwan · AmanahKu · on the dashboard until Thu 10 Sep, 14:48 · then on the Wins page", confetti present (`grade-cr28-3-banner.png`, `grade-cr28-3-dash.png`). Note: moments rotate by day-of-year with the live Big Deal from S22, index 1 was selected for the screenshot. | PASS |
| 4 | Reactions | Legend then Power: tally swaps to the new pick (one reaction per person), row in `victory_bell_reactions` (`grade-cr28-4-react.png`). | PASS |
| 5 | 24h window then Wins | Dev clock 2026-09-10 16:00: moment gone from `/app/dash`; `/app/wins` shows `[data-win-bell="1"]` with kind label, title, line, reactions (`grade-cr28-5-wins.png`). | PASS |
| 6 | Keep it plain | prefs `plain:true`: moment still renders text, no `.uj-db-confetti`, no canvas, no audio, no bell art, `data-plain` set (`grade-cr28-6-plain.png`). | PASS |

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR28Test.php` | 6 passed, 147 assertions |
| Due date change `PATCH /app/board/254 {due_at}` | 403 |
| Audit row edit via tinker | `RuntimeException: audit_logs rows are append-only` |
| Dashboard, Shazwan, 2026-09-15 09:00 | baseline order, only pre-existing Holiday Eve moment (`grade-cr28-dashboard.png`); clock reset to real |
| Keep it plain | no animation, no cheeky text (item 6) |
| Outbound SDK / HTTP in S23 diff | none |
| OPEN entries name Alternatives + Reversal cost | yes (S23 / CR-28 entry) |
| Protected files untouched by session | yes (only QA's own `tests/Acceptance/CR25Test.php` changed since the CR-28 test commit) |

## Screenshots
grade-cr28-flag-drawer.png, grade-cr28-2-prompt.png, grade-cr28-3-banner.png, grade-cr28-3-dash.png, grade-cr28-4-react.png, grade-cr28-5-wins.png, grade-cr28-6-plain.png, grade-cr28-dashboard.png
