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
 * holidays) come from the server. A fully locked day (holiday, whole-day leave) counts as a
 * full day and is never editable. A half-day leave locks only 50%: the staffer still fills
 * the other half, so that day is editable and must reach 100% from the 50% leave plus their
 * own rows. The POST body is unchanged: one entry per (day, allocation); the server
 * re-appends the leave portion itself.
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
        sheetOpen: false,
        saving: false,
        savePromise: null,
        savedAt: null,
        error: '',

        // Bilingual weekday names, indexed 0=Sun..6=Sat to match Date#getUTCDay().
        weekdayNames: {
            short: {
                en: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
                ms: ['Ahad', 'Isn', 'Sel', 'Rabu', 'Kha', 'Jum', 'Sab'],
            },
            long: {
                en: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
                ms: ['Ahad', 'Isnin', 'Selasa', 'Rabu', 'Khamis', 'Jumaat', 'Sabtu'],
            },
        },

        init() {
            const seed = cfg.existing || {};
            for (const iso of Object.keys(seed)) {
                // Fully locked days never carry editable rows (the server drops them and
                // owns the day). A half day keeps the staffer's work rows, so seed those.
                if (this.isFullyLocked(iso)) continue;
                this.rows[iso] = seed[iso].map((e) => ({
                    category_id: e.category_id || '',
                    project_id: e.project_id || '',
                    sub_pillar_id: e.sub_pillar_id || '',
                    description: e.description || '',
                    percentage: e.percentage,
                }));
            }
            // Land on today when it falls in the visible week, so the screen opens focused
            // on the day the user is most likely filling. Fall back to the first day still
            // needing work when today is out of range (viewing a past/future week, or today
            // is a hidden weekend day).
            this.selected = (this.dayDates().includes(this.today) && !this.isOffDay(this.today))
                ? this.today
                : this.firstDayNeedingWork();
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
            const lang = this.$store.ui.lang === 'en' ? 'en' : 'ms';
            const idx = new Date(iso + 'T00:00:00Z').getUTCDay();
            return this.weekdayNames.short[lang][idx];
        },
        dayLong(iso) {
            const lang = this.$store.ui.lang === 'en' ? 'en' : 'ms';
            const dt = new Date(iso + 'T00:00:00Z');
            const weekday = this.weekdayNames.long[lang][dt.getUTCDay()];
            const rest = dt.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' });
            return `${weekday} ${rest}`;
        },
        isLocked(iso) {
            return !!this.locked[iso];
        },
        // Percentage HR has already claimed on this day: 100 (holiday / whole-day leave),
        // 50 (half-day leave), or 0 (nothing locked).
        lockedPct(iso) {
            return this.locked[iso] ? parseFloat(this.locked[iso].percentage) || 0 : 0;
        },
        isFullyLocked(iso) {
            return this.lockedPct(iso) >= 100;
        },
        isPartlyLocked(iso) {
            const pct = this.lockedPct(iso);
            return pct > 0 && pct < 100;
        },
        isFuture(iso) {
            return iso > this.today;
        },
        // Unijaya works a six-day week: Sunday is the weekly rest day and is never a
        // recordable work day. Saturday IS a work day (the first Saturday of the month is a
        // half day, which the server applies), so only Sunday is gated here.
        isOffDay(iso) {
            return new Date(iso + 'T00:00:00Z').getUTCDay() === 0;
        },
        // Index of the last selectable day in the visible week (skips the Sunday rest day),
        // so the forward day-arrow knows where to stop.
        lastSelectableIndex() {
            const ds = this.dayDates();
            for (let i = ds.length - 1; i >= 0; i--) if (!this.isOffDay(ds[i])) return i;
            return ds.length - 1;
        },
        isEditable(iso) {
            // A partly locked (half-day) day is editable for the unlocked half. The Sunday
            // rest day is never editable.
            return !this.readonly && !this.isFullyLocked(iso) && !this.isFuture(iso)
                && !this.isOffDay(iso) && iso >= this.earliestWeek;
        },
        dayTotal(iso) {
            if (this.isFullyLocked(iso)) return 100;
            // The leave half (if any) plus the staffer's own rows.
            return this.lockedPct(iso) + (this.rows[iso] || []).reduce((sum, r) => sum + (parseFloat(r.percentage) || 0), 0);
        },
        dayState(iso) {
            if (this.isFullyLocked(iso)) return 'locked';
            if (this.isFuture(iso)) return 'future';
            const total = this.dayTotal(iso);
            if (total === 0) return 'empty';
            if (total > 100) return 'over';
            // A day sitting on exactly 100% is still unfinished while it holds a line the
            // staffer added but never costed — the week strip, the tally and the submit gate
            // must all agree, or the dot reads "done" on a day that cannot be submitted.
            if (Math.abs(total - 100) < 0.01) return this.hasBlankRows(iso) ? 'partial' : 'done';

            return 'partial';
        },
        firstDayNeedingWork() {
            const days = this.dayDates().filter((d) => this.isEditable(d));
            return days.find((d) => this.dayState(d) !== 'done') || days[days.length - 1] || this.weekStart;
        },
        // Navigating to a future day is allowed for viewing — the arrows and the shown
        // weekend can reach a day that hasn't happened yet. Editing stays gated by
        // isEditable(), and flatRows() never submits a non-editable day, so a future day is
        // view-only and can never poison a save.
        select(iso) {
            // The Sunday rest day is never selectable.
            if (this.isOffDay(iso)) return;
            this.save();
            this.selected = iso;
        },
        // Step the selected day one back/forward within the visible week, skipping the
        // Sunday rest day so an arrow never lands on an ungated day.
        stepDay(delta) {
            const ds = this.dayDates();
            let j = ds.indexOf(this.selected) + delta;
            while (j >= 0 && j < ds.length && this.isOffDay(ds[j])) j += delta;
            if (j >= 0 && j < ds.length) this.select(ds[j]);
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
        // Typed percentages are clamped here rather than left to the input's min/max: this
        // field never goes through native form validation (the screen POSTs over fetch), so
        // without this a typo of 999 sat in the box and pushed the day to 1099%.
        clampPct(row) {
            const raw = String(row.percentage ?? '').replace(/[^\d.]/g, '');
            if (raw === '') {
                row.percentage = '';

                return;
            }
            const n = parseFloat(raw);
            row.percentage = isNaN(n) ? '' : Math.min(100, Math.max(0, Math.round(n * 100) / 100));
        },
        // A line the staffer added but has not costed yet. It is kept and flagged rather than
        // dropped: the day cannot be submitted while one exists, but it survives a reload.
        isBlank(row) {
            return !(parseFloat(row.percentage) > 0);
        },
        hasBlankRows(iso) {
            return (this.rows[iso] || []).some((r) => this.isBlank(r));
        },
        // Give this line whatever is unallocated. Shown only while something is left, so it
        // can never subtract — the old day-level "give the rest to the last line" set the
        // last line to 0 when the day was already over 100%.
        giveRemainder(row) {
            const rest = this.remainder(this.selected);
            if (rest <= 0) return;
            row.percentage = Math.round(((parseFloat(row.percentage) || 0) + rest) * 100) / 100;
            this.save();
        },
        // True when this day already carries the exact Category · Project · Sub-pillar the
        // picker item would add, so the picker can grey it out instead of making a twin line.
        isOnDay(item) {
            return (this.rows[this.selected] || []).some((r) => String(r.category_id) === String(item.category_id)
                && String(r.project_id || '') === String(item.project_id || '')
                && String(r.sub_pillar_id || '') === String(item.sub_pillar_id || ''));
        },
        // Four repeating line colours, shared by a row's dot and its slice of the day bar so
        // the bar can be read back to the lines without a legend. Days rarely hold more than
        // four lines; beyond that the palette repeats rather than inventing new hues.
        rowColour(i) {
            return ['var(--info)', 'var(--success)', 'var(--amber)', 'var(--muted-soft)'][i % 4];
        },
        rowLabel(r) {
            const cat = this.categories.find((c) => String(c.id) === String(r.category_id));
            const proj = this.projects.find((p) => String(p.id) === String(r.project_id));
            const sub = proj && (proj.sub_pillars || []).find((s) => String(s.id) === String(r.sub_pillar_id));
            return [cat && cat.name, proj && proj.name, sub && sub.name].filter(Boolean).join(' · ');
        },

        // ---- picker: one question at a time --------------------------------------------
        // Category, then project, then sub-pillar — each step a short list of full-width
        // rows, with a back arrow and a breadcrumb of what is already chosen. A flat list of
        // every combination was tried and rejected: ~31 lines is too much to read when the
        // staffer already knows which category they want. Steps the data does not need are
        // skipped, so a standalone category is still one tap and a project with no
        // sub-pillars is still two.
        picker: { open: false, step: 'category', category: null, project: null },

        openPicker() {
            this.picker = { open: true, step: 'category', category: null, project: null };
        },
        // One step back, or shut the picker when there is nowhere further back to go.
        pickerBack() {
            if (this.picker.step === 'sub') {
                this.picker.step = 'project';
                this.picker.project = null;
            } else if (this.picker.step === 'project') {
                this.picker.step = 'category';
                this.picker.category = null;
            } else {
                this.picker.open = false;
            }
        },
        // What the staffer has chosen so far, for the panel's breadcrumb.
        pickerTrail() {
            return [this.picker.category && this.categoryName(this.picker.category), this.picker.project && this.picker.project.name]
                .filter(Boolean).join(' · ');
        },
        categoryName(c) {
            return this.$store.ui.lang === 'en' ? c.name : (c.name_ms || c.name);
        },
        // The line a set of choices adds up to — the same shape isOnDay() and chooseItem()
        // already take, so a step's options can be greyed the moment they are terminal.
        pickerItem(category, project, sub) {
            return {
                key: `c${category.id}-${project ? project.id : ''}-${sub ? sub.id : ''}`,
                label: [this.categoryName(category), project && project.name, sub && sub.name].filter(Boolean).join(' · '),
                category_id: category.id,
                project_id: project ? project.id : '',
                sub_pillar_id: sub ? sub.id : '',
            };
        },
        // Step 1. A category that needs no project is terminal here, so it can already be
        // greyed as "already on this day"; one that needs a project only opens step 2.
        pickerCategories() {
            return this.categories.map((c) => ({
                c,
                label: this.categoryName(c),
                item: c.requires_project ? null : this.pickerItem(c, null, null),
            }));
        },
        // Step 2. Every project, terminal only when the project carries no sub-pillar.
        pickerProjects() {
            const c = this.picker.category;

            return this.projects.map((p) => ({
                p,
                label: p.name,
                item: (p.sub_pillars || []).length ? null : this.pickerItem(c, p, null),
            }));
        },
        // Step 3. The whole project first, then each sub-pillar. All terminal.
        pickerSubs() {
            const c = this.picker.category;
            const p = this.picker.project;
            const whole = this.$store.ui.lang === 'en' ? 'The whole project' : 'Keseluruhan projek';

            return [
                { label: whole, item: this.pickerItem(c, p, null) },
                ...(p.sub_pillars || []).map((s) => ({ label: s.name, item: this.pickerItem(c, p, s) })),
            ];
        },
        // The rows the current step offers.
        pickerOptions() {
            if (this.picker.step === 'category') return this.pickerCategories();

            return this.picker.step === 'project' ? this.pickerProjects() : this.pickerSubs();
        },
        // A row in any step: take the line if the choice is terminal, else go one step in.
        chooseStep(option) {
            if (option.item) {
                this.chooseItem(option.item);
            } else if (this.picker.step === 'category') {
                this.picker.category = option.c;
                this.picker.step = 'project';
            } else {
                this.picker.project = option.p;
                this.picker.step = 'sub';
            }
        },
        // Saved templates (named, deletable) then recent combinations, pinned above the
        // first step as a one-tap shortcut past the whole drill-down.
        pinnedItems() {
            const templates = (this.templates || []).map((t) => ({
                key: 'tpl-' + t.id,
                template_id: t.id,
                category_id: t.category_id,
                project_id: t.project_id || '',
                sub_pillar_id: t.sub_pillar_id || '',
                percentage: t.percentage,
                label: t.name,
                isTemplate: true,
            }));

            return [...templates, ...(this.items || [])];
        },
        chooseItem(item) {
            if (this.isOnDay(item)) return;
            // A template carries its own default percentage. Everything else takes whatever
            // is unallocated — including 0, which shows as a blank line asking to be filled
            // rather than silently claiming a whole day that is already full.
            const pct = item.isTemplate && item.percentage != null
                ? item.percentage
                : this.remainder(this.selected);
            this.addRow(item, pct);
            this.picker.open = false;
            this.save();
        },

        // ---- templates: save-as-template and delete, through the existing routes -------
        // (routes/web.php: timesheets.templates.store / .delete). Both redirect rather than
        // return JSON, so the Blade posts real <form>s instead of using save()'s fetch.
        templateDraft: { name: '', category_id: '', project_id: '', sub_pillar_id: '', percentage: null },
        savingTemplate: false,
        // Copies a row's fields into templateDraft and opens the save-as-template form.
        // The form's own submit handler autosaves the day first, so the page reload from
        // the store route's redirect never drops in-progress work. name is reset to '' every
        // time (the panel uses x-show so it stays in the DOM) so a name typed for one row
        // can never survive to mismatch a different row's allocation.
        startSaveTemplate(row) {
            this.templateDraft = {
                name: '',
                category_id: row.category_id,
                project_id: row.project_id || '',
                sub_pillar_id: row.sub_pillar_id || '',
                percentage: row.percentage,
            };
            this.savingTemplate = true;
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
        // ---- submit gate ---------------------------------------------------
        // A day blocks the week when it is not at 100% OR still carries a line with no
        // percentage. The two are reported separately below, because "not at 100% yet" is
        // the wrong sentence for a day that has gone over it.
        blockingDays() {
            const lang = this.$store.ui.lang === 'en' ? 'en' : 'ms';
            return this.dayDates()
                .filter((d) => this.isEditable(d) && (this.dayState(d) !== 'done' || this.hasBlankRows(d)))
                .map((d) => this.weekdayNames.long[lang][new Date(d + 'T00:00:00Z').getUTCDay()]);
        },
        // Days that have gone past 100%, named for the footer. Kept apart from blockingDays()
        // so the message can say "over by" instead of "not at 100% yet".
        overDays() {
            const lang = this.$store.ui.lang === 'en' ? 'en' : 'ms';
            return this.dayDates()
                .filter((d) => this.isEditable(d) && this.dayState(d) === 'over')
                .map((d) => this.weekdayNames.long[lang][new Date(d + 'T00:00:00Z').getUTCDay()]);
        },
        // Days holding a line the staffer added but never costed.
        blankDays() {
            const lang = this.$store.ui.lang === 'en' ? 'en' : 'ms';
            return this.dayDates()
                .filter((d) => this.isEditable(d) && this.hasBlankRows(d))
                .map((d) => this.weekdayNames.long[lang][new Date(d + 'T00:00:00Z').getUTCDay()]);
        },
        // The one sentence under the week strip: over-allocation first (it is an error the
        // staffer must undo), then uncosted lines, then the ordinary "still to fill".
        blockingMessage() {
            const en = this.$store.ui.lang === 'en';
            const over = this.overDays();
            if (over.length) {
                return this.joinDays(over) + (en ? ' went over 100% — take the extra off a line.' : ' melebihi 100% — kurangkan satu baris.');
            }
            const blank = this.blankDays();
            if (blank.length) {
                return this.joinDays(blank) + (en ? ' has a line with no percentage yet.' : ' ada baris tanpa peratus lagi.');
            }
            const days = this.blockingDays();
            if (!days.length) return '';

            return this.joinDays(days) + (en ? ' not at 100% yet' : ' belum 100%');
        },
        // Joins day names into a natural sentence fragment: "Monday", "Monday and Tuesday",
        // or "Monday, Tuesday, Wednesday and Friday" (no Oxford comma; "dan" in BM).
        joinDays(days) {
            if (days.length <= 1) return days.join('');
            const connector = this.$store.ui.lang === 'en' ? ' and ' : ' dan ';

            return days.slice(0, -1).join(', ') + connector + days[days.length - 1];
        },
        weekComplete() {
            return this.blockingDays().length === 0;
        },

        // ---- persistence ---------------------------------------------------
        flatRows() {
            const out = [];
            for (const iso of Object.keys(this.rows)) {
                // Only submit days the user may actually edit. This skips locked days (the
                // server re-appends leave/holiday itself) AND future / out-of-window days,
                // which the server rejects (D2). A stale future row seeded from an existing
                // draft would otherwise poison every save with "… has not happened yet."
                if (!this.isEditable(iso)) continue;
                for (const r of this.rows[iso]) {
                    // A 0% line IS sent. It used to be dropped here, which meant a line the
                    // staffer had added but not yet costed vanished on the next reload with
                    // nothing said. The server accepts 0 in a draft and refuses it at submit,
                    // so the line survives and the week stays blocked until it is filled.
                    const pct = parseFloat(r.percentage) || 0;
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
        // Re-entrant: a caller that hits save() while one is already in flight (e.g. the
        // save-as-template form's `await save()` firing right after a blur/chip-click save)
        // gets back the SAME promise as the in-flight request, so it genuinely waits for that
        // network round-trip instead of no-oping and letting the caller move on early — which
        // used to let `$event.target.submit()` navigate while the draft POST was still pending.
        async save(submitNow = false, announce = false) {
            if (this.readonly) return Promise.resolve();
            if (this.saving && this.savePromise) return this.savePromise;

            const entries = this.flatRows();
            // Nothing to persist at all — no typed rows and no locked days to materialise.
            if (!entries.length && !Object.keys(this.locked).length && !submitNow) {
                if (announce) this.$store.toast.info(this.$store.ui.lang === 'en' ? 'Nothing to save yet.' : 'Belum ada apa-apa untuk disimpan.');
                return;
            }

            this.saving = true;
            this.error = '';
            this.savePromise = (async () => {
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
                        if (announce) this.$store.toast.error(this.error);
                        return;
                    }
                    this.locked = body.locked || {};
                    this.savedAt = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
                    // Submit reloads the page (the server re-renders the locked/submitted view),
                    // so only the manual draft save needs an in-place toast.
                    if (announce && !submitNow) this.$store.toast.success(this.$store.ui.lang === 'en' ? 'Draft saved.' : 'Draf disimpan.');
                    if (submitNow) window.location.reload();
                } catch (e) {
                    this.error = 'Could not reach the server. Your changes are still on screen.';
                    if (announce) this.$store.toast.error(this.$store.ui.lang === 'en' ? 'Could not reach the server.' : 'Tak dapat hubungi pelayan.');
                } finally {
                    this.saving = false;
                    this.savePromise = null;
                }
            })();

            return this.savePromise;
        },
    }));
}
