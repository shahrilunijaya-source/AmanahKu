// Team board (Task 2 of the all-staff task screen redesign): a sortable
// summary strip plus a filterable, sortable table, replacing the old
// per-person lane layout. Follows the pattern in resources/js/work-board.js —
// an Alpine component that filters and reorders already-rendered DOM nodes by
// their data-* attributes, never a fetch. The row markup itself lives in one
// place, server-side: partials/team-board-row.blade.php.
//
// Task 3 wires rows to the existing card drawer. This screen has no drawer
// component of its own (unlike work-board.js's board screen), and building a
// second one here is exactly what the design doc rules out — so a row opens
// its card by navigating to the personal board's own `?card=` deep link
// (see work-board.js's openCardById()/init()), which already renders the
// drawer read-only when `can_manage` is false. A real cross-screen
// navigation, not a fetch: there is no drawer on this screen to swap in place.

const STATUS_ORDER = { todo: 0, prog: 1, review: 2, done: 3 };
const PRIORITY_ORDER = { high: 0, medium: 1, low: 2 };

export function registerTeamBoard(Alpine) {
    Alpine.data('teamBoard', () => ({
        // ── Always-visible filters ──
        search: '',
        // Done is excluded by default — the question this screen answers most
        // often is "what's still open", not "what's finished".
        statusFilter: ['todo', 'prog', 'review'],
        overdueOnly: false,
        blockedOnly: false,
        // Clicking a strip row narrows the table to that person; clicking the
        // active row again clears it. Null = no person filter.
        personFilter: null,

        // ── "More filters" disclosure ──
        moreOpen: false,
        typeFilter: '',
        priorityFilter: '',
        projectFilter: '',
        labelFilter: null,
        dueWindow: '', // '' | 'overdue' | 'week' | 'none'

        // ── Sort state — one column at a time, direction toggles on repeat click ──
        tableSort: { key: null, dir: 'asc' },
        stripSort: { key: 'open', dir: 'desc' },

        visibleCount: 0,
        totalCount: 0,

        init() {
            this.totalCount = this.$root.querySelectorAll('[data-card-id]').length;
            // The strip already renders sorted by open descending (Blade sorts
            // teamPeople before the loop) — this just brings the JS state and
            // the DOM into agreement from the start, so a later re-sort back to
            // "Open" behaves identically to the initial render.
            this.applyStripSort();
            this.applyFilter();

            // Open a row's card on click, or on Enter/Space from the keyboard —
            // the row is a role="button" div, not a native control, so neither
            // activation is free. Mirrors work-board.js's own card-open listeners.
            this.$root.addEventListener('click', (e) => {
                const row = e.target.closest('[data-card-id]');
                if (row && this.$root.contains(row)) this.openRow(row);
            });
            this.$root.addEventListener('keydown', (e) => {
                if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
                const row = e.target.closest('[data-card-id][role="button"]');
                if (!row || !this.$root.contains(row)) return;
                e.preventDefault();
                this.openRow(row);
            });
        },

        // Navigates to the personal board's card deep link — see the file header
        // comment for why this is a navigation rather than an in-place drawer.
        openRow(row) {
            const id = row.dataset.cardId;
            if (!id) return;
            window.location.href = `/app/board?card=${id}`;
        },

        // ── Status chip (multi-select: To Do / In Progress / In Review / Done) ──
        statusOn(status) {
            return this.statusFilter.includes(status);
        },

        toggleStatus(status) {
            this.statusFilter = this.statusOn(status)
                ? this.statusFilter.filter((s) => s !== status)
                : [...this.statusFilter, status];
            this.applyFilter();
        },

        toggleOverdue() {
            this.overdueOnly = !this.overdueOnly;
            this.applyFilter();
        },

        toggleBlocked() {
            this.blockedOnly = !this.blockedOnly;
            this.applyFilter();
        },

        // Toggle the person filter from a strip row click. Same id clicked
        // again clears it — the row's own :data-active reflects this state.
        filterByPerson(id) {
            this.personFilter = this.personFilter === id ? null : id;
            this.applyFilter();
        },

        // Toggle the label filter: click an active label to clear it.
        setLabelFilter(key) {
            this.labelFilter = this.labelFilter === key ? null : key;
            this.applyFilter();
        },

        // Count of active filters hidden behind "More filters" — drives its badge.
        get moreFilterCount() {
            return [this.typeFilter, this.priorityFilter, this.projectFilter, this.labelFilter, this.dueWindow]
                .filter((v) => v).length;
        },

        // Resets EVERYTHING, including the always-visible filters — not just
        // the disclosure's own fields. This is the one Clear for the whole bar.
        clearAll() {
            this.search = '';
            this.statusFilter = ['todo', 'prog', 'review'];
            this.overdueOnly = false;
            this.blockedOnly = false;
            this.personFilter = null;
            this.typeFilter = '';
            this.priorityFilter = '';
            this.projectFilter = '';
            this.labelFilter = null;
            this.dueWindow = '';
            this.applyFilter();
        },

        // Whether a rendered row's due date falls in the selected due window.
        // 'week' means "due between today and 6 days from now" inclusive; it
        // does NOT also match already-overdue rows — use the Overdue chip (or
        // this window's own 'overdue' option) for those.
        dueWindowMatch(row) {
            if (!this.dueWindow) return true;
            const due = row.dataset.due;
            if (this.dueWindow === 'none') return !due;
            if (!due) return false;
            if (this.dueWindow === 'overdue') return row.dataset.overdue === '1';
            if (this.dueWindow === 'week') {
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const weekOut = new Date(today);
                weekOut.setDate(weekOut.getDate() + 6);
                const dueDate = new Date(`${due}T00:00:00`);
                return dueDate >= today && dueDate <= weekOut;
            }
            return true;
        },

        // Recomputes every row's visibility from its own data-* attributes.
        // Client-side, over already-rendered rows — comfortable to roughly 500
        // rows, since it walks every row on every keystroke or toggle. Past
        // that ceiling this screen needs server-side paging, not a bigger
        // client-side filter — see the design doc's "Risks" table.
        applyFilter() {
            const q = this.search.trim().toLowerCase();
            let visible = 0;
            this.$root.querySelectorAll('[data-card-id]').forEach((row) => {
                const labels = (row.dataset.labels || '').split(',').filter(Boolean);
                const matches = (!q || row.dataset.ownerName.includes(q) || row.dataset.title.includes(q))
                    && this.statusFilter.includes(row.dataset.status)
                    && (!this.overdueOnly || row.dataset.overdue === '1')
                    && (!this.blockedOnly || labels.includes('blocked'))
                    && (!this.personFilter || row.dataset.ownerId === String(this.personFilter))
                    && (!this.typeFilter || row.dataset.type === this.typeFilter)
                    && (!this.priorityFilter || row.dataset.priority === this.priorityFilter)
                    && (!this.projectFilter || row.dataset.project === this.projectFilter)
                    && (!this.labelFilter || labels.includes(this.labelFilter))
                    && this.dueWindowMatch(row);
                row.style.display = matches ? '' : 'none';
                if (matches) visible += 1;
            });
            this.visibleCount = visible;
        },

        // ── Table sort: Person, Status, Priority, Due — one column at a time ──
        sortTable(key) {
            this.tableSort = this.tableSort.key === key
                ? { key, dir: this.tableSort.dir === 'asc' ? 'desc' : 'asc' }
                : { key, dir: 'asc' };
            this.applyTableSort();
        },

        tableCompare(a, b, key) {
            if (key === 'due') {
                // No due date sorts last regardless of direction — asc still
                // puts the earliest (most overdue) date first, which is what
                // "sort by due" should surface.
                const av = a.dataset.due || '9999-99-99';
                const bv = b.dataset.due || '9999-99-99';
                return av < bv ? -1 : av > bv ? 1 : 0;
            }
            if (key === 'priority') {
                return (PRIORITY_ORDER[a.dataset.priority] ?? 9) - (PRIORITY_ORDER[b.dataset.priority] ?? 9);
            }
            if (key === 'status') {
                return (STATUS_ORDER[a.dataset.status] ?? 9) - (STATUS_ORDER[b.dataset.status] ?? 9);
            }
            return a.dataset.ownerName.localeCompare(b.dataset.ownerName);
        },

        applyTableSort() {
            if (!this.tableSort.key) return;
            const body = this.$refs.tableBody;
            if (!body) return;
            const dir = this.tableSort.dir === 'asc' ? 1 : -1;
            const rows = [...body.querySelectorAll('[data-card-id]')];
            rows.sort((a, b) => this.tableCompare(a, b, this.tableSort.key) * dir);
            rows.forEach((row) => body.appendChild(row));
        },

        // ── Strip sort: Person, Open, Overdue, Blocked, In review ──
        sortStrip(key) {
            this.stripSort = this.stripSort.key === key
                ? { key, dir: this.stripSort.dir === 'asc' ? 'desc' : 'asc' }
                : { key, dir: key === 'person' ? 'asc' : 'desc' };
            this.applyStripSort();
        },

        stripCompare(a, b, key) {
            if (key === 'person') return a.dataset.personName.localeCompare(b.dataset.personName);
            return Number(a.dataset[key] || 0) - Number(b.dataset[key] || 0);
        },

        applyStripSort() {
            const body = this.$refs.stripBody;
            if (!body) return;
            const dir = this.stripSort.dir === 'asc' ? 1 : -1;
            const rows = [...body.querySelectorAll('[data-person-id]')];
            rows.sort((a, b) => this.stripCompare(a, b, this.stripSort.key) * dir);
            rows.forEach((row) => body.appendChild(row));
        },
    }));
}
