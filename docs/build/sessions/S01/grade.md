# QA grade: S01 global-clause (audit log)

Verdict: **PASS** after fix F1 (re-graded 2026-09-07). First pass was FAIL on F1.

Graded in the browser at `http://worktree-change-request-tracker.amanahku.localhost`
(Playwright), dev clock 2026-09-15 10:00 for the band checks, real clock otherwise.

## Acceptance items

| # | Item | Result |
|---|------|--------|
| 1 | Change a due date, audit shows old date, new date, actor, time, reason; entry cannot be edited | PASS (after F1 fix) |
| 2 | Timesheet approved 1 Oct for September counts in October freeze | incomplete by design (S17), not graded |
| 3 | Director award override shows note + audit entry | incomplete by design (S18), not graded |

Item 1 detail. Set due_at on card 310 through `PATCH /app/board/310` with a reason: DB row 942
holds subject, field `due_at`, old `null`, new date, user_id, created_at, reason, source `ui`.
Correct. Update and delete on that row through tinker both throw
`audit_logs rows are append-only`. Correct. The Audit Logs screen (`/app/audit`) shows
`work_item.due_at — QA S01 grade card`, actor and time only. Old value, new value and
reason are not on screen; they are only in the CSV. Spec says audit **shows** them, so
partial is FAIL.

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/GlobalClauseTest.php` | PASS (4 pass, 2 incomplete by design) |
| Change a Task due date via API, must be rejected | not S01 scope: second due_at change returned 200. Owed by S02, `assertDueDateLocked` is not called until S02. Not counted against S01. |
| Edit an audit-log row, must be rejected | PASS (model throws; PATCH/PUT/DELETE `/app/audit/{id}` are 404) |
| Dashboard as plain staff on a quiet day matches `docs/build/baseline/dashboard.png` | PASS (only diff is header badge counts, rows 7 to 48) |
| Toggle Keep it plain: no animation, no cheeky text | PASS (confetti gone, `Good morning, Shazwan.`, bands stay as text) |
| Grep diff for Google / Track / mail SDK or outbound HTTP | PASS, no hits |
| New OPEN.md entries name alternatives and reversal cost | PASS (one entry, QA global-clause reinterpretation) |
| No edits to `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*` | PASS |
| Migration run on dev DB | done during grade (`lerd artisan migrate`) |
| Export CSV gated | PASS (employee 403, HR sees button and gets valid CSV with old/new/reason columns) |

## Failures for the generator

F1 (fixed, re-graded PASS: `audit-regrade.png` in this folder shows `due_at: — → 2026-10-01 00:00:00` and `Reason: Client agreed the date` on screen as HR). Audit Logs screen does not show old value, new value or reason.
- `resources/views/screens/audit.blade.php:36` renders only `action` and `target`; the row
  has no output for `old_value`, `new_value`, `reason`, `field`.
- `app/Http/Controllers/AppController.php` `auditLogsData()` should already select those
  columns (check it does not `select()` a narrow list).
- Fix: in the row, when `field` is set, render a second line like
  `due_at: null → 2026-10-01` (values via `json_decode`, dates through the KL timezone
  formatter used by the CSV export) and, when `reason` is set, `Reason: …`. Keep the
  current one-line layout for legacy rows with no `field`.
- Re-grade: set a due date with a reason on any card, open `/app/audit`, both values and
  the reason must be visible without opening the CSV.

## Notes, not failures

- Due-date lock is not built yet. S02 must make the second `PATCH` return 422 and the model
  update throw, then `assertDueDateLocked` starts running.
- Dev database state after grade: card 310 exists with audit rows 942 to 950; plain pref
  reset to false; dev clock reset.
