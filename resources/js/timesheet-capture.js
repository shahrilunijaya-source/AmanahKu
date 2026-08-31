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

/** Find which day + row index carries this entry id, for the Review tab's "open this
 *  entry" deep link. Pure — no Alpine/DOM — so it's testable without a browser. */
export function findEditTarget(rows, editId) {
    for (const iso of Object.keys(rows)) {
        const i = rows[iso].findIndex((r) => String(r.id) === String(editId));
        if (i !== -1) return { iso, index: i };
    }

    return null;
}

/** Plain text (a board card's title/description) escaped for Quill's dangerouslyPasteHTML,
 *  which parses its input as HTML — an unescaped "<" or "&" would otherwise corrupt or
 *  vanish from the note it's meant to seed. */
export function escapeForNotes(text) {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** ISO date $days after $iso, in UTC so a local timezone can never shift the day. */
export function addDaysIso(iso, days) {
    const [y, m, d] = iso.split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
}

/** True when $iso is the first Saturday of its month — Unijaya's TOT day, a work half day.
 *  Mirrors App\Timesheet\DayCapacity::isFirstSaturday() on the server. */
export function isFirstSaturday(iso) {
    const dt = new Date(iso + 'T00:00:00Z');

    return dt.getUTCDay() === 6 && dt.getUTCDate() <= 7;
}

export function registerTimesheetCapture(Alpine) {
    // Shared with the outer tab-bar scope (a sibling Alpine root) so it can hide itself
    // while the pre-submit review is open — Alpine scope chaining only flows parent to
    // child, so a plain component property on timesheetCapture can't cross that sibling
    // boundary to the tab bar.
    Alpine.store('tsReview', { open: false });

    Alpine.data('timesheetCapture', (cfg) => ({
        weekStart: cfg.weekStart,
        // 5 on an ordinary week, 6 when the week holds the first Saturday of the month —
        // Unijaya's TOT half day, which staff must be able to fill without hunting for the
        // "Show weekend" toggle. cfg.days still wins when the caller passes one (tests).
        days: cfg.days || (isFirstSaturday(addDaysIso(cfg.weekStart, 5)) ? 6 : 5),
        // Kept in sync with the "Show weekend" toggle, which flips between this and 7.
        today: cfg.today,
        earliestWeek: cfg.earliestWeek,
        locked: cfg.locked || {},
        categories: cfg.categories || [],
        projects: cfg.projects || [],
        // What the staffer was doing on a line (Technical, Meeting, ...) — the one thing
        // a board card cannot say for them, asked in the row's own edit overlay.
        subPillars: cfg.subPillars || [],
        // Board cards struck off a day, keyed by ISO date. Seeded from what the last save
        // stored, so a removed row stays removed across a reload.
        dismissed: {},
        readonly: cfg.readonly || false,
        editEntryId: cfg.editEntryId || null,
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
            // The review pane's open/closed flag lives on a store (singleton across screen
            // navigations, see the class-level comment above), so a previous mount leaving it
            // `true` would otherwise land a fresh mount straight on the review pane. Every
            // mount starts closed regardless of what the last one left behind.
            this.$store.tsReview.open = false;

            const seed = cfg.existing || {};
            for (const iso of Object.keys(seed)) {
                // Fully locked days never carry editable rows (the server drops them and
                // owns the day). A half day keeps the staffer's work rows, so seed those.
                if (this.isFullyLocked(iso)) continue;
                this.rows[iso] = seed[iso].map((e) => ({
                    id: e.id,
                    category_id: e.category_id || '',
                    project_id: e.project_id || '',
                    sub_pillar_id: e.sub_pillar_id || '',
                    description: e.description || '',
                    percentage: e.percentage,
                    work_item_id: e.work_item_id || null,
                }));
            }

            // Rows proposed from the board's In Progress cards. Appended after the saved
            // rows so what the staffer actually typed always comes first, and skipped on
            // fully locked days for the same reason the seed above skips them. The
            // `suggested` flag is client-only: it marks a row as not-yet-real, and is
            // cleared the moment the staffer gives it a percentage.
            const suggested = cfg.suggested || {};
            for (const iso of Object.keys(suggested)) {
                if (this.isFullyLocked(iso) || !this.isEditable(iso)) continue;
                this.rows[iso] = (this.rows[iso] || []).concat(suggested[iso].map((s) => ({
                    id: null,
                    work_item_id: s.work_item_id,
                    category_id: s.category_id || '',
                    project_id: s.project_id || '',
                    sub_pillar_id: s.sub_pillar_id || '',
                    description: s.description || '',
                    percentage: '',
                    suggested: true,
                })));
            }

            const dismissed = cfg.dismissed || {};
            for (const iso of Object.keys(dismissed)) {
                this.dismissed[iso] = dismissed[iso].map((d) => ({ ...d }));
            }

            // Land on today when it falls in the visible week, so the screen opens focused
            // on the day the user is most likely filling. Fall back to the first day still
            // needing work when today is out of range (viewing a past/future week, or today
            // is a hidden weekend day).
            this.selected = (this.dayDates().includes(this.today) && !this.isOffDay(this.today))
                ? this.today
                : this.firstDayNeedingWork();

            // Every day reads 100% but the week is still a draft — a gentle nudge so the
            // staffer doesn't leave it sitting unsubmitted after the last "Save draft".
            if (!this.readonly && this.weekComplete()) {
                this.$store.toast.info(this.$store.ui.lang === 'en'
                    ? 'This week is ready — remember to submit it.'
                    : 'Minggu ini sudah sedia — jangan lupa hantar.');
            }

            // Deep link from the Review tab ("open this entry"). Skipped on a submitted
            // week: Record shows it locked with a reopen banner, there is no row to edit
            // until it's recalled, and openEditRow() assumes an editable this.selected day.
            if (this.editEntryId && !this.readonly) {
                const target = findEditTarget(this.rows, this.editEntryId);
                if (target) {
                    this.selected = target.iso;
                    this.$nextTick(() => this.openEditRow(target.index));
                }
            }
        },

        // ---- the week ------------------------------------------------------
        // The week's own day count with the weekend hidden: 6 when it holds the TOT
        // Saturday, 5 otherwise. The "Show weekend" toggle returns here.
        baseDays() {
            return isFirstSaturday(addDaysIso(this.weekStart, 5)) ? 6 : 5;
        },
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
        // Percentage HR has already claimed on this day: the day's whole capacity (holiday
        // / whole-day leave), half of it (half-day leave), or 0 (nothing locked).
        lockedPct(iso) {
            return this.locked[iso] ? parseFloat(this.locked[iso].percentage) || 0 : 0;
        },
        // How much this day asks to be filled: 50% on the first Saturday of the month (the
        // TOT half day), 100% on every other day. Mirrors App\Timesheet\DayCapacity, which
        // is what the submit gate actually enforces.
        capacityFor(iso) {
            return isFirstSaturday(iso) ? 50 : 100;
        },
        isFullyLocked(iso) {
            return this.lockedPct(iso) >= this.capacityFor(iso);
        },
        isPartlyLocked(iso) {
            const pct = this.lockedPct(iso);
            return pct > 0 && pct < this.capacityFor(iso);
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
            if (this.isFullyLocked(iso)) return this.capacityFor(iso);
            // The leave half (if any) plus the staffer's own rows.
            return this.lockedPct(iso) + (this.rows[iso] || []).reduce((sum, r) => sum + (parseFloat(r.percentage) || 0), 0);
        },
        dayState(iso) {
            if (this.isFullyLocked(iso)) return 'locked';
            if (this.isFuture(iso)) return 'future';
            const total = this.dayTotal(iso);
            if (total === 0) return 'empty';
            if (total > this.capacityFor(iso)) return 'over';
            // A day sitting on exactly its capacity is still unfinished while it holds a line
            // the staffer added but never costed — the week strip, the tally and the submit
            // gate must all agree, or the dot reads "done" on a day that cannot be submitted.
            if (Math.abs(total - this.capacityFor(iso)) < 0.01) return this.hasBlankRows(iso) ? 'partial' : 'done';

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
        // Rows are not added by hand any more: they arrive from the board's In Progress
        // and In Review cards. Removing one is therefore a decision that has to stick —
        // the prefill rebuilds itself from the card's stints on every load, so a plain
        // splice would bring the row straight back. The card is remembered as dismissed
        // (sent with the next save) and offered back under the day.
        removeRow(i) {
            const iso = this.selected;
            const [row] = this.rows[iso].splice(i, 1);

            if (!row || !row.work_item_id) return;

            if (!this.dismissed[iso]) this.dismissed[iso] = [];
            if (this.dismissed[iso].some((d) => String(d.work_item_id) === String(row.work_item_id))) return;

            this.dismissed[iso].push({
                work_item_id: row.work_item_id,
                title: this.rowLabel(row),
                category_id: row.category_id || '',
                project_id: row.project_id || '',
                description: row.description || '',
            });
        },
        // Put a struck-off card back on its day, exactly as the prefill would have offered
        // it: uncosted, so it still has to be given a percentage before the week can go.
        restoreRow(iso, workItemId) {
            const list = this.dismissed[iso] || [];
            const at = list.findIndex((d) => String(d.work_item_id) === String(workItemId));
            if (at === -1) return;

            const [card] = list.splice(at, 1);
            if (!list.length) delete this.dismissed[iso];
            if (!this.rows[iso]) this.rows[iso] = [];

            this.rows[iso].push({
                id: null,
                work_item_id: card.work_item_id,
                category_id: card.category_id || '',
                project_id: card.project_id || '',
                sub_pillar_id: '',
                description: card.description || '',
                percentage: '',
                suggested: true,
            });
            this.save();
        },
        dismissedFor(iso) {
            return this.dismissed[iso] || [];
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
            // A suggestion the staffer has costed is an ordinary row.
            if (row.percentage !== '') row.suggested = false;
        },
        // A line the staffer added but has not costed yet. It is kept and flagged rather than
        // dropped: the day cannot be submitted while one exists, but it survives a reload.
        isBlank(row) {
            return !(parseFloat(row.percentage) > 0);
        },
        // A card that reached the grid with no category behind it: its own choice is unset,
        // its project is ambiguous or absent, and the overhead bucket the fallback wants is
        // missing. The row cannot be costed and the server would refuse the whole week's
        // save over it, so it is held back and named instead — the fix is on the card.
        needsCategory(row) {
            return !row.category_id;
        },
        // Gates dayState() and the submit blockers. An uncosted suggestion is excluded here
        // (but stays `isBlank()` for its own row styling) — it is never sent (flatRows()),
        // so it must never be able to stop the week from being sent either. A row the
        // staffer actually typed is never `suggested: true`, so this changes nothing for it.
        hasBlankRows(iso) {
            return (this.rows[iso] || []).some((r) => (!r.suggested && this.isBlank(r)) || this.needsCategory(r));
        },
        // Give this line whatever is unallocated. Shown only while something is left, so it
        // can never subtract — the old day-level "give the rest to the last line" set the
        // last line to 0 when the day was already over 100%.
        giveRemainder(row) {
            const rest = this.remainder(this.selected);
            if (rest <= 0) return;
            row.percentage = Math.round(((parseFloat(row.percentage) || 0) + rest) * 100) / 100;
            row.suggested = false;
            this.save();
        },
        // Four repeating line colours, shared by a row's dot and its slice of the day bar so
        // the bar can be read back to the lines without a legend. Days rarely hold more than
        // four lines; beyond that the palette repeats rather than inventing new hues.
        rowColour(i) {
            return ['var(--info)', 'var(--success)', 'var(--amber)', 'var(--muted-soft)'][i % 4];
        },
        // A category's colour in the picker. Comes straight from the server
        // (TimesheetCategory::colour()) so the same category reads the same here and
        // on the Projects register — one list of colours, not two that drift apart.
        // Deliberately NOT rowColour(): that one distinguishes lines within a single
        // day and must stay index-based (four fixed slots for up to four lines).
        categoryColour(categoryId) {
            const cat = this.categories.find((c) => String(c.id) === String(categoryId));
            return (cat && cat.colour) || 'var(--muted-soft)';
        },
        rowLabel(r) {
            const cat = this.categories.find((c) => String(c.id) === String(r.category_id));
            const proj = this.projects.find((p) => String(p.id) === String(r.project_id));
            const sub = proj && (proj.sub_pillars || []).find((s) => String(s.id) === String(r.sub_pillar_id));
            return [cat && cat.name, proj && proj.name, sub && sub.name].filter(Boolean).join(' · ');
        },

        // ---- the row overlay ------------------------------------------------------
        // One popup, one job: the row already knows its category and project (they come
        // from the board card), so this asks the three things the card cannot answer —
        // what the staffer was doing, how much of the day it took, and any notes.
        picker: {
            open: false, step: 'category', pendingItem: null, pendingPct: null,
            pendingDesc: '', pendingSub: '', editingIndex: null,
        },

        // Opens the row's overlay, pre-filled from the row itself, so a rich-text note is
        // edited through the same Quill instance that wrote it rather than a plain input
        // showing its raw HTML tags. Category and project are shown, not asked: they come
        // from the board card, and the card is where they get corrected.
        openEditRow(i) {
            const r = this.rows[this.selected][i];
            const cat = this.categories.find((c) => String(c.id) === String(r.category_id));
            const proj = this.projects.find((p) => String(p.id) === String(r.project_id));
            this.picker = {
                open: true, step: 'details',
                pendingItem: cat ? this.pickerItem(cat, proj, null) : { label: this.rowLabel(r), category_id: r.category_id, project_id: r.project_id || '' },
                pendingPct: r.percentage, pendingDesc: r.description || '',
                pendingSub: r.sub_pillar_id || '', editingIndex: i,
            };
            this.focusPickerTitle();
        },
        // The overlay is one step deep now, so Back is simply Cancel.
        pickerBack() {
            this.closePicker();
        },
        // Shared by every way the popup can shut (pick, back-out, Escape, backdrop click) so
        // keyboard/screen-reader focus always lands back on the button that opened it, instead
        // of falling to <body> the way it does for the rest of the app's fixed-overlay dialogs.
        closePicker() {
            this.picker.open = false;
            // Step must not stay 'details': openEditRow() sets step to 'details' again on
            // the very next edit, and Alpine's x-if only remounts the details step (and its
            // Quill container) on a false->true transition — leaving it at 'details' here
            // would make that a same-value no-op, so the next edit's Quill instance keeps
            // showing the previous row's notes instead of the new row's.
            this.picker.step = 'category';
        },
        categoryName(c) {
            return this.$store.ui.lang === 'en' ? c.name : (c.name_ms || c.name);
        },
        // How a row names itself in the overlay's header: its category, and its project
        // when it has one.
        pickerItem(category, project, sub) {
            return {
                key: `c${category.id}-${project ? project.id : ''}-${sub ? sub.id : ''}`,
                label: [this.categoryName(category), project && project.name, sub && sub.name].filter(Boolean).join(' · '),
                category_id: category.id,
                project_id: project ? project.id : '',
                sub_pillar_id: sub ? sub.id : '',
            };
        },
        // Moves focus to the picker dialog's own step heading (tabindex="-1", script-focus
        // only) — called after any step change so focus tracks the step instead of dropping
        // to <body> when the previously-focused element is removed from the DOM.
        focusPickerTitle() {
            this.$nextTick(() => document.getElementById('ts-picker-title')?.focus());
        },
        // Percentage field on the details step is the same free-typed value as a row's own
        // (clampPct works on a row object; this is the same clamp against picker.pendingPct).
        clampPickerPct() {
            const raw = String(this.picker.pendingPct ?? '').replace(/[^\d.]/g, '');
            if (raw === '') {
                this.picker.pendingPct = '';

                return;
            }
            const n = parseFloat(raw);
            this.picker.pendingPct = isNaN(n) ? '' : Math.min(100, Math.max(0, Math.round(n * 100) / 100));
        },
        // Overlay Submit: writes what the staffer was doing, how much of the day it took
        // and any notes back into the row it was opened from.
        confirmEntry() {
            if (this.picker.editingIndex == null) return;
            const r = this.rows[this.selected][this.picker.editingIndex];
            r.percentage = this.picker.pendingPct;
            r.description = this.picker.pendingDesc;
            r.sub_pillar_id = this.picker.pendingSub || '';
            if (r.percentage !== '') r.suggested = false;
            this.closePicker();
            this.save();
        },
        // Quill mounts fresh each time the details step's x-if inserts its container (add or
        // edit), and dynamic-imports itself + its stylesheet on first use rather than taxing
        // every page's bundle for a component only this one step needs. The toolbar is
        // deliberately narrower than Quill's defaults: it offers exactly the tags
        // HtmlSanitizer keeps server-side (app/Support/HtmlSanitizer.php) — anything else
        // (headings beyond h3, colour, alignment, tables) would be silently stripped on save,
        // which reads as a bug, not a missing feature.
        async mountQuillEditor(el) {
            const [{ default: Quill }] = await Promise.all([
                import('quill'),
                import('quill/dist/quill.snow.css'),
            ]);
            const quill = new Quill(el, {
                theme: 'snow',
                placeholder: this.$store.ui.lang === 'en' ? 'What did you do?' : 'Apa yang anda buat?',
                modules: {
                    toolbar: [
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ list: 'ordered' }, { list: 'bullet' }],
                        ['blockquote', 'link'],
                        ['clean'],
                    ],
                },
            });
            // Native title attrs give each toolbar button a hover tooltip — Quill's snow
            // theme ships icons only, with no accessible label or hint visible until clicked.
            const en = this.$store.ui.lang === 'en';
            const toolbarHints = {
                '.ql-bold': en ? 'Bold' : 'Tebal',
                '.ql-italic': en ? 'Italic' : 'Condong',
                '.ql-underline': en ? 'Underline' : 'Garis bawah',
                '.ql-strike': en ? 'Strikethrough' : 'Coret',
                '.ql-list[value="ordered"]': en ? 'Numbered list' : 'Senarai bernombor',
                '.ql-list[value="bullet"]': en ? 'Bullet list' : 'Senarai bulet',
                '.ql-blockquote': en ? 'Quote' : 'Petikan',
                '.ql-link': en ? 'Link' : 'Pautan',
                '.ql-clean': en ? 'Clear formatting' : 'Kosongkan format',
            };
            const toolbarEl = quill.getModule('toolbar').container;
            for (const [selector, hint] of Object.entries(toolbarHints)) {
                toolbarEl.querySelector(selector)?.setAttribute('title', hint);
            }
            // The visible "Notes (optional)" <label> above isn't programmatically linked to
            // Quill's contenteditable root (no for/id pairing exists for a rich-text editor),
            // so its accessible name has to be set directly here.
            quill.root.setAttribute('aria-label', this.$store.ui.lang === 'en' ? 'Notes (optional)' : 'Nota (pilihan)');
            // dangerouslyPasteHTML (not root.innerHTML=) so Quill parses the markup into its
            // own Delta model on load — a direct innerHTML assignment leaves Quill's internal
            // state out of sync with the DOM, and Quill's own next reconciliation silently
            // drops formatting it never registered, corrupting list markup on next save.
            quill.clipboard.dangerouslyPasteHTML(this.picker.pendingDesc || '');
            quill.on('text-change', () => {
                this.picker.pendingDesc = quill.getText().trim() ? quill.root.innerHTML : '';
            });
            // Setting Quill's initial selection/range (above) focuses its contenteditable as
            // a side effect of the browser's Selection API — that finishes well after
            // focusPickerTitle() already ran (this whole method is behind a dynamic import),
            // so it silently steals focus from the step heading a moment later. Re-asserting
            // it here, last, is what actually wins: a keyboard/screen-reader user's focus
            // should land on the new step, not get yanked into an editor they didn't ask for.
            this.focusPickerTitle();
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
                return this.joinDays(over) + (en ? ' went over its total — take the extra off a line.' : ' melebihi jumlahnya — kurangkan satu baris.');
            }
            const blank = this.blankDays();
            if (blank.length) {
                return this.joinDays(blank) + (en ? ' has a line with no percentage yet.' : ' ada baris tanpa peratus lagi.');
            }
            const days = this.blockingDays();
            if (days.length) {
                return this.joinDays(days) + (en ? ' not filled yet' : ' belum penuh');
            }
            if (!this.weekEndReached()) {
                return en ? 'Week is still open — submit becomes available on ' + this.dayLong(this.weekEndsOn()) + '.'
                    : 'Minggu masih dibuka — hantar boleh dibuat pada ' + this.dayLong(this.weekEndsOn()) + '.';
            }

            return '';
        },
        // The week's cutoff date: Friday, unless this week's Saturday is the first Saturday
        // of the month (Unijaya's TOT day), which pushes the cutoff to that Saturday.
        weekEndsOn() {
            const saturday = addDaysIso(this.weekStart, 5);
            return isFirstSaturday(saturday) ? saturday : addDaysIso(this.weekStart, 4);
        },
        // A day can be fully filled without the week being over — a staffer could otherwise
        // finish Mon-Wed by Wednesday and submit early, skipping days that haven't happened.
        weekEndReached() {
            return this.today >= this.weekEndsOn();
        },
        // Joins day names into a natural sentence fragment: "Monday", "Monday and Tuesday",
        // or "Monday, Tuesday, Wednesday and Friday" (no Oxford comma; "dan" in BM).
        joinDays(days) {
            if (days.length <= 1) return days.join('');
            const connector = this.$store.ui.lang === 'en' ? ' and ' : ' dan ';

            return days.slice(0, -1).join(', ') + connector + days[days.length - 1];
        },
        weekComplete() {
            return this.blockingDays().length === 0 && this.weekEndReached();
        },

        // ---- persistence ---------------------------------------------------
        // Dismissals as the server wants them: ISO date => card ids, editable days only,
        // for the same reason flatRows() skips the rest.
        dismissedPayload() {
            const out = {};
            for (const iso of Object.keys(this.dismissed)) {
                if (!this.isEditable(iso)) continue;
                const ids = this.dismissed[iso].map((d) => d.work_item_id).filter(Boolean);
                if (ids.length) out[iso] = ids;
            }

            return out;
        },
        flatRows() {
            const out = [];
            for (const iso of Object.keys(this.rows)) {
                // Only submit days the user may actually edit. This skips locked days (the
                // server re-appends leave/holiday itself) AND future / out-of-window days,
                // which the server rejects (D2). A stale future row seeded from an existing
                // draft would otherwise poison every save with "… has not happened yet."
                if (!this.isEditable(iso)) continue;
                // A suggestion nobody costed is not a claim — it must not reach the
                // server, where a 0% line would block the week's submit
                // (WeekWriter::assertNoBlankLines) and clutter the draft.
                const dayRows = this.rows[iso]
                    .filter((r) => !(r.suggested && (r.percentage === '' || r.percentage === null)))
                    // A row with no category cannot be stored (the server requires one) and
                    // would take the whole week's save down with it.
                    .filter((r) => !this.needsCategory(r));
                for (const r of dayRows) {
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
                        work_item_id: r.work_item_id || null,
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
                            // Always sent (even empty): this screen owns the dismissals, so
                            // clearing the last one has to clear it on the server too. Other
                            // callers of the save endpoint omit the key and keep what is stored.
                            dismissed: this.dismissedPayload(),
                        }),
                    });
                    const body = await res.json();
                    if (!res.ok) {
                        this.error = this.explainRefusal(body, entries);
                        // The toast is a one-line box; the block under the buttons is what
                        // carries the full list. Squeezing three days into the toast turns
                        // it into a wall, so it takes the first and counts the rest.
                        if (announce) this.$store.toast.error(this.toastLine(this.error));
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
        /**
         * Turn a refused save into something that names the day it is about.
         *
         * Three things used to go missing here. Laravel's own field errors are keyed
         * `entries.7.percentage`, and row 7 of a flattened list means nothing to someone
         * looking at a week grid — the index is resolved back to its date. A refusal
         * raised with abort() carries `message` and no `errors` bag at all, so the real
         * reason (empty week, already submitted) was replaced by a flat "Could not save."
         * And only the first message was ever read, so a week with three bad days was
         * fixed and resubmitted three times to be told about them one at a time.
         */
        /**
         * Carbon's 'D, j M' — how every server-side timesheet message opens. Assembled
         * rather than asked of toLocaleDateString, which drops the comma under en-GB and
         * would then never match the string it exists to recognise.
         */
        dayShortEn(iso) {
            const dt = new Date(iso + 'T00:00:00Z');
            const rest = dt.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' });

            return `${this.weekdayNames.short.en[dt.getUTCDay()]}, ${rest}`;
        },

        /** The first refusal, plus how many others are waiting in the block below. */
        toastLine(error) {
            const lines = error.split('\n');
            if (lines.length < 2) return error;
            const more = lines.length - 1;

            return this.$store.ui.lang === 'en'
                ? `${lines[0]} (+${more} more)`
                : `${lines[0]} (+${more} lagi)`;
        },

        explainRefusal(body, entries) {
            const lines = [];
            for (const [key, messages] of Object.entries(body.errors || {})) {
                // `entries.<index>.<field>` — the index is a position in the array THIS
                // save sent, so the date is already in hand and needs no server round trip.
                const at = key.match(/^entries\.(\d+)\./);
                const iso = at ? entries[Number(at[1])]?.entry_date : null;
                for (const message of [].concat(messages)) {
                    // A message the server already opened with the day must not be given a
                    // second one. Its checks format the date with Carbon's 'D, j M', which
                    // is always English regardless of the reader's language, so matching on
                    // dayLong() alone would miss it and print the day twice in two tongues.
                    const alreadyNamed = iso && (message.startsWith(this.dayLong(iso)) || message.startsWith(this.dayShortEn(iso)));
                    lines.push(iso && !alreadyNamed ? `${this.dayLong(iso)}: ${message}` : message);
                }
            }
            if (!lines.length && body.message) lines.push(body.message);

            return [...new Set(lines)].join('\n') || 'Could not save.';
        },

        // ---- pre-submit review ---------------------------------------------
        // A pane swap, not a dialog: no aria-modal, no focus trap. openReview()/closeReview()
        // own the two things a pane swap always gets wrong — where focus goes, and what the
        // back gesture does. Escape and "Back to editing" both call history.back() so every
        // closing path funnels through the one popstate listener below.
        openReview() {
            if (this.readonly) return;
            this.$store.tsReview.open = true;
            history.pushState(null, '', location.href);
            this.$nextTick(() => document.getElementById('ts-review-title')?.focus());
        },
        closeReview() {
            this.$store.tsReview.open = false;
            this.$nextTick(() => document.getElementById('ts-submit-btn')?.focus());
        },
        // The exact set flatRows() will POST: isEditable() days holding rows, plus every
        // locked day. Deliberately NOT dayDates() — that follows the reactive 5/7 "Show
        // weekend" toggle, which flatRows() ignores entirely (Saturday's rows still post
        // even after the toggle is switched back to 5). Sorted so the day cards read Mon→Fri.
        reviewDays() {
            const rowDays = Object.keys(this.rows).filter((d) => this.isEditable(d) && (this.rows[d] || []).length);
            const lockedDays = Object.keys(this.locked);

            return [...new Set([...rowDays, ...lockedDays])].sort();
        },
        // Week split by category, over reviewDays() — not dayDates() — for the same reason.
        // Each day's contribution clamps at 100 so an over-allocated day can't push the split
        // past the headline week-percent figure shown directly above it.
        categoryTotals() {
            const days = this.reviewDays();
            const denom = Math.max(1, days.length) * 100;
            const buckets = {};

            for (const iso of days) {
                const raw = this.dayTotal(iso);
                const scale = raw > 100 ? 100 / raw : 1;

                if (this.locked[iso]) {
                    const label = this.locked[iso].label;
                    const key = 'locked:' + label;
                    buckets[key] = buckets[key] || { key, label, colour: 'var(--muted)', amount: 0 };
                    buckets[key].amount += this.lockedPct(iso) * scale;
                }
                // A fully-locked day contributes only its locked amount (handled above) —
                // never its rows. Those can still be sitting in this.rows[iso] stale from
                // before HR approved leave / added a holiday mid-session (save() refreshes
                // `locked` from the server but never touches `rows`), and dayTotal() already
                // reads the day as 100 regardless of what's in rows. Counting the stale rows
                // on top of that would push this day's bucket contributions past 100.
                for (const r of (this.isFullyLocked(iso) ? [] : (this.rows[iso] || []))) {
                    const cat = this.categories.find((c) => String(c.id) === String(r.category_id));
                    const key = 'cat:' + r.category_id;
                    buckets[key] = buckets[key] || { key, label: cat ? this.categoryName(cat) : '', colour: this.categoryColour(r.category_id), amount: 0 };
                    buckets[key].amount += (parseFloat(r.percentage) || 0) * scale;
                }
            }

            return Object.values(buckets)
                .map((b) => ({ key: b.key, label: b.label, colour: b.colour, pct: Math.round((b.amount / denom) * 100) }))
                .filter((b) => b.pct > 0)
                .sort((a, b) => b.pct - a.pct);
        },
    }));
}
