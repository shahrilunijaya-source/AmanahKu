# S25 grade: CR-29 Friday Sign-Off

Verdict: **PASS** (one cosmetic QA fix, F1). Graded 2026-09-09 in the browser at
`http://worktree-change-request-tracker.amanahku.localhost` (Playwright), dev DB, dev clock.

## Defects found and fixed by QA

- **F1 Mood kicker and question rendered on one line.** In the 5 PM state the "COMPANY MOOD · FRI 5 PM" kicker and "This week, Unijaya was…" sat inline, unlike the mockup. Fix: `.uj-fr-k` and `.uj-fr-q` are `display:block` (`resources/css/app.css`). Assets rebuilt, no test impact (11/11 still green).

## Acceptance items (tests/Acceptance/CR29Test.php, 8/8 green, 268 assertions; tests/Feature/FridaySignOffTest.php 3/3)

| # | Item | How verified | Result |
|---|------|--------------|--------|
| 1 | Friday 3 PM prompt appears | Shazwan, Thu 10 Sep 15:00: no `friday` widget. Fri 11 Sep 15:30: widget with four `[data-mood]` tiles (Productive, Chaotic, Suspiciously Peaceful, I Survived), win input, share checkbox, Sign off button (`grade-cr29-1-prompt.png`). | PASS |
| 2 | Tap once, done | Tapped Productive, typed a win, ticked share, Sign off: body swapped in place to `[data-friday-done]`, no tiles, no reload (`grade-cr29-2-done.png`). Second POST 422. DB: one `friday_moods` row (no identity column), one receipt (64-char, no mood, no created_at), audit `friday.signed_off` with `user_id NULL` actor Anonymous; the shared win audited under Shazwan as `friday.win_shared`. | PASS |
| 3 | 5 PM company mood shows percentages | Kussairi, Yati, Hidayah signed off at 16:00, director at 17:05 (5th). Director and Kussairi at 17:30: `[data-friday-mood]` 40 / 20 / 20 / 20, "5 signed off · nobody can see who picked what", shared win under Shazwan's name (`grade-cr29-3-mood-director.png`, `grade-cr29-3-dash-director.png`). Still shown Sun 13 Sep 20:00. | PASS |
| 4 | Director view has no per-person mood | Director's widget names nobody but the shared-win author; Yati's private win never shown to others; `friday_moods` columns id/tenant_id/week_of/mood/created_at only; `friday_receipts` has no mood, no created_at; only route containing "friday" is `POST app/friday-signoff`, no GET, no export. | PASS |
| 5 | With 4 responses mood is hidden | Director at 17:05 with 4 sign-offs: no `[data-friday-mood]`, no percentages, "Company mood needs 5 sign-offs" line (`grade-cr29-5-few.png`). Fifth answer flipped it on. | PASS |
| 6 | Shared vs private win, closes Monday 9 AM | `friday_wins`: Shazwan shared=1, Yati shared=0. Mon 14 Sep 09:00: widget absent, POST 422. | PASS |
| 7 | Keep it plain | Director with `plain:true`: no emoji art, "Peaceful" and "I Survived" labels, sentence-case kicker, no canvas/audio (`grade-cr29-6-plain.png`). | PASS |

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR29Test.php` | 8 passed, 268 assertions |
| Due date change `PATCH /app/board/422 {due_at}` as Kussairi | 422, locked |
| Audit row edit and delete via tinker | `RuntimeException` on both (checked this session for S24, same code path) |
| Dashboard, Shazwan, Tue 2026-09-15 09:00 | no `friday` widget, widget order summary, clock, tasks, leave, style, calendar, notices, flowers, claims, work (`grade-cr29-dashboard.png`); clock reset to real |
| Keep it plain | no animation, no cheeky text (item 7) |
| Outbound SDK / HTTP in S25 diff | none |
| OPEN entries name Alternatives + Reversal cost | yes (both S25 / CR-29 entries) |
| Protected files untouched by session | yes |

## Screenshots
grade-cr29-1-prompt, -2-done, -3-mood-director, -3-dash-director, -5-few, -6-plain, -dashboard (all `.png`).
