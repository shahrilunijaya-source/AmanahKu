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

// --- "Pull from board": pick an In Progress card, then fill in the same details step ---

const PROJECTS = [
    { id: 5, name: 'KPT: RMS', category_ids: [], sub_pillars: [{ id: 50, name: 'Backend' }] },
    { id: 6, name: 'Legacy Project', category_ids: [], sub_pillars: [] },
];
const BOARD_TASKS = [
    { id: 100, title: 'Tender ISCAF', description: 'Prep the submission', project_id: 5 },
    { id: 101, title: 'No project card', description: '', project_id: null },
];

test("chooseSource('board') opens the picker on the board step", () => {
    const c = makeComponent({ days: 5, boardTasks: BOARD_TASKS });
    c.openPicker();
    c.chooseSource('board');

    expect(c.picker.open).toBe(true);
    expect(c.picker.step).toBe('board');
});

test('chooseBoardTask() carries the card into a category step, pre-filling its project and description', () => {
    const c = makeComponent({ days: 5, projects: PROJECTS, boardTasks: BOARD_TASKS });
    c.openPicker();
    c.chooseSource('board');
    c.chooseBoardTask(BOARD_TASKS[0]);

    expect(c.picker.step).toBe('category');
    expect(c.picker.boardProject.id).toBe(5);
    expect(c.picker.boardDesc).toBe('Prep the submission');
});

test('chooseBoardTask() falls back to the card title when it has no description', () => {
    const c = makeComponent({ days: 5, projects: PROJECTS, boardTasks: BOARD_TASKS });
    c.openPicker();
    c.chooseSource('board');
    c.chooseBoardTask(BOARD_TASKS[1]);

    expect(c.picker.boardProject).toBeNull();
    expect(c.picker.boardDesc).toBe('No project card');
});

test('picking a project-requiring category after a board pull skips straight to sub-pillar, project already set', () => {
    const c = makeComponent({ days: 5, projects: PROJECTS, boardTasks: BOARD_TASKS });
    c.openPicker();
    c.chooseSource('board');
    c.chooseBoardTask(BOARD_TASKS[0]); // project 5 has a sub-pillar
    c.chooseStep({ c: CATEGORIES[0], label: 'Development', item: null }); // requires_project: true

    expect(c.picker.step).toBe('sub');
    expect(c.picker.project.id).toBe(5);
});

test('a board-pulled project with no sub-pillars lands straight on the details step, notes pre-filled', () => {
    const c = makeComponent({
        days: 5,
        projects: [{ id: 6, name: 'Legacy Project', category_ids: [], sub_pillars: [] }],
        boardTasks: [{ id: 102, title: 'Quick fix', description: 'Patch the thing', project_id: 6 }],
    });
    c.openPicker();
    c.chooseSource('board');
    c.chooseBoardTask(c.boardTasks[0]);
    c.chooseStep({ c: CATEGORIES[0], label: 'Development', item: null });

    expect(c.picker.step).toBe('details');
    expect(c.picker.pendingItem.project_id).toBe(6);
    expect(c.picker.pendingDesc).toBe('Patch the thing');
});

test('a category that does not require a project ignores a board-pulled project and stays terminal', () => {
    const c = makeComponent({ days: 5, projects: PROJECTS, boardTasks: BOARD_TASKS });
    c.openPicker();
    c.chooseSource('board');
    c.chooseBoardTask(BOARD_TASKS[0]);
    // requires_project: false, so pickerCategories() would hand this option a terminal item.
    c.chooseStep({ c: CATEGORIES[1], label: 'Sales', item: c.pickerItem(CATEGORIES[1], null, null) });

    expect(c.picker.step).toBe('details');
    expect(c.picker.pendingItem.project_id).toBe('');
});

test('manually adding an entry never carries a leftover board description from an earlier pull', () => {
    const c = makeComponent({ days: 5, projects: PROJECTS, boardTasks: BOARD_TASKS });
    c.openPicker();
    c.chooseSource('board');
    c.chooseBoardTask(BOARD_TASKS[0]);
    c.closePicker();

    c.openPicker();
    c.chooseItem(c.pickerItem(CATEGORIES[1], null, null));

    expect(c.picker.pendingDesc).toBe('');
});

test('pickerBack() from category returns to the board list when the flow started there', () => {
    const c = makeComponent({ days: 5, projects: PROJECTS, boardTasks: BOARD_TASKS });
    c.openPicker();
    c.chooseSource('board');
    c.chooseBoardTask(BOARD_TASKS[0]);

    c.pickerBack();

    expect(c.picker.step).toBe('board');
    expect(c.picker.open).toBe(true);
});

test('pickerBack() from category still closes the picker for a manually-started flow', () => {
    const c = makeComponent({ days: 5 });
    c.openPicker();

    c.pickerBack();

    expect(c.picker.open).toBe(false);
});

test('an uncosted suggestion does not block dayState() from reading done, or count as a blank row', () => {
    const c = makeComponent({ days: 5 });
    c.rows[THURSDAY] = [
        { category_id: 1, project_id: '', sub_pillar_id: '', description: '', percentage: 100 },
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

// ---- Finding 2: abandoned board state must not stamp a manual row -------------------

test('choosing "Enter manually" straight from the source step never carries board state (baseline)', () => {
    const c = makeComponent({ days: 5, projects: PROJECTS, boardTasks: BOARD_TASKS });
    c.openPicker();
    c.chooseSource('manual');

    expect(c.picker.viaBoard).toBe(false);
    expect(c.picker.boardWorkItemId).toBeNull();
});

test('Add > Pull from board > pick card > Back > Back > Enter manually clears the abandoned card before a row is added', () => {
    const c = makeComponent({ days: 5, projects: PROJECTS, boardTasks: BOARD_TASKS });
    c.selected = THURSDAY;
    c.openPicker();
    c.chooseSource('board');
    c.chooseBoardTask(BOARD_TASKS[0]); // lands on 'category', viaBoard true, boardWorkItemId 100

    c.pickerBack(); // category -> board
    expect(c.picker.step).toBe('board');
    c.pickerBack(); // board -> source
    expect(c.picker.step).toBe('source');

    // Stale state is still sitting there right up until the source choice is (re-)made.
    expect(c.picker.viaBoard).toBe(true);
    expect(c.picker.boardWorkItemId).toBe(100);

    c.chooseSource('manual');
    expect(c.picker.viaBoard).toBe(false);
    expect(c.picker.boardWorkItemId).toBeNull();
    expect(c.picker.boardProject).toBeNull();
    expect(c.picker.boardDesc).toBe('');

    // Typing a manual line through to the end must not pick up card 100's id or project.
    c.chooseStep({ c: CATEGORIES[1], label: 'Sales', item: c.pickerItem(CATEGORIES[1], null, null) });
    c.picker.pendingPct = 100;
    c.confirmEntry();

    const row = c.rows[c.selected][0];
    expect(row.work_item_id).toBeNull();
    expect(row.project_id).toBe('');
});
