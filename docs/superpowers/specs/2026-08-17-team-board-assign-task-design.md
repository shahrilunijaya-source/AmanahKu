# Team board — assign a task without leaving the screen

2026-08-17

## Problem

Today, assigning a task to someone requires: open Team board to see who's carrying
what → leave the screen → find that person's profile page → use the "Assign task"
form there (`resources/views/screens/profile.blade.php:142-220`, posting to
`WorkItemController::assign()`). Team board itself has no way to assign — its own
guide text calls it "read-only."

## Goal

Add "Assign task" directly on Team board: one popup form, reachable from two spots,
using the existing `assign()` endpoint unchanged.

## Non-goals

- Team board's person table stays scoped to people who already carry at least one
  open item — a zero-task employee still isn't a row. (Confirmed with the user:
  the "assign to anyone" entry point below is the accepted way to reach them,
  rather than expanding the table.)
- No live DOM patching of the new card into the table/window after assign. Team
  board's rows and window are fully server-rendered once per page load and
  client-filtered from there (`resources/js/team-board.js`) — splicing a new card
  into that in-memory state live (counts, sort order, window column, search index)
  is a lot of fragile plumbing for one write action. A classic form POST →
  redirect → fresh render is the same cost the profile-page flow already pays,
  and keeps this change small.
- No participants/labels/links/comments on the assign form — those are drawer
  features for an already-existing card. This form creates the card in one shot,
  same shape `assign()` already accepts.

## Entry points

1. **Top of page** — a button next to the existing "← My tasks" link. Opens the
   modal with the employee picker defaulted to the first person alphabetically.
   This is the "assign to anyone" path (any active employee in the tenant, not
   just people already listed in the table).
2. **Inside a person's window** — a button in the window header (`wd-head`, next
   to the close icon). Opens the same modal, pre-selecting that person. The
   picker stays editable — if you opened the wrong person's window, you can still
   redirect within the same modal instead of closing and reopening.

Both share one Alpine-driven modal; only the initial `employeeId` differs.

## Who sees it

Same gate as the profile page's existing form: `hasTenantRole($request, ['manager',
'management', 'hr'])` (director included — folds into `management` via
`Permissions::effectiveRole()`). Team board is visible to a wider set of people
(anyone `canSeeAll()` admits — management, HR, or any immediate superior), so the
button is conditionally rendered, not everyone who can view Team board can assign.

## Data

`BuildsWorkData::teamBoardData()` gains two keys, alongside the existing
`teamRows`/`teamPeople`:

- `canAssign` (bool) — the role check above.
- `assignableEmployees` — `Employee::active()->where('id', '!=', $self->id)
  ->orderBy('name')->get(['id','name','nickname','initials','avatar_color'])`,
  mapped to `{id, name, initials, color}` — same shape as `boardScreenData()`'s
  existing `people` roster (`BuildsWorkData.php:136-143`), same exclude-self
  convention. Deliberately **not** DataScope-restricted: neither `assign()`
  itself nor the profile page's `canAssign` check apply DataScope today, so this
  roster matches the actual authorization boundary rather than introducing a new,
  narrower one that would just be confusing (a manager could still open a
  DataScope-excluded person's profile and assign there).

No new route. `POST /app/board/assign/{employee}` (`work.assign`) is reused as-is;
the employee id is baked into the form's `action` attribute client-side (Alpine
template-string replace against a Blade-emitted URL with a placeholder segment),
same technique, no controller change.

## Form fields

Same set `assign()` already validates: title, type, priority, due date,
description. One addition — an "Assign to" picker (the roster above), since
team board's modal has no single implicit target the way the profile page's form
does.

Styled with the card drawer's existing classes rather than profile.blade.php's
raw inline styles — `.wd-props`/`.wd-plabel`/`.wd-pval`/`.wd-inline` for the
Assign to / Type / Priority / Due rows (`board.blade.php:196-230`), `.wd-desc`
for the description textarea, a `.wd-title`-weight text input for the title.
This is styling reuse only — not the drawer's autosave-per-field machinery
(`work-board.js`'s `drawer.*`), which assumes a card that already exists in the
database. This form still creates the card in one POST, same as today.

On a validation error (`$errors->getBag('assign')`), the page redirects back per
Laravel convention. To reopen the modal already pointed at the right person, the
form also carries the employee id as a hidden field purely for this
client-side re-hydration (`old('employee_id')` → Alpine's initial state) — the
controller doesn't read it; the URL path parameter is still what it actually acts
on.

## Overlay stacking

Team board already has two overlays sharing `.wd-scrim`/`.wd`-family CSS: the
board drawer (`board.blade.php`, z-index 61) and the person window
(`.tb-win-modal`, also z-index 61 — see `app.css:876-928`). A past incident
("Board .wd z-index trap") found these collide if a bare z-index gets bumped,
since three overlays across two screens share the same class names.

The assign modal can legitimately be open **while the person window is also
open** (entry point 2, above) — a real nested-overlay case, not a hypothetical.
So it gets its own scoped classes, not `.wd-scrim`/`.wd`/`.tb-win-modal`:
`.tb-assign-scrim` and `.tb-assign-modal`, each above the existing stack (e.g.
z-index 62/63). Internals (`wd-head`, `wd-ico`, `wd-body`, `wd-props`, etc.) are
still reused — only the two outer shell classes are new.

Escape handling: the person window's existing `@keydown.escape.window="win.show
&& closeWindow()"` gains one guard — `win.show && !assign.show && closeWindow()`
— so Escape closes the (topmost) assign modal first when both are open, rather
than closing the window out from under it.

## Guide text

`team-board.blade.php`'s guide currently opens with "A read-only, company-wide
view…". That line changes to drop "read-only" (EN + MS), since it's no longer
accurate for the three assign-permitted roles. No other guide changes.

## Files touched

- `app/Http/Controllers/Concerns/BuildsWorkData.php` — `teamBoardData()`.
- `resources/views/screens/team-board.blade.php` — two buttons, one modal, guide
  copy tweak.
- `resources/js/team-board.js` — `assign: { show, employeeId }` state, open/close
  methods, Escape-guard tweak.
- `resources/css/app.css` — `.tb-assign-scrim` / `.tb-assign-modal` rules.

No changes to `WorkItemController`, routes, or `work-board.js`.

## Testing

- Feature test: a manager/HR/management-role user posts to `work.assign` with a
  `assignableEmployees`-sourced id from the team board screen's own data — covers
  nothing new server-side (the endpoint is unchanged) but confirms the new
  `canAssign`/`assignableEmployees` keys are present and correctly gated for an
  `employee`-role viewer (absent) vs a `manager` (present).
- Existing `TeamBoardDataTest`/`TeamBoardScreenTest` extended for the new payload
  keys rather than new test files.
