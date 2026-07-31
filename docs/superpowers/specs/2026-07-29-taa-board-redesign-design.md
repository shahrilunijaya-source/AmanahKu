# T.A.A. board redesign — design

**Date:** 2026-07-29
**Surface:** `/app/board` (Tasks, Assignments & Adhoc), plus `/app/team-board` as a forced dependency
**Mode:** Operate — the visitor completes a task; scanability and consistency outrank expression
**Prototype:** `public/_proto-board.html` (delete once the port lands)

## Why

Two defects drove this, both found by reading the live screen with realistic data.

**The card face reads in the wrong order.** A card opens with up to three saturated pills — the type tag, then label chips — before the title appears fourth. Type and labels share a shape, a weight and a fill, so they compete with each other and both outrank the one thing a person is scanning for.

**The card modal has three save behaviours and signposts none of them.** The `Column` select PATCHes instantly. Comments post instantly. The other seven fields stage behind a `Save changes` button, and Escape throws them away. Nothing on screen tells you which field belongs to which rule.

A third finding came out of the label data. Three of the six labels duplicate a field the card already carries, and the duplicates can contradict the field they duplicate: a card can be labelled `Urgent` while its priority is `Low`, or labelled `Review` while sitting in To Do.

## Decisions

All confirmed with the user before this spec was written.

| Decision | Choice |
|---|---|
| Card detail shape | Right slide-over, 560px, matching the existing `messages-panel` grammar |
| Save model | Autosave every field, with a "Saved" tick |
| Card markup location | One Blade partial; write responses return the rendered HTML |
| Refresh blast radius | Targeted invalidation — only what depends on the changed field |
| `estimate_hours` | Column dropped |
| Priority on the card face | Rendered only when High |
| Label palette | Pruned to `blocked`, `client`, `internal` |
| Comment threading | Mentions, not replies |
| team-board | Ported to the same card partial |

## Scope

**In:** the card face, the card detail surface, the comment thread, the card partial extraction, team-board's adoption of that partial, the two data migrations, and the `?card=` deep link that mentions need.

**Out, deliberately:** the three-row filter panel, board search, the 272px column min-width that forces horizontal scroll on a 1366px laptop, and mobile's four-column sideways scroll. All are real problems. None are this change.

**Out, handed off:** avatar initials render at 9px and the green avatar colour puts white text at 4.3:1, under the 4.5:1 small-text minimum. That palette is app-wide, not board-specific. A separate session owns it.

## Architecture

### Files

| File | Change |
|---|---|
| `resources/views/partials/work-card.blade.php` | **New.** The only card template. Accepts `$c` and an optional `$compact`. |
| `resources/views/screens/board.blade.php` | Loop `@include`s the partial. Drawer replaces the modal. Page-level `<style>` block removed. |
| `resources/views/screens/team-board.blade.php` | `@include`s the partial with `$compact = true`. |
| `resources/css/app.css` | `.wc-*`, `.wa-*`, `.wd-*` added. `.wi-*` removed once nothing references it. |
| `resources/js/work-board.js` | `cardInner()` and `buildCardNode()` deleted. Drawer state, autosave, mention picker, targeted invalidation added. |
| `app/Http/Controllers/WorkItemController.php` | Write responses carry rendered card HTML. `estimate_hours` leaves validation. Mention parsing on comment create. |
| `app/Models/WorkItem.php` | `LABELS` reduced to three entries. |
| `app/Http/Controllers/Concerns/BuildsWorkData.php` | Board payload gains `mentionable` per card; `?card=` passed to the view. |
| `database/migrations/*_migrate_work_item_labels.php` | **New.** |
| `database/migrations/*_drop_estimate_hours_from_work_items.php` | **New.** |

### The card partial

`partials.work-card` is the single source of truth for a card's markup. It is rendered in three places and must produce identical output in all of them:

1. The initial board render, inside the column loop.
2. The team-board render, with `$compact = true`.
3. Every write response in `WorkItemController`, through one private helper.

The helper:

```php
private function cardHtml(WorkItem $item): string
{
    $item->loadMissing(['participants', 'projectRef', 'assignedBy'])->loadCount('comments');

    return view('partials.work-card', ['c' => $item])->render();
}
```

Client side, a repaint is `node.outerHTML = html`.

**Known trap:** assigning `outerHTML` destroys the original node. Any retained reference — notably the drawer's `modal.node` — becomes detached and silently stops working. Every swap must re-resolve the node by `[data-id="…"]` afterwards. SortableJS binds to the list container rather than to individual cards, so drag should survive a swap, but that must be verified by dragging a card that was just edited, not assumed.

### Targeted invalidation

Today, one save calls `repaintNode()` then `applyFilter()`, and `applyFilter()` walks every card on the board recomputing visibility and all four chip counts. Editing one card's due date touches all of them. Under autosave that runs on every field change rather than once per Save click.

The replacement is a map from changed field to what actually depends on it:

| Changed field | Repaints |
|---|---|
| `title`, `description`, `due_at`, `project_id`, `labels`, `participants` | That card's node only |
| `priority` | That card's node only — the face renders priority only at High, so the value can appear or vanish |
| `type` | That card's node, the chip counts, and that card's own filter visibility |
| `status` | That card's node, its move to the destination list, and both affected column counts |
| Comment added or deleted | That card's node (comment count) and the drawer thread |

`applyFilter()` survives, but only the filter controls may call it. A new `applyFilterTo(node)` recomputes a single card's visibility from its own `data-*` attributes.

### Drawer

Replaces the centre modal. Teleported to `body`, as the modal already is. Loads from the existing `GET /app/board/{workItem}`.

**Geometry.** 560px wide, `max-width: 94vw`, full height, right-anchored, `border-left` hairline, `box-shadow: -14px 0 44px rgba(31,30,26,.12)`, scrim `rgba(31,30,26,.18)`. Enter and exit travel the same path, `transform: translateX`, 280ms in and 260ms scrim, `cubic-bezier(.32,.72,0,1)` matching the existing `.uj-overlay-*` easing. Under `prefers-reduced-motion` it cross-fades with no transform. Below 600px it goes full width.

The house slide-over is 464px. This one is wider because a content-plus-rail split needs roughly 900px, which a drawer does not have, so properties sit in a single column beneath the title instead of in a rail. 560px is what makes the property rows and the comment thread both readable in one column.

**Layout, top to bottom.** Sticky header carrying the status segmented control, the "Saved" indicator, an overflow menu and close. Then the title as a click-to-edit heading, a subline (`Opened 12 Jul 2026 by Dev HR · #418`), the property rows, the description, and the comment thread. A docked composer sits on the panel floor so a long thread never hides it.

**Autosave.** Selects and status commit on `change`. Title and description commit on `blur` and after 600ms idle. Each commit PATCHes one field.

**Race guard.** A monotonically increasing sequence number accompanies each request. A response whose sequence is older than the last applied one is discarded without repainting. Without this, a slow title save landing after a fast priority save reverts the priority on screen while the database holds the correct value — the worst class of bug here, because the UI lies rather than errors.

**Failure.** An inline error strip inside the drawer. The field keeps focus and keeps the typed value. Nothing is silently discarded, which is the failure the old Save button already had.

**Keyboard and focus.** Escape closes the drawer, except while the mention picker is open, where it closes only the picker. Focus moves to the title on open and returns to the originating card on close. `role="dialog"`, `aria-modal="true"`, focus trapped inside while open.

**Read-only cards.** `modal.locked` currently greys fields and explains nothing. The drawer states the reason: who assigned the card, and that moving and commenting still work. Values render at full strength, since they are the card's truth rather than disabled chrome; only the affordance is removed.

**Removed from the detail surface.** The `Column` select — it duplicated drag, was named after the layout rather than the concept, and was the one control that already saved instantly. It becomes the header's status segmented control. `Delete` moves into the overflow menu; it currently sits one gap from Save.

### Card face

Reads title-first. Every existing fact is kept; only the emphasis changes.

- Type becomes a 5px dot plus a muted word, keeping all three colours but no longer sharing shape and weight with labels.
- Priority renders only at High.
- Labels are tinted — the hue at roughly 11% alpha with a 20% inset ring — rather than filled.
- The project moves from above the title into the footer, beside the due date, where the other facts already live.
- An absent due date reads "No due date" rather than leaving an empty row.
- Overdue keeps its `--error` treatment.

A colour spine was considered and rejected: a coloured left border above 1px is a house anti-pattern.

### Mentions

The card is already the thread, so replies are not built. At three comments a card, nesting a second thread inside the first buys little and costs a `parent_id`, a depth rule, collapse affordances, ordering rules, notification fan-out rules, and indentation inside a 560px column.

Mentions do the job replies were being asked to do, and need no new table.

**Client.** Typing `@` at a word boundary opens a picker above the composer. It filters as you type, arrow keys move, Enter or Tab inserts, and multi-word names insert whole. Escape closes only the picker.

**Candidate set.** Participants plus the assigner. Not the full roster. `authorizeAccess()` admits the owner, the assigner and participants only, so offering anyone else would notify a person about a card they get a 403 on. Adding people to a card is privileged (`syncParticipants` is manager/management/hr), so an ordinary employee could not resolve that themselves. When the set is empty the picker says so and points at the People row.

**Server.** The controller re-parses `@Name` against the same candidate set rather than trusting a client-supplied id list, then calls the existing `AppNotification::sendMany`. A crafted request cannot notify an outsider.

**Deep link.** Notifications need somewhere to land. `/app/board?card={id}` opens the board with that card's drawer already open. Without it the notification drops the person on the board and leaves them to hunt. The board screen reads the parameter once on mount; it is not written back to the URL by ordinary card opens beyond the existing `pushState` behaviour.

**Ambiguity, accepted.** Two people with the same name on one card produce an ambiguous `@Name`. Resolving it means storing resolved employee ids on the comment, which is a migration. Not built now. Revisit on the first real collision.

## Data

### Migration A — labels

Applied to every `work_items` row:

- `urgent` → set `priority = 'high'` where priority is `null`, `'low'` or `'medium'`; leave an existing `'high'` alone; remove the label.
- `waiting` → `blocked`, de-duplicating if the card already carries `blocked`.
- `review` → removed.

`WorkItem::LABELS` then holds `blocked`, `client`, `internal` only.

The mirrored `LABELS` constant in `work-board.js` is deleted rather than pruned. The card partial owns label rendering on the card face, and the drawer's label toggles receive the palette the same way the people roster already arrives — passed from Blade into the Alpine component as a prop. One server-side definition, no JS twin to drift.

The mapping lives in a plain function the migration calls, so it can be unit-tested without running a migration.

`review` maps to nothing and is genuinely deleted. Three rows carry it today.

### Migration B — drop `estimate_hours`

**13 of 49 rows currently hold a value.** Roughly half of those are seeded demo rows from this session, so call it seven real ones. The column drop is irreversible. `git revert` restores code, not rows.

**A `mysqldump` before this migration is a blocker in the plan, not a recommendation.** It is the only recovery path.

Migration B runs last, after the UI has stopped reading and writing the field, so a rollback at any earlier point cannot strand the schema.

## Testing

Feature tests:

- `PATCH /app/board/{item}` returns an `html` key containing the updated title.
- `PATCH` rejects `estimate_hours` as an unknown field.
- `POST /app/board/{item}/move` returns `html` plus updated counts.
- A comment mentioning a card participant creates `AppNotification` rows for exactly that set.
- A comment naming somebody not on the card creates no notification rows.
- A participant on a locked card can still move it and comment, and cannot PATCH its properties.
- `/app/board?card={id}` renders with that card's drawer open.

Unit test:

- The label mapping function, covering every combination: `urgent` alone, `urgent` on an already-high card, `waiting` alongside an existing `blocked`, `review` alone, and a card carrying all three.

Existing `WorkItem` tests must keep passing untouched. Run with `php artisan test --compact --filter=WorkItem`.

Browser verification, per stage, at 1440px and at 375px: no console errors, drag still works on a card that was just edited, the drawer traps focus, and the "Saved" tick appears on every field type.

## Implementation stages

Four stages, sequential. Each is independently verifiable and leaves the board working. The diff is reviewed between each.

**Stage 1 — the card face becomes one template.** Create `partials.work-card` rendering the new card face. Point both `board.blade.php` and `team-board.blade.php` at it. Move the CSS into `app.css`. Remove team-board's estimate cell.

`cardInner()` and `buildCardNode()` are deleted in this stage, not a later one. They cannot survive a card-face change without reproducing it, which is the duplication being removed. Their two callers — the add-a-card composer and the modal's repaint — are switched to the server-rendered HTML now: `cardHtml()` is added, and `store`, `move` and `update` return an `html` key.

The existing modal stays, still opening and still saving behind its Save button. Only its repaint path changes, from building markup to swapping in what the server returned. At the end of this stage the board looks new and behaves exactly as before.

**Stage 2 — drawer and autosave.** Replace the modal with the drawer. Add per-field autosave, the sequence guard, and the targeted-invalidation map. `applyFilter()` is demoted to the filter controls and `applyFilterTo(node)` takes over per-card visibility.

**Stage 3 — mentions.** Extend the card payload with `mentionable`. Add the picker, server-side parsing, `AppNotification::sendMany`, and the `?card=` deep link.

**Stage 4 — migrations.** `mysqldump` first. Then Migration A, then Migration B. Prune `WorkItem::LABELS`.

Finally, delete `public/_proto-board.html`. It is a tracked path and must not reach `main`.

## Risks

| Risk | Mitigation |
|---|---|
| `outerHTML` swap detaches `modal.node` and the drawer silently stops updating its card | Re-resolve by `[data-id]` after every swap; test by editing a card and immediately dragging it |
| Out-of-order autosave responses revert a field on screen while the database is correct | Monotonic sequence guard; discard stale responses |
| Dropping `estimate_hours` destroys real data | `mysqldump` gate before Migration B; migration runs last |
| Deleting `.wi-*` breaks a screen nobody checked | Stage 1 ports both known consumers; grep for `.wi-` across `resources/` before deleting the CSS |
| Autosave makes a card jump or vanish mid-edit when a change crosses the active filter | `applyFilterTo(node)` recomputes one card, and only on `type` change |
| The prototype file reaching `main` | Deleted in Stage 4; called out in the plan |
