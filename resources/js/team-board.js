// Team board: one table, one line per person (2026-07-29 reshape — see
// docs/superpowers/specs/2026-07-29-team-board-redesign-design.md, updated
// "Architecture"/"Decisions"). Replaces the earlier summary-strip-above-a-
// 50-row-task-table shape: that second table is gone, and clicking a person
// opens a floating window listing just their own tasks instead of a row
// navigating to the personal board's `?card=` deep link (which threw the
// viewer onto their OWN board and discarded this screen's state).
//
// An Alpine component that filters and reorders already-rendered DOM nodes
// by their data-* attributes, never a fetch — same pattern as
// resources/js/work-board.js. The person table's row markup lives in this
// file's Blade (resources/views/screens/team-board.blade.php); the task
// line markup lives in partials/team-board-row.blade.php, rendered once per
// $teamRows entry INSIDE the floating window (teleported to <body>) rather
// than in a page-level table — opening a person only toggles which of
// those already-rendered lines are visible (openWindow()/applyWinFilter()).
//
// The window reuses the personal board's .wd-* slide-over CSS wholesale
// (see resources/css/app.css) so it looks and moves identically to
// board.blade.php's drawer — this is deliberately NOT a second visual
// language. Unlike that drawer, nothing here writes: no fetch, no PATCH, no
// comment composer. board.blade.php/work-board.js are not touched.

export function registerTeamBoard(Alpine) {
    Alpine.data('teamBoard', (people = []) => ({
        // Full per-person records from $teamPeople (id, name, initials,
        // avatar_color, position, department, open, overdue, blocked,
        // in_review, done) — embedded once from the server, read back by id
        // when a person's row is opened. Small (one entry per staff member
        // carrying work), so this is comfortable to keep in memory whole
        // rather than re-deriving it from the DOM.
        people,

        // ── Always-visible, person-level filters ──
        search: '',
        overdueOnly: false,
        blockedOnly: false,

        // ── Sort state for the person table — one column at a time,
        // direction toggles on repeat click ──
        peopleSort: { key: 'open', dir: 'desc' },

        visibleCount: 0,
        totalCount: 0,

        // ── Floating window state: one person's tasks at a time ──
        win: {
            show: false, // mounted / unmounted (Alpine x-show), toggled first
            open: false, // transform/opacity state, toggled a frame later so the
            // 280ms transition has something to animate from — see openWindow().
            person: null, // the full record from `people`, looked up by id.
            trigger: null, // the person row that opened the window — focus returns here on close.
            // Task-level filters, scoped to the window's own cards only.
            typeFilter: '',
            priorityFilter: '',
            projectFilter: '',
            labelFilter: null,
            _closeTimer: null,
        },
        winVisibleCount: 0,

        init() {
            this.totalCount = this.$root.querySelectorAll('[data-person-id]').length;
            // The table already renders sorted by open descending (Blade sorts
            // teamPeople before the loop) — this just brings the JS state and
            // the DOM into agreement from the start, so a later re-sort back to
            // "Open" behaves identically to the initial render.
            this.applyPeopleSort();
            this.applyFilter();

            // Open a person's window on click, or on Enter/Space from the
            // keyboard — the row is a role="button" div, not a native control,
            // so neither activation is free. Mirrors work-board.js's own
            // card-open listeners.
            this.$root.addEventListener('click', (e) => {
                const row = e.target.closest('[data-person-id]');
                if (row && this.$root.contains(row)) this.openWindow(row);
            });
            this.$root.addEventListener('keydown', (e) => {
                if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
                const row = e.target.closest('[data-person-id][role="button"]');
                if (!row || !this.$root.contains(row)) return;
                e.preventDefault();
                this.openWindow(row);
            });
        },

        t(en, ms) {
            return this.$store.ui.lang === 'en' ? en : ms;
        },

        // "Faizal Othman · position · department" for the window header —
        // mirrors the person table row's own trim(name.' · '.dept, ' ·')
        // treatment, just computed client-side from the embedded record.
        get winPersonSub() {
            const p = this.win.person;
            if (!p) return '';
            const bits = [p.position, p.department].filter((v) => v);
            return bits.length ? bits.join(' · ') : '—';
        },

        // One-line summary of this person's counts, same four metrics as the
        // table columns (Open / Overdue / Blocked / In review).
        get winSummary() {
            const p = this.win.person;
            if (!p) return '';
            return [
                `${p.open} ${this.t('open', 'terbuka')}`,
                `${p.overdue} ${this.t('overdue', 'lewat')}`,
                `${p.blocked} ${this.t('blocked', 'tersekat')}`,
                `${p.in_review} ${this.t('in review', 'disemak')}`,
            ].join(' · ');
        },

        // ── Person-level filters (search + Overdue/Blocked toggles) ──
        toggleOverdue() {
            this.overdueOnly = !this.overdueOnly;
            this.applyFilter();
        },

        toggleBlocked() {
            this.blockedOnly = !this.blockedOnly;
            this.applyFilter();
        },

        // Resets the always-visible filters only — the window's own task
        // filters reset themselves fresh every time a window opens (see
        // openWindow()), and sort is deliberately untouched by Clear.
        clearAll() {
            this.search = '';
            this.overdueOnly = false;
            this.blockedOnly = false;
            this.applyFilter();
        },

        // Recomputes every person row's visibility from its own data-*
        // attributes. Client-side, over already-rendered rows.
        applyFilter() {
            const q = this.search.trim().toLowerCase();
            let visible = 0;
            this.$root.querySelectorAll('[data-person-id]').forEach((row) => {
                const matches = (!q || row.dataset.search.includes(q))
                    && (!this.overdueOnly || Number(row.dataset.overdue) > 0)
                    && (!this.blockedOnly || Number(row.dataset.blocked) > 0);
                row.style.display = matches ? '' : 'none';
                if (matches) visible += 1;
            });
            this.visibleCount = visible;
        },

        // ── Person table sort: Person, Open, Overdue, Blocked, In review ──
        sortPeople(key) {
            this.peopleSort = this.peopleSort.key === key
                ? { key, dir: this.peopleSort.dir === 'asc' ? 'desc' : 'asc' }
                : { key, dir: key === 'person' ? 'asc' : 'desc' };
            this.applyPeopleSort();
        },

        peopleCompare(a, b, key) {
            if (key === 'person') return a.dataset.personName.localeCompare(b.dataset.personName);
            return Number(a.dataset[key] || 0) - Number(b.dataset[key] || 0);
        },

        applyPeopleSort() {
            const body = this.$refs.peopleBody;
            if (!body) return;
            const dir = this.peopleSort.dir === 'asc' ? 1 : -1;
            const rows = [...body.querySelectorAll('[data-person-id]')];
            rows.sort((a, b) => this.peopleCompare(a, b, this.peopleSort.key) * dir);
            rows.forEach((row) => body.appendChild(row));
        },

        // ── Floating window ──

        // Opens the window for one person. Every task line is already in the
        // DOM (rendered from $teamRows) — this only picks the person record
        // by id, resets the window's own task filters to their defaults, and
        // re-runs applyWinFilter() to show that person's lines.
        openWindow(row) {
            const id = Number(row.dataset.personId);
            const person = this.people.find((p) => p.id === id);
            if (!person) return;

            this.win.trigger = row;
            this.win.person = person;
            this.win.typeFilter = '';
            this.win.priorityFilter = '';
            this.win.projectFilter = '';
            this.win.labelFilter = null;
            this.applyWinFilter();

            this.win.show = true;
            this.$nextTick(() => requestAnimationFrame(() => {
                this.win.open = true;
            }));

            // Focus the window container, never an inner element — it is the
            // dialog's own Escape/Tab-trap anchor, same as work-board.js's
            // openCardCore().
            this.$nextTick(() => {
                this.$refs.winEl?.focus({ preventScroll: true });
            });
        },

        closeWindow() {
            if (!this.win.show) return;
            this.win.open = false;
            const trigger = this.win.trigger;
            clearTimeout(this.win._closeTimer);
            this.win._closeTimer = setTimeout(() => {
                this.win.show = false;
                trigger?.focus?.({ preventScroll: true });
            }, 280);
        },

        // Keeps Tab cycling inside the window while it is open (WAI-ARIA
        // dialog pattern) — identical algorithm to work-board.js's
        // trapFocus(), scoped to this window's own $refs.winEl.
        trapFocusWindow(e) {
            if (e.key !== 'Tab') return;
            const root = this.$refs.winEl;
            if (!root) return;
            const nodes = root.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            const list = Array.from(nodes).filter((el) => !el.disabled && el.offsetParent !== null);
            if (!list.length) return;
            const first = list[0];
            const last = list[list.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        },

        // ── Task-level filters, scoped to the window's own cards ──
        setWinLabelFilter(key) {
            this.win.labelFilter = this.win.labelFilter === key ? null : key;
            this.applyWinFilter();
        },

        // Recomputes every card's visibility: owned by the open person, AND
        // matching this window's own type/priority/project/label filters. No
        // status filter — status is the column layout itself now. Every
        // $teamRows card lives in the DOM regardless of which (or whether
        // any) person's window is open — comfortable to roughly 500 cards,
        // same ceiling as the rest of this screen's client-side filtering;
        // past that this needs server-side paging, not a bigger client-side
        // filter.
        applyWinFilter() {
            const body = this.$refs.winTaskBody;
            if (!body || !this.win.person) {
                this.winVisibleCount = 0;
                return;
            }
            const ownerId = String(this.win.person.id);
            let visible = 0;
            body.querySelectorAll('[data-id]').forEach((row) => {
                const labels = (row.dataset.labels || '').split(',').filter(Boolean);
                const matches = row.dataset.ownerId === ownerId
                    && (!this.win.typeFilter || row.dataset.type === this.win.typeFilter)
                    && (!this.win.priorityFilter || row.dataset.priority === this.win.priorityFilter)
                    && (!this.win.projectFilter || row.dataset.project === this.win.projectFilter)
                    && (!this.win.labelFilter || labels.includes(this.win.labelFilter));
                row.style.display = matches ? '' : 'none';
                if (matches) visible += 1;
            });
            this.winVisibleCount = visible;
        },
    }));
}
