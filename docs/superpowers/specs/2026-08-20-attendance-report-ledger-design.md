# Attendance report → Ledger

Replaces the HR/Management attendance report (`/app/attendance-report`) with the
approved **Ledger** direction from `public/_attendance-report-ledger.html`.

Approved mockup: `public/_attendance-report-ledger.html` (open at
`http://localhost:9100/_attendance-report-ledger.html`; toggle Desktop/Phone and
EN/MS in the grey bar). Every layout, copy string and interaction below exists
in that file and was verified in the browser.

## Why

The screen was audited against the feature list HR actually asked for. Two of
nine items were done; the rest were partial or absent.

| Requirement | Before |
|---|---|
| Date range, default current month | **Partial** — three fixed rolling windows (7/30/90 days), default 7. No calendar month, no custom range. |
| Staff filter by name, department, all | **Partial** — department only. No name search. |
| Daily table: date, in, out, hours, status | **Partial** — exists only inside the single-person drill-down, never across staff. |
| Late flag against office start | **Done** — `ClockService::isLate()` vs the site's `work_start` plus `late_grace_minutes`. |
| Missing clock-out, visually obvious | **Missing** — rendered as a bare `—`, styled like any other empty cell. The `missingClockOut()` concept exists only in the reminder subsystem. |
| Export to Excel | **Missing** — no export of any kind; no spreadsheet package in `composer.json`. |
| Monthly summary row | **Partial** — day counts and a coverage percentage, scoped to a rolling window, with **no total-hours figure anywhere**. |
| Geofence violation flag | **Done** — off-site chips, map view, permission-gated coordinates. |
| Leave type breakdown | **Missing here** — the screen reads leave only as a boolean; the per-type split lives on the separate `leave-report` screen. |

The deeper problem is that the screen answers a different question than the one
it is opened for. It is a **roster health** view (who is clocking, who stopped,
who never clocked). HR opens it to **reconcile payroll** and to **chase the
punches that are missing a half**. Those need one row per person per day, real
hours, and a file they can hand to finance.

## The direction

**One row per employee per working day.** Rows are synthesized from the employee
list crossed with the working days in the period, not read off the attendance
records, so a person who never clocked still occupies fourteen rows that say
`No punch`. (This is the same failure that killed the pre-roster version in
`2026-07-29-attendance-report-roster.md`; the rule is kept.)

Above the rows sit the period totals. Beside them sits a lens that pulls the
broken records to the top. Clicking any row opens that person's month in a
drawer, with the selfie, the remark, the map and the reverse action for each of
their days.

### Screen anatomy

```
Attendance                                        ← h1 only
[Day|Week|Month]  ‹ August 2026 ›  Custom   [Dept ▾]   [Search a name]   Sort [Date|Person]
[ 476 all records | 19 missing clock-out | 7 no punch | 16 short hours | 36 late ]   [Export]
┌──────────────────────────────────────────────────────────────────────────┐
│ MONTH TO DATE   452 present  6 absent  56 late  21 on leave  34 staff  3706.3 hours │
│                 12 Annual · 6 Medical · 4 Emergency · 1 Unpaid           │
├──────────────────────────────────────────────────────────────────────────┤
│ DATE   STAFF          IN     OUT    HOURS  STATUS    FLAGS               │
│ 20 Aug Ahmad Danial   08:52  17:35  8.72   On time   —                   │
│ Thu    Engineering                                                        │
│ 20 Aug Chong Mei Ling 08:58  Missing  —    Missing out  —          [Fix]  │  ← tinted row
│ …                                                                        │
└──────────────────────────────────────────────────────────────────────────┘
```

## Decisions

Confirmed with the user during the design pass. Each is load-bearing; a builder
must not quietly reverse one.

1. **Ledger leads; the roster view is deleted, not tabbed.** Initially the
   roster survived as a second tab. On review the user asked whether it was
   still needed. It is not: because ledger rows are built from the employee list,
   a person who stopped clocking already appears as a run of `No punch` rows
   under *Sort by Person*. The strip was a nicer picture of the same data, and it
   appears nowhere on the requirements list. **Accepted loss:** seeing a
   per-person pattern at a glance rather than counting rows.

2. **Person-day rows, not person-column-day grids.** At 34 staff × ~22 working
   days a grid has no room for clock-in *and* clock-out *and* hours in a cell,
   and it collapses on a phone. Rows sort, filter, and export 1:1.

3. **CSV, not `.xlsx`.** No new composer dependency. Matches how this app already
   exports employees and payroll (`App\Support\Csv` + `fputcsv`). HR
   double-clicks the file and Excel opens it. **Accepted loss:** no bold header,
   no frozen row, no second sheet.

4. **Missing clock-out is flagged *and* fixable in place.** Tinted row, a stamped
   word where the time should be, and a `Fix` button that sets the clock-out
   without leaving the screen.

5. **The default view is NOT pre-filtered to exceptions** — this reverses the
   option text the user originally selected, and was raised with them explicitly.
   The reason is decision 6.

6. **Totals follow scope, never the lens.** Department and staff-name are
   *scope*: they change whose period this is, and the totals move with them. The
   exception chips are a *lens*: they change which rows you are looking at
   within that period, and the totals do **not** move. A block captioned "month
   to date" that reads `19 present` because someone clicked *missing clock-out*
   is not a total, it is a lie. Pre-filtering by default would have shipped that
   lie on first paint.

7. **Status vocabulary is the app's, not the request's.** The requirement said
   Present / Absent / Late / Half-day / Leave. The `attendance_records.status`
   enum only stores `on_time | late | pending`, and everything else is inferred.
   Rendered vocabulary is **On time / Late / No punch / On leave / Half day /
   Missing out**. `No punch` is used rather than `Absent` because a person with
   no record is not necessarily absent — they may simply not have clocked yet.

8. **Reverse-punch lives in the person drawer, not on table rows.** Reversing a
   punch after seeing the selfie and the typed reason is a different decision
   from reversing it blind off a table row. The only row-level write is `Fix`,
   because a missing clock-out is the stated recurring pain point and deserves
   one click.

9. **Location opens from the off-site chip.** Those chips mark exactly the
   records that carry usable coordinates, so the control sits on the thing it
   explains and costs no column.

10. **On a single day the Date column is dropped.** All 34 rows would otherwise
    repeat the same date. The table goes 8 columns → 7; the phone card loses a
    line and drops from 170px to 93px.

11. **The lens is a segmented control**, on a neutral track with a white raised
    active pill — the same shape the screen already uses for `Day/Week/Month` and
    `Sort`. As bare numbers on the canvas it read as statistics and nobody
    realised it was clickable.

12. **Totals sit above the table, fused to it.** Rounded top corners on the
    totals, rounded bottom on the table, no gap. The answer to "what am I looking
    at" arrives before 476 rows rather than after them.

## Data contract

`AttendanceReportController::screenData()` returns:

```php
[
    'gran'        => 'day'|'week'|'month'|'custom',   // resolved, never raw input
    'from'        => '2026-08-01',                    // Y-m-d
    'to'          => '2026-08-20',
    'label'       => ['en'=>'August 2026','ms'=>'Ogos 2026'],       // period control
    'rangeLabel'  => ['en'=>'1 – 20 Aug 2026','ms'=>'1 – 20 Ogos 2026'],  // sub-head
    'captionKey'  => 'month'|'week'|'day'|'weekPast'|'dayPast'|'custom',  // totals caption
    'offset'      => int,                             // 0 = current period, negative = back
    'canPrev'     => bool,  'canNext' => bool,        // stepper state
    'workingDays' => ['2026-08-03', ...],             // Mon–Fri + any date carrying a record

    'dept'        => ?string,
    'departments' => Collection<string>,
    'q'           => ?string,                         // staff-name search
    'sort'        => 'date'|'person',
    'lens'        => null|'miss'|'absent'|'short'|'late',

    'rows'        => Collection<LedgerRow>,           // scope + lens applied, sorted
    'counts'      => ['all'=>int,'miss'=>int,'absent'=>int,'short'=>int,'late'=>int],  // scope only
    'totals'      => [                                // scope only, never lens
        'present'=>int,'absent'=>int,'late'=>int,'leave'=>int,
        'staff'=>int,'hours'=>float,
        'leaveByType'=>array<string,int>,             // ['Annual leave'=>12, ...]
        'caption'=>['en'=>'Month to date','ms'=>'Bulan setakat ini'],
    ],

    'person'      => ?PersonDetail,                   // present when ?emp= is set and in scope
    'canReversePunch' => bool,
    'canSeeLocation'  => bool,
]
```

`LedgerRow`:

```php
[
    'employeeId'=>int, 'name'=>string, 'initials'=>?string,
    'color'=>string, 'dept'=>?string,
    'date'=>'2026-08-20', 'dow'=>int,                 // 0=Sun
    'in'=>?'08:52', 'out'=>?'17:35',
    'hours'=>?float,                                  // 8.72, null when no clock-out
    'status'=>'ontime'|'late'|'miss'|'absent'|'leave'|'half'|'pending',
    'flags'=>list<string>,                            // off, visit, short, early, noloc, amended
    'leaveType'=>?string,
    'recordId'=>?int,                                 // null on a synthesized no-punch row
    'points'=>list<array{lat,lng,labelEn,labelMs}>,   // stripped when !canSeeLocation
    'hasPoint'=>bool,                                 // shorthand for points !== []
]
```

The row carries `points` rather than only advertising them, because decision 9
puts the map on the off-site chip and the chip is on the row. `LedgerBuilder`
owns that shape; the controller strips it for a viewer without `canSeeLocation`
so coordinates are never rendered-then-hidden. `amended` marks an HR-typed
clock-out (see the amend endpoint below): it has no selfie and no coordinates,
so the ledger says so rather than letting it pass for a punch.

`PersonDetail` is `['id','name','initials','color','dept','days','openDay']`.
`days` is that person's ledger rows, newest first, each additionally carrying
`noteIn`, `noteOut` (the existing `clock_in_justification` /
`clock_out_justification`) and `photoIn`, `photoOut` — the model's existing
`photo_url` / `clock_out_photo_url` accessors, which route through the
auth-gated `attendance.photo` endpoint. No signed storage URL and no
`temporaryUrl()` is involved; the selfies are on the private `local` disk and
that accessor is already how every other screen reaches
them. `openDay` is a `Y-m-d` from
`?day=`, validated to be one of `days`, which arrives pre-expanded — that is how
the ledger's one-click `Fix` and a plain click on the person land on the same
screen instead of forking into two write paths.

## Rules

**Period.** `month` is the default and means the **calendar month to date**, not
a rolling 30 days. `week` means the calendar week (Mon-start) containing today.
`day` means today. `custom` takes `from`/`to`. Stepping never goes past today.

**Working days.** Mon–Fri, plus any date in the window on which any in-scope
employee has a record — preserving the existing weekend-record behaviour.

**Row synthesis.** For every in-scope active employee × every working day, emit a
row. If a record exists, read it. Otherwise the row is `leave` when an approved
`LeaveRequest` covers the date, `pending` when the date is today, else `absent`.

**Hours** are `worked_minutes / 60`, rendered to two decimals, and are `null`
without a clock-out. The totals' `hours` sums `worked_minutes` only.

**Half day** is a worked span under 5 hours. **Short hours** is the existing
`short_hours` flag from `ClockService`.

**Amending a clock-out.** Nothing in the app could *set* a clock-out before this
change: `ClockService` is the employee clocking themselves and `reversePunch`
only clears one. `POST /app/attendance-admin/records/{record}/clock-out`
(`attendance.admin.records.amend`) fills the hole, gated to the same
`['hr','director']` + super-admin as reversing. It refuses a record that already
has a clock-out — reverse that first, so there is exactly one way to overwrite a
real punch and it leaves two audit entries. Reversing also clears the `amended`
mark, because the typed time it described is gone.

**Missing out** is `clock_in !== null && clock_out === null` on a date **before
today** — a person still mid-shift today is not broken.

**`pending`** is a working day that has not resolved yet: today, with no punch.
It is neither present nor absent, and it is excluded from all three of
`present`, `absent` and the lens counts. Flagging someone red at 09:30 for a day
that has not ended is the single most common way an attendance report loses its
reader's trust.

## Permissions

Unchanged from the current screen; the constants move but the roles do not.

- Screen access: the existing management/HR gate on `/app/attendance-report`.
- `canReversePunch`: `['hr','director']` or super-admin. Deliberately excludes
  `management`.
- `canSeeLocation`: `Permissions::OVERSIGHT_ROLES + ['director']` or super-admin.
- Export inherits the screen gate and writes an `AuditLog` entry, as
  `PayrollExportController` does.
- Data scope (`DataScope::visibleEmployeeIds`) applies to rows, counts, totals,
  export and the person drawer alike. A `?emp=` outside scope resolves to null.

## Copy and i18n

Every string ships EN and MS, matching the rest of the screen. The strings are
in the mockup's `T` map and are the approved wording. Malay runs roughly 15%
longer and is the case that breaks layouts — it is a required check, not a
nicety.

## Measure

The screen opts into the app's existing wide cap (`$wideScreens` in
`layouts/app.blade.php` → `.uj-main--wide`, 1280px), not the roster's focused
920px. Eight dense columns never fitted 920, and the mock was drawn at 1280.
The staff column takes most of the free space: real names here run to
"Muhammad Faris Akmal Bin …", and a payroll screen that truncates two of them to
the same string has stopped doing its job. At the project's 1600px reference
width none of 435 names truncate.

## Built differently from the mock

Three deliberate departures, all mechanically verified in the browser:

- **The custom range is two inline date inputs**, not the mock's popover. Native
  `<input type="date">`, no picker library, and the filter bar works with
  JavaScript off. The popover's CSS is deleted rather than left unused.
- **The mobile filter sheet is the filter form itself**, presented as a sheet
  under 760px, rather than a second copy of every control. One element, one
  state, so the bar and the sheet cannot disagree about what is selected.
  Dropped with it: drag-to-dismiss. Tap the scrim, press Escape, or Show results.
- **The mock's own fake map is gone.** The row chip and the drawer both dispatch
  `open-map-view` to the existing `partials/map-view.blade.php`.

## Out of scope

- Overtime calculation.
- Heatmaps and trend analytics.
- Shift scheduling.
- Biometric integration.
- `.xlsx` export (see decision 3).
- Editing anything other than reversing a punch or setting a missing clock-out.

## What is removed

`kpis()`-era code is already gone. This change additionally deletes the roster
strip, the coverage bar, the lead shelf, the summary dialog, and the
`?emp=`-driven full-page drill-down (replaced by the drawer).

**Test fallout is the largest risk in this change.** Four files, 45 tests, assert
the roster/strip/summary shape:

| File | Tests | Fate |
|---|---|---|
| `AttendanceReportDataTest` | 15 | Rewrite. The *claims* survive (an employee with no records still appears; approved leave is not absence; a narrowed scope hides staff; an archived employee gets no row; a weekend record joins the day list) and must be re-asserted against ledger rows. |
| `AttendanceReportSummaryTest` | 12 | Rewrite as lens-count assertions. Every claim survives (today without a punch is not absent; an off-site day is not late; a short day that started on time is neither). |
| `AttendanceReportScreenTest` | 9 | Mostly survives; selectors change. The reverse-button role tests move to the drawer. |
| `AttendanceReportLocationTest` | 9 | Mostly survives untouched — `canSeeLocation` and the map-point shape are unchanged. |

`CLAUDE.md` forbids deleting test files without approval. **Rewrite in place;
do not delete.** If a claim genuinely no longer applies (strip bucketing by
week, for example), say so and get approval before removing that test.
