# Timesheet capture revamp — design

Date: 2026-07-22
Status: approved in brainstorming, not yet planned
Scope owner: Shazwan

## Problem

Internal test users cannot operate `/app/timesheets`. The confusion is **mechanical, not
conceptual**: they understand that the module allocates effort by percentage, and the
vocabulary (category, project, sub-pillar, person-day) is not what blocks them. They cannot
drive the screen.

Observed in a live walkthrough on 2026-07-22, signed in as an HR-role employee:

- The day columns are cut off. The grid sits in a `flex:1.4` card next to a sidebar and
  carries `min-width:480px`, so on a laptop it scrolls sideways *inside* the card. Friday
  was not visible at 956px.
- Choosing what you worked on happens in a panel that expands *between* grid rows, pushing
  the day inputs of that row away from the choice being made.
- One line of work costs roughly nine interactions: add line, category pill, project pill,
  sub-pillar pill, Done, then five day values.
- The two accelerators are effectively invisible. `copyAcross` is an 11.5px text link inside
  the collapsed panel; `fillDay` is a `title` tooltip on a number.
- When Submit week is unavailable, the button is greyed with no statement of what is missing.

## Scope

**In scope**

1. Redesign the capture screen at `/app/timesheets`.
2. Three gaps that cause or compound the confusion:
   - a draft counting as compliant,
   - no way back after submit,
   - approved leave and public holidays not prefilling.

**Out of scope**

- `/app/timesheet-reports` and `/app/timesheet-setup`. No complaints, no changes.
- The manday cost model and `MandayRateService`.
- Building the manager approve/reject step. The UI currently promises approval that does not
  exist; that stays a known gap, tracked separately. The recall action added here does not
  depend on it.

## Decisions

| # | Decision | Why |
|---|---|---|
| D1 | Day-first entry, one day at a time, with a week strip for navigation and progress | Only option that is the same layout on phone and desktop, which is the stated requirement. Removes horizontal scrolling entirely. Safe while the real week shape is unmeasured: per-day entry always works, and a repeat action collapses the uniform case. |
| D2 | Days after today are not editable | You cannot have spent time you have not spent. Prevents fill-it-in-advance-and-forget, which would destroy the only signal this module produces. |
| D3 | Past days are editable within the current week plus the previous three weeks | Blocking past days creates an unfixable dead end: a forgotten Monday can never reach 100%, so the week can never be submitted. An open-ended window lets someone backfill months before an audit. Three weeks is a hardcoded constant, not configuration. |
| D4 | Approved leave and public holidays override user rows on that day, in drafts only | Approved leave is a fact from HR; work rows on that day are wrong by definition. Submitted weeks are finalised records feeding the cost report and are never silently rewritten. |
| D5 | Remove `On Leave` and `Public Holiday` from the manual work picker | With D4, leave in a timesheet can only originate from an approved leave request. Leaving the pills in would let staff log leave HR never approved, straight into the manday cost report. The categories stay in `timesheet_categories` because the generated rows use them. |
| D6 | A draft no longer counts as compliant | Today `TimesheetCompliance::isComplete()` checks only that entries sum to 100 and ignores `status`, so an unsubmitted draft shows DONE on the roster and silences the overdue banner. Combined with the one-way submit, the system currently rewards never submitting. |
| D7 | The owner can recall a submitted week back to draft | Submit is currently a one-way door with no key, not even for HR. Nothing approves timesheets, so there is no approval to invalidate. |

## The screen

Route, controller entry, and position in the app layout are unchanged:
`GET /app/timesheets?week=YYYY-MM-DD`.

### Week header

Previous and next week arrows plus a plain label ("Week of 20 to 24 Jul") and a status chip
(Draft, Submitted). The current bare `<input type="date">` requires the user to know they
must pick a Monday. A "jump to another week" link keeps the date input for reaching further
back, bounded by D3.

### Week strip

One segment per weekday (seven when the weekend toggle is on): day name, date, fill bar.

| State | Appearance |
|---|---|
| Empty | Grey bar |
| Partial | Amber bar |
| Complete | Green bar |
| Locked (leave or holiday) | Filled, muted, lock icon |
| Not yet reachable (future, D2) | Dimmed, not selectable |

A future day that is also a public holiday shows the lock, because the holiday is already a
known fact. It is still not editable, so the two states do not conflict in practice.

Tapping a segment selects that day. This one component replaces three things that are
deleted: the day-column header row, the day-total footer row, and the
"5 days filled · all days at 100%" text line.

### Day card

The only editable surface.

- Header: "Wednesday 22 Jul" left, "60 of 100" right, progress bar beneath.
- The allocation rows for that day: work label, percentage, note marker. Tap to edit or remove.
- One dashed "Add what else you did" affordance.
- Two quick actions, as visible buttons rather than a buried link and a tooltip:
  - "Same as Tuesday" copies the previous weekday's rows wholesale (replaces `copyAcross`).
  - "Give the rest to KPT: RMS" fills to 100 using the last row in that day (replaces `fillDay`).
    Not shown when the day has no rows yet, since there is nothing to give the remainder to.

### The picker

The largest single reduction in effort. Today: three sequential decisions across three pill
groups. Instead, one searchable list of **pre-composed work items**, each being a
`Category · Project · Sub-pillar` combination.

The list is assembled from:

1. The employee's own distinct combinations from the last eight weeks of entries, most recent first.
2. Their saved `TimesheetTemplate` rows.
3. A "something else" link that reveals the existing three-step drill-down, so no combination
   becomes unreachable.

For the Unijaya tenant today the full cross product is roughly thirty entries, which is
browsable with search. `On Leave` and `Public Holiday` are excluded per D5.

Amount is then one question, with chips for the round numbers people actually use (all day,
half, quarter) plus a number field.

### Bottom bar

Save draft and Submit week. When submit is unavailable, state why in words
("Wednesday and Thursday are not at 100% yet") rather than only grey the button.

### Autosave

Load-bearing, not a nicety: once entry is day-by-day, losing typed input on navigation would
break the model. Save on day change and on blur, reusing `timesheets.store` and its existing
replace-the-whole-week behaviour. No new route, no new table.

The client skips the request only when there is nothing to persist at all, meaning no user
rows *and* no locked days. A week that is entirely leave still posts once, with zero user
rows, so the locked rows get written. That is why the server has to accept a draft save with
zero user rows.

### Unchanged

The "My timesheets" list and the "My time spent" breakdown below the card stay as they are.

## Leave and public holidays

### The rule

A weekday is **locked** when either holds:

- a `public_holidays` row exists for that date in the tenant, or
- a `leave_requests` row with `status = 'approved'` covers that date.

A locked day is filled to 100% and is not editable. Any user rows that existed on that day in
a **draft** are replaced (D4). One line informs the user. Pending or submitted leave does
nothing, because unapproved leave is not a fact.

There is no half-day branch. `LeaveController` computes
`days = date_from->diffInDays(date_to) + 1`, always a whole number. The `decimal(4,1)` column
can hold `0.5` but nothing writes it. If half-day leave is ever introduced, this rule needs
revisiting.

### Storage

Locked rows are written as real `timesheet_entries` using the tenant's existing `On Leave`
and `Public Holiday` categories, not held as a virtual overlay. This is what lets both report
screens and the compliance sum keep working untouched.

If a tenant has deleted those categories, the prefill is skipped and the day behaves
normally. This matches how the rest of the codebase fails open on tenant reads.

### Drift

Prevented by regeneration, not synchronisation. `store()` already deletes every entry and
rewrites the week on each save, so each save writes user rows plus freshly computed locked
rows, and each page load recomputes from live leave data. Cancelled leave disappears on the
next load. There is no sync job and no reconciliation state.

### Weeks entirely on leave

If every weekday of a week is locked, the employee is not expected to submit at all. This is
handled in `TimesheetCompliance::isEligible()`, alongside the existing inactive and
joined-late exclusions, so no overdue banner, no roster row, no reminder. Partially locked
weeks need no special handling: the user opens the screen to fill the other days, which
persists the locked rows.

## Backend changes

| Change | File | Note |
|---|---|---|
| `source` nullable string column on `timesheet_entries` (`leave`, `holiday`, or null) | new migration | Minimum needed to tell a generated row from a typed one. Deriving it from the category instead works most of the time and confuses the rest. |
| Status check in `isComplete()` | `app/Timesheet/TimesheetCompliance.php` | Only `submitted` or `approved` counts (D6). Fixes the roster, the overdue banner, and `TimesheetReminder` at once, since all three read this method. |
| All-locked-week exclusion in `isEligible()` | `app/Timesheet/TimesheetCompliance.php` | See above. |
| Allow a draft save with zero user rows | `TimesheetController::store()` | Current rule is `entries => required|array|min:1`. Needed for autosave and for fully locked weeks. |
| Reject entry dates after today, and before the D3 window | `TimesheetController::store()` | Server-side, not only in the UI. Generated locked rows bypass this since they are approved facts. |
| Regenerate locked rows on every save | `TimesheetController::store()` | Inside the existing delete-and-rewrite transaction. Overriding user rows on locked days is a skip inside a loop that already exists. |
| `POST /app/timesheets/{timesheet}/recall` | `routes/web.php`, `TimesheetController` | Owner only, `submitted` only, sets `status = 'draft'`, clears `submitted_at`, writes an `AuditLog` line (D7). |

The data model is otherwise unchanged. Still one `TimesheetEntry` per day per work item. No
migration of existing rows.

## Edge cases

| Case | Behaviour |
|---|---|
| Forgot Monday, it is now Thursday | Monday is still editable. Select it in the strip and fill it. |
| Tries to fill Friday on Monday | Friday is dimmed and not selectable. Server rejects the date too. |
| Leave approved for a day already filled, week is a draft | Work rows on that day are replaced by the leave row. One line says so. |
| Leave approved for a day in an already submitted week | Nothing is touched. The user recalls the week (D7), and the leave lands on the next save. |
| Leave cancelled after the lock was written | Next load recomputes and the lock is gone. The day reverts to empty and editable. |
| Whole week is public holiday | Week is complete with zero user input, and the employee is not flagged as overdue even if they never open the screen. |
| Tenant has deleted the `On Leave` category | Prefill silently skipped, day behaves normally. |
| Week submitted, user opens it | Read-only, as today, plus a Recall button. |
| Two browser tabs saving the same week | Last write wins, as today. `store()` replaces the whole week inside a transaction, so no partial state. |

## Testing

Match the existing style in `tests/Feature/TimesheetTest.php`: PHPUnit classes, `RefreshDatabase`,
the `setUp` / `actingInTenant` / `hrActor` harness. Run with `php artisan test`.

New coverage:

1. A draft week at 100% on every weekday is **not** compliant; the same week submitted is.
2. An employee whose whole week is public holidays is not eligible, so not overdue.
3. Posting an entry dated after today is rejected.
4. Posting an entry dated before the three-week window is rejected.
5. Saving a draft with zero user rows succeeds.
6. Approved leave on a weekday replaces the user's rows for that day in a draft.
7. Approved leave on a weekday in a submitted week leaves it untouched.
8. Cancelling approved leave removes the locked row on the next save.
9. Recall moves a submitted sheet back to draft, and is refused for a non-owner and for a
   sheet that is already a draft.

Existing timesheet tests (`TimesheetTest`, `TimesheetCostTest`, `TimesheetComplianceTest`,
`TimesheetReminderTest`) must keep passing. `TimesheetComplianceTest` will need updating for
D6, which is expected rather than a regression.

## Risks

- **The DONE count will drop on deploy.** D6 stops counting drafts. This is a correction, not
  a regression, but it will look like one on the roster the first week. Worth telling users
  before it ships.
- **The picker list assumes a modest cross product.** Around thirty items for Unijaya today.
  A tenant with fifty projects would need the search path to carry more weight, and the
  recents-first ordering becomes essential rather than convenient.
- **Week shape is still unmeasured.** D1 was chosen because it is safe under that uncertainty.
  Once there is real usage data, if weeks turn out to be highly uniform, a "declare your
  normal week" shortcut becomes worth revisiting as an addition, not a replacement.
- **The approval loop remains missing.** Staff still see "submitted for approval" and nothing
  approves. Out of scope here, but shipping a nicer capture screen does not make that copy
  less wrong. The wording should be corrected even if the loop is not built.
