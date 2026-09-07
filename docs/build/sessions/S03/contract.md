# Session S03 contract: CR-04 (roles on a card)

Tests to pass: `tests/Acceptance/CR04Test.php` (frozen, written by `/qa write`). Shapes are in the OPEN entry "QA / CR-04 / shapes fixed by CR04Test".

## Files to touch

- `database/migrations/2026_09_09_100000_add_card_roles.php` (new)
- `app/Models/WorkItem.php` (pivot role, `reviewer()`, `roleFor()`, `reviewer_id` audited)
- `app/Support/BoardRules.php` (reviewer may open the card; `assertReviewerMovesToDone()`)
- `app/Http/Controllers/WorkItemController.php` (`tagged` + `reviewer_id` on PATCH, role-aware `syncParticipants`, reviewer gate on `move`, payload gains `reviewer`, `viewer_role`, `can_set_reviewer`; subtask `helper_ids` writes role helper)
- `app/Mcp/Tools/MoveCardTool.php` (same reviewer gate on preview and confirm)
- `app/Http/Controllers/Concerns/BuildsWorkData.php` (personal board query adds `reviewer_id = me`; Assigned-only column counts; team board loads helper/FYI/reviewer rows and per-person `helping` / `reviewing`)
- `resources/views/partials/work-card.blade.php` (`data-role`, role label)
- `resources/views/screens/board.blade.php` (role chips Assigned / Tagged / Reviewing / All, Assigned-only badges)
- `resources/views/screens/team-board.blade.php` (person row `data-helping` / `data-reviewing` and "helping on N / reviewing N", window rows for tagged and reviewed cards)
- `resources/views/partials/work-drawer.blade.php` (Helper / FYI choice when tagging, role on the chip, Reviewer row)
- `resources/js/work-board.js` (role filter, tagging with role, reviewer picker)
- `resources/js/team-board.js` (window summary adds helping / reviewing)
- `resources/css/app.css` (`.wc-role`, chip row)
- `public/build/*` (rebuilt)
- `docs/build/OPEN.md` (append only), `docs/build/sessions/S03/handoff.md`
- Feature tests that break because the pivot now carries a role or the reviewer gate exists.

Never: `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*`.

## Schema changes

Migration `2026_09_09_100000_add_card_roles.php`:
- `work_item_participant.role` string(10), default `helper`, not null. Existing rows therefore read back as `helper` (contract: safe, credit-bearing default).
- `work_items.reviewer_id` nullable FK to `employees`, null on delete.

Run on the dev DB with `lerd artisan migrate` after the suite is green.

## Acceptance items and how each is verified

1. Helper tag on Adri's card appears on Emysha's board with "Tagged – Helper", Assigned counters unchanged, "helping on 1": `test_acceptance_1_*`. PATCH `tagged` writes pivot role `helper`, audit row `participants` carries the role, `GET /app/board` renders the card with `data-role="helper"` and the label, the team board row keeps `data-open` / `data-overdue` and gains `data-helping="1"` plus the text "helping on 1".
2. FYI tag shows "Tagged – FYI", no credit, no counter: `test_acceptance_2_*`. Pivot role `fyi`, label on her board, `data-helping="0"`, an overdue FYI card adds nothing to `data-overdue`; changing FYI to Helper replaces the row; unknown role is 422.
3. Reviewer sees the card under Reviewing and only she moves In Review to Done: `test_acceptance_3_*`. Owner setting `reviewer_id` is 403, PM setting it to the owner is 422, PM setting Yati is 200 and audited (`reviewer_id`); Yati's board renders `data-role="reviewer"` and "Reviewer", her row has `data-reviewing="1"`; owner, helper and PM get 403 on `move` to done while the card sits in review; Yati gets 403 on a title edit and 200 on the move; a card without a reviewer moves as today.
4. Assigned filter hides tagged and reviewing items: `test_acceptance_4_*` checks every card carries the right `data-role`, the four chips exist with `data-role-filter`, and the To Do / In Review badges count Assigned cards only. The hiding itself is Alpine (`roleFilter`, default Assigned) and is a browser check for `/qa grade`.
5. (CR-05 migration) legacy pivot rows and subtask `helper_ids` are helpers: `test_acceptance_cr05_migration_*`.

Always checks: `test_always_checks_from_s03` (due date lock, audit immutability, dashboard unchanged, Keep it plain).
