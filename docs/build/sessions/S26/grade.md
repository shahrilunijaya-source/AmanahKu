# S26 grade: CR-26 Side Quests

Verdict: **PASS** (one QA test defect fixed, sidebar label restored). Graded 2026-09-09 in the browser at
`http://worktree-change-request-tracker.amanahku.localhost` (Playwright), dev DB, dev clock.

## Findings

- **QA test defect (mine, not the session's).** `CR26Test` item 4 asserted the whole `/app/wins` body never contains "Side Quest", but the sidebar names every Playground screen on every page. The session dodged it by renaming the nav entry to "Quests" (OPEN `S26 / CR-26 / sidebar nav label shortened...`). Fixed at the source: the assertion is now scoped to `<main>` and the sidebar label is "Side Quests" again (`app/Support/Amanahku.php`). Session's OPEN entry superseded, see `QA / CR-26 / S26 grade PASS`.
- **Minor, left as is.** The HR publish form has only a title input; the `blurb` column exists but has no UI. Spec does not require it.
- **Dev clock note.** The first grading run's rows carry real timestamps (18:04–18:06) despite the clock being set, so the first expiry check was inconclusive. Re-ran as Kussairi with the clock at 2026-09-20 10:00: post and badge stored at 10:00 exactly, so the clock is honoured; the first run's clock set evidently did not stick. Expiry re-verified on that badge.

## Acceptance items (tests/Acceptance/CR26Test.php + tests/Feature/SideQuestTest.php, 10/10 green, 248 assertions)

| # | Item | How verified | Result |
|---|------|--------------|--------|
| 0 | Screen exists, empty state | HR: sidebar "Side Quests" under The Playground, `/app/side-quests` renders kicker + empty state (`grade-cr26-0-empty-hr.png`). | PASS |
| 1 | HR publishes, max 3 live | HR published 3 quests via form; 4th refused with flash "At most 3 quests can be live at a time — retire one first." Staff sees 3 live cards, no publish form, no suggestions block (`-1-hr-published.png`, `-1-staff.png`). Publish as staff 403. | PASS |
| 2 | Complete with note + photo | Yati: "I did this" toggles the form in place, note + PNG upload; feed shows newest post first with photo link, card shows "✓ Done · badge until 9 Oct" (`-2-form.png`, `-2-feed.png`). | PASS |
| 3 | Badge on profile for 30 days | Badge on Yati's own profile and on `?emp=4` as Shazwan, not on Shazwan's own (`-3-badge.png`). Kussairi completed quest 2 at 2026-09-20 10:00: DB `expires_at 2026-10-20 10:00:00`; profile at 2026-10-19 10:00 shows "until 20 Oct", at 2026-10-20 10:01 badge gone (`-3-badge-expired.png`). | PASS |
| 4 | Reactions, suggestions | Shazwan reacts Respect: tally `respect:1`; unknown reaction 422. Shazwan suggests a quest: visible to HR only (`-4-react.png`, `-4-hr-suggestion.png`). Approve while 3 live refused; retire quest 3 then approve: live quests 1, 2, 4. | PASS |
| 5 | Keep it plain | Shazwan `plain:true`: kicker "OPTIONAL CHALLENGES", no emoji art, no cheeky copy (`-5-plain.png`). | PASS |
| 6 | Audit | `audit_logs`: side_quest.published ×3 (HR), completed (Yati, Kussairi), suggested (Shazwan), retired + approved (HR), actor on every row. | PASS |

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR26Test.php` | 5 passed (after QA scope fix) |
| Due date change `PATCH /app/board/422 {due_at}` | 422 as Kussairi (owner), 403 as Shazwan |
| Audit row edit and delete via tinker | `RuntimeException` on both |
| Dashboard, Shazwan, Tue 2026-09-15 09:00 | widget order summary, clock, tasks, leave, style, calendar, notices, flowers, claims, work; no new widget (`grade-cr26-dashboard.png`); clock reset to real |
| Keep it plain | item 5 |
| Outbound SDK / HTTP in S26 diff | none |
| OPEN entries name Alternatives + Reversal cost | yes |
| Protected files untouched by session (`git diff 79409681..f1f0ef1d --stat`) | yes; QA edited its own test only |

## Screenshots
grade-cr26-0-empty-hr, -1-hr-published, -1-staff, -2-form, -2-feed, -3-badge, -3-badge-expired, -4-react, -4-hr-suggestion, -5-plain, -dashboard (all `.png`).
