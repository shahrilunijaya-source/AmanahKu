import { test, expect } from 'bun:test';
import { registerTimesheetCapture } from './timesheet-capture';

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
