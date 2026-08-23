# Timesheet report full-width drill-down Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the "Where time went" drill-down on the timesheet report screen from a cramped 340px sidebar into a full-width takeover, with breadcrumb wayfinding, working browser Back, focus management, and an accessibility pass on the tabs/pills it shares the screen with.

**Architecture:** Frontend-only. `TimesheetController::reportData()` is untouched — same payload. The giant inline `x-data="{...}"` object in `timesheet-reports.blade.php` is extracted into a registered Alpine component (`resources/js/timesheet-report.js`), following the same pattern the codebase already uses for `timesheet-capture.js` / `team-board.js`. The pure state/URL logic (which has real branches worth getting wrong) is split into standalone exported functions, unit tested with `bun:test`, and imported by the Alpine component — the same split `partial-nav.js` already uses between `isPartialLink` (tested) and `registerPartialNav` (DOM wiring, not directly tested). Browser Back/Forward is not handled by a new listener; drill states ride on the app's existing `partial-nav.js` `popstate` handling, and the Alpine component re-derives its view from the URL in `init()` after that re-render.

**Tech Stack:** Laravel 13 Blade, Alpine.js 3, Vite, `bun:test` for the JS unit tests (see `resources/js/partial-nav.test.js` for the existing pattern in this repo).

## Global Constraints

- Frontend-only: `app/Http/Controllers/TimesheetController.php` is not modified anywhere in this plan.
- No PHPUnit coverage to add — the spec confirms no backend change.
- Bilingual: every new/changed user-facing string uses the existing `$store.ui.lang==='en' ? … : …` ternary pattern (or `Alpine.store('ui').lang` inside the JS module, same store, see `resources/js/tot-card.js:34`).
- JS package manager is `bun` — never `npm`/`node` directly (`bun test`, `bun run build`).
- Run `vendor/bin/pint --dirty --format agent` if this plan ever touches a `.php` file (it doesn't, but keep it in mind if a task grows).
- Money is always formatted `toLocaleString('en-MY')` regardless of language — a pre-existing wart the spec explicitly says not to fix.

---

## File Structure

| File | Responsibility |
|---|---|
| `resources/js/timesheet-report.js` | New. Pure state/URL/breadcrumb helpers (exported, unit tested) + `registerTimesheetReport(Alpine)` which wires them into an `Alpine.data('timesheetReport', ...)` component. |
| `resources/js/timesheet-report.test.js` | New. `bun:test` coverage for the pure helpers. |
| `resources/js/app.js` | Modify. Import and call `registerTimesheetReport(Alpine)`, same spot as the other `registerX` calls. |
| `resources/views/screens/timesheet-reports.blade.php` | Modify. Report-tab markup: swap the inline `x-data` object for `timesheetReport(...)`, full-width drill takeover, breadcrumb header, shelf/filter collapse, a11y attributes on tabs/pills, Escape handling, edge-case copy. The "This week" tab markup (lines ~146–299) is untouched except `setTab()`'s new param-clearing, which lives in the JS file. |
| `resources/css/app.css` | Modify. `.uj-tr-lens`/`.uj-tr-panel` lose the two-column layout; new `.uj-tr-crumb*` and `.uj-tr-summary` rules; directional panel animation; motion/target-size fixes from the spec's table. |

---

### Task 1: Pure drill-state helpers, unit tested

**Files:**
- Create: `resources/js/timesheet-report.js`
- Create: `resources/js/timesheet-report.test.js`

**Interfaces:**
- Produces: `backTarget(sel)`, `selFromSearch(search, lensFallback, rowsForLens)`, `selToParams(sel, lens)`, `breadcrumb(sel, lens, currentSliceRow, personRow, fromSliceRow, isEn)` — all pure, no DOM/Alpine access. Task 2 imports all four.
- `sel` shape used everywhere: `{ view: 'bars' | 'slice' | 'person', key: string|number|null, from: string|number|null }`.

- [ ] **Step 1: Write the failing tests**

Create `resources/js/timesheet-report.test.js`:

```js
import { test, expect } from 'bun:test';
import { backTarget, selFromSearch, selToParams, breadcrumb } from './timesheet-report';

test('backTarget pops person-with-slice to its slice', () => {
    expect(backTarget({ view: 'person', key: '42', from: '3' })).toEqual({ view: 'slice', key: '3', from: null });
});

test('backTarget pops person-without-slice to bars', () => {
    expect(backTarget({ view: 'person', key: '42', from: null })).toEqual({ view: 'bars', key: null, from: null });
});

test('backTarget pops slice to bars', () => {
    expect(backTarget({ view: 'slice', key: '3', from: null })).toEqual({ view: 'bars', key: null, from: null });
});

test('selFromSearch reads bars when view is absent', () => {
    const result = selFromSearch(new URLSearchParams(''), 'category', () => [{ id: 3 }]);
    expect(result).toEqual({ lens: 'category', sel: { view: 'bars', key: null, from: null }, stale: false });
});

test('selFromSearch reads a slice whose id exists', () => {
    const rows = [{ id: 3 }, { id: 4 }];
    const result = selFromSearch(new URLSearchParams('view=slice&lens=category&id=3'), 'category', () => rows);
    expect(result).toEqual({ lens: 'category', sel: { view: 'slice', key: '3', from: null }, stale: false });
});

test('selFromSearch reads a person with a from slice', () => {
    const rows = [{ id: 42 }];
    const result = selFromSearch(new URLSearchParams('view=person&lens=category&id=42&from=3'), 'category', () => rows);
    expect(result).toEqual({ lens: 'category', sel: { view: 'person', key: '42', from: '3' }, stale: false });
});

test('selFromSearch falls back to bars and flags stale when the id is gone', () => {
    const result = selFromSearch(new URLSearchParams('view=slice&lens=category&id=99'), 'category', () => [{ id: 3 }]);
    expect(result).toEqual({ lens: 'category', sel: { view: 'bars', key: null, from: null }, stale: true });
});

test('selFromSearch ignores a slice view for the staff lens (no member-list step there)', () => {
    const result = selFromSearch(new URLSearchParams('view=slice&lens=staff&id=42'), 'staff', () => [{ id: 42 }]);
    expect(result).toEqual({ lens: 'staff', sel: { view: 'bars', key: null, from: null }, stale: false });
});

test('selToParams clears every param at bars', () => {
    expect(selToParams({ view: 'bars', key: null, from: null }, 'category'))
        .toEqual({ view: null, lens: null, id: null, from: null });
});

test('selToParams carries lens and id for a slice', () => {
    expect(selToParams({ view: 'slice', key: '3', from: null }, 'category'))
        .toEqual({ view: 'slice', lens: 'category', id: '3', from: null });
});

test('selToParams carries from only for a person opened from a slice', () => {
    expect(selToParams({ view: 'person', key: '42', from: '3' }, 'category'))
        .toEqual({ view: 'person', lens: 'category', id: '42', from: '3' });
    expect(selToParams({ view: 'person', key: '42', from: null }, 'staff'))
        .toEqual({ view: 'person', lens: 'staff', id: '42', from: null });
});

test('breadcrumb for a slice: root + current slice, only root clickable', () => {
    const crumbs = breadcrumb(
        { view: 'slice', key: '3', from: null }, 'category',
        { id: 3, label: 'Maintenance' }, null, null, true
    );
    expect(crumbs).toEqual([
        { label: 'All categories', target: 'bars' },
        { label: 'Maintenance', target: null },
    ]);
});

test('breadcrumb for a person opened from a slice: root, slice, person', () => {
    const crumbs = breadcrumb(
        { view: 'person', key: '42', from: '3' }, 'category',
        null, { id: 42, name: 'Ahmad Kussairi' }, { id: 3, label: 'Maintenance' }, true
    );
    expect(crumbs).toEqual([
        { label: 'All categories', target: 'bars' },
        { label: 'Maintenance', target: 'slice' },
        { label: 'Ahmad Kussairi', target: null },
    ]);
});

test('breadcrumb for a person opened directly from the staff lens: root, person', () => {
    const crumbs = breadcrumb(
        { view: 'person', key: '42', from: null }, 'staff',
        null, { id: 42, name: 'Ahmad Kussairi' }, null, false
    );
    expect(crumbs).toEqual([
        { label: 'Semua individu', target: 'bars' },
        { label: 'Ahmad Kussairi', target: null },
    ]);
});

test('breadcrumb at bars is empty', () => {
    expect(breadcrumb({ view: 'bars', key: null, from: null }, 'category', null, null, null, true)).toEqual([]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `bun test resources/js/timesheet-report.test.js`
Expected: FAIL — `resources/js/timesheet-report.js` does not exist yet (module not found).

- [ ] **Step 3: Write the implementation**

Create `resources/js/timesheet-report.js`:

```js
/**
 * Timesheet report — "Where time went" drill-down.
 *
 * Pure state/URL helpers live here so they can be unit tested without a DOM or
 * Alpine runtime — same split as partial-nav.js between isPartialLink (tested)
 * and registerPartialNav (DOM wiring).
 */

/** One step back: person -> its slice (if it has one) -> bars. */
export function backTarget(sel) {
    return sel.view === 'person' && sel.from
        ? { view: 'slice', key: sel.from, from: null }
        : { view: 'bars', key: null, from: null };
}

/**
 * Read view/lens/id/from off a URLSearchParams into { lens, sel, stale }.
 * rowsForLens(lens) returns that lens's row array, used to validate `id` still
 * exists — a filter change can shrink the result set out from under a deep link.
 */
export function selFromSearch(search, lensFallback, rowsForLens) {
    const view = search.get('view');
    const lens = search.get('lens') || lensFallback;
    const id = search.get('id');
    const bars = { view: 'bars', key: null, from: null };

    if (!view || id === null) {
        return { lens, sel: bars, stale: false };
    }

    const rows = rowsForLens(lens) || [];
    const exists = rows.some((r) => String(r.id) === String(id));
    if (!exists) {
        return { lens, sel: bars, stale: true };
    }

    if (view === 'person') {
        const from = search.get('from');
        return { lens, sel: { view: 'person', key: id, from: from || null }, stale: false };
    }
    if (view === 'slice' && lens !== 'staff') {
        return { lens, sel: { view: 'slice', key: id, from: null }, stale: false };
    }
    return { lens, sel: bars, stale: false };
}

/**
 * sel + lens -> params pushUrl() should set on the URL. null means "delete this
 * param" — bars carries none of them.
 */
export function selToParams(sel, lens) {
    if (sel.view === 'bars') {
        return { view: null, lens: null, id: null, from: null };
    }
    return {
        view: sel.view,
        lens,
        id: sel.key,
        from: sel.view === 'person' && sel.from ? sel.from : null,
    };
}

/**
 * Breadcrumb segments for the drill header. target is 'bars' | 'slice' | null
 * (null = current location, rendered as plain text, not a button).
 */
export function breadcrumb(sel, lens, currentSliceRow, personRow, fromSliceRow, isEn) {
    const rootLabel = lens === 'category' ? (isEn ? 'All categories' : 'Semua kategori')
        : lens === 'project' ? (isEn ? 'All projects' : 'Semua projek')
        : (isEn ? 'All people' : 'Semua individu');

    if (sel.view === 'slice') {
        return [
            { label: rootLabel, target: 'bars' },
            { label: currentSliceRow ? (currentSliceRow.label || currentSliceRow.name) : '', target: null },
        ];
    }
    if (sel.view === 'person') {
        const crumbs = [{ label: rootLabel, target: 'bars' }];
        if (sel.from && fromSliceRow) {
            crumbs.push({ label: fromSliceRow.label || fromSliceRow.name, target: 'slice' });
        }
        crumbs.push({ label: personRow ? personRow.name : '', target: null });
        return crumbs;
    }
    return [];
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `bun test resources/js/timesheet-report.test.js`
Expected: PASS, 15 tests.

- [ ] **Step 5: Commit**

```bash
git add resources/js/timesheet-report.js resources/js/timesheet-report.test.js
git commit -m "feat(timesheet-report): add pure drill-state/URL helpers"
```

---

### Task 2: Alpine component, registered in app.js

**Files:**
- Modify: `resources/js/timesheet-report.js` (append to the file Task 1 created)
- Modify: `resources/js/app.js`

**Interfaces:**
- Consumes: `backTarget`, `selFromSearch`, `selToParams`, `breadcrumb` from Task 1.
- Produces: `registerTimesheetReport(Alpine)`, registering `Alpine.data('timesheetReport', (cfg) => ({...}))` where `cfg` is `{ category, project, staff, weeks, tab }` (same four arrays/value the current inline `x-data` receives via `@js(...)`, plus `tab`). Task 3's Blade markup calls `timesheetReport(@js([...]))` and relies on every method name below existing exactly as spelled: `rows()`, `setLens(l)`, `setTab(t)`, `slice(key)`, `openPerson(id, from)`, `back()`, `goToBars()`, `goToSlice(key)`, `currentSlice()`, `personToDisplay()`, `crumbs()`, `formatSliceSubline(slice)`, `formatMissingWeeks(p)`, and the reactive properties `lens`, `sel`, `direction`, `hasAnimated`, `staleNotice`, `tab`.

**Deviation from the spec's pseudocode, noted here so it's visible:** the spec's draft state model included a `returnFocusTo` field written by `slice()`/`openPerson()` but never read anywhere in its own pseudocode (`back()` computed its own local `restore` instead). That's dead state. This task folds `slice()`, `openPerson()`, `back()`, `goToBars()`, and `goToSlice()` through one `navigate(nextSel, dir)` helper that computes the row-to-restore-focus-to as a local variable at call time — same observable behavior (focus follows every view change, back restores to the row you came from), no unread field.

- [ ] **Step 1: Append the Alpine component to `resources/js/timesheet-report.js`**

Add after the `breadcrumb` function:

```js
export function registerTimesheetReport(Alpine) {
    Alpine.data('timesheetReport', (cfg) => ({
        lens: 'category',
        category: cfg.category,
        project: cfg.project,
        staff: cfg.staff,
        weeks: cfg.weeks,
        tab: cfg.tab,
        sel: { view: 'bars', key: null, from: null },
        direction: 'fwd',
        hasAnimated: false,
        staleNotice: false,

        init() {
            const { lens, sel, stale } = selFromSearch(
                new URLSearchParams(window.location.search),
                this.lens,
                (l) => this.rowsFor(l)
            );
            this.lens = lens;
            this.sel = sel;
            this.staleNotice = stale;
            this.$nextTick(() => { this.hasAnimated = true; });
        },

        rowsFor(lens) {
            return lens === 'category' ? this.category
                : lens === 'project' ? this.project : this.staff;
        },
        rows() { return this.rowsFor(this.lens); },

        setLens(l) {
            this.lens = l;
            this.sel = { view: 'bars', key: null, from: null };
            this.pushUrl();
        },

        /* tab switching stays a replaceState (not a Back-button step, same as
           today) but must not leave stale drill params behind — a link copied
           from the "This week" tab used to carry view=person&id=42 forever. */
        setTab(t) {
            this.tab = t;
            const url = new URL(location);
            url.searchParams.set('tab', t);
            ['view', 'lens', 'id', 'from'].forEach((p) => url.searchParams.delete(p));
            history.replaceState(null, '', url);
        },

        navigate(nextSel, dir) {
            const restoreId = this.sel.key;
            this.direction = dir;
            this.sel = nextSel;
            this.pushUrl();
            this.$nextTick(() => {
                if (dir === 'back' && this.focusRow(restoreId)) { return; }
                this.focusHeading();
            });
        },
        slice(key) { this.navigate({ view: 'slice', key, from: null }, 'fwd'); },
        openPerson(id, from) { this.navigate({ view: 'person', key: id, from }, 'fwd'); },
        back() { this.navigate(backTarget(this.sel), 'back'); },
        goToBars() { this.navigate({ view: 'bars', key: null, from: null }, 'back'); },
        goToSlice(key) { this.navigate({ view: 'slice', key, from: null }, 'back'); },

        pushUrl() {
            const url = new URL(location);
            const params = selToParams(this.sel, this.lens);
            Object.entries(params).forEach(([k, v]) => {
                if (v === null) { url.searchParams.delete(k); } else { url.searchParams.set(k, v); }
            });
            history.pushState({ partialNav: true }, '', url);
        },

        focusHeading() {
            (this.sel.view === 'bars' ? this.$refs.barList : this.$refs.drillHeading)?.focus();
        },
        focusRow(id) {
            if (id === null || id === undefined) { return false; }
            const el = this.$root.querySelector(`[data-row-id="${CSS.escape(String(id))}"]`);
            if (!el) { return false; }
            el.focus();
            return true;
        },

        currentSlice() {
            if (this.sel.key === null) { return null; }
            return this.rows().find((r) => String(r.id) === String(this.sel.key)) || null;
        },
        currentPerson() {
            if (this.sel.key === null) { return null; }
            return this.staff.find((r) => String(r.id) === String(this.sel.key)) || null;
        },
        personToDisplay() { return this.currentPerson(); },

        crumbs() {
            const isEn = this.$store.ui.lang === 'en';
            const fromRow = this.sel.from
                ? this.rowsFor(this.lens).find((r) => String(r.id) === String(this.sel.from))
                : null;
            return breadcrumb(this.sel, this.lens, this.currentSlice(), this.personToDisplay(), fromRow, isEn)
                .map((c) => ({
                    ...c,
                    action: c.target === 'bars' ? () => this.goToBars()
                        : c.target === 'slice' ? () => this.goToSlice(this.sel.from)
                        : null,
                }));
        },

        formatSliceSubline(slice) {
            if (!slice) { return ''; }
            const memCount = slice.members ? slice.members.length : 0;
            const isEn = this.$store.ui.lang === 'en';
            const pWord = isEn ? (memCount === 1 ? 'person' : 'people') : 'orang';
            const mdVal = (Math.round((slice.days || 0) * 100) / 100).toFixed(2).replace(/\.?0+$/, '') + ' md';
            const rmVal = 'RM ' + Number(slice.cost || 0).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            return memCount + ' ' + pWord + ' · ' + mdVal + ' · ' + rmVal;
        },
        formatMissingWeeks(p) {
            if (!p || !p.missingWeeks || p.missingWeeks.length === 0) { return ''; }
            const mw = p.missingWeeks;
            const isEn = this.$store.ui.lang === 'en';
            const names = mw.length === 1 ? mw[0] : mw.slice(0, -1).join(', ') + (isEn ? ' and ' : ' dan ') + mw[mw.length - 1];
            const verb = mw.length === 1
                ? (isEn ? 'is not here: no sheet was ever submitted.' : 'tiada di sini: tiada lembaran pernah dihantar.')
                : (isEn ? 'are not here: no sheet was ever submitted.' : 'tiada di sini: tiada lembaran pernah dihantar.');
            return names + ' ' + verb;
        },
    }));
}
```

- [ ] **Step 2: Register it in `resources/js/app.js`**

Add the import alongside the other `registerX` imports (`app.js:14`, alphabetical with the rest):

```js
import { registerTimesheetReport } from './timesheet-report';
```

Add the call alongside `registerTimesheetCapture(Alpine);` (`app.js:58`):

```js
registerTimesheetReport(Alpine);
```

- [ ] **Step 3: Run the full JS test suite to confirm nothing broke**

Run: `bun test`
Expected: PASS, including the 15 tests from Task 1.

- [ ] **Step 4: Build to confirm the module compiles cleanly**

Run: `bun run build`
Expected: build succeeds, no import errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/timesheet-report.js resources/js/app.js
git commit -m "feat(timesheet-report): register the timesheetReport Alpine component"
```

---

### Task 3: Blade markup — full-width takeover, breadcrumb, collapse, a11y, edge cases

**Files:**
- Modify: `resources/views/screens/timesheet-reports.blade.php`

**Interfaces:**
- Consumes: `timesheetReport(cfg)` from Task 2 — every method/property named in Task 2's Interfaces block.
- No new interfaces produced; this is the leaf task for the frontend change (Task 4 styles what this task renders).

This task replaces three regions of the file. Work top to bottom; the line numbers below are current-file positions, re-check them as you go since earlier edits shift later ones.

- [ ] **Step 1: Drop the dead `uj-tr-wrap` class and swap the `x-data` root object for `timesheetReport(...)`**

Replace lines 34–87 (`<div class="uj-tr-wrap" x-data="{` through the closing `}">`) with:

```blade
<div x-data="timesheetReport({
    category: @js($lensCategory),
    project: @js($lensProject),
    staff: @js($lensStaff),
    weeks: @js($staffWeeks),
    tab: @js($tab),
})"
    @keydown.escape.window="tab === 'report' && sel.view !== 'bars' && back()">
```

(`uj-tr-wrap` had no matching CSS rule since commit `93494a2` — confirmed in the spec's Problem section. The escape handler is scoped to the report tab and to an actual drilled-in state, so pressing Escape while on "This week", or while already at bars, is a no-op rather than a surprise URL/state change.)

- [ ] **Step 2: Wire the tabs for keyboard/a11y (spec's Accessibility item 2)**

Replace the tabs block (lines 129–141):

```blade
<div class="uj-tr-tabs" role="tablist">
    <button type="button" id="tr-tab-report" class="uj-tr-tab" role="tab" :data-on="tab==='report'"
        :aria-selected="tab==='report'" aria-controls="tr-panel-report" :tabindex="tab==='report' ? 0 : -1"
        @click="setTab('report')" @keydown.right.prevent="setTab('week')" @keydown.left.prevent="setTab('week')">
        <span x-text="$store.ui.lang==='en' ? 'Where time went' : 'Ke mana masa pergi'">Where time went</span>
    </button>
    <button type="button" id="tr-tab-week" class="uj-tr-tab" role="tab" :data-on="tab==='week'"
        :aria-selected="tab==='week'" aria-controls="tr-panel-week" :tabindex="tab==='week' ? 0 : -1"
        @click="setTab('week')" @keydown.right.prevent="setTab('report')" @keydown.left.prevent="setTab('report')">
        <span x-text="$store.ui.lang==='en' ? 'This week' : 'Minggu ini'">This week</span>
        @if ($oweCount > 0)
            <span class="uj-tr-tabcount">{{ $oweCount }}</span>
        @endif
    </button>
</div>
```

Two tabs, so Left and Right both just toggle — no need for a roving-index array. Add `id="tr-panel-week"` and `tabindex="0"` to the "This week" panel wrapper (line 146):

```blade
<div x-show="tab==='week'" x-cloak role="tabpanel" id="tr-panel-week" aria-labelledby="tr-tab-week" tabindex="0">
```

- [ ] **Step 3: Replace the report-tab body (lines 301–525) with the full-width version**

This is the bulk of the task. Replace everything from `<div x-show="tab==='report'"` through its matching `</div>{{-- /tab: report --}}` with:

```blade
<div x-show="tab==='report'" x-cloak role="tabpanel" id="tr-panel-report" aria-labelledby="tr-tab-report" tabindex="0">
    {{-- Shelf + filters: full while browsing; a one-line summary while drilled in,
         so the thing you drilled into doesn't have to compete with eight controls
         above it (spec: "Shelf and filter collapse while drilled"). --}}
    <div x-show="sel.view==='bars'">
        <div class="uj-tr-shelf">
            <div class="uj-tr-lede">
                <span x-text="$store.ui.lang==='en' ? 'Submitted timesheets' : 'Timesheet dihantar'">Submitted timesheets</span> · <b>{{ $dateRange }}</b>
            </div>
            <div class="uj-tr-figrow">
                <span class="uj-tr-fig">{{ $md($totals['days']) }}</span>
                <span class="uj-tr-figsub">
                    <b><span x-text="$store.ui.lang==='en' ? 'person-days recorded' : 'hari-orang direkod'">person-days recorded</span></b>@if($totals['cost'] > 0), <span x-text="$store.ui.lang==='en' ? 'worth' : 'bernilai'">worth</span> {{ $rm($totals['cost']) }} <span x-text="$store.ui.lang==='en' ? 'at charge-out rates.' : 'pada kadar caj.'">at charge-out rates.</span>@else.@endif
                    @if ($totals['uncostedDays'] > 0)
                        {{ $md($totals['uncostedDays']) }} md <span x-text="$store.ui.lang==='en' ? 'have no band and no cost.' : 'tiada band dan tiada kos.'">have no band dan tiada kos.</span>
                    @endif
                </span>
            </div>
            {{-- PERSON-DAYS chip dropped: it repeated the hero figure above it verbatim. --}}
            <div class="uj-tr-chips">
                @if ($totals['uncostedDays'] > 0)
                    <div class="uj-tr-chip" data-t="warn">
                        <b>{{ $md($totals['uncostedDays']) }}</b>
                        <span x-text="$store.ui.lang==='en' ? 'UNCOSTED MD' : 'MD TANPA KOS'">UNCOSTED MD</span>
                    </div>
                @endif
                @if ($totals['weeksNotIn'] > 0)
                    <div class="uj-tr-chip" data-t="warn">
                        <b>{{ $totals['weeksNotIn'] }}</b>
                        <span x-text="$store.ui.lang==='en' ? 'WEEKS NOT IN' : 'MINGGU BELUM MASUK'">WEEKS NOT IN</span>
                    </div>
                @endif
            </div>
        </div>

        <form method="get" action="{{ route('app.screen', 'timesheet-reports') }}" class="uj-tr-filter">
            <div>
                <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'From' : 'Dari'">From</span></label>
                <input type="date" name="from" value="{{ $from }}" class="uj-tr-sel" />
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'To' : 'Hingga'">To</span></label>
                <input type="date" name="to" value="{{ $to }}" class="uj-tr-sel" />
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</span></label>
                <select name="category" class="uj-tr-sel">
                    <option value="" x-text="$store.ui.lang==='en' ? 'All categories' : 'Semua kategori'">All categories</option>
                    @foreach ($filterCategories as $c)
                        <option value="{{ $c->id }}" @selected((string) $selCategory === (string) $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Project' : 'Projek'">Project</span></label>
                <select name="project" class="uj-tr-sel">
                    <option value="" x-text="$store.ui.lang==='en' ? 'All projects' : 'Semua projek'">All projects</option>
                    @foreach ($filterProjects as $p)
                        <option value="{{ $p->id }}" @selected((string) $selProject === (string) $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="uj-tr-btn" data-primary style="align-self:flex-end;"><span x-text="$store.ui.lang==='en' ? 'Apply' : 'Guna'">Apply</span></button>
            <span class="uj-tr-range">{{ $dateRange }}</span>
        </form>

        <div style="margin-top:20px;">
            <div class="uj-tr-pills" role="group" :aria-label="$store.ui.lang==='en' ? 'Break down by' : 'Pecahkan mengikut'">
                <button type="button" class="uj-tr-pill" :data-on="lens==='category'" :aria-pressed="lens==='category'" @click="setLens('category')">
                    <span x-text="$store.ui.lang==='en' ? 'By category' : 'Mengikut kategori'">By category</span>
                </button>
                <button type="button" class="uj-tr-pill" :data-on="lens==='project'" :aria-pressed="lens==='project'" @click="setLens('project')">
                    <span x-text="$store.ui.lang==='en' ? 'By project' : 'Mengikut projek'">By project</span>
                </button>
                <button type="button" class="uj-tr-pill" :data-on="lens==='staff'" :aria-pressed="lens==='staff'" @click="setLens('staff')">
                    <span x-text="$store.ui.lang==='en' ? 'By person' : 'Mengikut individu'">By person</span>
                </button>
            </div>
        </div>
    </div>

    {{-- Collapsed summary line, shown only while drilled in. --}}
    <div class="uj-tr-summary" x-show="sel.view!=='bars'">
        <span>{{ $dateRange }}</span>
        @if ($selCatName || $selProjName)
            <span>&middot; {{ $activeFilterName }}</span>
        @endif
    </div>

    @if ($reportEmpty)
        <div class="uj-tr-card">
            <div class="uj-tr-empty">
                <b x-text="$store.ui.lang==='en' ? 'No submitted time matches this filter' : 'Tiada masa dihantar yang sepadan dengan tapisan ini'">No submitted time matches this filter</b>
                <div>{{ $activeFilterName }}</div>
                <span x-text="$store.ui.lang==='en' ? 'Clear a filter, or widen the period.' : 'Kosongkan satu tapisan, atau luaskan tempoh.'">Clear a filter, or widen the period.</span>
            </div>
        </div>
    @else
        <div x-show="staleNotice" class="uj-tr-notice">
            <span x-text="$store.ui.lang==='en' ? 'That row is not in the current period or filter.' : 'Baris itu tiada dalam tempoh atau tapisan semasa.'"></span>
            <button type="button" class="uj-tr-notice-close" @click="staleNotice=false" :aria-label="$store.ui.lang==='en' ? 'Dismiss' : 'Tutup'">&times;</button>
        </div>

        <div class="uj-tr-lens">
            {{-- Level 0: bars --}}
            <div x-show="sel.view==='bars'">
                <div class="uj-tr-card" :class="{ 'uj-tr-anim': !hasAnimated }" x-ref="barList" tabindex="-1">
                    <template x-if="rows().length === 0">
                        <div class="uj-tr-empty"
                             x-text="lens === 'category' ? ($store.ui.lang === 'en' ? 'No categorised time in this period.' : 'Tiada masa berkategori dalam tempoh ini.')
                                   : (lens === 'project' ? ($store.ui.lang === 'en' ? 'No project-linked time in this period.' : 'Tiada masa berkaitan projek dalam tempoh ini.')
                                   : ($store.ui.lang === 'en' ? 'Nobody has submitted time in this period.' : 'Tiada sesiapa menghantar masa dalam tempoh ini.'))">
                            No project-linked time in this period.
                        </div>
                    </template>
                    <template x-for="(row, index) in rows()" :key="row.id || row.label || index">
                        <button type="button" class="uj-tr-lensrow" :data-row-id="row.id"
                                @click="lens === 'staff' ? openPerson(row.id, null) : slice(row.id)">
                            <div class="uj-tr-barrow">
                                <span class="lbl">
                                    <span x-text="row.name || row.label"></span>
                                    <span class="uj-tr-sub" style="display:inline;margin-left:6px"
                                          x-text="lens === 'staff' ? (row.title || '')
                                                : ((row.members ? row.members.length : 0) + ' ' +
                                                   ($store.ui.lang === 'en'
                                                       ? ((row.members ? row.members.length : 0) === 1 ? 'person' : 'people')
                                                       : 'orang'))">
                                    </span>
                                </span>
                                <span class="val">
                                    <template x-if="(lens === 'staff' && !row.costed) || !(row.cost > 0)">
                                        <span style="color:var(--amber-ink)" x-text="$store.ui.lang==='en' ? 'uncosted' : 'tanpa kos'">uncosted</span>
                                    </template>
                                    <template x-if="row.cost > 0 && !(lens === 'staff' && !row.costed)">
                                        <b x-text="'RM ' + Number(row.cost || 0).toLocaleString('en-MY', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></b>
                                    </template>
                                    <span x-text="' · ' + (Math.round((row.days || 0) * 100) / 100).toFixed(2).replace(/\.?0+$/, '') + ' md'"></span>
                                    <template x-if="lens === 'staff' && row.weeksIn < row.weeksTotal">
                                        <span class="dim" x-text="' · ' + row.weeksIn + '/' + row.weeksTotal + ' ' + ($store.ui.lang === 'en' ? 'wk' : 'mgu')"></span>
                                    </template>
                                    <span x-text="' · ' + (row.pct || 0) + '%'"></span>
                                </span>
                            </div>
                            <div class="uj-tr-bar">
                                <i aria-hidden="true" :style="'width:' + Math.max(row.pct || 0, 1.5) + '%;background:' + (lens === 'category' ? 'var(--info)' : (lens === 'project' ? 'var(--success)' : (row.color || 'var(--info)'))) + ';animation-delay:' + Math.min(index * 45, 200) + 'ms'"></i>
                            </div>
                        </button>
                    </template>
                </div>
                @if ($totals['weeksNotIn'] > 0)
                    <div class="uj-tr-note" x-text="$store.ui.lang==='en' ? 'A row short of its weeks is short a submitted sheet, not short of work.' : 'Baris yang kurang minggunya kekurangan lembaran dihantar, bukan kekurangan kerja.'">A row short of its weeks is short a submitted sheet, not short of work.</div>
                @endif
            </div>

            {{-- Level 1: a category/project's members, full width --}}
            <template x-if="sel.view==='slice' && currentSlice()">
                <div class="uj-tr-panel" :data-dir="direction">
                    <div class="uj-tr-crumb" x-ref="drillHeading" tabindex="-1">
                        <template x-for="(c, i) in crumbs()" :key="i">
                            <span>
                                <template x-if="c.action">
                                    <button type="button" class="uj-tr-crumb-btn" @click="c.action()">
                                        <span x-show="i === 0">&larr;</span> <span x-text="c.label"></span>
                                    </button>
                                </template>
                                <template x-if="!c.action">
                                    <span class="uj-tr-crumb-cur" x-text="c.label"></span>
                                </template>
                                <span x-show="i < crumbs().length - 1" class="uj-tr-crumb-sep" aria-hidden="true">/</span>
                            </span>
                        </template>
                        <span class="uj-tr-crumb-share" x-text="formatSliceSubline(currentSlice())"></span>
                    </div>
                    <div class="uj-tr-bar" style="margin-bottom:6px">
                        <i aria-hidden="true" :style="'width:' + Math.max(currentSlice().pct || 0, 1.5) + '%;background:' + (lens === 'category' ? 'var(--info)' : 'var(--success)')"></i>
                    </div>
                    <template x-if="(currentSlice().members || []).length === 0">
                        <div class="uj-tr-empty" x-text="$store.ui.lang==='en' ? 'Nobody in this slice for the current filter.' : 'Tiada sesiapa dalam bahagian ini untuk tapisan semasa.'"></div>
                    </template>
                    <template x-for="member in (currentSlice().members || [])" :key="member.id">
                        <button type="button" class="uj-tr-lensrow" :data-row-id="member.id" @click="openPerson(member.id, currentSlice().id)">
                            <div class="uj-tr-barrow">
                                <span class="lbl" x-text="member.name"></span>
                                <span class="val" x-text="(Math.round((member.days || 0) * 100) / 100).toFixed(2).replace(/\.?0+$/, '') + ' md · ' + (member.pct || 0) + '%'"></span>
                            </div>
                            <div class="uj-tr-bar">
                                <i aria-hidden="true" :style="'width:' + Math.max(member.pct || 0, 1.5) + '%;background:' + (lens === 'category' ? 'var(--info)' : 'var(--success)')"></i>
                            </div>
                        </button>
                    </template>
                </div>
            </template>

            {{-- Level 2 (or level 1 for the staff lens): one person's weeks and lines, full width --}}
            <template x-if="sel.view==='person' && personToDisplay()">
                <div class="uj-tr-panel" :data-dir="direction" x-data="{ get p() { return personToDisplay() } }">
                    <div class="uj-tr-crumb" x-ref="drillHeading" tabindex="-1">
                        <template x-for="(c, i) in crumbs()" :key="i">
                            <span>
                                <template x-if="c.action">
                                    <button type="button" class="uj-tr-crumb-btn" @click="c.action()">
                                        <span x-show="i === 0">&larr;</span> <span x-text="c.label"></span>
                                    </button>
                                </template>
                                <template x-if="!c.action">
                                    <span class="uj-tr-crumb-cur" x-text="c.label"></span>
                                </template>
                                <span x-show="i < crumbs().length - 1" class="uj-tr-crumb-sep" aria-hidden="true">/</span>
                            </span>
                        </template>
                    </div>
                    <div style="display:flex;align-items:center;gap:11px;margin:10px 0 12px;">
                        <span class="uj-tr-av" :style="'background:' + (p.color || 'var(--info)')" x-text="p.initials"></span>
                        <div style="min-width:0;flex:1">
                            <div class="uj-tr-sub" x-text="(p.title || '') + (p.costed && p.rate ? ((p.title ? ' · ' : '') + 'RM ' + Number(p.rate).toLocaleString('en-MY', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '/day') : '')"></div>
                        </div>
                    </div>
                    <div style="font-size:var(--t-sm);color:var(--muted);margin-bottom:12px;" x-text="p.weeksIn + ' ' + ($store.ui.lang === 'en' ? 'of' : 'daripada') + ' ' + p.weeksTotal + ' ' + ($store.ui.lang === 'en' ? 'weeks submitted' : 'minggu dihantar')"></div>
                    <template x-if="(weeks[p.id] || []).length === 0">
                        <div class="uj-tr-empty" x-text="$store.ui.lang==='en' ? 'No submitted lines in this period.' : 'Tiada baris dihantar dalam tempoh ini.'"></div>
                    </template>
                    <template x-for="wk in (weeks[p.id] || [])" :key="wk.label">
                        <div class="uj-tr-wk">
                            <div class="hdr">
                                <span x-text="wk.label + ' · ' + wk.dates"></span>
                                <span x-text="(Math.round((wk.days || 0) * 100) / 100).toFixed(2).replace(/\.?0+$/, '') + ' md' + (p.costed && wk.cost > 0 ? ' · RM ' + Number(wk.cost || 0).toLocaleString('en-MY', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '')"></span>
                            </div>
                            <template x-for="(line, lidx) in wk.lines" :key="lidx">
                                <div class="uj-tr-ent">
                                    <div>
                                        <span x-text="line.label"></span>
                                        <template x-if="line.note">
                                            <span class="n" x-html="line.note"></span>
                                        </template>
                                    </div>
                                    <span class="d" x-text="(Math.round((line.days || 0) * 100) / 100).toFixed(2).replace(/\.?0+$/, '')"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                    <template x-if="p.missingWeeks && p.missingWeeks.length > 0">
                        <div class="uj-tr-note" style="margin-top:12px" x-text="formatMissingWeeks(p)"></div>
                    </template>
                    <template x-if="!p.costed">
                        <div class="uj-tr-note" style="margin-top:12px" x-text="$store.ui.lang==='en' ? 'You have no position band assigned, so your timesheet cost can\'t be computed. Set it in Administration → Position & Manday Rates.' : 'Anda belum ada band pangkat, jadi kos timesheet anda tidak dapat dikira. Tetapkan di Pentadbiran → Pangkat & Kadar Manday.'"></div>
                    </template>
                </div>
            </template>
        </div>
    @endif
</div>{{-- /tab: report --}}
```

Notes on what changed versus the old markup, so the diff makes sense on review:
- The old dead `:data-on="..."` ternary on `.uj-tr-lensrow` (row-selected highlighting) is gone — bars are never on screen at the same time as the panel now, so there is no "selected row" to highlight. Every row instead carries `:data-row-id` for `focusRow()`.
- Member rows (inside the `slice` panel) now render the same `.uj-tr-bar` fill the top-level rows do, per spec's "Member rows become bar rows" — they no longer need the `padding:11px 0` override since they're not squeezed into 340px anymore.
- Both drill panels share one `x-ref="drillHeading"` name; Alpine resolves it to whichever one is actually mounted, and only one is ever mounted at a time (`x-if`, not `x-show`, on both — the panel is fully removed/recreated on every view change, which is also what makes the CSS animation replay each time, see Task 4).
- `x-html="line.note"` is unchanged — it was already sanitized server-side (`HtmlSanitizer::clean` on write) before this plan; not touched here.

- [ ] **Step 4: Verify the file has no leftover references to the removed state shape**

Run: `grep -n "sel.kind\|personToDisplay()\.id\|uj-tr-wrap" resources/views/screens/timesheet-reports.blade.php`
Expected: no matches (the old `{ kind, key, from }` shape and the dead wrapper class are both fully gone).

- [ ] **Step 5: Commit**

```bash
git add resources/views/screens/timesheet-reports.blade.php
git commit -m "feat(timesheet-report): full-width drill-down markup, breadcrumb, a11y, edge cases"
```

---

### Task 4: CSS — layout, breadcrumb, motion, target sizes

**Files:**
- Modify: `resources/css/app.css`

**Interfaces:**
- Consumes: the class names and `data-*` attributes Task 3's markup uses — `.uj-tr-lens`, `.uj-tr-panel`, `.uj-tr-crumb`, `.uj-tr-crumb-btn`, `.uj-tr-crumb-cur`, `.uj-tr-crumb-sep`, `.uj-tr-crumb-share`, `.uj-tr-summary`, `.uj-tr-notice`, `.uj-tr-notice-close`, `[data-dir]`.
- No new interfaces produced — this is styling only.

- [ ] **Step 1: Drop the two-column grid and sticky rail; fold the mobile override into the base rule**

Replace lines 1864 and 1872–1874 and delete lines 1898–1899:

```css
.uj-tr-lens { display: grid; grid-template-columns: 1fr; gap: 16px; align-items: start; margin-top: 14px; }
```

```css
.uj-tr-panel { background: var(--card); border: 1px solid var(--hairline); border-radius: 14px; padding: 18px; }
.uj-tr-panel h3 { margin: 0; font-size: var(--t-base); font-weight: 600; color: var(--ink); line-height: 1.3; }
```

Remove `.uj-tr-lens { grid-template-columns: 1fr; }` and `.uj-tr-panel { position: static; }` from the `@media (max-width: 640px)` block (lines 1898–1899) — the base rule now says exactly that, there is nothing left for the mobile override to do.

- [ ] **Step 2: Breadcrumb + collapsed-summary styles**

Add after the `.uj-tr-panel h3` rule:

```css
/* Drill header: replaces the old rail's "back arrow only" with wayfinding at
   every level. Sticky because the entry list under it can run long — losing
   the way back at the top of a long scroll was the rail's exact problem. */
.uj-tr-crumb { position: sticky; top: 0; background: var(--card); z-index: 1; display: flex; align-items: baseline; flex-wrap: wrap; gap: 4px; padding-bottom: 12px; margin-bottom: 12px; border-bottom: 1px solid var(--hairline-soft); font-size: var(--t-sm); }
.uj-tr-crumb-btn { background: none; border: 0; padding: 2px 4px; margin: -2px -4px; font: inherit; font-size: var(--t-sm); color: var(--muted); cursor: pointer; border-radius: 6px; min-height: 28px; }
.uj-tr-crumb-btn:hover { color: var(--ink); background: var(--hairline-soft); }
.uj-tr-crumb-btn:focus-visible { outline: 2px solid var(--red); outline-offset: 1px; }
.uj-tr-crumb-cur { color: var(--ink); font-weight: 600; }
.uj-tr-crumb-sep { color: var(--muted); }
.uj-tr-crumb-share { margin-left: auto; color: var(--muted); font-family: var(--font-mono); font-variant-numeric: tabular-nums; font-size: var(--t-sm); white-space: nowrap; }

/* One line replacing the shelf + filter form while drilled in. */
.uj-tr-summary { display: flex; gap: 6px; align-items: baseline; font-size: var(--t-sm); color: var(--muted); font-family: var(--font-mono); font-variant-numeric: tabular-nums; margin: 14px 0; }

.uj-tr-notice { display: flex; align-items: center; gap: 10px; background: #faf1e2; border: 1px solid #f0dfc0; border-radius: 10px; padding: 10px 14px; margin: 14px 0; font-size: var(--t-sm); color: var(--amber-ink); }
.uj-tr-notice-close { margin-left: auto; background: none; border: 0; font-size: 16px; line-height: 1; color: inherit; cursor: pointer; padding: 4px; }
```

- [ ] **Step 3: Directional panel animation, replacing the single `uj-tr-panel-in` keyframe**

Replace lines 1882–1883:

```css
.uj-tr-panel[data-dir="fwd"] { animation: uj-tr-panel-in-fwd .2s var(--ease) both; }
.uj-tr-panel[data-dir="back"] { animation: uj-tr-panel-in-back .2s var(--ease) both; }
@keyframes uj-tr-panel-in-fwd { from { opacity: 0; transform: translateX(12px) scale(.985); filter: blur(3px); } to { opacity: 1; transform: none; filter: blur(0); } }
@keyframes uj-tr-panel-in-back { from { opacity: 0; transform: translateX(-12px) scale(.985); filter: blur(3px); } to { opacity: 1; transform: none; filter: blur(0); } }
```

- [ ] **Step 4: Extend the reduced-motion block**

Replace lines 1885–1889:

```css
@media (prefers-reduced-motion: reduce) {
  .uj-tr-anim .uj-tr-bar i { animation: none; }
  .uj-tr-panel[data-dir="fwd"], .uj-tr-panel[data-dir="back"] { animation: uj-tr-fade .2s ease-out both; }
  @keyframes uj-tr-fade { from { opacity: 0; } to { opacity: 1; } }
}
```

(Fade only, no translate — matches the spec's motion table exactly: "no translate, fade only.")

- [ ] **Step 5: Target size and press feedback on `.uj-tr-btn`**

Replace line 1852 and add to line 1854 (this class is shared by every button on the screen that uses it — the "Remind"/"Open week" buttons on the "This week" tab too, so the pressed-state feedback lands everywhere the spec's table asks for it: "the control used at every level"):

```css
.uj-tr-btn { min-height: 44px; padding: 0 13px; border: 1px solid var(--shelf-line); border-radius: 9px; background: var(--card); color: var(--ink); font: inherit; font-size: var(--t-sm); cursor: pointer; transition: border-color .14s var(--ease), background .14s var(--ease), transform .04s var(--ease); }
.uj-tr-btn:hover { border-color: var(--muted-soft); }
.uj-tr-btn:active { background: var(--hairline-soft); transform: scale(0.97); transition-duration: .04s; }
```

(`height: 32px` becomes `min-height: 44px` — WCAG 2.5.5 target size on coarse pointers, per the spec's accessibility table item 5. This does make the shelf/tab buttons a few px taller than before; that's the intended trade, not a side effect to work around.)

- [ ] **Step 6: Verify no other screen's CSS depended on the old two-column `.uj-tr-lens` or the old single `uj-tr-panel-in` keyframe name**

Run: `grep -rn "uj-tr-lens\|uj-tr-panel-in\b" resources/views resources/css | grep -v timesheet-report`
Expected: no matches — these classes are namespaced to this screen only (per the file's own header comment at `app.css:1770-1774`), so nothing else can break.

- [ ] **Step 7: Build and visually smoke-check**

Run: `bun run build`
Expected: build succeeds. (Full visual verification happens in Task 5, against a running server — a production build alone doesn't prove the layout is right.)

- [ ] **Step 8: Commit**

```bash
git add resources/css/app.css
git commit -m "style(timesheet-report): full-width drill layout, breadcrumb, motion, target sizes"
```

---

### Task 5: Manual browser verification

**Files:** none (verification only).

**Interfaces:** none.

No PHPUnit or `bun:test` coverage applies here — this task is walking the spec's own Testing checklist by hand in a real browser, per this project's rule that UI changes need to be exercised in the browser before being called done, not just type-checked or unit-tested.

- [ ] **Step 1: Start the site and log in**

The worktree already has its own lerd vhost (`worktree-<branch>.amanahku.localhost`, seeded `vendor/` and `.env` — see this project's `CLAUDE.md`). Open the Browser pane at that URL and use a dev quick-login button for `manager@amanahku.test` or `management@amanahku.com` (this screen is management/HR only). Navigate to Reports & Audit → Timesheet reports, "Where time went" tab.

- [ ] **Step 2: Default load**

Expected: bars only, no panel in the DOM (`document.querySelector('.uj-tr-panel')` is `null`), full shelf/filter/pills visible.

- [ ] **Step 3: Drill in and back, three ways**

Click a category bar → member list, full width, no bars visible. Click a member → their entries, full width. Confirm the breadcrumb reads `← All categories / <category> / <name>`. Go back via: (a) the breadcrumb's leading segment, (b) `Escape`, (c) the browser's own Back button. Confirm each lands one level up and the screen actually changes (not just the URL).

- [ ] **Step 4: Cross-screen Back**

Drill into a person, click a sidebar nav link to another screen, then press browser Back twice. Expected: first Back lands back on this screen at the drill view you left (a partial re-fetch is expected here, not a bug — see the spec's History section), second Back pops one level further.

- [ ] **Step 5: Direct links**

Copy the URL while at `view=person`, open it in a new tab: expected to land straight in that person's entries. Then hand-edit the URL's `id` to something that doesn't exist in the current period and reload: expected to land on bars with the dismissible "not in the current period" notice.

- [ ] **Step 6: Tab switch clears drill params**

While drilled into a person, click "This week". Expected: URL loses `view`/`lens`/`id`/`from`, keeps `tab=week`.

- [ ] **Step 7: Keyboard-only pass**

Tab to a bar, press Enter — focus should land on the breadcrumb heading (`document.activeElement` is the `.uj-tr-crumb` div). Press Escape — focus should return to the row you drilled from.

- [ ] **Step 8: Motion**

Reload the report tab fresh — bar-grow animation plays once. Drill in and back to bars a few times — bar-grow does not replay. Enable `prefers-reduced-motion` (`resize_window` supports a `colorScheme` override; for reduced motion, use the browser's own emulation or `javascript_tool` to check the media query result) and confirm the panel fades in without sliding.

- [ ] **Step 9: Mobile**

`resize_window` to the `mobile` preset, reload, repeat the drill-in/back flow. Expected: unchanged from before this plan (already single-column), back control comfortably tappable.

- [ ] **Step 10: Screenshot for the record**

Take a `computer` screenshot of the full-width member-list view and the full-width person-entries view, and share both with the user as proof the layout change is live.

- [ ] **Step 11: Run the full test suite one more time**

Run: `bun test && php artisan test --compact --filter=Timesheet`
Expected: PASS. (The PHP filter is a safety net confirming `reportData()` truly wasn't touched — it should be unaffected and green.)

---

## Self-Review

**Spec coverage:**
- Full-width takeover at both drill levels — Task 3 Step 3. ✓
- Breadcrumb wayfinding — Task 1 (`breadcrumb()`), Task 2 (`crumbs()`), Task 3, Task 4 Step 2. ✓
- `pushState` + partial-nav `popstate` integration, no local listener — Task 2 (`pushUrl` uses `{partialNav:true}`), documented reliance on `partial-nav.js:121`. ✓
- `setTab` clears drill params — Task 2 Step 1. ✓
- Focus management (drill in, back, Escape) — Task 2 (`navigate`/`focusHeading`/`focusRow`), Task 3 Step 1 (Escape binding). ✓
- Member rows become bar rows — Task 3 Step 3. ✓
- Shelf/filter collapse, `PERSON-DAYS` chip removal — Task 3 Step 3. ✓
- Dead `uj-tr-wrap` removal — Task 3 Step 1. ✓
- Dead `:data-on` selected-row removal — Task 3 Step 3 (noted in the "what changed" callout). ✓
- Motion table (directional animation, `.2s`, animate-bars-once, staggerd cap, back-button size, `:active` transform) — Task 4 Steps 3–5. ✓
- Accessibility table items 1–8 — item 1 (Task 2/3), item 2 (Task 3 Step 2), item 3 (Task 3 Step 3, `aria-pressed`), item 5 (Task 4 Step 5), item 6 (Task 3 Step 3, `:aria-label`), item 7 (Task 3 Step 3, `aria-hidden` on bar fills), item 8 (breadcrumb button labels carry the destination name, not just "Back"). Item 4 (`aria-live`) is explicitly conditional in the spec ("if testing shows a gap") — left for Task 5's manual pass to judge; not pre-emptively added. ✓
- Edge cases (no submitted weeks, stale deep link, long note clamp, zero-member slice) — Task 3 Step 3; note the `max-width: 64ch` note clamp was already inherited from `.uj-tr-note` before this plan and needs no new rule. ✓
- Bilingual strings — every new string in Task 3 uses the ternary pattern; breadcrumb roots live in `breadcrumb()` (Task 1) parameterized by `isEn`. ✓
- Cost-gating non-goal — no gating code added anywhere in this plan, matching the spec's finding that it's a no-op on this screen. ✓

**Placeholder scan:** no TBD/TODO, every step has real code, no "similar to Task N" references, no test descriptions without test code.

**Type/name consistency check:** `sel.view` (not `sel.kind`) used consistently from Task 1 through Task 3; method names (`slice`, `openPerson`, `back`, `goToBars`, `goToSlice`, `crumbs`, `currentSlice`, `personToDisplay`, `formatSliceSubline`, `formatMissingWeeks`, `rows`, `setLens`, `setTab`) match between Task 2's definitions and Task 3's template calls. `data-row-id` attribute name matches between Task 2's `focusRow()` selector and Task 3's three row-rendering blocks (bars, members, — the person view has no further drill-down rows so it needs none).

**One documented deviation:** Task 2 drops the spec pseudocode's unused `returnFocusTo` field in favor of a local variable inside a shared `navigate()` — same observable behavior, less dead state. Flagged inline in Task 2 rather than silently diverging.

---

Plan complete and saved to `docs/superpowers/plans/2026-08-18-timesheet-report-drilldown.md`. Two execution options:

**1. Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**
