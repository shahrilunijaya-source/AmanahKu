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
