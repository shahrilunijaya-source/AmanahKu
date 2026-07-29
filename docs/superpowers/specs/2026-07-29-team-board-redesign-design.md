# All-staff task screen redesign — design

**Date:** 2026-07-29
**Surface:** `/app/team-board` ("See all staff tasks")
**Mode:** Operate — the visitor answers a question about other people's work
**Follows:** [2026-07-29-taa-board-redesign-design.md](2026-07-29-taa-board-redesign-design.md), shipped as `a9ea5f8`, `3a8e2ca`, `20d1260`

## Why

The screen is a board of boards: person-major, then status-major. Measured at 1280px with 7 people and 50 cards:

```
main scroll height   3407px   (viewport 900 → 3.8 screens)
lane heights         197 · 289 · 289 · 324 · 380 · 380 · 1018
column headers       28       (7 people × 4)
cards                50       → 1.8 cards per column average
active employees     17       only 7 carry cards today
```

Height grows O(people) and each person costs about 200px even carrying one card. At full adoption that is roughly 8,000px.

The four-column skeleton is inherited from the personal board, where it exists because the owner drags cards through it. Nothing here is draggable, so on this screen the skeleton is decoration costing 28 headers and 28 empty-state markers to display 50 cards.

Against the questions people actually bring to it:

| Question | Today |
|---|---|
| Who is overloaded, who is idle? | Barely — an "N open" pill per lane, but comparing seven numbers means scrolling 3,400px |
| What is blocked or overdue anywhere? | No — visually scan 50 cards for red dates and Blocked chips |
| Where is project X across everyone? | No — project sits in a card footer, no grouping, no filter |
| What is sitting In Review? | No |
| What did we finish recently? | Partly — a Done column per person, 30-day window |

One of five, badly. The header summary reads "7 people · 43 open items", an aggregate that prompts no action.

## Decisions

Confirmed with the user before this spec was written.

| Decision | Choice |
|---|---|
| Shape | Summary strip, then a filterable table |
| Filter bar | This screen gets its own. The personal board's bar is **not** touched |
| Card detail | Rows open the existing drawer, read-only for overseers |
| Edit rights | Unchanged — owner, assigner, and a data-scoped `manager` only |

## Scope

**In:** the summary strip, the filter bar, the table, responsive collapse, the read-only drawer grant and the `canSeeAll` extraction it needs.

**Out:** the personal board's filter bar, board search on the personal board, its 272px column min-width, and its mobile column scroll. All still open, none touched here.

## Architecture

### Files

| File | Change |
|---|---|
| `resources/views/screens/team-board.blade.php` | Rewritten. Lanes replaced by strip plus table. |
| `resources/views/partials/team-board-row.blade.php` | **New.** One table row, so the row markup has one home. |
| `resources/js/team-board.js` | **New.** Alpine component: filter state, sort state, row → drawer. |
| `resources/js/app.js` | Register the new component. |
| `resources/css/app.css` | `.tb-*` rules. |
| `app/Http/Controllers/Concerns/BuildsWorkData.php` | `teamBoardData()` returns flat rows plus per-person aggregates instead of nested lanes. |
| `app/Http/Controllers/WorkItemController.php` | Read grant in `authorizeAccess()`. |
| `app/Support/Permissions.php` | `canSeeAll()` moves here from `AppController`. |
| `app/Http/Controllers/AppController.php` | Calls the moved helper. |

### Summary strip

One row per person, sorted by open count descending, above the table.

```
Person            Open   Overdue   Blocked   In review
Dev HR             13        2         1          3
Faizal Othman       8        1         -          2
Aisyah Rahman       5        -         1          1
```

Numeric columns use `tabular-nums` so they compare at a glance. A zero renders as `–`, not `0`, so a non-zero count is what draws the eye. Overdue counts use `--error`; the rest stay neutral. Sortable by any column.

Clicking a person filters the table to them and marks the row as active. Clicking again clears it. This is the interaction that replaces the 3,400px scroll: the strip answers the load question outright, and every other question is one filter away.

People with no cards stay excluded, as today.

### Filter bar

Its own component, not shared with the personal board.

**Always visible:** a search box matching **both** person name and task title; and three chips — **Status** (with Done hidden by default), **Overdue**, **Blocked**.

**Behind "More filters":** type, priority, project, label, and a due window (overdue / this week / no date). The disclosure carries a count badge when any are active, plus a Clear.

The personal board was criticised for hiding filters behind a toggle. The difference here is deliberate and worth stating: there, three stacked rows of low-value filters hid behind a toggle with nothing indicating they were active. Here the three filters that answer the common questions stay visible and the hidden ones announce themselves with a count. If that distinction fails in use, surface all of them — do not add a second toggle.

### Table

Columns: `Person · Task · Type · Status · Priority · Project · Due · Labels`

- **Task** is the widest column and truncates with an ellipsis; the full title is the drawer's job.
- **Due** is `tabular-nums`, and renders in `--error` when overdue on a card not yet Done — the same rule the card face uses.
- **Status** is a pill. **Labels** reuse the tinted `.wc-label` chips.
- Sortable by person, status, priority and due. One sort at a time, direction toggles, the active column shows its direction.
- Row hover and `:focus-visible` are visible states; rows are keyboard reachable and open on Enter.

Filtering and sorting run **client-side over already-rendered rows**. No fetch, which is the most region-scoped update available, and comfortable to roughly 500 rows. Past that this needs server-side paging; say so in a comment rather than building it now.

Done cards keep the existing 30-day window.

### Responsive

Below roughly 700px each row collapses to two lines — title and person on the first, status, due and labels on the second. The table never scrolls sideways.

### Row opens the drawer

The drawer built for the personal board is reused as-is. It already renders read-only when `can_manage` is false, and already explains why rather than greying out in silence.

This screen's rows are therefore **read-only for overseers and editable only for those who could already edit** — the owner, the assigner, and a data-scoped `manager`. A director opening a card from here reads it and cannot change it, which is the rule agreed for the personal board.

## Permissions

This is the one part that is not presentation, so it gets its own tests.

`authorizeAccess()` currently admits the owner, the assigner, a participant, and `isManagerOver()`. A director is `director` → collapses to `management`, which is not `manager`, so today they would get a 403 clicking any row on a screen whose own guide says it is for "Management · HR · Immediate superiors".

**Add a read grant, separate from the edit grant.**

`AppController::canSeeAll()` is already the predicate for who may load this screen: `manager`, `management` (so also `director`), `hr`, or anyone with at least one direct report. It is currently private on `AppController`. Move it to `Permissions` so both the screen gate and the card gate read from one definition, and update `AppController` to call it there.

**The read grant must be scope-bounded exactly like the edit grant.** `canSeeAll` decides *whether* someone oversees people; `DataScope` decides *whose* records. Without the scope check a team-scoped manager could open any card in the tenant by typing its id into the URL — the same hole `AK-AUTHZ-01` exists to close, reintroduced through a different door.

Refactor the existing `isManagerOver()` into two pieces:

- `coversCardOwner(request, item, employee)` — the DataScope check alone.
- **Edit** = `role === 'manager'` **and** `coversCardOwner(...)`.
- **View** = `canSeeAll(role, employee)` **and** `coversCardOwner(...)`.

View is strictly wider than edit, so nothing anyone can do today stops working.

## Testing

Feature tests:

- A director opening another person's card via `GET /app/board/{id}` succeeds and reports `can_manage: false`.
- That same director's `PATCH` is forbidden.
- A **team-scoped** manager cannot `GET` a card whose owner is outside their reporting line, even though their role passes `canSeeAll`. This is the hole the scope check closes — write it as a real attempt, not a smoke test.
- An ordinary employee with no direct reports still cannot `GET` a colleague's card.
- An employee who has a direct report **can** `GET` that report's card read-only, since `canSeeAll` admits them.
- `/app/team-board` still 403s for anyone `canSeeAll` rejects.
- The screen payload carries flat rows and per-person aggregates, and the aggregates match the rows (open, overdue, blocked, in-review counts recomputed in the test rather than copied from the implementation).

Browser verification at 1440px and 375px: console clean; the strip's numbers match the table when unfiltered; clicking a person filters the table; sorting by due puts overdue first; a row opens the drawer read-only for a director and editable for the owner; the table never scrolls the body sideways at 375px.

## Implementation stages

Three stages, sequential, each independently verifiable, with the diff reviewed between.

**Stage 1 — reshape the data.** `teamBoardData()` returns flat rows plus per-person aggregates. Add the payload test. The Blade still renders lanes from the new shape, so the screen looks unchanged and only the data contract moves. Proves the aggregates before any UI depends on them.

**Stage 2 — the screen.** Strip, filter bar, table, responsive collapse, sorting, client-side filtering. Rows are not yet clickable.

**Stage 3 — the read grant and the drawer.** Move `canSeeAll` to `Permissions`, split `isManagerOver`, widen `authorizeAccess`, make rows open the drawer. All the permission tests land here.

Permissions come last on purpose: the screen is useful without clickable rows, and an authorization change should not ride along with a large presentation diff.

## Risks

| Risk | Mitigation |
|---|---|
| The read grant leaks cards across data scope | `coversCardOwner()` applies to both grants; the team-scoped-manager test is written as a real attempt |
| `canSeeAll` behaves differently after moving | Move it unchanged, and keep an `AppController` test covering the screen gate |
| Client-side filtering quietly stops scaling | Comment the ~500-row ceiling at the filter function; the 30-day Done window already bounds growth |
| The strip and the table disagree | Aggregates are derived from the same rows server-side and asserted against a recount in the test |
| Losing the board metaphor confuses existing users | The guide partial is rewritten in the same change to describe what the screen now is |
