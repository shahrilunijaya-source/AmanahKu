# QA grade: S11 / CR-09 (TOT sessions: chair, attendance, slots, tindakan)

Verdict: **PASS** after four fixes (F1 to F4 below), applied and re-verified in the browser by QA. Graded on 2026-09-08 against commits `8981fb80` + `6b46e5b2` (S11) with QA fixes committed on top.

Driven as a user at `http://worktree-change-request-tracker.amanahku.localhost/app/tot?year=2026` (Playwright), quick-login as Hidayah (hr), Kussairi (manager, chair), Shazwan (employee). The dev database is intact (35 projects, 203 cards, batch 15, 21 legacy TOT sessions); S11 ran no wiping command.

## Acceptance items

| # | Item | Result | Evidence |
|---|---|---|---|
| 1 | Recreate 1 Ogos 2026: 1 session, 4 slots, 19 + 1 attendance, 4 tindakan, one page | PASS | As Hidayah on the August drawer (session 6): chair set to Kussairi, legacy backfilled slot edited (PostgREST, team Rubmin + Syafiq sokongan, demo, ujian), three slots added (Antigravity pembentangan, Graphify vs Repomix demonstrasi, Sambungan: Laravel AI chatbox sambungan), attendance ticked 19 present + Shazwan absent "Cuti sakit", four tindakan added (Rubmin/no date, Ain Akilah 15 Aug, Shazwan, Syafiq). Drawer shows "19 hadir / 1 tidak hadir", the absentee reason, all four slots in order, all four tindakan. `grade-cr09-1-august-top.png`, `grade-cr09-1-august-bottom.png`. Audit rows 980 to 989 (Updated TOT slot, Added TOT slot x3, Recorded TOT attendance, Added TOT tindakan x4). |
| 2 | Each slot has its own discussion thread and Nota link section | PASS after F1 | Comment posted on the Antigravity thread and on the PostgREST thread; each thread shows only its own, the session-level Discussion at the bottom stays empty (`tot_comments` rows 1 and 2 carry slot_id 7 and 5). `grade-cr09-2-slot-threads.png`. Nota Perbincangan section is on the drawer, but the link could not be set from the screen until F1. |
| 3 | 'Create T.A.A. task' on Tindakan 1 creates a card for Rubmin with target 'Bulan hadapan' | PASS after F4 | As Kussairi (chair, manager): click creates `work_items` 244 for employee 13 (Rubmin), title = the action, task/todo, `due_at` 2026-09-05 (next TOT Saturday), `due_label` "Bulan hadapan", `tot_actions.work_item_id` = 244, audit rows 991 (work_item.created) and 992 (Created T.A.A. task from TOT tindakan). Button flips to "Task created". Due date PATCH on the card as Kussairi and as Rubmin: 422 "Due dates are locked after the first save". `grade-cr09-3-taa-created.png`. Rubmin's own board could not be opened in the browser (the prod-copy account has no NRIC, so the profile gate sends him to `/app/welcome`; `grade-cr09-3-rubmin-board.png` shows that page); CR09Test item 3 covers the board render. The row showed the raw ISO date after creation, fixed as F4. |
| 4 | September session shows the agenda carried from August | PASS | August next-month agenda saved as "Amy: Antigravity untuk projek RMS / Rubmin/Syafiq: sambungan PostgREST"; the September drawer shows "Agenda dari bulan lepas" with both lines, nothing copied into September's row. `grade-cr09-4-september-agenda.png`. |

Scope 6 (legacy migration): the five S11 migrations are applied on dev (batch 15); the 6 titled legacy sessions each got one Pembentangan slot (`tot_slots` = 6 before this grade), and January to July 2026 open with their old title as slot 1.

## Findings and fixes

- **F1 (fixed): the Nota Perbincangan link had no input on the screen.** `nota_url` was carried only as hidden fields in the chair and agenda forms; the drawer always said "No link yet." Fix: a visible URL input in the "Edit chair & attendance" form (`resources/views/partials/tot-attendance-summary.blade.php`). Re-verified: link saved and shown, "not a link" answers the toast "must be a valid URL."
- **F2 (fixed): no "linked slot" on the Tindakan form.** The spec's Tindakan carries a linked slot and the route validates `slot_id`, but the add form never offered it. Fix: a slot picker on the add-tindakan form and the slot title on the row (`resources/views/partials/tot-actions-table.blade.php`). Re-verified: "Semak had kadar API PostgREST" linked to the PostgREST slot shows the slot tag.
- **F3 (fixed): no per-slot reaction control.** The spec's slot has hearts per slot; S11 built `tot.slots.react` and its tests but no button reached it. Fix: the tenant's reaction picker inline under each slot with a live tally (`tot-slots.blade.php`, `react()` in `resources/js/tot-slot-thread.js`, `slots.reactions` eager load in `TotController::screenData`, one CSS line so the inline bar has no entrance animation). Re-verified: Legend on the Antigravity slot counts 1 there and 0 on PostgREST, pressing again undoes it.
- **F4 (fixed): a Tindakan with a card showed the raw ISO date** ("Rubmin · 2026-09-05") while the others show "15 Aug 2026". Fix: the card route also answers `due_text` and the row renders it (`TotController::createActionCard`, `tot-action.js`, the actions partial). Re-verified: "Rubmin · 5 Sep 2026".
- Tests added for all four in `tests/Feature/TotSessionSlotsTest.php` (render assertions for the nota input, slot picker and reaction bar; `due_text` in the card JSON and the seeded row).

Not failed, noted for Shazwan:
- The screen header still says "One person, one topic." and the drawer heading still shows the legacy session title ("PostgREST ... Graphify vs Repomix") above the slot list; both are pre-CR-09 copy. Cosmetic.
- The toast for a chair or agenda save reads "TOT slot updated." (the old session wording).
- S11's OPEN entry on the reaction unique key: the same reaction on a session and one of its slots by the same person collides and the second is dropped. Narrow, logged, left for a later session as S11 decided.
- Add-slot takes only title and kind; format, status, presenters and summary come through "Edit slot" on the new row. Two steps, works.

## Every-session checks

| Check | Result |
|---|---|
| `php artisan test --compact tests/Acceptance/CR09Test.php` | 5 passed, 180 assertions |
| Task due date change via API | 422 on `PATCH /app/board/244` as Kussairi and as the owner Rubmin |
| Audit-log row edit | `update()` and `delete()` on the latest row both throw RuntimeException (tinker), row unchanged |
| Dashboard as Shazwan, quiet 2026-09-09 (dev clock) | Same cards and order as the S10 baseline: summary, clock log, pending tasks / calendar. `grade-cr09-dashboard.png` |
| Keep it plain | On, TOT drawer with the August session: no cheeky text, no CR-09 animation (the only animated nodes are the pre-existing hidden reaction flyout). `grade-cr09-plain.png`. Turned back off afterwards. |
| Outbound calls in the diff | none (grep for Http::, curl, guzzle, googleapis, Mail::, Track) |
| OPEN entries | two S11 entries, both carry Alternatives and Reversal cost |
| Protected files | `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*` untouched by S11 |
| Full suite before fixes | 2781 tests, 2776 passed, 5 skipped, 12 incomplete, 0 failed |
| Full suite after fixes | 2782 tests, 2777 passed, 5 skipped, 12 incomplete, 0 failed |

## Screenshots

`docs/build/sessions/S11/grade-cr09-*.png`: 1-august-top, 1-august-bottom, 2-slot-threads, 3-taa-created, 3-rubmin-board, 4-september-agenda, dashboard, plain, fixes-f1-f3, fixes-f2-f4.
