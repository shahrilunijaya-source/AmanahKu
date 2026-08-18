import { test, expect } from 'bun:test';
import { reviewEntryUrl } from './timesheet-review';

test('reviewEntryUrl() builds a tab=record + week + edit link for a normal entry', () => {
    const url = reviewEntryUrl('/app/timesheets', '2026-06-15', { id: 42 });
    expect(url).toBe('/app/timesheets?tab=record&week=2026-06-15&edit=42');
});

test('reviewEntryUrl() returns null for a system-generated line (no id)', () => {
    expect(reviewEntryUrl('/app/timesheets', '2026-06-15', { id: null })).toBeNull();
});
