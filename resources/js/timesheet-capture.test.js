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
