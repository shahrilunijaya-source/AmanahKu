# QA grade: S22 / CR-24 Big Deal Alert

**Verdict: PASS** (after F1 and F2 fixed by QA in this grade commit).

Graded in the browser on `worktree-change-request-tracker.amanahku.localhost` as Kussairi (PM) and Shazwan (employee), plus `php artisan test`.

## Findings

- **F1 (fixed)** Single-paragraph story left the "What it took" box holding only the meta line; the story rendered as the one-liner under the title instead. `DashboardBands::bigDealMoments()` and `wins.blade.php` each split the story with "first line = one-liner" and never fell back. Fix: `BigDeal::storyParts()` (one place, both views use it) returns the whole story as box lines when there is only one line; raise-form textarea placeholder now explains the first-line convention. Test `tests/Feature/BigDealStoryTest.php`.
- **F2 (fixed, Shazwan mid-grade)** Red left border on the "What it took" box on dashboard and Wins. Dropped from `.uj-bd-story` in `app.css`, plain-mode override adjusted.

## Acceptance items (spec: PM marks 'iLPF UAT completed' as Big Deal with 3 photos, banner on all dashboards with team avatars and story; reactions work; visible in Wins page after 3 days)

| # | Check | Result |
|---|-------|--------|
| 1 | Kussairi on Projects row "Mark as Big Deal": type UAT completed, headline, story, Track ref, team, 3 photos, submit | PASS. `big_deals` row 1, 3 `big_deal_photos` on disk, member 26, audit `big_deal.raised` target `big_deal:1` (`grade-cr24-1-form.png`) |
| 2 | Banner on Shazwan's dashboard | PASS. Moment `data-kind="big-deal" data-big-deal="1"`, BIG DEAL ALERT kicker, title, avatar, story, meta line, 3 photo URLs 200, above Current month summary (`grade-cr24-2-banner.png`, `-2-dash.png`) |
| 3 | Reactions | PASS. Respect → tally 1; Power replaces it; Power again removes; fetch-and-swap, no reload (`grade-cr24-3-react.png`) |
| 4 | 3-day window | PASS. Clock 2026-09-12 09:59 banner shown; 14:01 gone; `/app/wins` lists `data-win="1"` with avatar, story, photos, reactions; Legend react works there (`grade-cr24-4-wins.png`) |
| 5 | Rules (types, compliment source, names gating) | PASS via `CR24Test` item 5 (7/7 green); form shows source/contact/approved fields only for Client compliment |
| 6 | Keep it plain | PASS. `data-plain` set: no `uj-db-art`, no confetti, no canvas/audio, title and story still shown, animation none (`grade-cr24-6-plain.png`) |

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR24Test.php` | 7/7, 176 assertions |
| Due date change via API (`PATCH /app/board/254 {due_at}`) | 403 |
| Audit row edit (tinker `update`) | `RuntimeException: audit_logs rows are append-only` |
| Dashboard baseline, Shazwan, 2026-09-15 09:00 | Current month summary, Daily clock log, Pending tasks, My leave summary, My working style, My calendar, Notice board, Flowers, My claim summary, My work summary; no big-deal band (`grade-cr24-dashboard.png`; the one band present is the pre-existing CR-31 holiday-eve moment for Malaysia Day) |
| Keep it plain | see item 6 |
| Outbound SDK/HTTP in diff | none |
| OPEN entries (3) | each has Alternatives + Reversal cost |
| Protected files | untouched |
| Related suites (Dashboard/Project/Awards/Reaction/Wins) | 169/169 |

Clock reset to real after grading. Test big deal (id 1, KDN: iLPF) left on the dev DB.
