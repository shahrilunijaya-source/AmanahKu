# Team-board kanban window

Date: 2026-08-07

## Goal

The all-staff "team board" screen (`resources/views/screens/team-board.blade.php`) shows one row per person; clicking a row opens a floating window listing that person's tasks as a flat list (`partials/team-board-row.blade.php`). Show that person's tasks as a simple, read-only kanban instead — the same 4-column shape the personal board already uses, reusing its card face — so a manager can see a person's work the way the person sees their own board.

## Scope

**In scope**
- The floating window's task list becomes a 4-column kanban (To Do / In Progress / In Review / Done), stacked vertically (the window is 560px wide — 4 side-by-side columns don't fit).
- Cards reuse `partials/work-card.blade.php` (`$compact = true`), the same partial the personal board renders, instead of `partials/team-board-row.blade.php`.
- Existing window filters (Type / Priority / Project / Label) still narrow the cards shown, scoped to whichever person's window is open — unchanged behavior, just applied to cards instead of list lines.
- Status chips (`toggleWinStatus()`, `win.statusFilter`) are removed — status is now the column itself, so filtering by it would just hide whole columns. Consequence: all 4 columns always show (the previous "Done excluded by default" behavior goes away — a Done *column* existing is not the same problem a Done *line* cluttering a flat list was).

**Out of scope — unchanged**
- The person-table itself: search, Overdue/Blocked toggles, sortable columns, click/Enter-to-open a row.
- Drag-and-drop, add-card, or any write path. This window stays strictly read-only, same as today.
- The person-level filters/summary line (`winSummary`, `winPersonSub`) above the task list.
- `teamBoardData()` and its return shape (`$teamRows`, `$teamPeople`) — no new query, no new data.

## Data

No server-side change. `teamBoardData()` (`app/Http/Controllers/Concerns/BuildsWorkData.php:181-243`) already returns `$teamRows` — every work item across every visible employee, each carrying `item` + owner id/name/initials/avatar_color. The Blade template groups these into 4 status buckets the same way `boardColumns()` already does for the personal board (`BuildsWorkData.php:161-171`), just against `$teamRows` instead of one employee's items.

## Changes

### `resources/views/partials/work-card.blade.php`

Add two data attributes so the client can filter these cards the same way it filtered `.tb-tline` rows. Both are optional and unused on the personal board (`$owner` isn't passed there):

```blade
@if ($owner ?? null)
    data-owner-id="{{ $owner['id'] }}"
@endif
data-priority="{{ $c->priority }}"
```

`data-priority` is unconditional (harmless everywhere); `data-owner-id` only renders when an `$owner` array is passed.

### `resources/views/screens/team-board.blade.php`

- Remove the status-chip loop (current lines 221-225).
- Replace the `winTaskBody` content: instead of one `@include('partials.team-board-row', ...)` per `$teamRows` entry, group `$teamRows` into 4 status buckets (`todo`/`prog`/`review`/`done`) and render each bucket as a stacked section — a heading, then `@include('partials.work-card', ['c' => $row['item'], 'compact' => true, 'owner' => [...]])` per card. Every card for every person still renders (same as today — the window only toggles visibility, never fetches), just grouped and using the kanban card face.

### `resources/js/team-board.js`

- Delete `win.statusFilter` state, its reset in `openWindow()`, and `toggleWinStatus()`.
- `applyWinFilter()`: selector moves from `[data-card-id]` to `[data-id]` (work-card's own attribute); drop the `statusFilter.includes(...)` clause; keep the owner/type/priority/project/label checks as-is (owner check now reads `data-owner-id` off the `.wc` card instead of `.tb-tline`).

### `resources/views/partials/team-board-row.blade.php`

Deleted — its only caller is the code being replaced.

### CSS (`resources/css/app.css`)

- A small addition for the stacked-column layout inside the window (heading + card list per status, vertical stack) — no existing class covers this shape.
- `.wc` sets `cursor: pointer` for the personal board's clickable cards. Add a scoped override (e.g. `.tb-win-kanban .wc { cursor: default; }`) since these cards aren't clickable, matching `work-card.blade.php`'s existing `@unless ($wcCompact) tabindex/role @endunless` guard that already skips making them focusable.

## Tests

`tests/Feature/TeamBoardScreenTest.php::test_every_task_is_present_in_the_markup` (line ~108-129) currently asserts `data-card-id="..."` markup and counts occurrences. Update both assertions to `data-id="..."` to match the new partial. No other test in this file touches the window's task markup; person-table tests are unaffected.
