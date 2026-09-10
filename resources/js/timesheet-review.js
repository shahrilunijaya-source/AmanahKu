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

/** 'Mon 15 Jun' — matches the capture screen's own day formatting. */
export function dayLabel(iso) {
    const dt = new Date(iso + 'T00:00:00Z');
    const weekday = dt.toLocaleDateString('en-GB', { weekday: 'short', timeZone: 'UTC' });
    const rest = dt.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' });

    return `${weekday} ${rest}`;
}

/** wk.dayStatuses (ISO date -> {status, late, resubmitted, zero_reason, return_reason,
 *  unlocked}) as a sorted, labelled list for the manager status strip (CR-03). */
export function dayList(wk, manage = false) {
    const statuses = wk.dayStatuses || {};
    const isos = new Set(Object.keys(statuses));
    // A manager needs a row for every working day, saved or not: an unsaved Monday
    // beyond the edit window has no timesheet_days row yet and can still be unlocked.
    if (manage && wk.weekStart) {
        const start = new Date(wk.weekStart + 'T00:00:00Z');
        for (let i = 0; i < 5; i++) {
            const d = new Date(start);
            d.setUTCDate(start.getUTCDate() + i);
            isos.add(d.toISOString().slice(0, 10));
        }
    }

    return [...isos].sort().map((iso) => ({ iso, label: dayLabel(iso), status: 'draft', ...(statuses[iso] || {}) }));
}

export function registerTimesheetReview(Alpine) {
    Alpine.data('timesheetReview', (cfg) => ({
        baseUrl: cfg.baseUrl,
        weeks: cfg.weeks || [],
        weekIdx: Math.max(0, (cfg.weeks || []).length - 1), // default: most recent week
        weekDir: 'fwd',
        // Manager actions (CR-03): the employee id to act against, or null on the
        // personal Review tab (no manage props passed there).
        manage: cfg.manage || null,
        // One inline reason box open at a time, named by which action opened it plus
        // the ISO date it belongs to, e.g. 'return:2026-06-19'.
        reasonOpen: null,
        reasonText: '',
        dayError: '',
        dayBusy: false,

        get currentWeek() { return this.weeks[this.weekIdx] || null; },
        prevWeek() { if (this.weekIdx > 0) { this.weekDir = 'back'; this.weekIdx--; } },
        nextWeek() { if (this.weekIdx < this.weeks.length - 1) { this.weekDir = 'fwd'; this.weekIdx++; } },

        daysInWeek(wk) { return groupLinesByDay(wk.lines); },
        dayList(wk) { return dayList(wk, !!this.manage); },
        md(value) { return formatDays(value); },
        entryUrl(line) { return reviewEntryUrl(this.baseUrl, this.currentWeek?.weekStart, line); },

        // ---- manager day actions (CR-03) -----------------------------------
        canReturn(day) { return day.status === 'submitted' || day.status === 'approved'; },
        canApprove(day) { return day.status === 'submitted'; },
        canUnlock(day) { return day.status !== 'submitted' && day.status !== 'approved'; },
        anySubmitted() { return (this.currentWeek?.dayStatuses ? Object.values(this.currentWeek.dayStatuses) : []).some((d) => d.status === 'submitted'); },

        openReason(action, iso) {
            this.reasonOpen = `${action}:${iso}`;
            this.reasonText = '';
            this.dayError = '';
        },
        closeReason() {
            this.reasonOpen = null;
            this.reasonText = '';
        },
        isReasonOpen(action, iso) { return this.reasonOpen === `${action}:${iso}`; },

        async postDay(iso, path, body) {
            this.dayBusy = true;
            this.dayError = '';
            try {
                const res = await fetch(`/app/timesheets/${this.manage}/days/${iso}/${path}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify(body || {}),
                });
                const resBody = await res.json();
                if (!res.ok) {
                    this.dayError = resBody.message || 'Could not save.';

                    return;
                }
                if (this.currentWeek) {
                    // An empty PHP array arrives as [], not {}; give it a real map first.
                    if (!this.currentWeek.dayStatuses || Array.isArray(this.currentWeek.dayStatuses)) this.currentWeek.dayStatuses = {};
                    this.currentWeek.dayStatuses[iso] = resBody.day;
                }
                this.closeReason();
            } catch (e) {
                this.dayError = this.$store.ui.lang === 'en' ? 'Could not reach the server.' : 'Tak dapat hubungi pelayan.';
            } finally {
                this.dayBusy = false;
            }
        },
        returnDay(iso) {
            if (!this.reasonText.trim()) return;
            this.postDay(iso, 'return', { reason: this.reasonText });
        },
        approveDay(iso) {
            this.postDay(iso, 'approve');
        },
        unlockDay(iso) {
            if (!this.reasonText.trim()) return;
            this.postDay(iso, 'unlock', { reason: this.reasonText });
        },
        async approveWeek() {
            if (!this.manage || !this.currentWeek) return;
            this.dayBusy = true;
            this.dayError = '';
            try {
                const res = await fetch(`/app/timesheets/${this.manage}/approve-week`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({ week_start: this.currentWeek.weekStart }),
                });
                const resBody = await res.json();
                if (!res.ok) {
                    this.dayError = resBody.message || 'Could not save.';

                    return;
                }
                for (const iso of Object.keys(this.currentWeek.dayStatuses)) {
                    if (this.currentWeek.dayStatuses[iso].status === 'submitted') {
                        this.currentWeek.dayStatuses[iso].status = 'approved';
                    }
                }
            } catch (e) {
                this.dayError = this.$store.ui.lang === 'en' ? 'Could not reach the server.' : 'Tak dapat hubungi pelayan.';
            } finally {
                this.dayBusy = false;
            }
        },
    }));
}
