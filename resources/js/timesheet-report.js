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
 * Read view/lens/id/pid off a URLSearchParams into { lens, sel, stale }.
 * rowsForLens(lens) returns that lens's row array, used to validate `id` still
 * exists — a filter change can shrink the result set out from under a deep link.
 *
 * The query key for "which slice this person was opened from" is `pid`
 * (parent id), not `from` — `from` is already the report's date-range-start
 * param (TimesheetController::periodFromRequest()), and reusing it made the
 * server try to Carbon::parse() a category id as a date and 500.
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
        const pid = search.get('pid');
        return { lens, sel: { view: 'person', key: id, from: pid || null }, stale: false };
    }
    if (view === 'slice' && lens !== 'staff') {
        return { lens, sel: { view: 'slice', key: id, from: null }, stale: false };
    }
    return { lens, sel: bars, stale: false };
}

/**
 * sel + lens -> params pushUrl() should set on the URL. null means "delete this
 * param" — bars carries none of them. See selFromSearch() above for why the
 * query key is `pid`, not `from`.
 */
export function selToParams(sel, lens) {
    if (sel.view === 'bars') {
        return { view: null, lens: null, id: null, pid: null };
    }
    return {
        view: sel.view,
        lens,
        id: sel.key,
        pid: sel.view === 'person' && sel.from ? sel.from : null,
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

/**
 * A week's `lines` (one flat array from TimesheetController::buildWeekBlocks())
 * grouped into one heading per day, in the order days first appear, each carrying
 * its own person-day total. The day is the unit that has to add up to 1, so a
 * reader should never have to sum 0.8 + 0.2 themselves to see a day is short.
 *
 * Grouping replaced a per-weekday colour scale on the day labels. Five accents
 * repeating down a list is decoration, and one of them was the action red, which
 * this system reserves for "act" and the focus ring. Structure separates the days
 * now: a heading, a rule, and the total.
 */
export function groupLinesByDay(lines) {
    const groups = [];
    const byDay = new Map();
    for (const line of lines) {
        if (!byDay.has(line.day)) {
            const group = { day: line.day, lines: [], days: 0 };
            byDay.set(line.day, group);
            groups.push(group);
        }
        const group = byDay.get(line.day);
        group.lines.push(line);
        group.days = round2(group.days + (Number(line.days) || 0));
    }

    return groups;
}

function round2(n) {
    return Math.round(n * 100) / 100;
}

/** 1 -> "1", 0.8 -> "0.8", 5.25 -> "5.25". The panel's one number format. */
export function formatDays(value) {
    return round2(Number(value) || 0).toFixed(2).replace(/\.?0+$/, '');
}

/** 1260 -> "RM 1,260.00". */
export function formatRm(value) {
    return 'RM ' + Number(value || 0).toLocaleString('en-MY', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/** "8 people · 35.5 md · RM 19,800.60" (or the BM equivalent) for a slice's share line. */
export function formatSliceSubline(slice, isEn) {
    if (!slice) { return ''; }
    const memCount = slice.members ? slice.members.length : 0;
    const pWord = isEn ? (memCount === 1 ? 'person' : 'people') : 'orang';
    const mdVal = formatDays(slice.days) + ' md';
    const rmVal = formatRm(slice.cost);
    return memCount + ' ' + pWord + ' · ' + mdVal + ' · ' + rmVal;
}

/** "Week 26 is not here: no sheet was ever submitted." (or the BM equivalent) for a person panel. */
export function formatMissingWeeks(p, isEn) {
    if (!p || !p.missingWeeks || p.missingWeeks.length === 0) { return ''; }
    const mw = p.missingWeeks;
    const names = mw.length === 1 ? mw[0] : mw.slice(0, -1).join(', ') + (isEn ? ' and ' : ' dan ') + mw[mw.length - 1];
    const verb = mw.length === 1
        ? (isEn ? 'is not here: no sheet was ever submitted.' : 'tiada di sini: tiada lembaran pernah dihantar.')
        : (isEn ? 'are not here: no sheet was ever submitted.' : 'tiada di sini: tiada lembaran pernah dihantar.');
    return names + ' ' + verb;
}

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
        /* "This week" tab: one staff member's weeks, fetched read-only. Separate from
           sel/lens above — that drill-down reads the closed report period and only ever
           holds submitted time, and the people on the chase list are precisely the ones
           with none of it. */
        staffWeekHtml: '',
        staffWeekLoading: null,
        staffWeekError: false,

        init() {
            const search = new URLSearchParams(window.location.search);
            const { lens, sel, stale } = selFromSearch(search, this.lens, (l) => this.rowsFor(l));
            this.lens = lens;
            this.sel = sel;
            this.staleNotice = stale;
            const emp = search.get('emp');
            if (this.tab === 'week' && emp) { this.fetchStaffWeek(Number(emp)); }
            this.$nextTick(() => { this.hasAnimated = true; });
        },

        openStaffWeek(id) {
            const url = new URL(location);
            url.searchParams.set('emp', id);
            history.pushState({ partialNav: true }, '', url);
            this.fetchStaffWeek(id);
        },

        closePerson() {
            const url = new URL(location);
            url.searchParams.delete('emp');
            history.pushState({ partialNav: true }, '', url);
            this.staffWeekHtml = '';
            this.staffWeekError = false;
        },

        async fetchStaffWeek(id) {
            this.staffWeekLoading = id;
            this.staffWeekError = false;
            try {
                const res = await fetch(`/app/timesheet-reports/person/${id}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) { this.staffWeekError = true; return; }
                this.staffWeekHtml = await res.text();
            } catch {
                this.staffWeekError = true;
            } finally {
                this.staffWeekLoading = null;
            }
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
            ['view', 'lens', 'id', 'pid', 'emp'].forEach((p) => url.searchParams.delete(p));
            history.replaceState(null, '', url);
            this.staffWeekHtml = '';
            this.staffWeekError = false;
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
            // Not this.$root: navigating from a click on a row that's about to be
            // torn down (e.g. a member row inside the slice panel, when the click
            // itself moves sel to 'person' and unmounts that panel) detaches the
            // clicked element before this callback runs, and Alpine resolves $root
            // from the triggering element's context — so it comes back undefined.
            // document.querySelector doesn't depend on that context.
            const refName = this.sel.view === 'bars' ? 'barList'
                : this.sel.view === 'slice' ? 'drillHeadingSlice'
                : 'drillHeadingPerson';
            document.querySelector(`[x-ref="${refName}"]`)?.focus();
        },
        focusRow(id) {
            if (id === null || id === undefined) { return false; }
            // Scoped to the landing view's own container: category/project/staff ids
            // and member/employee ids are different entities that can share a numeric
            // id (e.g. category 5 and employee 5), and the bars list stays in the DOM
            // (x-show, not x-if) even while hidden, so an unscoped id match can hit a
            // display:none row instead of the one actually on screen.
            const scope = this.sel.view === 'bars' ? '[x-ref="barList"]' : '.uj-tr-panel';
            const el = document.querySelector(`${scope} [data-row-id="${CSS.escape(String(id))}"]`);
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
            return formatSliceSubline(slice, this.$store.ui.lang === 'en');
        },
        formatMissingWeeks(p) {
            return formatMissingWeeks(p, this.$store.ui.lang === 'en');
        },
        daysInWeek(wk) { return groupLinesByDay(wk.lines || []); },
        md(value) { return formatDays(value); },
        rm(value) { return formatRm(value); },
    }));
}
