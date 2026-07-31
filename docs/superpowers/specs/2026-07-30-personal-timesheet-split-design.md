# Personal timesheet: split Record from Review

**Date:** 2026-07-30
**Screen:** `screens/timesheets.blade.php` (the `timesheets` app screen — every staffer's
personal weekly timesheet)
**Reference:** `~/Downloads/Timesheets.dc.html` (the day-first recording mock)

## Problem

The personal timesheet screen does two unrelated jobs in one scroll:

1. **Record** — the day-first capture card at the top: pick a week, open a day, add what you
   worked on, set each entry's %, submit once every working day reads 100%. This is a daily
   write action, in and out.
2. **Review** — a `uj-card` below it with an inner tab bar (`My timesheets` / `My time
   spent`): the history of past weeks, and a date-range analytics breakdown of where recorded
   time went. This is an occasional read.

A daily writer scrolls past, or lands beside, an analytics panel they did not come for.
Decision 3 of the timesheet revamp was to give each job a focused view. The sibling all-staff
report screen (`timesheet-reports`) was just split the same way, into a two-tab underline bar.

## Approach

One `Timesheets` screen, a two-tab underline bar mirroring the report screen's idiom
(`uj-tr-tabs`), splitting the two jobs:

- **Record** (default tab) — the existing capture card, plus one new element from the
  reference: a week shelf.
- **Review** — the two existing reference panels, un-nested and stacked into one tab.

Frontend only. `myTimesheets` and `myBreakdown` already arrive from
`TimesheetController::screenData`; the split is Blade + Alpine. No controller, route, nav, or
migration change.

## Tab bar

Reuse the report screen's pattern verbatim in behaviour:

- Root `x-data` carries `tab`, seeded from `request()->query('tab')`, defaulting to `record`.
- An unrecognised `?tab=` value falls back to `record` (never a screen with both panels
  hidden).
- `setTab(t)` writes the query with `history.replaceState` so the URL stays true and deep
  links work (`?tab=review`).
- Two `role="tab"` buttons in a `uj-tr-tabs`-style underline bar; the active one carries the
  red underline. **No count badge** — nothing time-critical hides behind the Review tab
  (unlike the report's owed count, which is the one thing that earns a badge).
- Default is **Record**: the nav item is named "Timesheet", the screen subtitle promises week
  allocation, and the daily action is writing — landing anywhere else contradicts the label.

## Tab 1 — Record

The existing capture card, unchanged in behaviour, with a week shelf added on top.

### Week shelf (new — the only net-new surface)

Ported from the reference's shelf (`Timesheets.dc.html` lines 95–118), slimmed:

- A big mono figure — the **week percent allocated** — beside "of the week allocated".
- The rule line: "Every working day must reach 100% before the week can be submitted."
- One or two chips: days at 100%, days left to fill.
- The week label / status / range already shown in the capture header stays where it is; the
  shelf does not duplicate the day-level nav.

**Computed client-side**, inside the existing `timesheetCapture` Alpine scope, so it stays
live as the user edits — no server round-trip and no stale figure:

```
weekPercent = round( Σ min(dayTotal(d), 100) over working days d
                     ÷ (workingDayCount × 100) × 100 )
```

Locked days (approved whole-day leave, public holiday) count as 100. `dayTotal`,
`dayState`, the working-day set, and `weekComplete()` already exist on the component — the
shelf reads them, it does not add state.

To read the component's values, the shelf markup must live **inside** the capture card's
`x-data="timesheetCapture({…})"` root (today it would sit above it). The capture card is
reorganised so the shelf is the first child of that root; everything else in the card is
untouched.

### Everything else on Record

The day header (Today pill, day nav, day total `/100`, progress bar), locked/half-locked/
future/off-day banners, editable rows, quick-fill chips, save-as-template, the add-what-you-
worked-on picker, the week-dots strip with its legend and weekend toggle, the date/week jump
sheet, and the save-draft / submit-week footer — all stay exactly as they are.

## Tab 2 — Review

The two existing reference panels, un-nested and stacked. The current inner tab bar
(`x-data="{ tab: 'sheets' }"`, lines 512–526) is **deleted**; both panels render together
under the Review tab, each introduced by a plain section heading.

1. **My timesheets** — the history list (`$myTimesheets`), unchanged: per-week row, entries
   count, status colour, RM cost for money-roles (`$canSeeCost`), the per-week expand
   (`View entries`), and Edit / Submit for draft weeks. The empty state stays.
2. **My time spent** — the analytics block (`$myBreakdown`), unchanged: the date-range
   `from`/`to` filter, person-days total, by-category and by-project bar lists, and its own
   empty state. Only shown when `$myBreakdown` is non-empty, as today.

Nothing about the data or the panels' internals changes — only their container (a stacked
tab instead of a nested tab).

## Styling

- **Tab bar:** the report screen's `.uj-tr-tabs` / `.uj-tr-tab` classes are not
  report-specific in meaning, only in name. Either lift them to a shared, screen-neutral name
  and use them on both screens, or reuse the `uj-tr-` names as-is. Decided in the plan;
  visually identical either way. No count-badge class is needed here.
- **Shelf:** new `uj-ts-` classes, duplicating the ~4 shelf rules
  (`.uj-tr-shelf` / `.uj-tr-fig` / `.uj-tr-figsub` / `.uj-tr-chip` shapes) rather than
  renaming the report's classes and disturbing that screen. A few duplicated CSS lines is
  cheaper than coupling the two screens through one class.
- No new design token. Reuse existing `--font-mono`, `--shelf`, `--shelf-line`, `--ink`,
  `--amber-ink`, `--success-ink`.

## Out of scope

- Approval — already dropped in the earlier backend pass.
- RM-cost role gating — already correct (`$canSeeCost`).
- No route, nav, migration, or dependency change.
- The reference's mobile mock is informational; the app is already responsive and the tab bar
  holds one line at 375px (proven on the report screen).

## Testing

`tests/Feature/TimesheetPersonalTabsTest`, mirroring `TimesheetChaseCardTest`'s tab cases:

- The screen offers both tabs (`Record`, `Review`).
- Record is the default (`tab: 'record'` in the rendered `x-data`).
- `?tab=review` deep-links Review (`tab: 'review'`).
- An unknown `?tab=` falls back to Record.
- The Review tab renders both the history heading and the time-spent heading (proving the
  inner tab bar is gone and both panels are present).
- The week shelf renders the correct week-percent for a fixture with a known half-filled week
  (asserting the computed figure, or — since it is client-side — asserting the shelf markup
  and the values it reads are present; the percentage itself is exercised by the existing
  `timesheetCapture` unit coverage if any, else left to the browser check).

Browser verification (the tests cannot see Alpine): both tabs mount, only one shows, URL
tracks the tab, the shelf percent updates live as a day's rows change, at 1280px and 375px.

## Acceptance

- Record and Review are two tabs on one screen; Record is default; `?tab=` deep-links and
  falls back cleanly.
- The capture card behaves exactly as before, now with a live week-percent shelf on top.
- Review shows the history list and the analytics breakdown stacked, no nested tab bar.
- No backend change; no new token; the report screen is untouched.
- New tab test passes; the existing timesheet suite stays green.
- `vendor/bin/pint --dirty` clean.
