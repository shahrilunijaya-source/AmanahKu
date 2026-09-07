# QA grade: S04 CR-32 (dashboard placement rule)

Verdict: **PASS**.

Graded in the browser at `http://worktree-change-request-tracker.amanahku.localhost`
(Playwright; the integrated browser cannot reach the vhost) as Shazwan (employee, stands in
for Emysha), Shahril (director), Hidayah (HR) and Kussairi (manager), with the dev clock
(`POST /dev/clock`) set per item, plus tinker on the dev database. Widget order was read both
from the server HTML (`data-widget` inside each `data-col`) and from the live DOM; the two
differ only where the pre-existing client-side column balancer in `dashboard-widgets.js`
moves the last card to the shorter column, which it also does in the baseline.

## Acceptance items

| # | Item | Result |
|---|------|--------|
| 1 | Quiet Tuesday as staff, dashboard exactly as today | **PASS** |
| 2 | Same day as director, only the management band at the top | **PASS** |
| 3 | 1 Oct awards band, existing cards unchanged below | **PASS** |
| 4 | Friday 15:00 sign-off after Pending tasks, gone Monday 09:00 | **PASS** |
| 5 | Layout matches Appendix B (human check) | **PASS**, with the contract's listed differences |

Item 1 detail. 2026-09-08 10:00, Shazwan's prefs reset to default: no `data-band`, no
`data-kind`, no `.uj-db` wrapper; left summary, clock, tasks, leave; right calendar, notices,
flowers, claims, work, style. Same eleven cards as `docs/build/baseline/dashboard.png`, same
order; only the rotating greeting differs. `grade-cr32-quiet.png`. Picker catalog has no
`friday` outside the window.

Item 2 detail. Shahril on the same day: exactly one band, `data-band="management"`, kicker
MANAGEMENT, title "Lateness today and overdue by Primary Owner", above `.uj-dw-grid`, no
moment, no `uj-db-art`; grid columns unchanged (left has the pre-existing `stuck` and
`pulse`). Hidayah (HR) sees the same band. Kussairi (manager) and Shazwan see none.
`grade-cr32-management.png`. Keep it plain as Shahril: band stays as text, wrapper
`<div class="uj-db" data-plain="">`, tint drops from rgb(236,233,225) to white, greeting
"Good morning, Shahrilnizam.", no animated element inside the band area.
`grade-cr32-management-plain.png`. 2026-10-02 as Shahril: two bands, management before awards.

Item 3 detail. Shazwan: 2026-10-01 (Thursday) one band `data-band="awards"`, "October's
awards", above the grid, left and right columns unchanged (`grade-cr32-awards.png`). 10-07
17:00 present; 10-08 09:00 absent. 11-01 (Sunday) absent; 11-02 present, "November's awards".

Item 4 detail. Shazwan: 09-11 14:59 no `friday`; 15:00 left is summary, clock, tasks,
**friday** ("Friday sign-off"), leave, right unchanged, no band (`grade-cr32-friday.png`);
09-13 present; 09-14 08:59 present; 09-14 09:00 absent. Picker lists `friday` after `tasks`
inside the window only.

Item 5 detail. Bands in Appendix B order (moments, management, awards, seen together on
2026-10-02 in `s04-management.png` from the session), Friday sign-off after Pending tasks,
Flowers after Notice board. `work` and `style` sit right and `stuck` / `pulse` exist, as
`contracts/dashboard-slots.md` says. Events and Plot Twist are not built yet (CR-11, CR-25).

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR32Test.php` | PASS (6 pass, 1 incomplete by design); all of `tests/Acceptance` green; full suite 2679 passed, 5 skipped, 9 incomplete |
| Change a Task due date via API, must be rejected | PASS (`PATCH /app/board/310 {due_at}` as Kussairi: 422 "Due dates are locked after the first save", due_at unchanged) |
| Edit an audit-log row, must be rejected | PASS through the model (`AuditLog` update and delete both throw "audit_logs rows are append-only"); see note below |
| Dashboard as plain staff on a quiet day matches baseline | PASS (item 1) |
| Toggle Keep it plain | PASS (staff: "Good morning, Shazwan.", no bands, no confetti; director: band text only, no tint) |
| Grep diff for Google / Track / mail SDK or outbound HTTP | PASS, no hits in `96053c3f..HEAD` |
| New OPEN.md entries name alternatives and reversal cost | PASS ("QA / CR-32 / shapes", "S04 / CR-32 / slot placeholders") |
| No edits to `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*` | PASS (only the QA-written `CR32Test.php` was added, before S04) |

## Failures for the generator

None.

## Notes, not failures

- The append-only guard on `audit_logs` lives in the `AuditLog` model. A raw
  `DB::table('audit_logs')->update()` still affects a row (probed inside a rolled-back
  transaction, nothing changed). Same as S01 to S03; no database trigger exists. Worth a
  trigger or a DB user without UPDATE/DELETE on that table if Shazwan wants the hard stop at
  the database. Not in CR-32.
- `PATCH /app/board/{id}` silently ignores an unknown key (`due_date`), returning 200;
  the real field is `due_at`.
- `POST /app/dashboard/prefs` with only `plain` wipes the caller's saved drag order (the UI
  always sends all three keys). Shahril's dev order was reset by the session and is back to
  registry default; Shazwan's was reset to default by this grade.
- Dev database after grade: clock reset, plain pref false for everyone touched, no rows
  created.
