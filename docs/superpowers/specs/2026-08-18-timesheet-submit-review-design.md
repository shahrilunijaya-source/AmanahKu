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

## What the review must show: the POST set, not the visible days

**This is the load-bearing rule of the whole screen.** The review has one promise — "this is what
you are about to submit" — and the obvious implementation breaks it.

`days` is reactive view state, flipped 5 ↔ 7 by the "Show weekend" button in the day strip.
`flatRows()` does *not* consult it: it posts every day where `isEditable(iso)` holds rows, and
`isEditable()` only checks readonly / fully-locked / future / Sunday / earliest-week. Saturday
is a working day here (`isOffDay()` gates Sunday alone, Unijaya runs a six-day week), and on a
TOT week `weekEndsOn()` pushes the submit cutoff to that Saturday.

So: staffer taps "Show weekend", fills Saturday, taps "Hide weekend", clicks Submit week. A review
built from `dayDates()` shows five days. The POST carries six. The staffer signs off on a page
that is not the thing being sent.

The review therefore iterates **the same set `flatRows()` will send**: days where `isEditable(d)`
and `rows[d]` is non-empty, plus every day in `locked` that falls in the week. Sorted by date.
Not `dayDates()`, not `days`.

- Add one helper — `reviewDays()` — returning that set, and have both the day cards and
  `categoryTotals()` read it. One source of truth; the two can never disagree.
- The same fix carries the denominator. `categoryTotals()` divides by `reviewDays().length * 100`,
  not by a hardcoded working-day count.
- Per-day contribution clamps at 100 (`Math.min(dayTotal(d), 100)`), matching the week-percent
  figure already in the shelf. Without the clamp, a day sitting at 140% makes the category split
  disagree with the headline percentage printed directly above it.

## Screen contents

- **Header:** back button (a real `<button>`, not an `<a href="#">`), an `<h2>` reading
  "Review before you submit", week range + "every working day at 100%".
- **Summary card** (reuses the `--shelf` background): the existing week-percent figure, plus one
  new element — a stacked bar + legend showing the week split by category (`Development 66% ·
  Meetings 4% · On Leave 20% · Study 10%`, per the mockup's sample data). New client-side
  aggregation: sum each row's `percentage` by `category_id` across `rows[iso]` for every day in
  `reviewDays()`, plus locked days' `percentage` filed under their `label` (`On Leave` /
  `Public Holiday` from `LockedDays::forWeek()`), divide as described above.
  - Locked segments render `var(--muted)`, matching the locked segment in the existing day bar.
    They have no `category_id`, so `categoryColour()` would drop them into the `--muted-soft`
    fallback and make them indistinguishable from a genuinely unmatched category.
  - Keep the existing 2px gap between segments. `--amber` against `--success` is 1.36:1; touching,
    they read as one block.
- **Day cards**, one per day in `reviewDays()`: day name, date, day total (`dayTotal(iso)`), then
  every line for that day. A line reuses `rowLabel(r)` (`"Category · Project · Sub-pillar"`,
  already joins whatever parts exist) and `categoryColour(r.category_id)` for the same coloured
  square the day view uses, plus the row's `description` as a note. A **half-locked day**
  (approved half-day leave) shows both the locked leave row (from `locked[iso]`, read-only, lock
  icon) and the staffer's own rows for the remaining half — the same two-part rendering
  `lockedPct()` / `dayTotal()` already combine for the day view; the review screen does not
  introduce new locked-day logic, it renders the same data.
- **Footer:** Back to editing / Confirm & submit.
  - Confirm is disabled when `!weekComplete() || readonly || saving` — the same three the Submit
    button carries today. `saving` matters: submit reloads the page, so there is a network gap
    where a second click looks like it did nothing.
  - While `saving`, the label reads "Submitting…" so the gap is visible.
  - When Confirm is disabled, `blockingMessage()` renders beside it. A disabled button is not
    focusable and announces nothing, so without the sentence a keyboard or screen-reader user
    is stuck at a dead control with no stated reason. `blockingMessage()` already picks the
    right sentence per cause (over 100% / uncosted line / not finished / week not over yet).

## Accessibility

Not a dialog and not a modal, so no `aria-modal`, no focus trap, no backdrop. It is a pane swap,
which means the two things a pane swap always gets wrong have to be handled explicitly.

- **Hide the entire Record pane, not just the day editor.** The tab bar, week shelf, day strip
  and the existing footer (which carries its own live Submit week button) all sit in that pane.
  Leaving them up puts two submit paths on screen at once and lets the staffer switch to the
  Review tab while `reviewing` is still true. `reviewing` gates the whole `tab==='record'` panel:
  review pane on one side, everything else on the other.
- **Move focus on open.** The Submit button becomes `display:none` the moment it is clicked, so
  focus silently falls to `<body>`: a screen-reader user hears nothing, a keyboard user tabs from
  the top of the document. Focus the `<h2>` instead, using the pattern already in this file —
  `tabindex="-1"` plus `outline:none`, the same trick `ts-picker-title` uses for the add-entry
  popup's step changes. Focus returns to the Submit button on "Back to editing".
- **Escape closes it.** `@keydown.escape.window` calling `closeReview()`. Every other overlay on
  this screen already takes Escape; one that does not is the odd one out.
- **Back gesture closes it, not the screen.** `pushState` a `review` marker on open and close on
  `popstate`, so a phone back-swipe leaves the review rather than leaving the timesheet. Matches
  the house rule that an in-screen swap keeps the URL honest; the Record/Review tab switcher
  already does the same thing with `replaceState` and `?tab=`.
- **The stacked bar gets `aria-hidden="true"`.** The legend beside it already carries every
  category name and percentage as real text, so the bar is a second copy. No `role="img"`, no
  duplicated label.
- **The lock icon on locked rows gets `aria-hidden="true"`.** The row already says "On Leave"; a
  screen reader announcing "lock, On Leave" is noise. (The existing day view has the same gap.
  Fix it here, do not copy it forward.)
- Colour is never the only carrier: every legend entry and every day line is labelled in text.
  `--amber` (2.61:1) and `--muted-soft` (2.92:1) sit under 3:1 against the shelf background, which
  is why the text labels and the segment gaps are doing the real work.
- Footer buttons stay 40px tall, matching every other button on the screen. Under WCAG 2.1's
  AAA 44px target, over WCAG 2.2 AA's 24px.

## Motion

Once a week per staffer, so a transition is earned — an instant full-screen swap with no motion
reads as a page navigation, and the whole point is that the staffer has *not* submitted yet.
Everything needed already exists in `app.css`; no new CSS and no new easing curve.

- Enter: reuse `.uj-overlay-enter` / `.uj-overlay-from` / `.uj-overlay-to` (250ms,
  `cubic-bezier(0.32, 0.72, 0, 1)`, `prefers-reduced-motion` branch already written).
- Exit: faster, 150ms, opacity only. Entering is the system explaining itself; leaving is the
  staffer retreating and wanting their editor back immediately.
- Confirm & submit gets `transform: scale(0.97)` on `:active`. It is the last click before the
  week goes in and it should feel like the button heard it.
- Origin stays centred. It is a full-page takeover, not a popover anchored to the Submit button.
- The stacked bar does not animate its fill. It arrives already on screen with the pane;
  animating it as well is two animations for one event.

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

A unit-style assertion on `reviewDays()` / `categoryTotals()` is worth having if the JS ends up
reachable from a test harness; if not, the browser pass below covers it.

Browser verification (required, per the existing pattern for this screen's Alpine behaviour):

- Submit week opens the review screen without posting.
- Every day and every line shown matches what was entered, including a half-locked leave day.
- **Saturday case:** tap "Show weekend", fill Saturday, tap "Hide weekend", then Submit week. The
  review must still show Saturday, and the category percentages must be computed over six days.
- Category percentages sum to the headline week figure, including on a week with a day over 100%.
- Back to editing returns to the day view with nothing lost.
- Confirm & submit posts once and reloads into the submitted, read-only state.
- Keyboard pass: tab lands inside the review immediately after opening; nothing behind the pane
  is reachable by tab; Escape returns to the day view with focus back on Submit week.
- At 1280px and 375px.

## Acceptance

- Clicking "Submit week" no longer posts immediately — it opens the full-page review first.
- The review shows exactly the days the submit will POST, including Saturday, and never a day
  the POST omits.
- The category breakdown is computed over that same set of days, with each day clamped at 100.
- Opening the review hides the whole Record pane, tab bar and old Submit button included.
- Focus moves to the review heading on open and back to Submit week on close; Escape and the
  browser back gesture both close the review.
- Confirm is disabled while `saving` and states its reason when blocked.
- Back to editing returns to Record with the draft intact; Confirm & submit performs the same
  submit `save(true)` already in use today.
- No new route, controller method, or DB column.
- New feature-test coverage for the rendered markup; existing timesheet suite stays green.
- `vendor/bin/pint --dirty` clean.
