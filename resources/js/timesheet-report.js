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
 * Weekday -> one of the app's existing accent tokens, so entry-line day labels
 * are scannable at a glance instead of all reading as the same muted grey.
 * Friday reuses Monday's colour — only four accents exist in the palette and
 * they're not adjacent in the list, so the repeat doesn't read as a mix-up.
 */
const DAY_COLORS = {
    Mon: 'var(--info)',
    Tue: 'var(--success-ink)',
    Wed: 'var(--amber-ink)',
    Thu: 'var(--red)',
    Fri: 'var(--info)',
};

/** dayColor('Mon 6 Jul') -> 'var(--info)'; unknown/weekend prefixes get no colour. */
export function dayColor(dayLabel) {
    if (!dayLabel) { return ''; }
    return DAY_COLORS[dayLabel.slice(0, 3)] || '';
}

/** "8 people · 35.5 md · RM 19,800.60" (or the BM equivalent) for a slice's share line. */
export function formatSliceSubline(slice, isEn) {
    if (!slice) { return ''; }
    const memCount = slice.members ? slice.members.length : 0;
    const pWord = isEn ? (memCount === 1 ? 'person' : 'people') : 'orang';
    const mdVal = (Math.round((slice.days || 0) * 100) / 100).toFixed(2).replace(/\.?0+$/, '') + ' md';
    const rmVal = 'RM ' + Number(slice.cost || 0).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
            ['view', 'lens', 'id', 'pid'].forEach((p) => url.searchParams.delete(p));
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
        dayColor(day) {
            return dayColor(day);
        },
    }));
}
