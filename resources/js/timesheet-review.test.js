import { test, expect } from 'bun:test';
import { reviewEntryUrl, groupLinesByDay } from './timesheet-review';

test('reviewEntryUrl() builds a tab=record + week + edit link for a normal entry', () => {
    const url = reviewEntryUrl('/app/timesheets', '2026-06-15', { id: 42 });
    expect(url).toBe('/app/timesheets?tab=record&week=2026-06-15&edit=42');
});

test('reviewEntryUrl() returns null with no baseUrl (somebody else\'s weeks)', () => {
    expect(reviewEntryUrl(null, '2026-06-15', { id: 42 })).toBeNull();
});

test('reviewEntryUrl() returns null for a system-generated line (no id)', () => {
    expect(reviewEntryUrl('/app/timesheets', '2026-06-15', { id: null })).toBeNull();
});

test('groupLinesByDay() groups consecutive same-day lines under one heading', () => {
    const lines = [
        { id: 1, day: 'Mon 27 Jul', category: 'Maintenance' },
        { id: 2, day: 'Mon 27 Jul', category: 'Development' },
        { id: 3, day: 'Tue 28 Jul', category: 'Maintenance' },
    ];
    const groups = groupLinesByDay(lines);
    expect(groups.length).toBe(2);
    expect(groups[0]).toEqual({ day: 'Mon 27 Jul', lines: [lines[0], lines[1]], days: 0 });
    expect(groups[1]).toEqual({ day: 'Tue 28 Jul', lines: [lines[2]], days: 0 });
});

test('groupLinesByDay() keeps day order as first-seen, even if the same day is not contiguous', () => {
    const lines = [
        { id: 1, day: 'Mon 27 Jul' },
        { id: 2, day: 'Tue 28 Jul' },
        { id: 3, day: 'Mon 27 Jul' },
    ];
    const groups = groupLinesByDay(lines);
    expect(groups.map((g) => g.day)).toEqual(['Mon 27 Jul', 'Tue 28 Jul']);
    expect(groups[0].lines.map((l) => l.id)).toEqual([1, 3]);
});

test('groupLinesByDay() returns an empty array for an empty week', () => {
    expect(groupLinesByDay([])).toEqual([]);
});
