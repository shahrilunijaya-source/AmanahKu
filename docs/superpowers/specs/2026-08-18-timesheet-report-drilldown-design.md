# Timesheet report — full-width drill-down

2026-08-18

## Problem

`resources/views/screens/timesheet-reports.blade.php`, "Where time went" tab. The
bar list (`.uj-tr-lens`, left column) and the drill-down rail (`.uj-tr-panel`,
right column) sit side by side in a `340px`-wide sidebar
(`app.css:1864` `.uj-tr-lens { grid-template-columns: 1fr 340px; }`). When a
person's entries run long, that 340px column scrolls internally — cramped,
easy to miss rows, unpleasant to read.

The rail also pre-populates before any click: `sel` initializes to
`{ kind: 'slice', key: null, from: null }`, and `currentSlice()` /
`personToDisplay()` both fall back to the first row when nothing is selected
(`timesheet-reports.blade.php:48-57`). So on page load, the panel is already
showing a slice's members (or, for the "By person" lens, the first person's
full week-by-week detail) — nobody asked for that detail yet, and it's part of
what makes the screen feel busy on arrival.

## Goal

Make the drill-down a full-width takeover instead of a squeezed sidebar:
- Nothing selected by default — bar list only, full width.
- Click a bar → bars hide, full-width member list (category/project lens) or
  full-width entries (person lens, which has no member-list step).
- Click a member → bars and member list hide, full-width entries for that
  person.
- A "← Back" control at every level, one step back.
- Browser Back button also works, via `pushState` — same idiom the tab switch
  already uses (`setTab()`, `timesheet-reports.blade.php:71-76`).

Frontend-only. `TimesheetController::reportData()` (`app/Http/Controllers/
TimesheetController.php:382-679`) is untouched — same payload, same filters,
same shelf, same "This week" tab. Pure Blade/Alpine relayout of the report
tab's bar-list-plus-panel section.

## Non-goals

- "This week" compliance tab — unchanged.
- Shelf, filter row, lens pills (By category/project/person) — unchanged.
- No export (PDF/Excel/CSV) — confirmed out of scope with the user; the
  timesheet module has none today and this revamp doesn't add it.
- Cost visibility (`canSeeCost`, `TimesheetController.php:45-48`) needs no new
  gating logic: `canSeeCost()` is true for `management`/`hr` roles, and access
  to this whole screen is already restricted to `management`/`hr`
  (`AppController.php:171-173`). Everyone who can open the report can already
  see cost — the flag is a no-op on this screen. The full-width views carry
  `row.cost` / `p.rate` straight through unchanged, same as today.

## State model

Replace the implicit-default `sel` with an explicit three-state view, and
drop the "select first row automatically" fallback:

```js
sel: { view: 'bars', key: null, from: null }   // was: { kind: 'slice', key: null, from: null }
```

- `view: 'bars'` — default. Full-width bar list, no panel.
- `view: 'slice'` — full-width member list for one category/project.
  Only reachable from `lens !== 'staff'`.
- `view: 'person'` — full-width entries for one person. `from` holds the
  slice id to return to (category/project lens), or `null` (person lens,
  or reached directly with no slice step).

```js
slice(key)        { this.sel = { view: 'slice', key, from: null } },
openPerson(id, from) { this.sel = { view: 'person', key: id, from } },
back() {
    this.sel = this.sel.view === 'person' && this.sel.from
        ? { view: 'slice', key: this.sel.from, from: null }
        : { view: 'bars', key: null, from: null }
},
```

`currentSlice()` and `personToDisplay()` drop their `rs[0]` / `staff[0]`
fallback — they return `null` when `sel.key` is `null`, which is now a real
state (`view: 'bars'`) instead of always resolving to something.

`setLens()` resets `sel` to `{ view: 'bars', key: null, from: null }`,
same as today.

## Layout

`.uj-tr-lens` stops being a permanent two-column grid. It renders one of:

- `sel.view === 'bars'` → the bar list only, full width (no `.uj-tr-panel`
  in the DOM at all).
- `sel.view === 'slice'` → `.uj-tr-panel` full width in place of the bar
  list, with a "← Back" button in its header that calls `back()`.
- `sel.view === 'person'` → same, full-width entries, "← Back" button
  always shown (unlike today, where the back arrow only renders `x-if
  sel.from`) — it now always has somewhere to go back to.

`.uj-tr-panel`'s `position: sticky` no longer applies (no adjacent column to
scroll past); drop it along with the now-dead 340px column rule in
`.uj-tr-lens`. The mobile breakpoint override at `app.css:1898-1899`
(`grid-template-columns: 1fr` / `position: static`) becomes the only-ever
layout, so those two lines fold into the base rule and the `@media` block
loses them.

Drop the dead `uj-tr-wrap` class (`timesheet-reports.blade.php:34`) while
touching this markup — no CSS rule targets it since `93494a2`.

## URL sync

`pushState` (not `replaceState` — each drill level is a real Back-button
step, unlike the tab switch which replaces) keeps `sel` in the URL:

- `?tab=report` — bars (no `view` param).
- `?tab=report&view=slice&lens=category&id=3`
- `?tab=report&view=person&lens=category&id=42&from=3`
- `?tab=report&view=person&lens=staff&id=42` (person lens, no `from`)

On load, `sel` initializes from these params (falling back to `bars` if
absent or the referenced id isn't in the current `rows()`, e.g. after a
filter change shrinks the result set). `slice()`, `openPerson()`, and
`back()` all push a URL alongside the state change.

## Bilingual

Only new string is the always-visible "← Back" button — it needs the same
`$store.ui.lang==='en' ? … : …` treatment as the rest of the view. `view:
'bars'` is just the existing bar list with no panel next to it, so it needs
no new copy of its own. Existing panel copy (headers, notes, missing-weeks
message) carries over unchanged.

## Testing

No backend change, so no PHPUnit coverage to add — `reportData()` and its
existing tests are untouched. Verify by hand in the browser: default load
shows bars only, click through category → member → person and back via both
the in-app back button and the browser Back button, confirm the URL updates
at each step and a direct link to a `view=person` URL opens straight into
that state, confirm mobile (already single-column) still works unchanged.
