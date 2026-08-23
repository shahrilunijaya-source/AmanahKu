# Timesheet Review tab — weekly entry panel

2026-08-18

## Problem

`resources/views/screens/timesheets.blade.php`, Review tab (`x-show="tab==='review'"`,
lines 755-846). Today this tab shows no entries at all:

- A "My weeks" list (lines 760-777) — week label + status + RM cost per week, gated
  behind `canSeeCost` (money roles only per `TimesheetController::canSeeCost()`,
  `TimesheetController.php:45-48`). Plain rows, no click, no detail.
- A "My time spent" breakdown card (lines 780-842) — category/project split by
  person-days over a `From`/`To` date-range filter. No per-week or per-day view.

There is no way to see a given week's actual entries from the Review tab, no week
prev/next nav, and no path from a viewed entry into editing it. All editing lives on
the Record tab, which shows one week at a time via its own day-strip nav.

## Goal

Replace the Review tab's content with a read-only week-by-week entry view:

- Prev/next arrows **and** a week picker (dropdown) to jump between weeks.
- All of the employee's own weeks, entries grouped by day, no time bound (this is one
  person's data — small by construction, unlike the all-staff report this pattern is
  borrowed from).
- Visible to every employee, not just money roles — the view carries no cost figures,
  so `canSeeCost` gating no longer applies here.
- Click an entry → jump to the Record tab, on that entry's week, with that entry's
  edit form already open — unless the week is submitted (locked), in which case land
  on the week only; there is nothing to edit until the week is recalled.

## Non-goals

- No new backend authorization: `screenData()` already scopes everything to the
  request's own `$employee`. This feature adds no cross-employee visibility.
- No changes to Record tab's own day-strip nav, submit flow, or the pre-submit review
  pane (`$store.tsReview`) — unrelated to this "Review" tab despite the name overlap.
- No cost figures anywhere in this view. The dropped "My weeks" list showed RM per
  week; nothing here replaces that number. (If money roles still need a per-week cost
  figure, that is a separate ask — not raised, not built.)
- `myBreakdown` / `personalBreakdown()` (category/project split) is deleted along with
  its card, not repurposed.

## Reused pattern

`resources/views/screens/timesheet-reports.blade.php` (lines 443-499) shipped days ago
with almost exactly this shape, for viewing *another* person's weeks from the all-staff
report: preload that person's weeks client-side, step through them with
`weekIdx`/`prevWeek()`/`nextWeek()`, no network round-trip per step, entries grouped by
day. Same CSS already exists and is reused as-is: `.uj-tr-weeknav*`, `.uj-tr-wk`,
`.uj-tr-ent*`, `.uj-tr-empty` (`resources/css/app.css:1861-1917`). The week-block
building loop that feeds it (`TimesheetController.php:582-637`, currently inline in
`reportData()`) is extracted into a shared private method and reused for the
employee's own weeks.

## Backend

### Extract `buildWeekBlocks()`

Pull `TimesheetController.php:582-637` out of `reportData()` into:

```php
/**
 * @param  Collection<int, TimesheetEntry>  $entries
 * @return array<int, array{label: string, dates: string, weekStart: string, status: ?string, days: float, cost: float, lines: array}>
 */
private function buildWeekBlocks(Collection $entries, ?Collection $timesheetsByWeekStart = null): array
```

Two additions beyond what `reportData()` needs today:

- Each week block gets `weekStart` (the date string already computed as the loop's
  array key — currently discarded, now kept) and `status` (looked up from
  `$timesheetsByWeekStart`, keyed by `week_start` date string; `reportData()` passes
  `null` and the field comes back `null`, which it already ignores).
- Each **line** gets `'id' => $e->id`.

`reportData()`'s call site is otherwise unchanged — same output for `staffWeeks`.

### `screenData()`: add `myWeeks`

```php
$myWeeks = $employee
    ? $this->buildWeekBlocks(
        $myTimesheets->flatMap->entries,   // already eager-loaded via $with, line 86 — no extra query
        $myTimesheets->keyBy(fn (Timesheet $t) => $t->week_start->toDateString()),
    )
    : [];
```

Add `'myWeeks' => $myWeeks` to the returned array (`TimesheetController.php:167-191`).
Drop `myBreakdown`, `breakdownFrom`, `breakdownTo` from that same return — nothing
reads them once the breakdown card is gone. Drop the `personalBreakdown()` call at
line 105 and the method itself (lines 719-756, per grep) since nothing else calls it.

### Record tab: entry id on existing rows, for the edit-link to resolve

`existingGrid` (`TimesheetController.php:112-127`) already excludes
system-generated lines (`if ($e->source !== null) continue;`) — only the employee's
own manually-entered rows reach the Record tab's editable grid. It does not currently
carry the entry's id. Add it:

```php
$existingGrid[$e->entry_date->toDateString()][] = [
    'id' => $e->id,
    'category_id' => $e->category_id,
    ...
];
```

## Review tab UI

Replace lines 755-846 wholesale. New markup, in the same spot:

```blade
<div x-show="tab==='review'" x-data="{
        weekIdx: {{ Js::from(count($myWeeks) - 1) }},   {{-- default: most recent week --}}
        weekDir: 'fwd',
        weeks: {{ Js::from($myWeeks) }},
        get currentWeek() { return this.weeks[this.weekIdx] || null },
        prevWeek() { if (this.weekIdx > 0) { this.weekDir = 'back'; this.weekIdx-- } },
        nextWeek() { if (this.weekIdx < this.weeks.length - 1) { this.weekDir = 'fwd'; this.weekIdx++ } },
    }">
    <template x-if="weeks.length === 0">
        <div class="uj-tr-empty">{{-- "No weeks yet." / bilingual --}}</div>
    </template>
    <template x-if="weeks.length > 0">
        <div class="uj-tr-panel">
            <div class="uj-tr-weeknav-hd">
                <button type="button" class="uj-tr-weeknav-btn" @click="prevWeek()" :disabled="weekIdx === 0">&lsaquo;</button>
                <span class="uj-tr-weeknav-pos" x-text="(weekIdx + 1) + ' / ' + weeks.length"></span>
                <button type="button" class="uj-tr-weeknav-btn" @click="nextWeek()" :disabled="weekIdx === weeks.length - 1">&rsaquo;</button>
            </div>
            <select class="uj-tr-weekpick" x-model.number="weekIdx">
                <template x-for="(w, i) in weeks" :key="i">
                    <option :value="i" x-text="w.label + ' — ' + w.dates"></option>
                </template>
            </select>
            <template x-for="wk in (currentWeek ? [currentWeek] : [])" :key="weekIdx">
                <div class="uj-tr-wk" :data-dir="weekDir">
                    <div class="hdr">
                        <span x-text="wk.label + ' · ' + wk.dates"></span>
                        <span>
                            <span class="uj-tr-status-badge" :data-status="wk.status || 'draft'"
                                x-text="wk.status === 'submitted' ? ($store.ui.lang==='en' ? 'Submitted' : 'Dihantar') : ($store.ui.lang==='en' ? 'Draft' : 'Draf')"></span>
                            <span x-text="fmtDays(wk.days) + ' md'"></span>
                        </span>
                    </div>
                    <template x-if="wk.lines.length === 0">
                        <div class="uj-tr-empty">{{-- "No entries this week." --}}</div>
                    </template>
                    <template x-for="(line, lidx) in wk.lines" :key="lidx">
                        {{-- source-generated lines (leave/holiday) have no id and are not
                             clickable — see "Locked / system-generated lines" below --}}
                        <a x-show="line.id" class="uj-tr-ent" :href="reviewEntryUrl(wk, line)">
                            <div class="uj-tr-ent-row"> ... same fields as the report's uj-tr-ent ... </div>
                        </a>
                        <div x-show="!line.id" class="uj-tr-ent uj-tr-ent--static"> ... same fields, no link ... </div>
                    </template>
                    <template x-if="wk.status === 'submitted'">
                        <div class="uj-tr-note">{{-- "This week is submitted..." --}}</div>
                    </template>
                </div>
            </template>
        </div>
    </template>
</div>
```

`reviewEntryUrl(wk, line)` builds
`{{ route('app.screen', ['screen' => 'timesheets']) }}?tab=record&week=<wk.weekStart>&edit=<line.id>`
as a plain string. It is a real `<a href>`, not a `@click` handler — `partial-nav.js`
intercepts real links automatically (`resources/js/partial-nav.js:129-137`), so this
needs no new JS wiring for the navigation itself.

**Week stepping has no URL sync**, matching the report screen's identical
`prevWeek()`/`nextWeek()` precedent (`timesheet-reports.blade.php:449-450`) — no
`pushState`, browser Back does not step weeks. Same trade-off, same reason: in-page
stepping through preloaded data is not a page you'd want a Back-button history entry
for, and copying that omission is consistent rather than an oversight this time.

`:data-dir="weekDir"` reuses the existing `uj-tr-wk[data-dir]` enter animation
(`app.css:1916-1917`) — direction follows the arrow pressed, same as the report.

A new `.uj-tr-status-badge` style is needed (draft/submitted colouring) — the removed
"My weeks" list had one inline; recreate it as a small reusable class rather than
inline styles, since it now appears on every week header.

## Click-through into Record

### `timesheet-capture.js` changes

`init()` (line 55) currently seeds `this.rows[iso]` without an `id` field
(lines 67-73) — add `id: e.id`. Then, after the existing seed loop, resolve a deep
link:

```js
const editId = new URLSearchParams(window.location.search).get('edit');
if (editId && !this.readonly) {
    for (const iso of Object.keys(this.rows)) {
        const i = this.rows[iso].findIndex((r) => String(r.id) === editId);
        if (i === -1) continue;
        this.selected = iso;
        this.$nextTick(() => this.openEditRow(i));
        break;
    }
}
```

Placed after the existing `this.selected = ...` default-day logic so it overrides that
default only when `?edit=` actually resolves to a row.

`this.readonly` (used elsewhere in this file, e.g. line 616) is already true for a
submitted week — the guard above means a `?edit=` link into a submitted week silently
lands on the week with no auto-open, and the existing "This week is submitted. Reopen
it to make changes." banner (`timesheets.blade.php:199-201`) is what the user sees.
This is also why the Review UI's entry links still point at the submitted week (not
suppressed) — landing there with the banner and a recall button is the correct next
step, just not a direct edit.

### Locked / system-generated lines

`buildWeekBlocks()` includes every entry in a week, including `source !== null` lines
(leave/holiday, auto-generated). Record's `existingGrid` deliberately excludes these
(`TimesheetController.php:115-117`) — they are represented on Record via the `locked`
day dict, not as editable rows, so there is no row for an `?edit=` link to resolve to.
Rather than have those lines link to an id that will never be found, they carry no
`id` in the line data (the field the JS query already returns null/absent for) and the
Review UI renders them as plain, non-clickable rows (`uj-tr-ent--static` above) — same
visual family, no click affordance, no dead link.

## Bilingual

Every new visible string needs the existing `$store.ui.lang==='en' ? … : …` pattern,
matching the rest of the screen:

- Empty states: "No weeks yet." / "Belum ada minggu." and "No entries this week." /
  "Tiada entri minggu ini." (the latter reuses the report's existing string verbatim).
- Submitted-week note: "This week is submitted. Click an entry to open it on the
  Record tab — reopen it there to make changes." / Malay equivalent.
- Status badge text: the new `.uj-tr-status-badge` in this view is bilingual (see the
  markup above). This is a separate element from the Record tab's own status line
  (`timesheets.blade.php:154`, `ucfirst($weekStatus ?? 'draft')`), which stays
  English-only — that is a pre-existing gap on a screen this change doesn't otherwise
  touch, not introduced here, and not scope-crept into fixing.

## Edge cases

- **New hire, zero weeks.** `myWeeks` is `[]` → top-level `uj-tr-empty`.
- **Current draft week with zero entries yet.** `buildWeekBlocks()` today builds
  blocks purely from `entries.groupBy(weekStart)`, so a `Timesheet` row with zero
  `TimesheetEntry` rows produces **no block at all** — a brand-new draft would be
  invisible in Review despite the current-week default expecting it to be there. Fix:
  when `$timesheetsByWeekStart` is given, seed `$weekBlocks` from its keys first, then
  merge in entries — an empty draft week gets a block with `lines: []` (renders the
  per-week `uj-tr-empty`, not the top-level one). This is opt-in on the second
  parameter: `reportData()`'s call site keeps passing nothing, so its output and its
  existing tests (`TimesheetReportScreenTest`, `TimesheetReportLensTest`) are
  untouched.
- **Deep link to `?edit=` for an id that no longer exists** (entry deleted since the
  Review page was loaded, e.g. another tab). The `findIndex` loop above simply finds
  nothing and falls through to the existing default-day selection logic — no error,
  no crash, just lands on the week's default day same as a plain `?tab=record&week=`
  link would.
- **Half-day leave** (`isPartlyLocked`): the leave line itself is `source !== null`
  (static, per above); the employee's own row for the worked half is a normal
  editable row and links normally.

## Testing

- `tests/Feature/TimesheetPersonalTabsTest.php`: existing assertions about the "My
  weeks" list and the breakdown card will fail once removed — replace with assertions
  that the Review tab renders `myWeeks` data (week label, dates, status) instead.
- New coverage (new test or added to the above):
  - Review shows every week the employee has a `Timesheet` row for, including the
    current empty draft.
  - `canSeeCost() === false` employee still sees their own weeks (gate removed).
  - An entry line's link target matches
    `?tab=record&week=<weekStart>&edit=<entryId>`.
  - A leave/holiday line renders with no link.
- `resources/js/timesheet-capture.test.js`: add a case for `?edit=` resolving to the
  right day + row index, and a case for `?edit=` on a `readonly` (submitted) week
  being a no-op.
- Hand-verify in browser: click through from Review into Record on both a draft week
  (edit dialog opens) and a submitted week (banner shows, no dialog); confirm the week
  picker and arrows agree with each other (moving one updates the other's position).
