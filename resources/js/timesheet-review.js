/**
 * Timesheet Review tab — read-only week-by-week view of the signed-in employee's own
 * entries. Reuses the week-block shape and step-through-preloaded-weeks pattern
 * timesheet-report.js's person drill-down already established (weekIdx/prevWeek/
 * nextWeek, no fetch per step), for one person's own weeks instead of a viewed
 * colleague's.
 */
import { formatDays, groupLinesByDay } from './timesheet-report';

/* Re-exported: the grouping moved to timesheet-report.js when the all-staff report
   started grouping its own day lines too, and both surfaces must group identically. */
export { groupLinesByDay };

/**
 * Build the link into Record for one entry line: its week, its edit form. Lines with
 * no `id` are system-generated (leave/holiday) — Record has no editable row for those
 * (see TimesheetController::existingGrid, which excludes source-tagged entries), so
 * there is nothing to link to.
 */
export function reviewEntryUrl(baseUrl, weekStart, line) {
    // No baseUrl = somebody else's weeks on the all-staff report. There is no edit
    // path into another person's sheet, so every line renders as plain text.
    if (!baseUrl || !line.id) return null;
    const sep = baseUrl.includes('?') ? '&' : '?';

    return `${baseUrl}${sep}tab=record&week=${encodeURIComponent(weekStart)}&edit=${encodeURIComponent(line.id)}`;
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

        daysInWeek(wk) { return groupLinesByDay(wk.lines); },
        md(value) { return formatDays(value); },
        entryUrl(line) { return reviewEntryUrl(this.baseUrl, this.currentWeek?.weekStart, line); },
    }));
}
