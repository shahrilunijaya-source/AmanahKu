# QA grade: S12 / CR-10 (Tindakan Susulan to T.A.A. with the next TOT date)

Verdict: **PASS** after one fix (F1 below), applied and re-verified in the browser by QA. Graded on 2026-09-08 against commit `5f27803d` (S12) with the QA fix committed on top.

Driven as a user at `http://worktree-change-request-tracker.amanahku.localhost/app/tot?year=2026` (Playwright), quick-login as Hidayah (hr), Kussairi (manager, August chair), Shazwan (employee). The dev database is intact (36 projects, 204 cards before the grade, migrations batch 16, `tot_action_helper` present); S12 ran no wiping command.

## Acceptance items

| # | Item | Result | Evidence |
|---|---|---|---|
| 1 | Add Tindakan 1 (Rubmin, helper Nabil) on the August session; a T.A.A. card lands on Rubmin's board with Nabil tagged, due on the September TOT date | PASS | As Hidayah, "Add tindakan" with Pemilik Rubmin, Pembantu Nabil, no date, "Create the T.A.A. task straight away" ticked by default: toast "Tindakan added.", row reads "Rubmin · Nabil · 5 Sep 2026 · Open". `tot_actions` 6 owner 13, `tot_action_helper` (6, 18), `work_items` 245 employee 13, task/todo/medium, `due_at` 2026-09-05, `due_label` Bulan hadapan, `labels` ["tot"], `links` [{label "TOT August 2026", url /app/tot?year=2026&month=8}], `work_item_participant` (245, 18, helper). Audit 995 "Added TOT tindakan", 996 work_item.created. Rubmin and Nabil cannot open their boards in the browser (prod-copy accounts without NRIC hit the profile gate, `grade-cr10-1-nabil-board.png`); CR10Test item 1 covers both boards. Shazwan, tagged as helper on the item-2 tindakan, sees "Guardrail berpusat…" under his board's "Tagged" filter with "Tagged – Helper" and the "TOT Action" label (`grade-cr10-1-shazwan-board-tagged.png`). `grade-cr10-1-tindakan-row.png`. |
| 2 | Sasaran editable before save, then locked together with the Task due date | PASS after F1 | Tindakan "Guardrail berpusat untuk semua projek" (Syafiq, helper Shazwan, Sasaran 20 Aug, card unticked): Edit → 27 Aug → "Tindakan updated.", row shows "27 Aug 2026" (`grade-cr10-2-sasaran-edited.png`). "Create T.A.A. task" → card 246 due 2026-08-27, no due_label. After that the edit form's date is disabled; API `POST .../actions/7` with target_date 2026-09-10 → 422 "Sasaran is locked once the T.A.A. task exists."; owner change → 422; text change → 200 and card title follows (audit 1001 work_item.title). `PATCH /app/board/246 {due_at}` as Kussairi → 422 "Due dates are locked after the first save". The Pemilik select was still enabled in the edit form and a change through it landed on a raw 422 page (F1). |
| 3 | Rubmin marks the card done; the Tindakan row shows Done | PASS | As Kussairi: `POST /app/board/245/move {status: prog}` → row "Rubmin · Nabil · 5 Sep 2026 · In Progress"; `{status: done}` → "… · Done" (`grade-cr10-3-done.png`). Audit 1003 to 1005 (work_item.status, done_at). Rubmin himself cannot log in (profile gate); CR10Test item 3 moves the card as Rubmin. |
| 4 | September session opens with last month's actions and their status | PASS | The September drawer shows "Tindakan bulan lepas" with all August rows in order, each with owner and status: five CR-09 rows "Open", "Sediakan PoC PostgREST…" "Done", "Guardrail…" "Open" (`grade-cr10-4-september-last-month.png`). September's own Tindakan list stays "No tindakan yet." (nothing copied). |

Scope 5 (delete): as Kussairi, "Tindakan yang dibatalkan (QA)" (Shazwan, card 247) → Delete, confirm → row gone; `work_items` 247 kept with `archived_at` and `cancelled_at` set; Shazwan's board no longer lists it; audit 1008 to 1010 (archived_at, cancelled_at, "Deleted TOT tindakan").

## Findings and fixes

- **F1 (fixed): changing the Pemilik in the edit form after the card exists showed a raw Laravel 422 page** (`grade-cr10-2-owner-change-form.png`). The server refused correctly, but through `abort(422)` on a plain form post, and the form still offered the select. Fix: the Pemilik select is disabled once the card exists (with a hidden field keeping the owner first in `owners[]` and a note "Pemilik and Sasaran are locked once the T.A.A. task exists."), and both locks in `TotController::updateAction()` throw `ValidationException` so a form post lands back on the screen with the toast while JSON callers still get 422. Re-verified: select disabled, note shown (`grade-cr10-fix-f1-locked.png`); forcing the field back on and posting gives the toast (`grade-cr10-fix-f1-toast.png`); a helpers-only edit through the same form still saves and re-syncs the card's participants. Test added: `TotActionsTest::test_a_form_post_changing_the_pemilik_after_the_card_exists_redirects_back_with_an_error`.

Not failed, noted for Shazwan:
- The one-step save (row + card in one request) writes "Added TOT tindakan" and the card's own `work_item.created` audit rows but not the CR-09 "Created T.A.A. task from TOT tindakan" line; the two-step button still writes it. Every state change is audited, the wording differs.
- The link label is "TOT August 2026" (English month from Carbon); the spec's example says "TOT Ogos 2026". Cosmetic.
- The "Delete" confirm is the browser's native dialog.
- Helpers picked in "Add tindakan" are written to the card only when a card is made in the same request or later through the button; that is by design (the row's helpers are the source of truth).

## Every-session checks

| Check | Result |
|---|---|
| `php artisan test --compact tests/Acceptance/CR10Test.php` | 6 passed, 121 assertions |
| Task due date change via API | 422 on `PATCH /app/board/245` and `/246` as Kussairi |
| Audit-log row edit | `update()` and `delete()` on row 1010 both throw RuntimeException "audit_logs rows are append-only", row unchanged |
| Dashboard as Shazwan, quiet 2026-09-09 (dev clock) | Identical to the S11 baseline: summary, clock log, pending tasks / calendar. `grade-cr10-dashboard.png` |
| Keep it plain | On, August drawer with the new Tindakan rows and edit forms: no cheeky text, no CR-10 animation (the only animated nodes are the pre-existing hidden reaction flyout, as in S11). `grade-cr10-plain.png`. Turned back off afterwards. |
| Outbound calls in the diff | none (grep for Http::, curl, guzzle, googleapis, Mail::, Track) |
| OPEN entries | none added by S12 (it followed the QA CR-10 entry, which carries Alternatives and Reversal cost) |
| Protected files | `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*` untouched by S12 |
| Assets | `view:clear` → `view:cache` → `bun run build` leaves `public/build` unchanged before and after the fix |
| Full suite before fix | 2799 tests, 2794 passed, 5 skipped, 12 incomplete, 0 failed |
| Full suite after fix | 2800 tests, 2795 passed, 5 skipped, 12 incomplete, 0 failed |

## Screenshots

`docs/build/sessions/S12/grade-cr10-*.png`: 1-tindakan-row, 1-nabil-board, 1-shazwan-board-tagged, 2-sasaran-edited, 2-owner-change-form, 3-done, 4-september-last-month, dashboard, plain, fix-f1-locked, fix-f1-toast.
