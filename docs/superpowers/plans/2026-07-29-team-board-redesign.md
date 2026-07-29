# All-staff task screen redesign — implementation plan

Design: [../specs/2026-07-29-team-board-redesign-design.md](../specs/2026-07-29-team-board-redesign-design.md)

Three tasks, sequential. Each leaves the screen working and is verifiable on its own.
The orchestrator reviews the diff between tasks. No task commits; the orchestrator
commits after review.

**Browser verification is deliberately absent from every task.** The implementer runs
headless and cannot drive a browser. The orchestrator does the browser pass after a
task clears review.

---

## Task 1 — reshape the team-board payload

Change `teamBoardData()` to return flat rows plus per-person aggregates instead of
nested per-person column collections. The Blade keeps rendering the existing lane
layout from the new shape, so the screen looks identical. This proves the data contract
before any UI depends on it.

**Files**

- `app/Http/Controllers/Concerns/BuildsWorkData.php`
- `resources/views/screens/team-board.blade.php` (adapt to the new shape only, no redesign)
- `tests/Feature/TeamBoardDataTest.php` (new)

**Shape**

`teamBoardData()` returns:

- `teamRows` — a flat collection, one entry per work item, each carrying the item and
  its owner's id, name, initials and avatar colour. Ordered by owner name, then
  `sort_order`, then id.
- `teamPeople` — one entry per person who has at least one card: id, name, initials,
  avatar colour, position title, department name, and the counts `open`, `overdue`,
  `blocked`, `in_review`, `done`.
- `teamOpenTotal` and the existing person count, unchanged in meaning.

Definitions, which the test must pin:

- `open` = status is not `done`.
- `overdue` = `due_at` is set, is before today, and status is not `done`. Same rule the
  card face uses.
- `blocked` = the `labels` array contains `blocked`.
- `in_review` = status is `review`.

**Keep**: the existing DataScope filter, the 30-day Done window, the eager loads
(`assignedBy`, `participants`, `projectRef`, `withCount('comments')`), the exclusion of
people with no cards, and the `positionBand` / `department` loads.

**Acceptance**

- The screen renders exactly as before. No visual change.
- No N+1: the request issues the same number of queries as before, or fewer.

**Tests**

`tests/Feature/TeamBoardDataTest.php`:

- The payload carries `teamRows` and `teamPeople`.
- Aggregates match the rows. Build a fixture with a known mix — an overdue card, a
  blocked card, a card that is both, a done card, an in-review card — then **recount
  from `teamRows` inside the test** and assert the aggregate equals the recount. Do not
  copy the implementation's arithmetic into the test.
- A done card older than 30 days is absent from `teamRows`.
- A person with no cards is absent from `teamPeople`.
- DataScope still applies: a team-scoped manager sees only their reports' rows.

---

## Task 2 — the summary strip, filter bar and table

Replace the lane layout. Rows are **not** clickable in this task.

**Files**

- `resources/views/screens/team-board.blade.php`
- `resources/views/partials/team-board-row.blade.php` (new)
- `resources/js/team-board.js` (new)
- `resources/js/app.js` (register the component)
- `resources/css/app.css` (`.tb-*` rules)

**Summary strip**

One row per person, sorted by `open` descending. Columns: Person, Open, Overdue,
Blocked, In review. Numeric columns use `font-variant-numeric: tabular-nums`. A zero
renders as an en dash, not `0`, so non-zero counts draw the eye. Overdue uses
`var(--error)`; the other numbers stay neutral. Every column sortable.

Clicking a person filters the table below to them and marks that strip row active.
Clicking the active row clears the filter.

**Filter bar**

Always visible: a search input matching **both** person name and task title; and chips
for Status, Overdue, Blocked. Status hides `done` by default.

Behind a "More filters" disclosure: type, priority, project, label, and a due window
(overdue / this week / no date). The disclosure shows a count badge when any hidden
filter is active, and a Clear control.

**Table**

Columns: Person, Task, Type, Status, Priority, Project, Due, Labels.

- Task is the widest column and truncates with an ellipsis.
- Due uses `tabular-nums` and renders in `var(--error)` when overdue on a card that is
  not done.
- Status is a pill. Labels reuse the existing tinted `.wc-label` chips.
- Sortable by Person, Status, Priority, Due. One sort at a time; direction toggles; the
  active column shows its direction.
- Rows have visible `:hover` and `:focus-visible` states and are keyboard reachable,
  ready for Task 3 to make them open the drawer.

Filtering and sorting run client-side over already-rendered rows. Add a comment at the
filter function naming the roughly 500-row ceiling and that server-side paging is the
next step past it.

**Responsive**

Below about 700px each row collapses to two lines: title and person on the first;
status, due and labels on the second. The page body must never scroll sideways.

**Copy**

Rewrite the `partials.guide` block for this screen, both `en` and `ms`. It currently
describes lanes and columns, which will no longer exist. Keep the same tone as the
existing entry.

**Acceptance**

- With no filters the table row count equals `teamRows` count.
- The strip's numbers equal a recount of the visible rows when unfiltered.
- Sorting by Due puts overdue rows first.
- Clearing all filters restores every row.

**Tests**

Extend `tests/Feature/TeamBoardDataTest.php`, or add
`tests/Feature/TeamBoardScreenTest.php`:

- The screen renders a row per work item in `teamRows`.
- The screen renders a strip entry per person in `teamPeople`.
- The guide copy no longer mentions lanes or columns.

---

## Task 3 — the read grant, and rows open the drawer

**Files**

- `app/Support/Permissions.php`
- `app/Http/Controllers/AppController.php`
- `app/Http/Controllers/WorkItemController.php`
- `resources/js/team-board.js`
- `resources/views/partials/team-board-row.blade.php`
- `tests/Feature/BoardCardTest.php`

**Move the predicate**

`AppController::canSeeAll(?Employee $employee, string $role): bool` is private and is
already the gate deciding who may load this screen. Move it to `App\Support\Permissions`
**unchanged in behaviour**, and have `AppController` call it there. Both the screen gate
and the new card gate must read one definition.

**Split the scope check**

`WorkItemController::isManagerOver()` currently does two things: a role check and a
DataScope check. Split them:

- `coversCardOwner(Request, WorkItem, Employee): bool` — the DataScope check alone.
- **Edit** stays `role === 'manager'` **and** `coversCardOwner(...)`.
- **View** becomes `Permissions::canSeeAll(...)` **and** `coversCardOwner(...)`.

Add the view grant to `authorizeAccess()`. Leave `canManage()` alone — editing rights do
not change in this task.

The scope check is not optional on the view grant. `canSeeAll` decides *whether* someone
oversees people; DataScope decides *whose* records. Without it, a team-scoped manager
could open any card in the tenant by putting its id in the URL.

**Rows open the drawer**

A table row opens the existing card drawer, by id, reusing whatever the personal board
already does for its `?card=` deep link. The drawer already renders read-only when
`can_manage` is false and already explains why. Do not build a second detail surface and
do not add an editing path here.

**Acceptance**

- A director opens another person's card from this screen and sees it read-only.
- Nothing that could be edited before becomes uneditable.

**Tests**

In `tests/Feature/BoardCardTest.php`:

- A `director` GETs another person's card: 200, and `card.can_manage` is `false`.
- That same director PATCHes it: 403.
- A **team-scoped** `manager` GETs a card owned by someone outside their reporting line:
  403. Their role passes `canSeeAll`, so this proves the scope check is what stops them.
  Write it as a real attempt.
- An `employee` with no direct reports GETs a colleague's card: 403.
- An `employee` who **has** a direct report GETs that report's card: 200, `can_manage`
  is `false`.
- `/app/team-board` still 403s for a user `canSeeAll` rejects.
