import { test, expect } from 'bun:test';
import { matchesQuery, monthGrid, parseTime, minuteOptions, outOfRange, isoDate } from './pickers';

test('search matches every typed word in any order, ignoring case', () => {
    expect(matchesQuery('Ahmad Kussairi Bin Sutikno', 'kus sut')).toBe(true);
    expect(matchesQuery('Ahmad Kussairi Bin Sutikno', 'SUTIKNO ahmad')).toBe(true);
    expect(matchesQuery('Ahmad Kussairi Bin Sutikno', 'kus haryati')).toBe(false);
    expect(matchesQuery('Anything', '   ')).toBe(true);
});

test('month grid is six Monday-first weeks', () => {
    const nov = monthGrid(2026, 10); // 1 Nov 2026 is a Sunday
    expect(nov).toHaveLength(42);
    expect(nov[0]).toMatchObject({ iso: '2026-10-26', inMonth: false });
    expect(nov[6]).toMatchObject({ iso: '2026-11-01', inMonth: true });
    expect(nov.filter((c) => c.inMonth)).toHaveLength(30);
    // A month starting on Monday has no lead-in days.
    expect(monthGrid(2026, 5)[0].iso).toBe('2026-06-01');
    // Year rollover.
    expect(monthGrid(2026, 11).at(-1).iso).toBe('2027-01-10');
});

test('time parsing', () => {
    expect(parseTime('09:05')).toEqual({ h: 9, m: 5 });
    expect(parseTime('23:59:30')).toEqual({ h: 23, m: 59 });
    expect(parseTime('')).toBeNull();
    expect(parseTime('9:5')).toBeNull();
});

test('minute column follows step and never hides the saved minute', () => {
    expect(minuteOptions(null, null)).toHaveLength(12);
    expect(minuteOptions('900', null)).toEqual([0, 15, 30, 45]);
    expect(minuteOptions('900', 7)).toEqual([0, 7, 15, 30, 45]);
    expect(minuteOptions('30', null)).toHaveLength(12); // sub-minute step falls back to 5
});

test('min/max bounds and iso dates', () => {
    expect(isoDate(2026, 0, 5)).toBe('2026-01-05');
    expect(outOfRange('2026-01-05', '2026-01-06', '')).toBe(true);
    expect(outOfRange('2026-01-05', '', '2026-01-05')).toBe(false);
    expect(outOfRange('2026-01-05', '', '')).toBe(false);
});
