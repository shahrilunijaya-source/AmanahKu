# Attendance report → Roster

Replaces the HR/Management attendance report (`/app/attendance-report`) with the
approved **Roster** direction from `public/_attendance-report-revamp.html`.

## Why

The current screen cannot answer the question HR opens it to ask.

- `AttendanceReportController::byStaff()` groups **attendance records**, so an employee
  with zero records in the period produces no row. Someone who never clocked, or who
  stopped clocking three weeks ago, is invisible. An attendance report that cannot show
  non-attendance is not doing its job.
- The headline `On-time rate` is computed only over people who clocked. On live staging
  data it reads `0%` while `coverage` reads `21%` in the sixth and last tile. The number
  that invalidates the first five is the one buried last.
- In drill-down mode the KPI tiles and the trend chart keep rendering **company-wide**
  figures above a single person's rows, which reads as that person's numbers.
  `drillTrend` is computed at `AttendanceReportController.php:95` and never rendered.
- No max-width. At 1920 the content spans roughly 1672px.

## The direction

One row per **active employee**, every period, whether they clocked or not. Each row
carries a strip of one cell per working day, so a pattern ("late every Monday") is
visible where a single punctuality percentage hides it. Absence is a row with a reason,
not a gap in a list.

Approved leave is distinguished from absence. Without that, everyone on leave reads as
a problem.

## Shared decisions

Both tasks depend on these. They are decided; do not re-open them.

- **Measure:** `max-width: 920px; margin: 0 auto;`, matching `.uj-at-wrap` on the staff
  attendance screen. Applies to the whole screen including the drill-down branch.
- **Working days:** Monday–Friday, **plus** any date in the window on which any visible
  employee has an attendance record. A Saturday punch must not vanish from the strip.
  Every employee's strip covers the same day list, so rows align vertically.
- **Day cell precedence**, highest first:
  1. record exists with a radius flag (`out_of_radius_in` / `out_of_radius_out`) → `x`
  2. record exists with `status === 'late'` → `l`
  3. record exists with a non-null `clock_in` → `o`
  4. no usable record, but an approved leave request covers the date → `v`
  5. otherwise → `-`
- **Punctuality:** `(o + x) / (o + l + x)`, rounded. Off-site counts as punctual because
  it is a location problem, not a timekeeping one. `null` when the denominator is 0.
- **`stopped`:** the employee has at least one clocked day, and the strip ends in a run
  of 5 or more `-` cells.
- **`never`:** zero clocked days and zero leave days.
- **Leave source:** `leave_requests` where `status = 'approved'` and the date range
  overlaps the window. One extra query for the whole window, not one per employee.
- **Scope and archiving are unchanged.** The roster is built from
  `Employee::active()` narrowed by `DataScope::visibleEmployeeIds()` and the `dept`
  filter, exactly as `$headcount` already is. An archived or out-of-scope employee must
  not gain a row by this change.
- **The drill-down branch is out of scope.** `$drill`, `$drillRecords`, and the
  `@if ($drill)` block keep their current markup and behaviour.

---

## Task 1 — Controller: build the roster

**Files:** `app/Http/Controllers/AttendanceReportController.php`,
`tests/Feature/AttendanceReportDataTest.php` (new).

Add to `screenData()` the keys `days`, `roster`, `totals`. Keep `period`, `periods`,
`rangeLabel`, `dept`, `departments`, `drill`, `drillRecords` unchanged.

Delete `kpis`, `trend`, `byStaff`, `drillTrend` and the private methods that exist only
to serve them (`kpis`, `trend`, `dayCounts`, `bucket`, `byStaff`). `hm`, `hasFlag` and
`hasAnyFlag` stay if the new code uses them. Nothing outside this controller and its
Blade reads those keys; `AppController.php:362` is the only caller.

`roster` is a list, sorted `never` first, then `stopped`, then on-leave employees last
among the flagged, then punctuality ascending, then late count descending. Each entry:
`id, name, initials, color, dept, strip, clocked, onTime, late, offsite, leaveDays,
pct, lastSeen, gapDays, never, stopped, onLeave`.

`totals`: `headcount, reported, onLeave, never, stopped, clockedDays, lateDays, pct`.

**Tests** (`AttendanceReportDataTest`, PHPUnit, `RefreshDatabase`, frozen clock — copy
the fixture idiom and `travelTo` from `tests/Feature/AttendanceScreenDataTest.php`):

- an employee with **zero** attendance records still appears in `roster`, flagged
  `never`
- an employee whose only absence is covered by an **approved** leave request is
  `onLeave`, is **not** `never`, and their covered days are `v` not `-`
- a **pending** leave request does not produce `v`
- an employee who clocked then stopped for 5+ working days is `stopped`, with the right
  `gapDays` and `lastSeen`
- every `strip` has exactly `count($days)` characters
- an **archived** employee gets no row
- an employee outside a narrowed `DataScope` gets no row
- a record on a Saturday adds that Saturday to `days`

---

## Task 2 — Blade and CSS: render the roster

**Files:** `resources/views/screens/attendance-report.blade.php`,
`resources/css/app.css`, `tests/Feature/AttendanceReportScreenTest.php` (new).

Replace the KPI tiles, the punctuality trend chart, and the by-staff table with the
Roster layout from `public/_attendance-report-revamp.html`. Port its CSS into a
namespaced `uj-ar-` block in `app.css`, following the `uj-at-` block already there, and
delete the screen's inline `<style>` element.

The mock is the visual authority. Carry over its shelf lead, coverage bar, filter row,
legend, and day strip. Do **not** carry over the mock's picker, its viewport toggle, or
its `#stageframe` harness.

Two copy rules the mock earns the hard way:

- Someone who clocked and then stopped is **not** "did not clock". Count and name the
  two failures separately.
- A missing day must be a **filled** cell, not an outline. At 10px an outline-only cell
  disappears, and a row of them reads as blank space, which is the exact thing this
  screen exists to make visible.

**Tests** (`AttendanceReportScreenTest`): the zero-record employee's name renders; the
on-leave employee renders with the leave wording and not the absence wording; the strip
cell count in the HTML equals employees × working days; a plain employee still cannot
reach the screen.

---

## Verification, both tasks

`php artisan test --compact` over the **entire** suite. A scoped run has missed a
regression on this project before: `TeamBoardAccessTest` exercises attendance screens
without "attendance" in its filename.
