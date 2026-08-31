# Prefill the timesheet week from In Progress board cards

Branch: `feature/board-timesheet-prefill` (off `dev`).

## Problem

Staff fill their timesheet at the end of the week and have to remember what
they worked on each day. The task board already knows: a card sat in the
**In Progress** column while it was being worked. Today that knowledge is
only reachable through a manual picker — one card, one day, one click at a
time (`TimesheetController::178`, `resources/js/timesheet-capture.js:356`,
"Pull from board").

The ask: open the week and find Monday–Friday already populated with the
cards that were in progress on each of those days, leaving only the
description and the percentage to fill.

## The blocker this design removes

`work_items.status` is current state only. Nothing records *when* a card
entered or left `prog`. A card moved to In Progress on Friday is
indistinguishable from one that sat there all week, and a
`prog → review → prog` bounce leaves no trace. "What was I doing on Monday"
is therefore unanswerable from today's data.

Three ways to make it answerable were considered:

1. **Approximate from current status + `created_at`/`done_at`.** No new
   storage, known-wrong on late starts and on bounces.
2. **Record each stint as the card moves.** Exact from ship day. Writes only
   when someone actually moves a card. No scheduler.
3. **Nightly snapshot job.** Also exact, but depends on `schedule:run`, and
   production cron is devops-owned and not visible to us
   (`CLAUDE.md`, "Deploy to staging").

**Chosen: 2.** Same accuracy as 3 without the cron dependency, and the write
point already exists (`WorkItemObserver` already watches `status`).

## Decisions

- **Prefilled rows are suggestions, not stored rows.** They are computed on
  page load and rendered into the grid. Nothing is written to
  `timesheet_entries` until the person saves. A card that leaves In Progress
  simply stops being suggested; there is no stale-row cleanup problem and no
  interaction with `WeekReconciler`'s locked-row merge.
- **Suggested rows are NOT the leave/holiday mechanism.** `LockedDays` rows
  are HR-owned facts, locked, and regenerated on every save. These are
  editable seeds, offered once, never re-merged.
- **`timesheet_entries.source` stays null on saved board rows.**
  `TimesheetController::120` drops every row with a non-null `source` from
  `existingGrid`, because those rows are the generated leave/holiday ones the
  server re-lays on each save. A board row marked `source = 'board'` would
  therefore vanish from the grid on the next page load. `work_item_id` is the
  marker instead; `source` keeps its existing meaning, "generated and owned
  by the server".
- **Percentage is left blank.** The person types it. Splitting evenly across
  a day's cards was rejected: a wrong guess costs more to correct than an
  empty field costs to fill.
- **Whose cards:** cards the person owns **plus** cards they are a
  participant on (`WorkItem::participants()`). Shared cards already appear on
  their board, so they appear in their timesheet.
- **No backfill.** Weeks before ship day have no stints and prefill nothing.
  Inventing stints from `created_at` would put fabricated days into a record
  that feeds the cost report.
- **No new category field on the card.** Real data (production dump imported
  2026-08-28): 30 of 35 projects map to exactly one timesheet category, 4 map
  to two, 1 to none. Of 36 currently In Progress cards, 20 carry a project
  and 19 of those infer a category cleanly. The remaining 16 carry no
  project. Rather than add a `timesheet_category_id` column to `work_items`
  that duplicates what the project already implies, the suggestion **reads
  back the last entry logged against that card** — so the person picks a
  category once, on the card's first appearance, and every later day of the
  week arrives complete.
- **One entry point on the capture screen.** The separate "Pull from board"
  and manual-add affordances merge into a single **"Add what you worked on
  +"**, whose first picker step offers *Pull from T.A.A.* or *Enter
  manually*.

## Data model

### New table: `work_item_progress_stints`

| column | type | notes |
|---|---|---|
| `id` | id | |
| `tenant_id` | FK tenants, cascade delete | `BelongsToTenant` auto-fills |
| `work_item_id` | FK work_items, cascade delete | |
| `started_at` | datetime | moment the card entered `prog` |
| `ended_at` | datetime, nullable | null while still In Progress |
| timestamps | | |

Index on `(work_item_id, started_at)` and on `(tenant_id, started_at)` — the
week lookup filters by tenant and a date range.

A separate table rather than two columns on `work_items`: two columns
remember only the most recent stint, so a `prog → review → prog` card would
lose its earlier days.

### New column: `timesheet_entries.work_item_id`

Nullable, FK `work_items`, `nullOnDelete`. Two jobs:

1. Stops a card being suggested again on a day it is already logged.
2. Carries the category/project/sub-pillar choice forward to the card's next
   day (the "picked once" rule above).

It also makes "hours per card" answerable later, without being built now.

The column has to survive a round trip, or both jobs fail on the second save
of a week: `WeekWriter::save()` replaces the whole week from the grid, and the
grid is seeded from `existingGrid`. So `work_item_id` must be added to the
`existingGrid` payload (`TimesheetController:125-130`), to the row shape
`init()` builds (`timesheet-capture.js:107-114`), to the save payload, and to
`store()`'s validation rules. Without that, a saved board row comes back
detached from its card, gets suggested again, and loses its category
carry-forward.

**`work_item_id` arrives from the browser, so it is a trust boundary.** An
`exists:work_items,id` rule is not sufficient — this codebase has the known
trap that model lookups resolve across every tenant unless ownership is
checked in the controller. `store()` must reject any `work_item_id` that does
not resolve to a card in the current tenant which the submitting employee
owns or participates in — the same membership rule `BoardSuggestions` applies.
A foreign card id would otherwise poison the dedupe (which keys off this
column), the category carry-forward (which reads back the linked entry), and
the hours-per-card figures this column exists to enable.

## Components

### `App\Observers\WorkItemObserver` (extend)

Already registered (`AppServiceProvider:61`) and already watches `status` for
calendar sync. Add stint bookkeeping to the same `saved()` hook, reading
`isDirty('status')` and `getOriginal('status')` (safe there — see that
method's existing docblock on why `isDirty` is correct in `saved`):

- entered `prog`: close any dangling open stint (defensive), open a new one
  with `started_at = now()`
- left `prog`: close the open stint with `ended_at = now()`
- archived (`archived_at` set) while in `prog`: close the open stint

Card created directly into `prog` opens a stint the same way. A deleted card
needs no handling — the FK cascade removes its stints, and deletion fires
`deleted()`, not `saved()`.

The stint row is created with `tenant_id => $item->tenant_id` set
**explicitly**. `BelongsToTenant` throws on a write with no active tenant
context (AK-DB-06, fail-closed), and the observer fires on every path that
saves a card, including the MCP tools, where the tenant context is not
guaranteed to be set. Passing the parent card's own `tenant_id` makes the
write correct on every path.

### `App\Timesheet\BoardSuggestions` (new)

Sits beside `LockedDays` and `WeekReconciler`. Read-only; never writes.

```
forWeek(Employee $employee, CarbonInterface|string $weekStart): array
// => [ '2026-08-24' => [ ['work_item_id'=>…, 'title'=>…, 'category_id'=>…,
//                        'project_id'=>…, 'sub_pillar_id'=>…,
//                        'description'=>…], … ], … ]
```

Rules:

- A stint covers every calendar day from `started_at`'s date through
  `ended_at`'s date inclusive. An open stint runs through **today**, never
  into future days.
- Only the week's working days, matching `LockedDays::forWeek()`'s
  Monday-through-Saturday window; dates are resolved in `Asia/Kuala_Lumpur`
  (`config/app.php:68`), the timezone the timestamps are written in.
- Fully locked days (`LockedDays`, public holiday or whole-day leave) are
  skipped — the person cannot log work there. A half-locked day still gets
  suggestions.
- Days outside the editable window (`WeekWriter::BACKFILL_WEEKS`) are
  skipped.
- A card already saved on that date (`timesheet_entries.work_item_id`
  matches) is skipped for that date.
- Cards: `employee_id = $employee->id` **or** present in
  `work_item_participant`. Archived cards excluded.

Field resolution per card, in order:

- **category / project / sub-pillar:** the most recent existing
  `timesheet_entries` row for that `work_item_id` and that employee; failing
  that, the card's `project_id` plus that project's single category when it
  has exactly one; failing that, blank.
- **description:** the card's `description`, falling back to its `title` —
  the same text the current manual pull uses
  (`timesheet-capture.js:369`).
- **percentage:** always blank.

### `TimesheetController` (extend)

`captureData()` (around line 174, where `tsBoardTasks` is already built)
gains `'tsSuggested' => app(BoardSuggestions::class)->forWeek(...)`, passed
into the Blade config alongside `existingGrid`
(`resources/views/screens/timesheets.blade.php:98-99`).

`tsBoardTasks` stays — the manual picker still needs the card list for work
that was never dragged into In Progress.

### `resources/js/timesheet-capture.js` (extend)

- `init()` (line 95) seeds `this.rows` from `cfg.existing`; after that loop,
  append the suggested rows for each editable, non-fully-locked day, each
  carrying `work_item_id` and a client-side `suggested` flag. Suggested rows are visually marked so it is obvious they were not
  typed.
- On save, drop every row still flagged `suggested` whose percentage is
  empty. A suggestion the person ignored must not reach the server, or
  `assertNoBlankLines()` would refuse the submit. Touching the percentage
  clears the flag, making it an ordinary row.
- Deleting a suggested row removes it for that render only; it will be
  offered again next load unless a real entry exists for it. Acceptable —
  the person's remedy is to move the card out of In Progress.
- Picker gains a first step `source` with two choices. `openBoardPicker()`
  and the manual-add path both enter through it. `pickerBack()` from `board`
  or from `category` (when `viaBoard`) returns to `source` rather than
  closing.

### `WeekWriter` (extend)

`normaliseEntries()` carries `work_item_id` through to the persisted row.

**`lineKey()` gains `work_item_id` as a fifth part.** Today a line's identity
is date + category + project + sub-pillar (`WeekWriter:296-310`), and
`assertNoDuplicateLines()` refuses a repeat. Two In Progress cards on the same
project would resolve to the same key and the save would be rejected — and
with 30 of 35 projects mapping to exactly one category, that is the ordinary
case, not a rare one. Two different cards are genuinely two different lines,
so the card becomes part of the identity. Rows the person typed carry a null
`work_item_id` and key exactly as they do today, so nothing about existing
behaviour changes.

`lineKey()` is also what `mergePartialIntoExisting()` uses to tell a
correction from an addition (MCP path). The same reasoning holds there: an
MCP row (no `work_item_id`) still upserts against another MCP row, and a
board row only ever upserts against the same card's row.

This loosens the guard in one direction, deliberately: a card-linked row and a
typed row with the same date, category, project and sub-pillar now have
different keys, so a pairing that used to be refused as "the same work listed
twice" is allowed. That is accepted — the person added the second line on
purpose, and one of the two is anchored to a specific card. Do not
"fix" it by folding them back together.

Otherwise suggested rows are ordinary editable rows once saved — the
locked-day filter and every other rule apply unchanged.

## Data flow

```
card dragged to In Progress
  -> WorkItemObserver::saved()  -> open work_item_progress_stints row
card dragged out / archived / deleted
  -> WorkItemObserver::saved()  -> close that row (ended_at = now)

open timesheet capture screen
  -> TimesheetController::captureData()
       -> BoardSuggestions::forWeek(employee, weekStart)
            reads stints overlapping the week
            minus fully locked days, minus days already logged for that card
            fills category/project from the card's last logged entry, else project
  -> Blade config `suggested`
  -> timesheet-capture.js init() appends rows to the grid

person types percentage + description, saves
  -> WeekWriter::save() persists them with work_item_id
  -> next load: that card is no longer suggested for that date,
     and its category is reused for its other days
```

## Error handling

- Card with no project and no prior entry: row appears with a blank
  category. The existing picker flow handles the pick; nothing errors.
- Employee with no board cards: `suggested` is empty, grid behaves exactly as
  today.
- Stint open across a week boundary: covered, the overlap test is a date
  range, not an equality.
- Dangling open stint (a status change that somehow skipped the observer):
  the "close any dangling stint before opening" step self-heals on the next
  move. A stint left open on an archived card is excluded by the archived
  filter.
- Suggestion computation failing must never take the capture screen down: a
  failure yields an empty `suggested` map, logged, not thrown.

## Testing

Feature tests unless noted; PHPUnit, per project convention.

**Observer / stints**
- moving a card into `prog` opens a stint with `ended_at` null
- moving it out closes that stint
- `prog → review → prog` produces two stints
- archiving an in-progress card closes its stint
- creating a card directly in `prog` opens a stint
- a status change that does not involve `prog` writes no stint

**BoardSuggestions**
- a card in progress Tue–Thu suggests on Tue, Wed, Thu and not Mon or Fri
- an open stint suggests up to today and not on future days of the week
- a card already saved on a date is not suggested for that date, but is
  still suggested for the week's other days
- a fully locked day (public holiday) receives no suggestions; a half-day
  leave day does
- a participant's card is suggested; an unrelated employee's card is not
- category comes from the card's last logged entry when one exists
- category comes from the project's single category when there is no prior
  entry
- category is blank when the card has no project and no prior entry
- an archived card is not suggested

**Capture screen (JS/behaviour)**
- suggested rows render with an empty percentage and do not count toward the
  day total
- an untouched suggested row is not sent on save
- a suggested row with a percentage typed is saved with its `work_item_id`
- saving a week twice keeps `work_item_id` on the board rows (round trip
  through `existingGrid`), so they are not suggested again
- two In Progress cards on the same project save on the same day without
  tripping the duplicate-line rejection
- a save carrying a `work_item_id` for another employee's card is refused
- a save carrying a `work_item_id` from another tenant is refused

## Out of scope

- Reporting hours per card (the `work_item_id` column enables it; no report
  is built here).
- Backfilling stints for cards that were already In Progress before ship.
- Any change to how leave and public holiday rows are generated.
- Suggesting cards from columns other than In Progress.
