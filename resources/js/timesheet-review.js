/**
 * Timesheet Review tab — read-only week-by-week view of the signed-in employee's own
 * entries. Reuses the week-block shape and step-through-preloaded-weeks pattern
 * timesheet-report.js's person drill-down already established (weekIdx/prevWeek/
 * nextWeek, no fetch per step), for one person's own weeks instead of a viewed
 * colleague's.
 */
import { dayColor } from './timesheet-report';

/**
 * Build the link into Record for one entry line: its week, its edit form. Lines with
 * no `id` are system-generated (leave/holiday) — Record has no editable row for those
 * (see TimesheetController::existingGrid, which excludes source-tagged entries), so
 * there is nothing to link to.
 */
export function reviewEntryUrl(baseUrl, weekStart, line) {
    if (!line.id) return null;
    const sep = baseUrl.includes('?') ? '&' : '?';

    return `${baseUrl}${sep}tab=record&week=${encodeURIComponent(weekStart)}&edit=${encodeURIComponent(line.id)}`;
}

/**
 * A week's `lines` (backend-shared with the all-staff report, one flat array — see
 * TimesheetController::buildWeekBlocks()) grouped into one heading per day, in the
 * order days first appear. Frontend-only: the report screen still gets the flat
 * array unchanged, this grouping is Review's own presentation choice.
 */
export function groupLinesByDay(lines) {
    const groups = [];
    const byDay = new Map();
    for (const line of lines) {
        if (!byDay.has(line.day)) {
            const group = { day: line.day, lines: [] };
            byDay.set(line.day, group);
            groups.push(group);
        }
        byDay.get(line.day).lines.push(line);
    }

    return groups;
}

export function registerTimesheetReview(Alpine) {
    Alpine.data('timesheetReview', (cfg) => ({
        baseUrl: cfg.baseUrl,
        weeks: cfg.weeks || [],
        weekIdx: Math.max(0, (cfg.weeks || []).length - 1), // default: most recent week
        weekDir: 'fwd',

        get currentWeek() { return this.weeks[this.weekIdx] || null; },
        prevWeek() { if (this.weekIdx > 0) { this.weekDir = 'back'; this.weekIdx--; } },
        nextWeek() { if (this.weekIdx < this.weeks.length - 1) { this.weekDir = 'fwd'; this.weekIdx++; } },

        dayColor,
        daysInWeek(wk) { return groupLinesByDay(wk.lines); },
        entryUrl(line) { return reviewEntryUrl(this.baseUrl, this.currentWeek?.weekStart, line); },
    }));
}
