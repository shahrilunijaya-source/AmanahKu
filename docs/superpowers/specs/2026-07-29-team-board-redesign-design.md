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

Confirmed with the user before this spec was written — **except the row marked
2026-07-29 (revision)**, which the user asked for after reviewing the first
build described below.

| Decision | Choice |
|---|---|
| Shape | **2026-07-29 (revision):** one table, one line per person. The original "summary strip, then a filterable task table" shipped first (`aa1180f`) but was rejected on review: two tables answering overlapping questions, and a task row's click navigated away to the personal board's `?card=` deep link, throwing the viewer onto their own (often empty) board and discarding this screen's state |
| Filter bar | This screen gets its own. The personal board's bar is **not** touched. **Revision:** simplified further — Status chips and the "More filters" disclosure (type/priority/project/label/due window) move into the floating window as task-level filters, since they describe one task, not one person |
| Card detail | **Revision:** clicking a person opens a floating window listing just that person's tasks (reusing the personal board's `.wd-*` slide-over CSS), not a fetched read-only clone of the personal board's card drawer. No separate per-task panel exists on this screen at all |
| Edit rights | Unchanged — owner, assigner, and a data-scoped `manager` only |

## Scope

**In:** the summary strip, the filter bar, the table, responsive collapse, the read-only drawer grant and the `canSeeAll` extraction it needs.

**Out:** the personal board's filter bar, board search on the personal board, its 272px column min-width, and its mobile column scroll. All still open, none touched here.

## Architecture

**2026-07-29 (revision):** this section describes the build as it stands
after the user reviewed the first version (summary strip above a 50-row task
table, rows opening a fetched read-only drawer clone) and asked for
something simpler. The original build's own follow-up fix — a floating panel
fetched from `GET /app/board/{id}` — is gone too; nothing on this screen
fetches anything anymore.

### Files

| File | Change |
|---|---|
| `resources/views/screens/team-board.blade.php` | Rewritten. One table, one line per person, plus the floating window's markup (teleported to `<body>`). |
| `resources/views/partials/team-board-row.blade.php` | Re-purposed: no longer a table row, now one task line inside the floating window. Same file, new role — see its own header comment. |
| `resources/views/partials/team-board-panel.blade.php` | **Deleted.** Was the fetch-based read-only drawer clone from the first build's follow-up fix; superseded by the floating window inlined in `team-board.blade.php`, which needs no fetch. |
| `resources/js/team-board.js` | Rewritten. Person-table filter/sort state, plus the floating window's own open/close/focus-trap and task-level filter state. No `fetch()` anywhere in the file. |
| `resources/js/app.js` | Unchanged — already registered the component from the first build. |
| `resources/css/app.css` | `.tb-*` rules reshaped: the old two-table layout's rules (`.tb-table`, `.tb-cols`, `.tb-row*`, `.tb-cell*`, the "More filters" disclosure, the fetched-panel's `.wd-static*`/`.wd-desc-ro`) are dead and removed; the person table's rules (`.tb-strip*`, `.tb-num*`, `.tb-sortbtn`) are reused as-is; new rules cover the floating window's summary/filter row and the task line's stacked layout. |
| `app/Http/Controllers/Concerns/BuildsWorkData.php` | **Untouched by this revision** — `teamBoardData()` already returns flat `$teamRows` plus per-person `$teamPeople` aggregates from the first build; this revision only changes how the Blade/JS layer presents that same data. |
| `app/Http/Controllers/WorkItemController.php`, `app/Support/Permissions.php`, `app/Http/Controllers/AppController.php` | **Untouched by this revision.** The read grant these files carry (see "Permissions" below) was added for the first build's fetched panel. This screen no longer calls `GET /app/board/{id}`, so the grant is currently unexercised from here — but it is left in place, since `BoardCardTest` still exercises and asserts it directly, and a director opening a card by URL should still work read-only regardless of which screen sent them there. |

### The person table

One line per person from `$teamPeople`, sorted by open count descending — this is now the *entire* always-visible content of the screen, not a strip sitting above a second, bigger table.

```
Person            Open  Overdue  Blocked  In review
Dev HR             13      2        1         3      <- click opens a floating window
Faizal Othman       8      1        -         2
Aisyah Rahman       5      -        1         1
```

Numeric columns use `tabular-nums` so they compare at a glance. A zero renders as `–`, not `0`, so a non-zero count is what draws the eye. Overdue counts use `--error`; the rest stay neutral. Sortable by any column, with a visible direction indicator.

Each line is `tabindex="0" role="button"`, with visible `:hover`/`:focus-visible`, and opens the floating window on click, Enter or Space — the same delegated-listener pattern `resources/js/work-board.js` already uses for its cards, not a native `<button>`.

People with no cards stay excluded, as before.

### Filter bar

Simplified again from the first build: search, Overdue, Blocked, Clear. The Status chips and the "More filters" disclosure (type, priority, project, label, due window) are gone from this bar entirely — those are task-level questions, not person-level ones, so they moved into the floating window instead (see below).

The search box matches **both** a person's name and any of that person's task titles (each person line carries a combined, lowercased haystack built server-side), so searching "payroll" surfaces the people who have payroll work, not just people literally named Payroll.

### The floating window

Clicking a person line opens a window listing just that person's tasks — the interaction that replaces both the old 3,400px lane scroll *and* the first build's 50-row table-plus-panel.

It reuses the personal board's `.wd-*` slide-over CSS **wholesale** (560px, `max-width: 94vw`, full width below 600px, `transform: translateX`, 280ms `cubic-bezier(.32,.72,0,1)`, the `prefers-reduced-motion` cross-fade) — the same visual language as `board.blade.php`'s own drawer, not a second one.

Contents: the person's avatar, name and `position · department` in the header, with a close button; a one-line summary of their counts; then their task lines. Every task line comes from `$teamRows`, rendered **once**, for **every** person, directly inside the window's markup — opening a person only toggles which of those already-rendered lines are visible (matching `data-owner-id` plus the window's own task-level filters). No fetch: the data was already on the page from the very first response.

Task-level filters live inside the window, scoped to that one person's lines only: type, priority, project, label, and a status control with Done excluded by default. These never touch the person table's own search/toggles/sort behind the window.

Everything in the window is read-only: no `<input>`, `<textarea>`, or `contenteditable` anywhere in it — only filter controls (`<select>`, chip `<button>`s) and the close button.

### Keyboard and focus

`role="dialog"`, `aria-modal="true"`, `aria-labelledby` pointing at the person's name heading. Focus moves to the window container itself on open (`tabindex="-1"` on the `<aside>`), never an inner element — same pattern as `work-board.js`'s `openCardCore()`. Escape closes it and returns focus to the person line that opened it. Tab is trapped inside while open, via the same algorithm as `work-board.js`'s `trapFocus()`.

### State survives opening and closing a window

Opening and closing the floating window only touches the window's own nested state (`win.*` in `resources/js/team-board.js`) — the person table's `search`, `overdueOnly`, `blockedOnly` and sort state are never read or written by `openWindow()`/`closeWindow()`. Verified in the browser: set the search to "payroll", open a person, close it, and both the search box's contents and the filtered person list are unchanged.

### Responsive

Below roughly 700px the person table's four number columns shrink to just what a one/two-digit count needs, handing the rest to Person. The floating window needs no team-board-specific responsive rule of its own — it already goes full-width below 600px via `.wd-*`. The page body never scrolls sideways at any width.

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
