# Timesheet report — full-width drill-down

2026-08-18 (revised after design / a11y / motion review)

## Problem

`resources/views/screens/timesheet-reports.blade.php`, "Where time went" tab.

**The rail is cramped.** The bar list (`.uj-tr-lens`, left column) and the drill-down
rail (`.uj-tr-panel`, right column) sit side by side in a `340px`-wide sidebar
(`app.css:1864` `.uj-tr-lens { grid-template-columns: 1fr 340px; }`). When a person's
entries run long, that 340px column scrolls internally — cramped, easy to miss rows,
unpleasant to read.

**The rail pre-populates before any click.** `sel` initializes to
`{ kind: 'slice', key: null, from: null }`, and `currentSlice()` / `personToDisplay()`
both fall back to the first row when nothing is selected
(`timesheet-reports.blade.php:48-57`). So on page load the panel is already showing a
slice's members (or, for the "By person" lens, the first person's full week-by-week
detail). Nobody asked for that detail yet, and it is part of what makes the screen
feel busy on arrival.

**The top of the screen repeats itself.** Measured live (HR, Aug 2026, 4 categories):
the `60` hero figure is repeated verbatim in a `60 PERSON-DAYS` chip 40px below it,
and `133 WEEKS NOT IN` reads as an alarm with no meaning attached until you reach the
explanatory note far below the bars. Between the shelf and the first bar there are
eight controls (four filter fields, Apply, the date summary, three lens pills). The
rail is not the only thing making this screen unfocused.

## Goal

Make the drill-down a full-width takeover, and make the screen above it quieter:

- Nothing selected by default — bar list only, full width.
- Click a bar → bars hide, full-width member list (category/project lens) or
  full-width entries (person lens, which has no member-list step).
- Click a member → bars and member list hide, full-width entries for that person.
- Breadcrumb wayfinding at every drill level, not just a one-step back arrow.
- Browser Back works, via `pushState` **and an explicit `popstate` handler**.
- Focus follows the view change, in both directions.
- The shelf and filter row collapse to one summary line while drilled in.

Frontend-only. `TimesheetController::reportData()` (`app/Http/Controllers/
TimesheetController.php:382-679`) is untouched — same payload, same filters, same
shelf data, same "This week" tab. Pure Blade/Alpine/CSS relayout.

## Non-goals

- "This week" compliance tab — unchanged apart from `setTab()` clearing drill params
  (see *History*).
- Lens pills (By category/project/person) — behaviour unchanged; they gain
  `aria-pressed` and hide while drilled in.
- No export (PDF/Excel/CSV) — confirmed out of scope with the user; the timesheet
  module has none today and this revamp does not add it.
- Cost visibility (`canSeeCost`, `TimesheetController.php:45-48`) needs no new gating
  logic: `canSeeCost()` is true for `management`/`hr` roles, and access to this whole
  screen is already restricted to `management`/`hr` (`AppController.php:171-173`).
  Everyone who can open the report can already see cost — the flag is a no-op on this
  screen. The full-width views carry `row.cost` / `p.rate` straight through unchanged.

### Scope widened since the first draft

The original spec listed the shelf and filter row as non-goals. Review found they are
the larger source of the "too much on one screen" feeling the revamp exists to fix, so
two shelf changes are now **in scope**:

1. Drop the `PERSON-DAYS` chip. It duplicates the hero figure exactly. Zero risk.
2. Collapse shelf + filter row to a single summary line while `sel.view !== 'bars'`.

Everything else about the shelf (hero figure, `PER HEAD`, warn chips, lede) stays.

## State model

Replace the implicit-default `sel` with an explicit three-state view, and drop the
"select first row automatically" fallback:

```js
sel: { view: 'bars', key: null, from: null },   // was: { kind: 'slice', key: null, from: null }
returnFocusTo: null,                            // row id to restore focus to on back
```

- `view: 'bars'` — default. Full-width bar list, no panel.
- `view: 'slice'` — full-width member list for one category/project. Only reachable
  from `lens !== 'staff'`.
- `view: 'person'` — full-width entries for one person. `from` holds the slice id to
  return to (category/project lens), or `null` (person lens, or reached directly).

```js
slice(key) {
    this.returnFocusTo = this.sel.view === 'bars' ? key : null
    this.sel = { view: 'slice', key, from: null }
    this.pushUrl()
    this.focusHeading()
},
openPerson(id, from) {
    this.returnFocusTo = id
    this.sel = { view: 'person', key: id, from }
    this.pushUrl()
    this.focusHeading()
},
back() {
    const target = this.sel.view === 'person' && this.sel.from
        ? { view: 'slice', key: this.sel.from, from: null }
        : { view: 'bars', key: null, from: null }
    const restore = this.sel.key
    this.sel = target
    this.pushUrl()
    this.$nextTick(() => this.focusRow(restore) || this.focusHeading())
},
```

`currentSlice()` and `personToDisplay()` drop their `rs[0]` / `staff[0]` fallback —
they return `null` when `sel.key` is `null`, which is now a real state
(`view: 'bars'`) instead of always resolving to something.

`setLens()` resets `sel` to `{ view: 'bars', key: null, from: null }`, same as today.

**Dead code this removes:** once bars are hidden during drill, the `:data-on`
selected-row expression on `.uj-tr-lensrow` (`timesheet-reports.blade.php:409-412`,
the nested ternary over `personToDisplay()` / `sel.from` / `currentSlice()`) can never
be true for a selected row. Delete it. Row selection is no longer a visual state.

## Layout

`.uj-tr-lens` stops being a permanent two-column grid. It renders one of:

- `sel.view === 'bars'` → the bar list only, full width (no `.uj-tr-panel` in the DOM).
- `sel.view === 'slice'` → `.uj-tr-panel` full width in place of the bar list.
- `sel.view === 'person'` → same, full-width entries.

`.uj-tr-panel`'s `position: sticky` no longer applies (no adjacent column to scroll
past); drop it along with the now-dead 340px column rule in `.uj-tr-lens`. The mobile
breakpoint override at `app.css:1898-1899` (`grid-template-columns: 1fr` /
`position: static`) becomes the only-ever layout, so those two lines fold into the
base rule and the `@media` block loses them.

Drop the dead `uj-tr-wrap` class (`timesheet-reports.blade.php:34`) while touching
this markup — no CSS rule targets it since `93494a2`.

### Drill header: breadcrumb, not a lone back arrow

A bare "← Back" is two presses to get from a person back to bars, with no indication
of where you are in between. The drill header instead carries a breadcrumb plus the
slice's own share, so the anchor the bar list provided is not lost:

```
← All categories / Maintenance          35.5 md · RM 19,800.60 · 59%
[███████████████████░░░░░░░░░░░░]
```

- Each breadcrumb segment before the last is a button; the last is plain text.
- The leading `←` is part of the first segment's button, and that button is the
  same target `back()` reaches. One control, two affordances.
- The share line reuses `formatSliceSubline()` plus the `pct`, and the slim bar
  reuses `.uj-tr-bar` with the lens colour already computed for the bar list.
- At `view: 'person'` reached from a slice, breadcrumb is
  `← All categories / Maintenance / Shazwan`, and the share line shows the person's
  own `md` / rate rather than the slice's.
- At `view: 'person'` in the staff lens (no slice step), breadcrumb is
  `← All people / Shazwan`.

The breadcrumb bar is the one thing worth keeping `position: sticky` — at
`view: 'person'` the entry list runs long, and losing the back control at the top of
a long scroll is the exact problem the rail had.

### Member rows become bar rows

At 340px, member rows had to be text-only (`5 md · 14%`). At full width there is
room for the same `.uj-tr-bar` treatment the top-level rows get. Same row shape at
every level, only the label changes. This is the main reason full width is worth
doing at all — not just more space for the same cramped list.

Member rows keep `.uj-tr-lensrow` but drop the inline `padding:11px 0` override and
use the class's own `13px 18px`, so a row is a row at every level.

### Shelf and filter collapse while drilled

While `sel.view !== 'bars'`:

- `.uj-tr-shelf` and the filter `<form>` are replaced by one line:
  `1 – 31 Aug 2026 · Maintenance` (period, plus any active category/project filter).
- The lens pills hide. You are inside one lens; switching lens from inside a drill
  has no coherent meaning and today it silently resets `sel`.
- Returning to `view: 'bars'` restores all three.

The `PERSON-DAYS` chip is deleted outright, at every view. It is the hero figure again.

## History, URL sync, and partial-nav

This is the part the first draft got wrong. It claimed `pushState` alone would make
the browser Back button work, "same idiom the tab switch already uses". Two errors:
`setTab()` uses `replaceState`, not `pushState`, and **nothing on this screen listens
for `popstate`**. `pushState` changes the URL on Back; without a listener nothing
re-derives `sel`, so the URL moves and the screen does not.

### URL shape

- `?tab=report` — bars (no `view` param).
- `?tab=report&view=slice&lens=category&id=3`
- `?tab=report&view=person&lens=category&id=42&pid=3`
- `?tab=report&view=person&lens=staff&id=42` (person lens, no `pid`)

Query key is `pid` ("parent id"), not `from` — `from` is already the report's
date-range-start param (`TimesheetController::periodFromRequest()`), and this
collision wasn't caught until implementation: reaching a person-from-slice URL
made the server try to `Carbon::parse()` a category id as a date and 500.

### The mechanism: let partial-nav own Back

`resources/js/partial-nav.js` already owns a global `popstate` listener
(`partial-nav.js:121`) and re-fetches the screen for any history entry whose state
carries `partialNav: true`. Drill entries join that scheme rather than competing with
it:

- `pushUrl()` pushes with `history.pushState({ partialNav: true }, '', url)`.
- **This screen adds no `popstate` listener of its own.** Back and Forward are handled
  entirely by partial-nav's `go()`, which re-fetches and re-renders the screen; the
  Alpine component's `init()` then reads `readSel()` from the URL and lands on the
  right view.
- `readSel()` therefore has exactly one caller: `init()`. It parses `view`/`lens`/
  `id`/`from`, falling back to `bars` when `view` is absent or the referenced id is
  not in the current `rows()` (e.g. a filter change shrank the result set).
  `pushUrl()` is its inverse.

**Why not a local `popstate` handler.** A local handler is faster (no fetch) but
cannot be made correct. Partial-nav swaps the whole of `<main>` when you click a nav
link, which destroys this Alpine component while leaving any `window` listener it
registered alive and pointing at detached nodes. Back from another screen onto a
drill entry would then hit a listener whose elements no longer exist: URL says
`view=person`, page still shows the other screen, nothing recovers it. Only a fetch
restores the view once `<main>` has been swapped. `messages.blade.php:168-176` pushes
`{ q: query }` and registers its own listener, so it carries this same latent bug —
it is precedent, not a solved pattern, and this screen should not copy it.

**What it costs.** One partial fetch per browser Back or Forward press, and the screen
re-renders from scratch, so the bar-grow animation plays again on arrival. In-app
navigation (`back()`, the breadcrumb, `Escape`) is still pure local state with no
fetch, which is the common path. Accepted: a re-render that always shows the right
thing beats a local handler with a broken case.

### setTab must clear drill params

`setTab()` today keeps every existing query param. Switching to "This week" while at
`view=person` leaves `view`, `lens`, `id` and `from` in the URL, and a link copied
from there restores nonsense. `setTab()` deletes all four alongside setting `tab`.
It keeps using `replaceState` — a tab switch is not a Back-button step.

## Focus and keyboard

A takeover with no focus management is broken: press Enter on a bar, the bar list
disappears, focus falls to `<body>`, nothing is announced.

- **Drill in** → focus the drill heading. Give the `<h3>` `tabindex="-1"` and call
  `.focus()` on `$nextTick`.
- **Back (in-app)** → focus the row you came from, matched by id; fall back to the
  heading if that row is no longer rendered.
- **Browser Back / Forward** → nothing to do here. The screen re-renders through
  partial-nav and `init()` restores the view; focus resets with the render, same as
  any other partial navigation on this app.
- **`Escape`** → `back()`. Natural for a takeover, costs one `@keydown.escape.window`.

## Motion

The existing `uj-tr-panel-in` keyframe was written for a rail sliding in from the
right beside stationary content. A view swap is a different move.

| Before | After | Why |
| --- | --- | --- |
| `uj-tr-panel-in` `translateX(14px)` always | forward `translateX(12px)`, back `translateX(-12px)` | Motion follows direction of travel; back must reverse, not repeat the arrival |
| One entry animation for every `sel` change | Reverse direction on `back()` / breadcrumb / `Escape` | Back is a return, not an arrival. Repeating the arrival move makes the control feel wrong. Browser Back re-renders the screen and plays the normal load animation — out of scope to special-case |
| `.3s` | `.2s` | Under 300ms for UI. 300ms was defensible for a rail beside content, not for a view swap |
| `filter: blur(3px)` | keep | Correct as-is. Masks the crossfade between two full-width states, exactly where blur earns its cost |
| `.uj-tr-anim .uj-tr-bar i` grow replays every return to bars | run once per page load — drop `.uj-tr-anim` from the card after first paint | Back gets pressed tens of times per session. Re-growing four bars each time is the over-animated case |
| `animation-delay: index * 45ms`, uncapped | keep 45ms, cap total at 200ms | Fine at 4 categories; 14 projects gives 630ms of stagger |
| Back control `height:28px; padding:0 10px`, glyph `←` only | real `← Back` text, 44px min height on coarse pointers | 28px fails WCAG 2.5.5; it is now primary navigation, not a corner affordance |
| `.uj-tr-btn:active` changes `background` only | add `transform: scale(0.97)` | Pressed feedback on the control used at every level |

`--ease: cubic-bezier(.23, 1, .32, 1)` is already a strong ease-out — keep it. Extend
the existing `prefers-reduced-motion` block to cover the new directional transform
(fade only, no translate).

## Accessibility

Contrast was measured against the live tokens and needs no changes: `--muted #6f6c61`
on white is 5.3:1 (passes at 11px), and the `--info` bar fill on the `--hairline-soft`
track is 4.6:1.

Everything below is structure and keyboard.

| # | Issue | Criterion | Severity | Fix |
|---|---|---|---|---|
| 1 | Focus never moves on drill or back | 2.4.3 Focus Order | Critical | See *Focus and keyboard* |
| 2 | Tabs carry `role="tablist"`/`role="tab"` but no `aria-controls`, no `id`, no arrow-key handling, no roving `tabindex`; panels have `role="tabpanel"` with no `aria-labelledby` and no `tabindex="0"` | 4.1.2 Name, Role, Value | Critical | Wire `id` / `aria-controls` / `aria-labelledby`, add a Left/Right arrow handler, `tabindex="0"` on panels |
| 3 | Lens pills carry only `:data-on`, no `aria-pressed` | 4.1.2 | Major | `:aria-pressed="lens==='category'"` on each |
| 4 | View change is not announced — clicking a bar swaps the whole region silently | 4.1.3 Status Messages | Major | Fix 1 covers most of it; add `aria-live="polite"` on the drill container if testing shows a gap |
| 5 | Back control is 28px tall | 2.5.5 Target Size | Major | 44px minimum on coarse pointers |
| 6 | `aria-label="Break down by"` and `aria-label="Timesheet detail"` are English-only on a bilingual screen | 3.1.2 Language of Parts | Minor | `:aria-label` with the `$store.ui.lang` ternary, same as every visible string |
| 7 | Bar fill `<i>` is exposed to screen readers as an empty element | 1.1.1 | Minor | `aria-hidden="true"` |
| 8 | Back control named only "Back" | 2.4.6 Headings and Labels | Minor | Name the destination: `← Back to categories`, `← Back to Maintenance` |

Item 2 predates this work but the file is open, so it is in scope here.

## Bilingual

New strings, all needing the `$store.ui.lang==='en' ? … : …` treatment:

- `← Back` / `← Kembali`, plus the destination-naming variants for `aria-label`.
- Breadcrumb roots: `All categories` / `Semua kategori`, `All projects` /
  `Semua projek`, `All people` / `Semua individu`.
- Empty-state lines below.

Existing panel copy (headers, notes, missing-weeks message) carries over unchanged.
`view: 'bars'` is the existing bar list with no panel beside it, so it needs no copy
of its own.

Known pre-existing wart, not fixed here: money is formatted with
`toLocaleString('en-MY')` regardless of language. Leave it.

## Edge cases

- **Person with no submitted weeks.** `weeks[p.id]` is empty, so `view: 'person'`
  renders a header and nothing under it. Add a line:
  "No submitted lines in this period." / "Tiada baris dihantar dalam tempoh ini."
- **Deep link to a stale id.** Falling back to bars silently reads as a broken link.
  Show a one-line dismissible notice above the bars: "That row is not in the current
  period or filter." / "Baris itu tiada dalam tempoh atau tapisan semasa."
- **Long entry notes.** `x-html="line.note"` renders sanitized rich text
  (`HtmlSanitizer::clean` on write, `TimesheetController.php:350` — safe, not a
  vector). At 340px it wrapped; at full width a long note runs the whole page width.
  Clamp the note column to `max-width: 64ch`, matching `.uj-tr-note`.
- **Slice with zero members.** Cannot arise from `reportData()` today (bars are built
  from grouped members), but render the same empty line rather than a bare header.

## Testing

No backend change, so no PHPUnit coverage to add — `reportData()` and its existing
tests are untouched. Verify by hand in the browser:

1. Default load shows bars only, no panel in the DOM.
2. Category → member → person and back, via the breadcrumb, the `←` control, and
   `Escape`.
3. Browser Back at each level moves the view, not just the URL. A partial re-fetch
   here is expected, not a bug. The case that must not break: drill in, click a nav
   link to another screen, then Back twice — both presses must land on the drill view
   and then on bars, with the screen actually changing each time.
4. Direct link to a `view=person` URL opens straight into that state.
5. Direct link to a `view=slice` URL whose id is filtered out lands on bars with the
   notice.
6. Switch to "This week" while drilled in; confirm the URL loses `view`/`lens`/`id`/
   `from`.
7. Keyboard only: Tab to a bar, Enter, confirm focus lands on the drill heading;
   `Escape`, confirm focus returns to the row.
8. Bar-grow animation plays once per page load, not on every return to bars.
9. `prefers-reduced-motion` on: no translate, fade only.
10. Mobile (already single-column) unchanged; back control is 44px tall.
