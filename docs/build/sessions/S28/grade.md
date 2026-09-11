# S28 grade: CR-22 Amanahku Wrapped

Verdict: **PASS** after three QA fixes (one test defect, two code defects). Graded 2026-09-09 in the
browser at `http://worktree-change-request-tracker.amanahku.localhost` (Playwright), dev DB, dev clock.
September 2026 stories built on the dev DB with `wrapped:build` under a 2026-10-01 08:00 test clock
(32 stories, 30 arcs for tenant 1). Dev DB has no `award_snapshots`, so every personal deck shows 0 closed.

## Defects found and fixed by QA

- **T1 QA test defect.** `CR22Test` item 3 expected `urgent` = 4, but the fixture's `finishedCard()` creates a card the day before it is done, so Yati's high card done 1 Sep was created 31 Aug. High-priority cards created in September are Yati 1 + Shazwan 2 = 3. Session was right. Expectation and docblock corrected to 3. Supersedes the session's OPEN entry on this.
- **F1 Company moment layout.** On the dashboard the sentence squeezed into a narrow column beside the reaction chips, the footer "Company totals, frozen with the awards." overlapped it, and the moment counter was clipped (see the pre-fix state described here, screenshot replaced). The band was left on the default no-wrap flex row. Fix in `resources/css/app.css`: same row model as the Victory Bell moment (`flex-wrap`, sentence and footer on their own rows). Verified desktop and 390px (`grade-cr22-moment.png`, `-moment-phone.png`).
- **F2 "6 of your 0 cards".** `cards_closed` is the frozen awards snapshot, but `best_day` and its count came from live cards, so a person with cards done but no snapshot row read "Your most productive day was Thursday, with 6 of your 0 cards landing there." Fix in `WrappedBuild`: best day is only computed when the frozen closed count is above 0, otherwise the deck uses its existing quiet line. Feature test `test_best_day_is_dropped_when_the_frozen_closed_count_is_zero` added to `tests/Feature/WrappedTest.php` (fails without the fix).

## Acceptance items (tests/Acceptance/CR22Test.php 6/6, tests/Feature/WrappedTest.php 4/4, acceptance suite 201/201)

| # | Item | How verified | Result |
|---|------|--------------|--------|
| 1 | 1 Oct, Yati sees her September Wrapped (6 to 8 cards) and can share it | Yati at 2026-10-01 10:00: `/app/wrapped` shows `data-wrapped="3"`, 7 `data-wrapped-card`, prev/next arrows and dots work (`grade-cr22-yati-card4.png`). "Share to the Wall" turns into "Shared on the Wall · Unshare"; `/app/wins` shows one `data-win-wrapped` card with her name, arc and four numbers (`grade-cr22-wins-yati.png`); Unshare removes it. Audit `wrapped.shared` / `wrapped.unshared` rows with actor name. Shazwan same flow (`grade-cr22-screen.png`, `grade-cr22-wins.png`). | PASS |
| 1 | Dashboard shows company Wrapped with reactions | Shazwan at 2026-10-02: `/app` moments band has `data-kind="wrapped" data-wrapped-company="62"` (was 30 before the rebuild), kicker "SEPTEMBER, WRAPPED", sentence "10 cards closed, 0 lessons shared, 1 fires extinguished and only 20 "urgent" tasks.", hidden stat carriers match. Clicking ⚡Power writes a `wrapped_reactions` row and the chip shows "1 ⚡ Power" (`grade-cr22-moment.png`). Company story 404s on share/unshare as any user. | PASS |
| 1 | Keep it plain gets a plain text summary | Shazwan `plain:true`: `/app/wrapped` renders `[data-wrapped-plain]` paragraph only, no deck, no animations (`grade-cr22-plain.png`); dashboard moment has no big number, no animation (`grade-cr22-moment-plain.png`). | PASS |
| Rules | Private by default, no comparison, arcs HR editable 30+ | Kussairi: share/unshare of Shazwan's story 403, arc add 403, his own screen has no other person's name. Nur Hidayah (HR): 30 `data-wrapped-arc` rows with retire forms, add "QA Grade Arc" (closer) shows as row 31, retire removes it (`grade-cr22-hr-arcs.png`); audit `wrapped.arc_added`, `wrapped.arc_retired`. | PASS |

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR22Test.php` | 6 passed (after T1) |
| Due date change `PATCH /app/board/422 {due_at}` | 422 as Kussairi (owner), 403 as Shazwan |
| Audit row edit and delete via tinker | `RuntimeException` on both |
| Dashboard, Shazwan, Tue 2026-09-15 09:00 | widget order summary, clock, tasks, leave, style, calendar, notices, flowers, claims, work; the only band is the CR-13 holiday-eve moment for Malaysia Day (16 Sep), not a CR-22 band (`grade-cr22-dashboard.png`); clock reset to real |
| Keep it plain | item 1 |
| Outbound SDK / HTTP in S28 diff | none |
| OPEN entries name Alternatives + Reversal cost | yes (8 S28 entries + the session's QA-addressed one) |
| Protected files untouched by session (`git diff beeef186..23c724ba`) | yes; QA edited its own test only |

Dev data left: September 2026 `wrapped_stories` and 30 arcs for tenants 1 and 3, one `wrapped_reactions` row (Shazwan, power, story 62). Arc 61 "QA Grade Arc" retired.

## Screenshots
grade-cr22-screen, -yati-card4, -plain, -wins, -wins-yati, -moment, -moment-phone, -moment-reacted, -moment-plain, -hr-arcs, -dashboard (all `.png`).
