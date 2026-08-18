import { test, expect } from 'bun:test';
import { fmtDays, reviewEntryUrl } from './timesheet-review';

test('fmtDays() drops trailing zeros', () => {
    expect(fmtDays(1)).toBe('1');
    expect(fmtDays(0.5)).toBe('0.5');
    expect(fmtDays(1.25)).toBe('1.25');
});

test('reviewEntryUrl() builds a tab=record + week + edit link for a normal entry', () => {
    const url = reviewEntryUrl('/app/timesheets', '2026-06-15', { id: 42 });
    expect(url).toBe('/app/timesheets?tab=record&week=2026-06-15&edit=42');
});

test('reviewEntryUrl() returns null for a system-generated line (no id)', () => {
    expect(reviewEntryUrl('/app/timesheets', '2026-06-15', { id: null })).toBeNull();
});
