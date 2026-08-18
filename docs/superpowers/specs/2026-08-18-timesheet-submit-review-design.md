# Confirm-before-submit: full-page week review

**Date:** 2026-08-18
**Screen:** `screens/timesheets.blade.php`, Record tab (`resources/js/timesheet-capture.js` drives it)
**Requested by:** staff — no way today to see the whole week at once, only one day at a time,
before hitting Submit.

## Problem

The personal timesheet screen edits one day at a time. There is no screen anywhere that shows
all five working days together. "Submit week" is a single click: it calls `save(true)` directly,
POSTs the week, and reloads — nothing is shown first. A staffer who wants to double-check what
they logged before it goes in has to step through each day one by one with the day-nav arrows.

The Review tab (added 2026-07-30, `docs/superpowers/specs/2026-07-30-personal-timesheet-split-design.md`)
does not fill this gap — it is read-only history/analytics for *past* weeks, scoped deliberately
away from the current draft.

Three overlay shapes were mocked up for a pre-submit check (accordion sheet, full-page takeover,
step-through bottom sheet). Full-page takeover is the one being built.

## Approach

Insert one step between clicking "Submit week" and the existing submit call: a full-page
"Review before you submit" view. It replaces the Record tab's rendered content client-side —
same mechanism the screen already uses to switch between Record and Review (an Alpine boolean
gating an `x-show` block), not a new route and not a modal dialog on top of the day view.

- **Trigger:** the Submit week button's `@click` changes from `save(true)` to a new
  `openReview()` call. Nothing is posted yet.
- **Back to editing:** sets `reviewing = false`. `rows` is untouched — it is the same Alpine
  state the day view already reads, so nothing is lost or re-fetched.
- **Confirm & submit:** calls the existing `save(true)` — unchanged behaviour, unchanged route
  (`POST /app/timesheets`, `submit_now: true`), unchanged reload-on-success.

This is a client-side gate in front of an existing call, not a new submit path. No new route,
no new controller method, no new DB field.

## Screen contents

- **Header:** back link, "Review before you submit", week range + "every working day at 100%".
- **Summary card** (reuses the `--shelf` background): the existing week-percent figure, plus one
  new element — a stacked bar + legend showing the week split by category (`Development 66% ·
  Meetings 4% · On Leave 20% · Study 10%`, per the mockup's sample data). New client-side
  aggregation: sum each row's `percentage` by `category_id` across `rows[iso]` for every working
  day, plus locked days' `percentage` filed under their `label` (`On Leave` / `Public Holiday`
  from `LockedDays::forWeek()`), divide by `workingDayCount * 100`.
- **Day cards**, one per working day (`dayDates()`, `days: 5` per the capture config): day name,
  date, day total (`dayTotal(iso)`), then every line for that day. A line reuses `rowLabel(r)`
  (`"Category · Project · Sub-pillar"`, already joins whatever parts exist) and
  `categoryColour(r.category_id)` for the same coloured square the day view uses, plus the row's
  `description` as a note. A **half-locked day** (approved half-day leave) shows both the locked
  leave row (from `locked[iso]`, read-only, lock icon) and the staffer's own rows for the
  remaining half — the same two-part rendering `lockedPct()` / `dayTotal()` already combine for
  the day view; the review screen does not introduce new locked-day logic, it renders the same
  data.
- **Footer:** Back to editing / Confirm & submit. Confirm stays disabled unless `weekComplete()`
  — same guard the Submit button has today, kept here defensively even though reaching this
  screen already implies the week was complete when Submit was clicked.

## Code

- `timesheets.blade.php` — new `<div x-show="reviewing">` block inside the existing
  `x-data="timesheetCapture({...})"` root (it must live inside that root to read `rows`,
  `dayDates()`, `dayTotal()`, `rowLabel()`, etc. directly, no prop drilling). Record's day-editor
  block gets `x-show="!reviewing"` alongside its current `x-show` state so the two panes don't
  render together.
- `timesheet-capture.js` — add `reviewing: false`; `openReview()` sets it true; `closeReview()`
  sets it false; a new `categoryTotals()` computed helper for the stacked bar (aggregates as
  described above, returns `[{label, pct, colour}]` sorted by `pct` descending). `save()` and its
  submit path are unchanged; the Submit button's handler and the new Confirm button both call it.

## Out of scope

- RM/manday cost in the review screen — costing is money-role-only and already lives on the
  Review tab; this pre-submit check stays cost-free like the Record tab it replaces.
- Editing from the review screen — "Back to editing" is the only way to change a line; no
  inline edit on this screen.
- Anything on the Review tab itself — unchanged.
- Mobile-specific layout beyond the app's existing responsive rules.

## Testing

Feature test (`tests/Feature/...`) can only assert what the server renders: the review block's
markup is present inside the Record tab pane, gated behind the same `x-data` root, with the
Back/Confirm controls present. It cannot assert the click-through (Alpine, client-side) — same
limitation the existing tab-split tests document.

Browser verification (required, per the existing pattern for this screen's Alpine behaviour):
Submit week opens the review screen without posting; every day and every line shown matches what
was entered, including a half-locked leave day; Back to editing returns to the day view with
nothing lost; Confirm & submit posts once and reloads into the submitted, read-only state; at
1280px and 375px.

## Acceptance

- Clicking "Submit week" no longer posts immediately — it opens the full-page review first.
- The review screen shows every working day's entries, correctly labelled, plus a category
  breakdown of the week.
- Back to editing returns to Record with the draft intact; Confirm & submit performs the same
  submit `save(true)` already in use today.
- No new route, controller method, or DB column.
- New feature-test coverage for the rendered markup; existing timesheet suite stays green.
- `vendor/bin/pint --dirty` clean.
