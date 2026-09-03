import { test, expect } from 'bun:test';
import { registerTimesheetCapture, findEditTarget } from './timesheet-capture';

// 2026-08-03 is a Monday, so a 7-day week from there is Mon..Sun with Saturday at index 5.
const WEEK_START = '2026-08-03';
const SATURDAY = '2026-08-08';
const THURSDAY = '2026-08-06';
// isEditable() excludes future days, so "today" must sit on/after every day these tests use.
const TODAY = '2026-08-10';

const CATEGORIES = [
    { id: 1, name: 'Development', name_ms: 'Pembangunan', requires_project: true },
    { id: 2, name: 'Sales', name_ms: 'Jualan', requires_project: false },
];

/** Minimal Alpine stand-in — only the calls registerTimesheetCapture makes. */
let capturedFactory;
const stores = {};
const fakeAlpine = {
    data: (name, factory) => { capturedFactory = factory; },
    store: (name, obj) => { stores[name] = obj; },
};
registerTimesheetCapture(fakeAlpine);

/** Builds the raw component object literal and wires up the $store magic property. */
function makeComponent(cfg) {
    const c = capturedFactory({ weekStart: WEEK_START, today: TODAY, earliestWeek: '2026-01-01', categories: CATEGORIES, ...cfg });
    c.$store = { ui: { lang: 'en' }, tsReview: stores.tsReview, toast: { info: () => {}, success: () => {}, error: () => {} } };
    // Real Alpine defers to next tick and then touches the DOM (focus/$refs) — this suite
    // only asserts on state, so the callback is dropped rather than given a fake DOM.
    c.$nextTick = () => {};
    return c;
}

test('init() resets the review store, so a fresh mount never inherits a previous mount\'s open review', () => {
    stores.tsReview.open = true; // as if a previous mount left the review pane open
    const c = makeComponent({ days: 5 });
    c.init();
    expect(stores.tsReview.open).toBe(false);
});

test('reviewDays() keeps a filled Saturday after "Show weekend" is toggled back off', () => {
    const c = makeComponent({ days: 7 });
    expect(c.dayDates()).toContain(SATURDAY);
    c.rows[SATURDAY] = [{ category_id: 1, project_id: '', sub_pillar_id: '', description: '', percentage: 100 }];

    c.days = 5;
    expect(c.dayDates()).not.toContain(SATURDAY);
    expect(c.reviewDays()).toContain(SATURDAY);
});

test('categoryTotals() scales an over-100% day back down to 100, keeping the two categories\' 1:1 split', () => {
    const c = makeComponent({ days: 5 });
    c.rows[THURSDAY] = [
        { category_id: 1, project_id: '', sub_pillar_id: '', description: '', percentage: 80 },
        { category_id: 2, project_id: '', sub_pillar_id: '', description: '', percentage: 80 },
    ];

    const totals = c.categoryTotals();
    expect(totals.length).toBe(2);
    expect(totals.reduce((s, b) => s + b.pct, 0)).toBe(100);
    expect(totals[0].pct).toBe(totals[1].pct); // 80:80 is 1:1, so the scale-down preserves it
});

test('categoryTotals() splits a half-locked day between the leave bucket and the row category', () => {
    const c = makeComponent({
        days: 5,
        locked: { [THURSDAY]: { label: 'On Leave', percentage: 50, source: 'leave', period: 'am' } },
    });
    c.rows[THURSDAY] = [{ category_id: 1, project_id: '', sub_pillar_id: '', description: '', percentage: 50 }];

    expect(c.reviewDays()).toContain(THURSDAY);
    const totals = c.categoryTotals();
    expect(totals.length).toBe(2);
    const leave = totals.find((b) => b.label === 'On Leave');
    const dev = totals.find((b) => b.label === 'Development');
    expect(leave.pct).toBe(50);
    expect(dev.pct).toBe(50);
});

test('categoryTotals() does not double-count a day that flipped fully-locked while stale rows were still in memory', () => {
    const c = makeComponent({
        days: 5,
        locked: { [THURSDAY]: { label: 'On Leave', percentage: 100, source: 'leave' } },
    });
    // Stale rows left over from before the day flipped fully-locked mid-session (e.g. save()
    // refreshed `locked` from the server but never touched `rows`).
    c.rows[THURSDAY] = [{ category_id: 1, project_id: '', sub_pillar_id: '', description: '', percentage: 40 }];

    expect(c.reviewDays()).toContain(THURSDAY);
    const totals = c.categoryTotals();
    // Only the locked bucket should exist — the stale row must not create a second bucket or
    // push the day's total contribution past 100.
    expect(totals.length).toBe(1);
    expect(totals[0].label).toBe('On Leave');
    expect(totals.reduce((s, b) => s + b.pct, 0)).toBe(100);
});

test('findEditTarget() finds the day and index of a matching row id', () => {
    const rows = {
        '2026-08-03': [{ id: 10, category_id: 1 }, { id: 11, category_id: 2 }],
        '2026-08-04': [{ id: 12, category_id: 1 }],
    };
    expect(findEditTarget(rows, '11')).toEqual({ iso: '2026-08-03', index: 1 });
    expect(findEditTarget(rows, 12)).toEqual({ iso: '2026-08-04', index: 0 });
});

test('findEditTarget() returns null when no row matches', () => {
    const rows = { '2026-08-03': [{ id: 10 }] };
    expect(findEditTarget(rows, '999')).toBeNull();
});

test('init() ignores editEntryId on a readonly (submitted) week — nothing to auto-open until recalled', () => {
    const c = makeComponent({ days: 5, readonly: true, editEntryId: '10', existing: { [WEEK_START]: [{ id: 10, category_id: 1, percentage: 100 }] } });
    expect(() => c.init()).not.toThrow();
    expect(c.picker.open).toBe(false);
});

test('categoryColour() uses the colour the server sent, and greys out a category it has none for', () => {
    const c = makeComponent({ categories: [{ id: 9, name: 'Sales', name_ms: 'Jualan', requires_project: true, colour: '#8a4bdb' }, { id: 8, name: 'Odd', name_ms: 'Odd' }] });
    expect(c.categoryColour(9)).toBe('#8a4bdb');
    expect(c.categoryColour(8)).toBe('var(--muted-soft)');
});

// --- TOT Saturday: the first Saturday of the month is a work half day -------------
// 2026-08-01 is the first Saturday of August, so its week (Mon 2026-07-27) is the TOT week.
// The week of Mon 2026-08-03 ends on Saturday 2026-08-08 — the second — so it is ordinary.
const TOT_WEEK = '2026-07-27';
const TOT_SATURDAY = '2026-08-01';
const TOT_THURSDAY = '2026-07-30';
const PLAIN_WEEK = '2026-08-03';

test('a first-Saturday week shows six days by default, an ordinary week five', () => {
    expect(makeComponent({ weekStart: TOT_WEEK }).days).toBe(6);
    expect(makeComponent({ weekStart: PLAIN_WEEK }).days).toBe(5);
});

test('the TOT Saturday is done at 50%, an ordinary day only at 100%', () => {
    const c = makeComponent({ weekStart: TOT_WEEK });
    c.rows = { [TOT_SATURDAY]: [{ category_id: 2, percentage: 50 }], [TOT_THURSDAY]: [{ category_id: 2, percentage: 50 }] };

    expect(c.capacityFor(TOT_SATURDAY)).toBe(50);
    expect(c.dayState(TOT_SATURDAY)).toBe('done');
    expect(c.dayState(TOT_THURSDAY)).toBe('partial');
});

test('the TOT Saturday goes over past 50%', () => {
    const c = makeComponent({ weekStart: TOT_WEEK });
    c.rows = { [TOT_SATURDAY]: [{ category_id: 2, percentage: 75 }] };

    expect(c.dayState(TOT_SATURDAY)).toBe('over');
});

test('a holiday locking the TOT Saturday at 50% reads as fully locked', () => {
    const c = makeComponent({
        weekStart: TOT_WEEK,
        locked: { [TOT_SATURDAY]: { label: 'Cuti Peristiwa', source: 'holiday', percentage: 50 } },
    });

    expect(c.isFullyLocked(TOT_SATURDAY)).toBe(true);
    expect(c.isPartlyLocked(TOT_SATURDAY)).toBe(false);
    expect(c.dayTotal(TOT_SATURDAY)).toBe(50);
    expect(c.dayState(TOT_SATURDAY)).toBe('locked');
});

test('the same 50% lock on an ordinary Saturday is only a half day', () => {
    const plainSaturday = '2026-08-08';
    const c = makeComponent({
        weekStart: PLAIN_WEEK,
        locked: { [plainSaturday]: { label: 'Annual', source: 'leave', percentage: 50 } },
    });

    expect(c.isFullyLocked(plainSaturday)).toBe(false);
    expect(c.isPartlyLocked(plainSaturday)).toBe(true);
});

test('weekEndsOn moves to the TOT Saturday and stays on Friday otherwise', () => {
    expect(makeComponent({ weekStart: TOT_WEEK }).weekEndsOn()).toBe('2026-08-01');
    expect(makeComponent({ weekStart: PLAIN_WEEK }).weekEndsOn()).toBe('2026-08-07');
});

// --- explainRefusal(): a refused save has to say which day it is about ------
// Laravel keys its own field errors `entries.<index>.<field>`, which names a position in
// the flattened array this save sent and nothing a person looking at a week grid can use.

test('explainRefusal() resolves a framework field error back to its day', () => {
    const c = makeComponent({ days: 5 });
    const entries = [
        { entry_date: THURSDAY, category_id: 1, percentage: 50 },
        { entry_date: SATURDAY, category_id: 1, percentage: 50 },
    ];
    const body = { errors: { 'entries.1.project_id': ['The selected project is invalid.'] } };

    expect(c.explainRefusal(body, entries)).toBe('Saturday 8 Aug: The selected project is invalid.');
});

test('explainRefusal() does not name the day twice when the server already named it', () => {
    const c = makeComponent({ days: 5 });
    const entries = [{ entry_date: THURSDAY, category_id: 1, percentage: 50 }];
    // Carbon's 'D, j M' — English regardless of the reader's language, and not the same
    // string as dayLong(), which is why matching on dayLong() alone is not enough.
    const body = { errors: { 'entries.0.entry_date': ['Thu, 6 Aug has not happened yet.'] } };

    expect(c.explainRefusal(body, entries)).toBe('Thu, 6 Aug has not happened yet.');
});

test('explainRefusal() reports every offending day, not just the first', () => {
    const c = makeComponent({ days: 5 });
    const body = {
        errors: {
            submit: [
                'Thu, 6 Aug totals 80% — that day must add up to 100% before submitting.',
                'Fri, 7 Aug totals 50% — that day must add up to 100% before submitting.',
            ],
        },
    };

    expect(c.explainRefusal(body, []).split('\n')).toHaveLength(2);
});

test('explainRefusal() keeps the reason from a refusal that carries no error bag', () => {
    const c = makeComponent({ days: 5 });
    // abort() responses are shaped {message} with no `errors` — the reason used to be
    // dropped on the floor and replaced with a flat "Could not save."
    const body = { message: 'This week has already been submitted and cannot be edited.' };

    expect(c.explainRefusal(body, [])).toBe('This week has already been submitted and cannot be edited.');
});

test('explainRefusal() still falls back when the server said nothing useful', () => {
    const c = makeComponent({ days: 5 });

    expect(c.explainRefusal({}, [])).toBe('Could not save.');
});

test('toastLine() keeps the toast to one line and counts the rest', () => {
    const c = makeComponent({ days: 5 });

    expect(c.toastLine('one problem')).toBe('one problem');
    expect(c.toastLine('first\nsecond\nthird')).toBe('first (+2 more)');
});

// --- rows come from the board: removing one has to stick, restoring one puts it back ---

const PROJECTS = [
    { id: 5, name: 'KPT: RMS', category_ids: [], sub_pillars: [{ id: 50, name: 'Backend' }] },
    { id: 6, name: 'Legacy Project', category_ids: [], sub_pillars: [] },
];

test('removeRow() remembers a board row as dismissed, so the prefill cannot offer it back', () => {
    const c = makeComponent({ days: 5 });
    c.selected = THURSDAY;
    c.rows[THURSDAY] = [
        { work_item_id: 42, category_id: 1, project_id: '', sub_pillar_id: '', description: 'Card notes', percentage: '', suggested: true },
    ];

    c.removeRow(0);

    expect(c.rows[THURSDAY]).toHaveLength(0);
    expect(c.dismissedFor(THURSDAY).map((d) => d.work_item_id)).toEqual([42]);
    expect(c.dismissedPayload()).toEqual({ [THURSDAY]: [42] });
});

test('removeRow() on a row with no card behind it records no dismissal', () => {
    const c = makeComponent({ days: 5 });
    c.selected = THURSDAY;
    c.rows[THURSDAY] = [{ category_id: 1, project_id: '', sub_pillar_id: '', description: '', percentage: 50 }];

    c.removeRow(0);

    expect(c.dismissedFor(THURSDAY)).toEqual([]);
    expect(c.dismissedPayload()).toEqual({});
});

test('restoreRow() puts a struck-off card back uncosted and clears the dismissal', () => {
    const c = makeComponent({ days: 5 });
    c.selected = THURSDAY;
    c.rows[THURSDAY] = [
        { work_item_id: 42, category_id: 7, project_id: 5, sub_pillar_id: '', description: 'Card notes', percentage: '', suggested: true },
    ];
    c.removeRow(0);

    c.restoreRow(THURSDAY, 42);

    const row = c.rows[THURSDAY][0];
    expect(row.work_item_id).toBe(42);
    expect(row.category_id).toBe(7);
    expect(row.project_id).toBe(5);
    expect(row.percentage).toBe('');
    expect(row.suggested).toBe(true);
    expect(c.dismissedFor(THURSDAY)).toEqual([]);
});

test('init() seeds dismissals the last save stored, so a removed row stays removed across a reload', () => {
    const c = makeComponent({
        days: 5,
        dismissed: { [THURSDAY]: [{ work_item_id: 42, title: 'Tender ISCAF', category_id: 1, project_id: '', description: '' }] },
    });
    c.init();

    expect(c.dismissedFor(THURSDAY).map((d) => d.title)).toEqual(['Tender ISCAF']);
    expect(c.dismissedPayload()).toEqual({ [THURSDAY]: [42] });
});

test('the row overlay writes what the staffer was doing back onto the row', () => {
    const c = makeComponent({ days: 5, projects: PROJECTS, subPillars: [{ id: 50, name: 'Technical' }] });
    c.selected = THURSDAY;
    c.rows[THURSDAY] = [
        { work_item_id: 42, category_id: 1, project_id: 5, sub_pillar_id: '', description: '', percentage: '', suggested: true },
    ];

    c.openEditRow(0);
    c.picker.pendingSub = 50;
    c.picker.pendingPct = 60;
    c.picker.pendingDesc = 'Reviewed the submission';
    c.confirmEntry();

    const row = c.rows[THURSDAY][0];
    expect(row.sub_pillar_id).toBe(50);
    expect(row.percentage).toBe(60);
    expect(row.description).toBe('Reviewed the submission');
    expect(row.suggested).toBe(false);
});

test('an uncosted suggestion does not block dayState() from reading done, or count as a blank row', () => {
    const c = makeComponent({ days: 5 });
    c.rows[THURSDAY] = [
        // category 2 (Sales) needs no project — this test is about the suggestion, not
        // about project-requirement gating (see the manual-mode tests for that).
        { category_id: 2, project_id: '', sub_pillar_id: '', description: '', percentage: 100 },
        { work_item_id: 42, category_id: 1, project_id: '', sub_pillar_id: '', description: '', percentage: '', suggested: true },
    ];

    expect(c.hasBlankRows(THURSDAY)).toBe(false);
    expect(c.dayState(THURSDAY)).toBe('done');
});

test('a genuinely blank typed row still blocks dayState() and reads as partial, suggestion or not', () => {
    const c = makeComponent({ days: 5 });
    c.rows[THURSDAY] = [
        { category_id: 1, project_id: '', sub_pillar_id: '', description: '', percentage: 100 },
        { category_id: 2, project_id: '', sub_pillar_id: '', description: '', percentage: '' },
    ];

    expect(c.hasBlankRows(THURSDAY)).toBe(true);
    expect(c.dayState(THURSDAY)).toBe('partial');
});

// ---- Finding 3: init() seed from cfg.suggested -------------------------------------

test('init() appends cfg.suggested rows after saved rows, flagged suggested with a blank percentage', () => {
    const c = makeComponent({
        days: 5,
        existing: { [THURSDAY]: [{ id: 9, category_id: 1, percentage: 40 }] },
        suggested: { [THURSDAY]: [{ work_item_id: 42, category_id: 2, project_id: '', sub_pillar_id: '', description: 'Card X' }] },
    });
    c.init();

    expect(c.rows[THURSDAY].length).toBe(2);
    expect(c.rows[THURSDAY][0].id).toBe(9);
    expect(c.rows[THURSDAY][1].work_item_id).toBe(42);
    expect(c.rows[THURSDAY][1].suggested).toBe(true);
    expect(c.rows[THURSDAY][1].percentage).toBe('');
});

test('init() skips a cfg.suggested row on a fully locked day', () => {
    const c = makeComponent({
        days: 5,
        locked: { [THURSDAY]: { label: 'Public Holiday', source: 'holiday', percentage: 100 } },
        suggested: { [THURSDAY]: [{ work_item_id: 42, category_id: 1, description: '' }] },
    });
    c.init();

    expect(c.rows[THURSDAY]).toBeUndefined();
});

test('init() skips a cfg.suggested row on a non-editable day (before the earliest editable week)', () => {
    const c = makeComponent({
        days: 5,
        earliestWeek: '2026-09-01', // pushes THURSDAY (2026-08-06) out of the editable window
        suggested: { [THURSDAY]: [{ work_item_id: 42, category_id: 1, description: '' }] },
    });
    c.init();

    expect(c.rows[THURSDAY]).toBeUndefined();
});

// ---- Finding 3: flatRows() drops an uncosted suggestion -----------------------------

test('flatRows() drops an uncosted suggestion so it can never block the week submit', () => {
    const c = makeComponent({ days: 5 });
    c.rows[THURSDAY] = [
        // category 2 (Sales) needs no project — this test is about the suggestion, not
        // about project-requirement gating (see the manual-mode tests for that).
        { category_id: 2, project_id: '', sub_pillar_id: '', description: '', percentage: 100, work_item_id: null },
        { work_item_id: 42, category_id: 1, project_id: '', sub_pillar_id: '', description: '', percentage: '', suggested: true },
    ];

    const out = c.flatRows();

    expect(out.length).toBe(1);
    expect(out[0].percentage).toBe(100);
});

test('flatRows() sends a costed (formerly suggested) row, including its work_item_id', () => {
    const c = makeComponent({ days: 5 });
    c.rows[THURSDAY] = [
        { category_id: 1, project_id: '', sub_pillar_id: '', description: '', percentage: 60, work_item_id: 42, suggested: false },
    ];

    const out = c.flatRows();

    expect(out.length).toBe(1);
    expect(out[0].work_item_id).toBe(42);
});

// ---- Finding 3: percentage-write paths clear the suggested flag ---------------------

test('clampPct() clears suggested once the row is given a real percentage', () => {
    const c = makeComponent({ days: 5 });
    const row = { category_id: 1, percentage: '55', suggested: true };

    c.clampPct(row);

    expect(row.suggested).toBe(false);
    expect(row.percentage).toBe(55);
});

test('giveRemainder() clears suggested when it fills a suggestion with the day\'s leftover', () => {
    const c = makeComponent({ days: 5 });
    c.selected = THURSDAY;
    c.rows[THURSDAY] = [
        { category_id: 1, project_id: '', sub_pillar_id: '', description: '', percentage: 70 },
        { work_item_id: 42, category_id: 2, project_id: '', sub_pillar_id: '', description: '', percentage: '', suggested: true },
    ];
    const row = c.rows[THURSDAY][1];

    c.giveRemainder(row);

    expect(row.suggested).toBe(false);
    expect(row.percentage).toBe(30);
});

test('confirmEntry() clears suggested when editing an existing suggested row in place', () => {
    const c = makeComponent({ days: 5 });
    c.selected = THURSDAY;
    c.rows[THURSDAY] = [
        { work_item_id: 42, category_id: 1, project_id: '', sub_pillar_id: '', description: '', percentage: '', suggested: true },
    ];
    c.picker.editingIndex = 0;
    c.picker.pendingItem = c.pickerItem(CATEGORIES[0], null, null);
    c.picker.pendingPct = 80;
    c.picker.pendingDesc = 'Filled it in';

    c.confirmEntry();

    const row = c.rows[THURSDAY][0];
    expect(row.suggested).toBe(false);
    expect(row.percentage).toBe(80);
});

test('a board row with no category is held back from the save and blocks the week', () => {
    const c = makeComponent({ days: 5 });
    c.selected = THURSDAY;
    c.rows[THURSDAY] = [
        { work_item_id: 42, category_id: '', project_id: '', sub_pillar_id: '', description: '', percentage: 100 },
    ];

    expect(c.needsCategory(c.rows[THURSDAY][0])).toBe(true);
    expect(c.flatRows()).toEqual([]);
    expect(c.hasBlankRows(THURSDAY)).toBe(true);
});

test('a board row that has a category is sent as usual', () => {
    const c = makeComponent({ days: 5 });
    c.selected = THURSDAY;
    c.rows[THURSDAY] = [
        { work_item_id: 42, category_id: 4, project_id: '', sub_pillar_id: '', description: '', percentage: 100 },
    ];

    expect(c.flatRows()).toHaveLength(1);
    expect(c.hasBlankRows(THURSDAY)).toBe(false);
});

// ---- The card's own title, and answering a card that reached the sheet uncategorised ----

test('rowTitle() names the line by its card, and falls back to the classification when there is none', () => {
    const c = makeComponent({ days: 5, projects: PROJECTS });

    expect(c.rowTitle({ title: 'Tender ISCAF', category_id: 1, project_id: 5 })).toBe('Tender ISCAF');
    expect(c.rowTitle({ title: '', category_id: 1, project_id: 5 })).toBe('Development · KPT: RMS');
});

test('init() carries the card title through both saved rows and board suggestions', () => {
    const c = makeComponent({
        days: 5,
        existing: { [THURSDAY]: [{ id: 9, title: 'Saved card', category_id: 1, percentage: 50 }] },
        suggested: { [THURSDAY]: [{ work_item_id: 42, title: 'Suggested card', category_id: null }] },
    });
    c.init();

    expect(c.rows[THURSDAY].map((r) => r.title)).toEqual(['Saved card', 'Suggested card']);
});

test('the overlay asks for a category only when the row arrived without one, and writes the pick back', () => {
    const c = makeComponent({ days: 5, projects: PROJECTS });
    c.selected = THURSDAY;
    c.rows[THURSDAY] = [
        { work_item_id: 42, title: 'No category card', category_id: '', project_id: '', sub_pillar_id: '', description: '', percentage: '', suggested: true },
        { work_item_id: 43, title: 'Has one', category_id: 1, project_id: '', sub_pillar_id: '', description: '', percentage: 50 },
    ];

    c.openEditRow(1);
    expect(c.picker.askCat).toBe(false);
    c.closePicker();

    c.openEditRow(0);
    expect(c.picker.askCat).toBe(true);
    c.picker.pendingCat = 2;
    c.picker.pendingPct = 50;
    c.confirmEntry();

    expect(c.rows[THURSDAY][0].category_id).toBe(2);
    expect(c.rows[THURSDAY][1].category_id).toBe(1);
    expect(c.needsCategory(c.rows[THURSDAY][0])).toBe(false);
});

test('answering one day of a card fills that card\'s other uncategorised days too', () => {
    const c = makeComponent({ days: 5 });
    c.selected = THURSDAY;
    c.rows[THURSDAY] = [
        { work_item_id: 42, title: 'Ran all week', category_id: '', project_id: '', sub_pillar_id: '', description: '', percentage: '', suggested: true },
    ];
    c.rows['2026-08-05'] = [
        { work_item_id: 42, title: 'Ran all week', category_id: '', project_id: '', sub_pillar_id: '', description: '', percentage: '', suggested: true },
        { work_item_id: 43, title: 'A different card', category_id: '', project_id: '', sub_pillar_id: '', description: '', percentage: '', suggested: true },
    ];

    c.openEditRow(0);
    c.picker.pendingCat = 2;
    c.picker.pendingPct = 100;
    c.confirmEntry();

    expect(c.rows['2026-08-05'][0].category_id).toBe(2);
    expect(c.rows['2026-08-05'][1].category_id).toBe('');
});

test('a row still without a category never reaches the server', () => {
    const c = makeComponent({ days: 5 });
    c.rows[THURSDAY] = [
        { work_item_id: 42, title: 'No category card', category_id: '', project_id: '', sub_pillar_id: '', description: '', percentage: 50 },
    ];

    expect(c.flatRows()).toEqual([]);
});

// ---- Manual mode: a row nobody's board card ever proposed --------------------------
// PROJECTS[0] (KPT: RMS) is used as the manual line's project below; its category_ids
// is [] on purpose (see PROJECTS above), so it stays reachable under any category.

test('fillFromBoard defaults true, and reflects cfg.fillFromBoard when given', () => {
    expect(makeComponent({ days: 5 }).fillFromBoard).toBe(true);
    expect(makeComponent({ days: 5, fillFromBoard: false }).fillFromBoard).toBe(false);
});

test('fillFromBoard: false does not touch rows already saved for the week', () => {
    const c = makeComponent({
        days: 5,
        fillFromBoard: false,
        existing: { [THURSDAY]: [{ id: 9, category_id: 1, percentage: 40 }] },
    });
    c.init();

    expect(c.rows[THURSDAY]).toHaveLength(1);
    expect(c.rows[THURSDAY][0].id).toBe(9);
});

test('addManualRow() opens the picker on a fresh line with askCat true', () => {
    const c = makeComponent({ days: 5 });
    c.selected = THURSDAY;

    c.addManualRow();

    expect(c.rows[THURSDAY]).toHaveLength(1);
    expect(c.rows[THURSDAY][0].work_item_id).toBeNull();
    expect(c.picker.open).toBe(true);
    expect(c.picker.askCat).toBe(true);
    expect(c.picker.isManual).toBe(true);
});

test('isBlank() reads a fresh manual row as blank', () => {
    const c = makeComponent({ days: 5 });
    const row = { id: null, work_item_id: null, category_id: '', project_id: '', sub_pillar_id: '', description: '', percentage: '', suggested: false, manual: true };

    expect(c.isBlank(row)).toBe(true);
});

test('rowLabel() names an unfinished manual row "New line" instead of rendering blank', () => {
    const c = makeComponent({ days: 5 });
    const row = { work_item_id: null, category_id: '', project_id: '', sub_pillar_id: '' };

    expect(c.rowLabel(row)).toBe('New line');
});

test('confirmEntry() withholds a manual row whose category requires a project until one is picked', () => {
    const c = makeComponent({ days: 5, projects: PROJECTS });
    c.selected = THURSDAY;
    c.addManualRow();

    c.pickManualCategory(1); // Development, requires_project: true
    expect(c.picker.askProject).toBe(true);
    c.picker.pendingPct = 50;
    c.confirmEntry();

    expect(c.flatRows()).toEqual([]);
});

test('confirmEntry() writes a manual row once category, project and percentage are all set, with work_item_id null', () => {
    const c = makeComponent({ days: 5, projects: PROJECTS });
    c.selected = THURSDAY;
    c.addManualRow();

    c.pickManualCategory(1);
    c.picker.pendingProject = 5;
    c.picker.pendingPct = 50;
    c.confirmEntry();

    const out = c.flatRows();
    expect(out).toHaveLength(1);
    expect(out[0].category_id).toBe(1);
    expect(out[0].project_id).toBe(5);
    expect(out[0].percentage).toBe(50);
    expect(out[0].work_item_id).toBeNull();
});

test('confirmEntry() writes a manual row under a category that needs no project, with project_id null', () => {
    const c = makeComponent({ days: 5, projects: PROJECTS });
    c.selected = THURSDAY;
    c.addManualRow();

    c.pickManualCategory(2); // Sales, requires_project: false
    c.picker.pendingPct = 100;
    c.confirmEntry();

    const out = c.flatRows();
    expect(out).toHaveLength(1);
    expect(out[0].project_id).toBeNull();
});

test('pickManualCategory() clears a pending project when switching to a category that no longer needs one', () => {
    const c = makeComponent({ days: 5, projects: PROJECTS });
    c.selected = THURSDAY;
    c.addManualRow();
    c.pickManualCategory(1);
    c.picker.pendingProject = 5;

    c.pickManualCategory(2);

    expect(c.picker.askProject).toBe(false);
    expect(c.picker.pendingProject).toBe('');
});

test('openEditRow() never asks for a project on a card row, even under a category that requires one', () => {
    const c = makeComponent({ days: 5, projects: PROJECTS });
    c.selected = THURSDAY;
    c.rows[THURSDAY] = [
        { work_item_id: 42, category_id: 1, project_id: 5, sub_pillar_id: '', description: '', percentage: 50 },
    ];

    c.openEditRow(0);

    expect(c.picker.isManual).toBe(false);
    expect(c.picker.askProject).toBe(false);
});
