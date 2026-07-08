/**
 * Weekly timesheet capture — line-item grid (projects as rows, days as columns).
 *
 * Re-thought for staff who fill this every week. Instead of re-picking the same
 * category/project/sub-pillar on every day, you add each thing you worked on ONCE
 * as a "line", then allocate its share of each day in a Mon–Fri grid. Every
 * populated day column must total 100% before the week can be submitted.
 *
 *  - the line picker uses pills (recognition, not recall), with a search box for
 *    long project lists
 *  - "copy across weekdays" spreads a line's value Mon–Fri in one click
 *  - per-day "fill" tops a day up to 100% into the last line
 *  - reusable per-staff templates (saved server-side) become new lines
 *  - a single shared Quill instance (note modal) holds a line's rich-text description
 *
 * The component holds `lines`, each with a `cells` map keyed by ISO date → percent.
 * On render the Blade flattens `flatRows()` into hidden entries[] inputs for the POST,
 * emitting one entry per (line, day) where that day's percent is > 0 — so the server
 * contract (per-day entries that each total 100%) is unchanged.
 */
export function registerTimesheetCapture(Alpine) {
    Alpine.data('timesheetCapture', (cfg) => ({
        weekStart: cfg.weekStart,                 // ISO 'YYYY-MM-DD' (Monday)
        days: cfg.days || 5,                      // 5 = Mon–Fri, 7 = Mon–Sun
        categories: cfg.categories || [],
        projects: cfg.projects || [],
        templates: cfg.templates || [],
        lines: [],                                // [{ category_id, project_id, sub_pillar_id, description, cells:{iso:pct}, _open }]
        note: { open: false, idx: null },
        projFilter: '',
        quill: null,

        // Pill styles, computed once. Selected = filled ink chip; idle = hairline outline.
        get pillOn() {
            return 'padding:6px 13px;border-radius:999px;border:1px solid var(--ink);background:var(--ink);color:#fff;font-size:12px;font-weight:500;cursor:pointer;white-space:nowrap;';
        },
        get pillOff() {
            return 'padding:6px 13px;border-radius:999px;border:1px solid var(--hairline);background:#fff;color:var(--ink);font-size:12px;cursor:pointer;white-space:nowrap;';
        },

        init() {
            // Collapse an existing draft's per-day entries back into lines: group by the
            // (category, project, sub-pillar) tuple; each becomes a row, each day a cell.
            const seed = cfg.existing || {};
            const byKey = {};
            const order = [];
            for (const iso of Object.keys(seed)) {
                for (const e of seed[iso]) {
                    const key = `${e.category_id}|${e.project_id || ''}|${e.sub_pillar_id || ''}`;
                    if (!byKey[key]) {
                        byKey[key] = {
                            category_id: e.category_id || '',
                            project_id: e.project_id || '',
                            sub_pillar_id: e.sub_pillar_id || '',
                            description: e.description || '',
                            cells: {},
                            _open: false,
                        };
                        order.push(key);
                    }
                    byKey[key].cells[iso] = e.percentage;
                    // Keep the first non-empty description we meet for the line.
                    if (!byKey[key].description && e.description) byKey[key].description = e.description;
                }
            }
            this.lines = order.map((k) => byKey[k]);
        },

        // ---- date helpers -------------------------------------------------
        addDays(iso, n) {
            const [y, m, d] = iso.split('-').map(Number);
            const dt = new Date(Date.UTC(y, m - 1, d + n));
            return dt.toISOString().slice(0, 10);
        },
        dayDates() {
            return Array.from({ length: this.days }, (_, i) => this.addDays(this.weekStart, i));
        },
        dayName(iso) {
            const [y, m, d] = iso.split('-').map(Number);
            const dt = new Date(Date.UTC(y, m - 1, d));
            const en = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            const ms = ['Ahad', 'Isnin', 'Selasa', 'Rabu', 'Khamis', 'Jumaat', 'Sabtu'];
            return (this.lang() === 'en' ? en : ms)[dt.getUTCDay()];
        },
        dayShort(iso) {
            const [, m, d] = iso.split('-').map(Number);
            const mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            return `${d} ${mon[m - 1]}`;
        },
        isToday(iso) {
            return iso === new Date().toISOString().slice(0, 10);
        },

        // ---- language -----------------------------------------------------
        lang() {
            return (this.$store && this.$store.ui && this.$store.ui.lang) || 'en';
        },
        t(en, ms) {
            return this.lang() === 'en' ? en : ms;
        },
        catLabel(c) {
            if (!c) return '';
            return this.lang() === 'en' ? c.name : (c.name_ms || c.name);
        },

        // ---- lookups ------------------------------------------------------
        category(id) {
            return this.categories.find((c) => String(c.id) === String(id)) || null;
        },
        requiresProject(catId) {
            return !!this.category(catId)?.requires_project;
        },
        project(id) {
            return this.projects.find((p) => String(p.id) === String(id)) || null;
        },
        subPillarsFor(projId) {
            return this.project(projId)?.sub_pillars || [];
        },
        filteredProjects() {
            const q = this.projFilter.trim().toLowerCase();
            if (!q) return this.projects;
            return this.projects.filter((p) => p.name.toLowerCase().includes(q));
        },

        // ---- line label ---------------------------------------------------
        lineLabel(line) {
            const parts = [];
            const c = this.category(line.category_id);
            if (c) parts.push(this.catLabel(c));
            const p = this.project(line.project_id);
            if (p) parts.push(p.name);
            const s = this.subPillarsFor(line.project_id).find((x) => String(x.id) === String(line.sub_pillar_id));
            if (s) parts.push(s.name);
            return parts.join(' · ');
        },

        // ---- totals -------------------------------------------------------
        cellVal(line, iso) {
            return parseFloat(line.cells[iso]) || 0;
        },
        dayTotal(iso) {
            return this.lines.reduce((s, l) => s + this.cellVal(l, iso), 0);
        },
        dayEmpty(iso) {
            return !this.lines.some((l) => this.cellVal(l, iso) > 0);
        },
        dayOk(iso) {
            return this.dayEmpty(iso) || Math.abs(this.dayTotal(iso) - 100) < 0.01;
        },
        dayState(iso) {
            if (this.dayEmpty(iso)) return 'empty';
            const t = this.dayTotal(iso);
            if (Math.abs(t - 100) < 0.01) return 'ok';
            return t > 100 ? 'over' : 'under';
        },
        dayColor(iso) {
            return { empty: 'var(--muted)', ok: 'var(--success)', under: 'var(--amber)', over: 'var(--error)' }[this.dayState(iso)];
        },
        fmtPct(n) {
            return (n % 1 === 0 ? n : n.toFixed(2)) + '%';
        },

        // A line that carries a percentage somewhere but has no category (or no project
        // where one is required) would be silently dropped on submit — flag it instead.
        lineHasCells(line) {
            return this.dayDates().some((d) => this.cellVal(line, d) > 0);
        },
        lineIncomplete(line) {
            if (!this.lineHasCells(line)) return false;
            if (!line.category_id) return true;
            return this.requiresProject(line.category_id) && !line.project_id;
        },
        anyIncomplete() {
            return this.lines.some((l) => this.lineIncomplete(l));
        },

        hasEntries() {
            return this.dayDates().some((d) => !this.dayEmpty(d));
        },
        weekOk() {
            return this.dayDates().every((d) => this.dayOk(d));
        },
        canSubmit() {
            return this.hasEntries() && this.weekOk() && !this.anyIncomplete();
        },
        filledDays() {
            return this.dayDates().filter((d) => !this.dayEmpty(d)).length;
        },

        // ---- line mutations ----------------------------------------------
        blankLine() {
            return { category_id: '', project_id: '', sub_pillar_id: '', description: '', cells: {}, _open: true };
        },
        addLine(prefill) {
            this.lines.push(prefill ? { ...this.blankLine(), ...prefill } : this.blankLine());
        },
        removeLine(i) {
            this.lines.splice(i, 1);
        },
        setCategory(i, catId) {
            const line = this.lines[i];
            line.category_id = catId;
            if (!this.requiresProject(catId)) {
                line.project_id = '';
                line.sub_pillar_id = '';
            }
        },
        setProject(i, projId) {
            const line = this.lines[i];
            line.project_id = projId;
            const subs = this.subPillarsFor(projId).map((s) => String(s.id));
            if (!subs.includes(String(line.sub_pillar_id))) line.sub_pillar_id = '';
        },
        setSub(i, subId) {
            this.lines[i].sub_pillar_id = subId;
        },

        // ---- accelerators -------------------------------------------------
        copyAcross(i) {
            const line = this.lines[i];
            const weekdays = this.dayDates().slice(0, 5);
            // Source = the first weekday cell that has a value.
            const src = weekdays.map((d) => line.cells[d]).find((v) => parseFloat(v) > 0);
            if (src == null) return;
            weekdays.forEach((d) => { line.cells[d] = src; });
        },
        fillDay(iso) {
            if (!this.lines.length) return;
            const last = this.lines[this.lines.length - 1];
            const others = this.dayTotal(iso) - this.cellVal(last, iso);
            last.cells[iso] = Math.max(0, Math.round((100 - others) * 100) / 100);
        },
        applyTemplate(tplId) {
            const tpl = this.templates.find((x) => String(x.id) === String(tplId));
            if (!tpl) return;
            const line = {
                ...this.blankLine(),
                category_id: tpl.category_id || '',
                project_id: tpl.project_id || '',
                sub_pillar_id: tpl.sub_pillar_id || '',
                description: tpl.description || '',
                _open: false,
            };
            // Seed Monday with the template's percentage; the user can "copy across".
            if (tpl.percentage != null) line.cells[this.weekStart] = tpl.percentage;
            this.lines.push(line);
        },
        saveAsTemplate(i) {
            const line = this.lines[i];
            if (!line.category_id) {
                alert(this.t('Pick a category before saving a template.', 'Pilih kategori sebelum menyimpan templat.'));
                return;
            }
            const name = window.prompt(this.t('Name this template (e.g. "Full-time KDN dev")', 'Namakan templat ini (cth. "Pembangunan KDN sepenuh masa")'));
            if (!name) return;
            // Use the first non-empty cell as the template's representative percentage.
            const pct = this.dayDates().map((d) => line.cells[d]).find((v) => parseFloat(v) > 0) || '';
            const f = this.$refs.tplForm;
            f.querySelector('[name="name"]').value = name;
            f.querySelector('[name="category_id"]').value = line.category_id;
            f.querySelector('[name="project_id"]').value = line.project_id || '';
            f.querySelector('[name="sub_pillar_id"]').value = line.sub_pillar_id || '';
            f.querySelector('[name="percentage"]').value = pct;
            f.querySelector('[name="description"]').value = line.description || '';
            f.submit();
        },

        // ---- note (rich-text) modal --------------------------------------
        openNote(i) {
            this.note = { open: true, idx: i };
            this.$nextTick(() => this.initQuill());
        },
        async initQuill() {
            // Quill (+snow css) loads on demand — it is only used by this note modal,
            // so it must never sit in the app-wide bundle every screen downloads.
            const { default: Quill } = await import('quill');
            await import('quill/dist/quill.snow.css');

            // Recreate per open — a Quill mounted while hidden mis-measures, and a stale
            // instance would keep the previous line's content.
            this.$refs.noteEditor.innerHTML = '';
            this.quill = new Quill(this.$refs.noteEditor, {
                theme: 'snow',
                placeholder: this.t('Describe what you worked on…', 'Terangkan apa yang anda kerjakan…'),
                modules: {
                    toolbar: [['bold', 'italic', 'underline'], [{ list: 'bullet' }, { list: 'ordered' }], ['link', 'clean']],
                },
            });
            const current = this.lines[this.note.idx].description || '';
            if (current) this.quill.clipboard.dangerouslyPasteHTML(current);
        },
        saveNote() {
            if (! this.quill) return;   // editor still loading — nothing typed yet
            const html = this.quill.root.innerHTML;
            this.lines[this.note.idx].description = this.quill.getText().trim() === '' ? '' : html;
            this.closeNote();
        },
        closeNote() {
            this.note = { open: false, idx: null };
            this.quill = null;
        },
        noteSummary(line) {
            const tmp = document.createElement('div');
            tmp.innerHTML = line.description || '';
            const text = (tmp.textContent || '').trim();
            return text ? (text.length > 60 ? text.slice(0, 60) + '…' : text) : '';
        },

        // ---- grid layout --------------------------------------------------
        gridCols() {
            // label | one column per day | actions. Object form so Alpine MERGES this
            // with the element's static `display:grid` style instead of replacing it.
            return { gridTemplateColumns: `minmax(130px,1.5fr) repeat(${this.days}, minmax(52px,1fr)) auto` };
        },

        // ---- submit flattening -------------------------------------------
        flatRows() {
            const out = [];
            for (const line of this.lines) {
                if (!line.category_id) continue;
                for (const d of this.dayDates()) {
                    const pct = this.cellVal(line, d);
                    if (pct <= 0) continue;
                    out.push({
                        entry_date: d,
                        category_id: line.category_id,
                        project_id: line.project_id || '',
                        sub_pillar_id: line.sub_pillar_id || '',
                        percentage: pct,
                        description: line.description || '',
                    });
                }
            }
            return out;
        },
    }));
}
