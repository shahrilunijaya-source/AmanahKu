# QA grade: S02 date-calendar-rules (lock engine)

Verdict: **PASS**.

Graded in the browser at `http://worktree-change-request-tracker.amanahku.localhost`
(Playwright, employee Shazwan, HR for nothing this time) plus tinker on the dev database.
Migration `2026_09_08_100000_add_date_rule_columns_to_work_items` run on dev during grade.

## Acceptance items

| # | Item | Result |
|---|------|--------|
| 1 | Event moved in Amanahku updates linked calendar event | incomplete by design (S13 + S07 stub), not graded |
| 2 | Calendar change updates the Amanahku Event | incomplete by design (S13), not graded |
| 3 | Repeated reschedules keep one Event and full history | incomplete by design (S13), not graded |
| 4 | Task due date change blocked, UI and API | **PASS** |
| 5 | Calendar move of a Task snaps back | incomplete by design (S13), not graded |
| 6 | Calendar cancellation marks Event Cancelled | incomplete by design (S13), not graded |
| 7 | Event reschedule has no effect on overdue | **PASS** (awards half owed by S17, nothing to exclude from yet) |
| 8 | TOT Tindakan Sasaran locked after first save | incomplete by design (S12), not graded |

Item 4 detail. "+ Add a card" on the board opens a date-first composer (Add disabled until a
date is picked); card 311 created with 01 Oct 2026; the drawer's date picker is disabled with
the hint "Locked. If the work has moved, cancel this card with a reason and create a new one"
(`grade-drawer.png`). `PATCH /app/board/311` with 2026-10-15 is 422 with the lock message;
with null 422; with the same date plus a title change 200. Subtask 312 created only when a
date is sent (422 without), and its date is 422 on change. `POST /app/board` without
`due_at` is 422. Tinker `$task->update(['due_at' => ...])` throws
`Work item due dates are locked after the first save`. "Cancel card (with reason)" in the
drawer menu prompts for a reason, the card leaves the board, the archived list shows it as
`cancelled: true` with no Reopen, `POST /app/board/311/restore` is 422. Audit rows 954 to 957
carry field `archived_at` and `cancelled_at` for parent and subtask with reason
"Client moved the milestone", source `ui`.

Item 7 detail. Rows 313 (`type = event`, due 01 Sep) and 314 (task, due 01 Sep) on the board:
only 314 carries `wc-when--over`. Tinker moved the Event's date to 30 Sep (allowed, one
`due_at` audit row) while the task refused. Probe rows deleted afterwards.

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/DateCalendarRulesTest.php` | PASS (9 pass, 6 incomplete by design) |
| Change a Task due date via API, must be rejected | PASS (422) |
| Edit an audit-log row, must be rejected | PASS (row 955 refuses update and delete) |
| Dashboard as plain staff on a quiet day matches baseline | PASS (only diff is header badge counts, rows 7 to 48, same as S01) |
| Toggle Keep it plain | PASS (3 bands stay as text, confetti gone, "Good morning, Shazwan.") |
| Grep diff for Google / Track / mail SDK or outbound HTTP | PASS, no hits |
| New OPEN.md entries name alternatives and reversal cost | PASS (QA shapes entry, S02 cancel granularity entry) |
| No edits to `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*` | PASS with a note: pint `--dirty` re-quoted one string literal in the untracked `DateCalendarRulesTest.php` (no assertion changed, recorded in the S02 handoff). Not counted as an edit. |
| Full suite | PASS (2667 passed, 5 skipped, 8 incomplete) |

## Failures for the generator

None.

## Notes, not failures

- A card's first due date set at creation is recorded on the `created` audit row, not as a
  separate `due_at` row (S01 trait design). A later first set through PATCH does write a
  `due_at` row. Fine for the spec.
- Dev database after grade: cards 311 and 312 remain cancelled on Shazwan's board (archived
  list), audit rows 951 to 957 exist. Dev clock reset, plain pref false.
