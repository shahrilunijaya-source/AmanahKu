# QA grade: S05 CR-30 (custom Unijaya reactions)

Graded in the browser on the worktree vhost (`worktree-change-request-tracker.amanahku.localhost`), quick-login as Shazwan (staff), Kussairi (manager) and Hidayah (HR). Evidence: `grade-cr30-*.png` in this folder.

Before grading, QA corrected one self-contradicting assertion in `tests/Acceptance/CR30Test.php` (line 103: after the same person presses the same reaction twice, the tally must be 0, not 1). Commit 8bffc7bb, OPEN entry "QA / CR-30 / CR30Test line 103 corrected after S05". The session did not touch the file.

## First pass

| # | Item | Result |
|---|------|--------|
| 1 | Reaction picker shows the eight custom reactions everywhere | **PASS** — TOT drawer, Knowledge Bank drawer and the dashboard birthday wish row all offer the same eight keys with icon and label; the catalog JSON lists them in order; a press counts once, a second press undoes, a different key replaces. |
| 2 | Send Help notifies nobody | **PASS** — Shazwan pressed Send Help on a TOT session and a lesson; `app_notifications` gained no row. |
| 3 | Request Help notifies and tags | **FAIL (F1)** — server side works (helper tagged, one notification "Kussairi asked for your help" with title, message and a card link in Shazwan's bell, second ask re-notifies without a second tag, empty and 201-char messages 422). The drawer control does not: the "Who?" select lists the card's owner (always refused with "That person already owns this card.") and hides anyone already on the card as FYI, who cannot then be asked from the UI although the API promotes them to Helper. |
| 4 | HR adds a ninth | **PASS** — Hidayah added `big_brain` from Company Settings; catalog 9, the TOT and wish pickers show it; duplicate key 422; staff POST 403; PATCH, PUT, POST and DELETE on `/app/admin/reactions/power` are 404; label unchanged. |
| 5 | Retired reaction still shows on old items | **PASS** — Hidayah left Claim Bila? on session 1 then retired it; catalog and every picker drop it; the session's tally and the list line still read "Claim Bila? 1" with the retired style; a new use is 422; staff retire 403. |

### Every-session checks (first pass)

- `php artisan test --compact tests/Acceptance/CR30Test.php`: 6 passed, 171 assertions. PASS.
- Due date change on card 310 via `PATCH /app/board/310 {due_at}`: 422 "Due dates are locked after the first save". PASS.
- Audit-log row edit and delete through the model: both throw "audit_logs rows are append-only". PASS.
- Dashboard as staff on a quiet Wednesday (2026-09-09): left `summary, clock, tasks, leave, style`, right `calendar, notices, flowers, claims, work`, no band, no new card; matches the baseline and the S04 grade. PASS.
- Keep it plain: with `data-plain` on the dashboard wrapper the picker's entrance animation is `none`; picker text is label only, no cheeky copy added. PASS.
- Diff grep for Google, Track, mail providers, Http or Guzzle: no hits (only `AppNotification::send`, in-app). PASS.
- New OPEN entries name alternatives and a reversal cost. PASS.
- `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*` untouched by the session; `tests/Acceptance/CR30Test.php` changed only in the QA commit. PASS.

## Failures to act on

- **F1** `resources/js/work-board.js`, the "Request help" select in `resources/views/partials/work-drawer.blade.php` is fed by `availablePeople` (roster minus current participants). Replace with a roster minus the card's owner only: an FYI person must be selectable (the server promotes them to Helper), the owner must not be offered. Verify: open card 315 as Kussairi (Shazwan is FYI on it) and Shazwan appears in the select while Kussairi does not; open card 310 as Kussairi and Shazwan (owner) is absent.

## Verdict (first pass): FAIL

## Second pass (after F1)

F1 fixed in `resources/views/partials/work-drawer.blade.php`: the Request help select now reads `reviewerOptions` (roster minus the card's owner), the same list the Reviewer select uses. Verified as Kussairi: card 310 (Shazwan owns) no longer offers Shazwan; card 316 (Shazwan FYI) offers Shazwan and not Kussairi; asking Shazwan from the drawer promoted him to Helper with no error (`grade-cr30-request-help-fix.png`). Item 3: **PASS**.

Full suite after the fix: 2685 passed, 0 failed, 5 skipped, 9 incomplete.

## Verdict: PASS
