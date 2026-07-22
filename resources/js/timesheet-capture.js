/**
 * Weekly timesheet capture — one day at a time.
 *
 * Replaces the lines-by-days matrix, which testers could not operate: the day columns
 * scrolled sideways inside their card, and choosing what you worked on happened in a panel
 * that expanded between grid rows. This component shows a week strip for navigation and
 * progress, and exactly one editable day beneath it, so the layout is identical on a phone
 * and on a laptop and nothing scrolls sideways.
 *
 * State is `rows`, an ISO date → array of allocations. Locked days (approved leave, public
 * holidays) come from the server, are never editable, and always count as a full day.
 * The POST body is unchanged: one entry per (day, allocation).
 */
export function registerTimesheetCapture(Alpine) {
    Alpine.data('timesheetCapture', (cfg) => ({
        weekStart: cfg.weekStart,
        days: cfg.days || 5,
        today: cfg.today,
        earliestWeek: cfg.earliestWeek,
        locked: cfg.locked || {},
        items: cfg.items || [],
        categories: cfg.categories || [],
        projects: cfg.projects || [],
        templates: cfg.templates || [],
        readonly: cfg.readonly || false,
        rows: {},
        selected: null,
        saving: false,
        savedAt: null,
        error: '',

        init() {
            const seed = cfg.existing || {};
            for (const iso of Object.keys(seed)) {
                if (this.locked[iso]) continue;
                this.rows[iso] = seed[iso].map((e) => ({
                    category_id: e.category_id || '',
                    project_id: e.project_id || '',
                    sub_pillar_id: e.sub_pillar_id || '',
                    description: e.description || '',
                    percentage: e.percentage,
                }));
            }
            this.selected = this.firstDayNeedingWork();
        },

        // ---- the week ------------------------------------------------------
        dayDates() {
            const out = [];
            const [y, m, d] = this.weekStart.split('-').map(Number);
            for (let i = 0; i < this.days; i++) {
                const dt = new Date(Date.UTC(y, m - 1, d + i));
                out.push(dt.toISOString().slice(0, 10));
            }
            return out;
        },
        dayName(iso) {
            return new Date(iso + 'T00:00:00Z')
                .toLocaleDateString('en-GB', { weekday: 'short', timeZone: 'UTC' });
        },
        dayLong(iso) {
            return new Date(iso + 'T00:00:00Z')
                .toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'short', timeZone: 'UTC' });
        },
        isLocked(iso) {
            return !!this.locked[iso];
        },
        isFuture(iso) {
            return iso > this.today;
        },
        isEditable(iso) {
            return !this.readonly && !this.isLocked(iso) && !this.isFuture(iso) && iso >= this.earliestWeek;
        },
        dayTotal(iso) {
            if (this.isLocked(iso)) return 100;
            return (this.rows[iso] || []).reduce((sum, r) => sum + (parseFloat(r.percentage) || 0), 0);
        },
        dayState(iso) {
            if (this.isLocked(iso)) return 'locked';
            if (this.isFuture(iso)) return 'future';
            const total = this.dayTotal(iso);
            if (total === 0) return 'empty';
            if (Math.abs(total - 100) < 0.01) return 'done';
            return total > 100 ? 'over' : 'partial';
        },
        firstDayNeedingWork() {
            const days = this.dayDates().filter((d) => this.isEditable(d));
            return days.find((d) => this.dayState(d) !== 'done') || days[days.length - 1] || this.weekStart;
        },
        select(iso) {
            if (this.isFuture(iso)) return;
            this.save();
            this.selected = iso;
        },

        // ---- rows ----------------------------------------------------------
        addRow(item, percentage) {
            const iso = this.selected;
            if (!this.isEditable(iso)) return;
            if (!this.rows[iso]) this.rows[iso] = [];
            this.rows[iso].push({
                category_id: item.category_id,
                project_id: item.project_id || '',
                sub_pillar_id: item.sub_pillar_id || '',
                description: '',
                percentage: percentage != null ? percentage : this.remainder(iso),
            });
        },
        removeRow(i) {
            this.rows[this.selected].splice(i, 1);
        },
        remainder(iso) {
            return Math.max(0, Math.round((100 - this.dayTotal(iso)) * 100) / 100);
        },
        rowLabel(r) {
            const cat = this.categories.find((c) => String(c.id) === String(r.category_id));
            const proj = this.projects.find((p) => String(p.id) === String(r.project_id));
            const sub = proj && (proj.sub_pillars || []).find((s) => String(s.id) === String(r.sub_pillar_id));
            return [cat && cat.name, proj && proj.name, sub && sub.name].filter(Boolean).join(' · ');
        },

        // ---- accelerators --------------------------------------------------
        previousWorkday(iso) {
            const days = this.dayDates();
            const idx = days.indexOf(iso);
            for (let i = idx - 1; i >= 0; i--) {
                if (this.isEditable(days[i]) && (this.rows[days[i]] || []).length) return days[i];
            }
            return null;
        },
        copyPreviousDay() {
            const src = this.previousWorkday(this.selected);
            if (!src) return;
            this.rows[this.selected] = this.rows[src].map((r) => ({ ...r }));
        },
        fillRemainder() {
            const iso = this.selected;
            const list = this.rows[iso] || [];
            if (!list.length) return;
            const last = list[list.length - 1];
            const others = this.dayTotal(iso) - (parseFloat(last.percentage) || 0);
            last.percentage = Math.max(0, Math.round((100 - others) * 100) / 100);
        },

        // ---- submit gate ---------------------------------------------------
        blockingDays() {
            return this.dayDates()
                .filter((d) => this.isEditable(d) && this.dayState(d) !== 'done')
                .map((d) => new Date(d + 'T00:00:00Z').toLocaleDateString('en-GB', { weekday: 'long', timeZone: 'UTC' }));
        },
        weekComplete() {
            return this.blockingDays().length === 0;
        },

        // ---- persistence ---------------------------------------------------
        flatRows() {
            const out = [];
            for (const iso of Object.keys(this.rows)) {
                if (this.isLocked(iso)) continue;
                for (const r of this.rows[iso]) {
                    const pct = parseFloat(r.percentage) || 0;
                    if (pct <= 0) continue;
                    out.push({
                        entry_date: iso,
                        category_id: r.category_id,
                        project_id: r.project_id || null,
                        sub_pillar_id: r.sub_pillar_id || null,
                        percentage: pct,
                        description: r.description || null,
                    });
                }
            }
            return out;
        },
        async save(submitNow = false) {
            if (this.readonly || this.saving) return;
            const entries = this.flatRows();
            // Nothing to persist at all — no typed rows and no locked days to materialise.
            if (!entries.length && !Object.keys(this.locked).length && !submitNow) return;

            this.saving = true;
            this.error = '';
            try {
                const res = await fetch('/app/timesheets', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({
                        week_start: this.weekStart,
                        week_label: cfg.weekLabel || null,
                        submit_now: submitNow,
                        entries,
                    }),
                });
                const body = await res.json();
                if (!res.ok) {
                    this.error = Object.values(body.errors || {}).flat()[0] || 'Could not save.';
                    return;
                }
                this.locked = body.locked || {};
                this.savedAt = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
                if (submitNow) window.location.reload();
            } catch (e) {
                this.error = 'Could not reach the server. Your changes are still on screen.';
            } finally {
                this.saving = false;
            }
        },
    }));
}
