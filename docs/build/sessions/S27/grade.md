# S27 grade: CR-27 Mystery Award

Verdict: **PASS** after three QA fixes (one test defect, two code defects). Graded 2026-09-09 in the
browser at `http://worktree-change-request-tracker.amanahku.localhost` (Playwright), dev DB, dev clock.

## Defects found and fixed by QA

- **T1 QA test defect.** `CR27Test` item 2 used the spec's example "Professional Tab Collector" as the secret, but the CR-31 easter egg in `layouts/app.blade.php` prints that exact phrase on every page. Session was right to leave it. Secret swapped for "Chief Snack Negotiator". Supersedes the session's OPEN entry on this.
- **F1 Late pick never reveals.** `awards:publish` runs once per month and skips a month it already stamped (audit `awards.published`), and the mystery stamp sat after that guard. A pick for September made on 2 Oct (still the selection month until the last Monday of October) stayed sealed forever. Fix in `AwardController::mysteryPick()`: when the month's awards are already published, `published_at = now()` at pick time, like a late Chosen One pick. Feature test added in `tests/Feature/MysteryAwardTest.php`. Found because the dev DB carries an `awards.published` row for 2026-09 from an earlier grade.
- **F2 Keep it plain ignored on the slide.** Envelope art (`.uj-ma-env`), the "Nobody knew this category existed until 8:00 this morning." line and the fade-in class rendered in plain mode. `partials/awards/mystery.blade.php` now reads the plain pref like side-quests does: no art, no animation class, sub line "One surprise award a month. Category kept sealed until today." CR27Test item 4 tightened (QA's own test) to refuse `uj-ma-env` and the cheeky line.

## Acceptance items (tests/Acceptance/CR27Test.php 5/5, tests/Feature/MysteryAwardTest.php 8/8, CR14bTest 8/8 still green)

| # | Item | How verified | Result |
|---|------|--------------|--------|
| 1 | Slide reveals on the 1st with category, winner, explanation | Director sealed a pick 29 Sep. Revealed via the F1 path on 2 Oct (dev `awards.published` row blocks the command path on this DB; command path is proven by CR27Test item 1 and the session's feature test). Shazwan and Yati at 2 Oct: band slides `done_and_dusted, mystery` (mystery last), slide shows "MYSTERY AWARD · SEPTEMBER", "Chief Snack Negotiator", `data-winner="26"` Shazwan, the explanation in quotes, "Picked by the Director · not a KPI..." (`grade-cr27-1-slide-shazwan.png`, `-yati.png`). `/app/awards?month=2026-09-01` renders `data-award="mystery"` with the same (`grade-cr27-1-awards-screen.png`). | PASS |
| 2 | Category visible nowhere before publish | After sealing, as director, Kussairi (committee) and Shazwan (winner) on 29/30 Sep: none of `/app/dash`, `/app/awards`, `?month=2026-09-01`, `/app/profile`, `?emp=26` contain the category, the explanation, `data-slide="mystery"` or `data-award="mystery"`. Select tab shows only the sealed box `data-mystery-picked="2026-09-01"`, form not prefilled (`grade-cr27-2-sealed.png`). | PASS |
| 3 | Rules: picker, committee, no back-to-back, not counted, audited, no rubric | HR POST 403 and no form; director set committee via the Change form (3 selects, "Committee saved."); Kussairi as member sees the form, self-pick 422, pick of Shazwan 200 (replaces, still one row). 27 Oct: "Shazwan (won last month)" disabled in the dropdown, POST 422 "Cannot win the Mystery Award two months in a row.", Yati accepted for October. `award_results` has no `mystery` key, profile `?emp=26` has no `data-award-badge="mystery"` / hall-of-fame. Audit: `award.mystery_committee` (director), `award.mystery_picked` ×3 with actors. No rubric column or field. | PASS |
| 4 | Keep it plain | After F2: Shazwan `plain:true`, band `data-plain`, slide has no `.uj-ma-env`, animation `none`, plain sub line, text kept; same on `/app/awards` (`grade-cr27-4-plain.png`). | PASS |

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR27Test.php` | 5 passed (after T1, F2 tightening) |
| Due date change `PATCH /app/board/422 {due_at}` | 422 as Kussairi (owner), 403 as Shazwan |
| Audit row edit and delete via tinker | `RuntimeException` on both |
| Dashboard, Shazwan, Tue 2026-09-15 09:00 | no band, widget order summary, clock, tasks, leave, style, calendar, notices, flowers, claims, work (`grade-cr27-dashboard.png`); clock reset to real |
| Keep it plain | item 4 |
| Outbound SDK / HTTP in S27 diff | none |
| OPEN entries name Alternatives + Reversal cost | yes (4 S27 entries + the session's QA-addressed one) |
| Protected files untouched by session (`git diff 38d61817~1..da1720ba`) | yes; QA edited its own test only |

Dev data: the grading `award_results` row (id 49) was removed; `mystery_awards` rows 3 (Sep, Shazwan) and 4 (Oct, Yati) remain.

## Screenshots
grade-cr27-1-slide-shazwan, -1-slide-yati, -1-awards-screen, -2-sealed, -4-plain, -dashboard (all `.png`).
