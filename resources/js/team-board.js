// Team board: one table, one line per person (2026-07-29 reshape — see
// docs/superpowers/specs/2026-07-29-team-board-redesign-design.md, updated
// "Architecture"/"Decisions"). Replaces the earlier summary-strip-above-a-
// 50-row-task-table shape: that second table is gone, and clicking a person
// opens a floating window listing just their own tasks instead of a row
// navigating to the personal board's `?card=` deep link (which threw the
// viewer onto their OWN board and discarded this screen's state).
//
// An Alpine component that filters and reorders already-rendered DOM nodes
// by their data-* attributes for the person table and the window's own task
// filters — the only fetch on this screen is opening a card's drawer (below),
// mirroring resources/js/work-board.js's own openCardCore(). The person
// table's row markup lives in this
// file's Blade (resources/views/screens/team-board.blade.php); each card is
// rendered by the shared partials/work-card.blade.php, once per $teamRows
// entry, grouped into 4 status columns INSIDE the floating window (teleported
// to <body>) rather than in a page-level table — opening a person only
// toggles which of those already-rendered cards are visible
// (openWindow()/applyWinFilter()).
//
// The window is a centered popup (.tb-win-modal, see resources/css/app.css)
// showing that person's cards as a 4-column kanban laid out side-by-side,
// the same shape board.blade.php uses for its own kanban — reusing its
// .wd-scrim/.wd-head/.wd-ico/.wd-body shell pieces, not a second visual
// language.
//
// A card inside the window CAN be opened — the shared partials.work-drawer
// (interactive: false) — to read its full detail and comment. It can never be
// edited or moved from here: drawer.locked is forced true unconditionally
// (never read from the server's card.can_manage, unlike work-board.js's own
// drawer), and the read-only branches partials.work-drawer already has for
// board.blade.php's own locked cards are what render everything else. See
// docs/superpowers/specs/2026-07-29-team-board-redesign-design.md.

export function registerTeamBoard(Alpine) {
    Alpine.data('teamBoard', (people = [], labels = {}, assignInit = { defaultId: null, show: false, employeeId: null }) => ({
        // Full per-person records from $teamPeople (id, name, initials,
        // avatar_color, position, department, open, overdue, blocked,
        // in_review, done) — embedded once from the server, read back by id
        // when a person's row is opened. Small (one entry per staff member
        // carrying work), so this is comfortable to keep in memory whole
        // rather than re-deriving it from the DOM.
        people,
        // The label palette, from the server (WorkItem::LABELS): slug => [name, color].
        // Only read by the card drawer's read-only Labels row.
        labels,
        token: document.querySelector('meta[name="csrf-token"]')?.content ?? '',

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

        // ── Card detail drawer: view + comment only, opened from a card inside
        // the person window. Shape mirrors work-board.js's own `drawer`, minus
        // every autosave/edit field that partials.work-drawer's $interactive
        // branch never renders here (menuOpen, saved, seq/lastApplied, _timers).
        drawer: {
            show: false,
            open: false,
            loading: false,
            error: '',
            locked: true, // always — never read from the server's card.can_manage.
            lockedReason: '',
            id: null,
            node: null,
            trigger: null,
            sub: '',
            newComment: '',
            labelMenuOpen: false,
            card: {
                id: null, title: '', description: '', type: 'task', priority: 'medium',
                due_at: '', due_label: '', status: 'todo', labels: [], links: [], participants: [],
                project_id: '', project: null, comments_count: 0, mentionable: [],
            },
            comments: [],
            mention: { open: false, hits: [], idx: 0 },
            _closeTimer: null,
        },

        // Who this card may mention: participants plus the assigner, exactly as
        // WorkItemController::show() sends it — never the full roster.
        get mentionPool() {
            return this.drawer.card.mentionable || [];
        },

        // The "add someone" picker itself is always hidden here (drawer.locked
        // is always true), but its x-for inside that x-show'd block still runs
        // regardless — x-show only toggles display, it doesn't stop Alpine from
        // evaluating child directives. Real content is never needed.
        get availablePeople() {
            return [];
        },

        // ── Assign-task modal: reachable from the top-of-page button (any
        // active employee) or a person window's header button (that person
        // preselected). `show`/`open` follow the exact two-stage dance `win`
        // uses above (mount, then a frame later flip the transform/opacity
        // state) so the 280ms CSS transition has something to animate from.
        // `assignInit.show`/`employeeId` come from a validation error
        // ($errors->getBag('assign')) reopening the modal on page reload —
        // in that case it should just appear already-open, no animation.
        assign: {
            show: assignInit.show,
            open: assignInit.show,
            employeeId: assignInit.employeeId ?? assignInit.defaultId,
            trigger: null,
            _closeTimer: null,
        },

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

            // Open the card drawer on click, or Enter/Space from the keyboard.
            // The kanban cards live inside the person window's own x-teleport
            // (moved to <body> in the real DOM), so they're never inside
            // $root — listen on document instead, scoped to winTaskBody.
            document.addEventListener('click', (e) => {
                const card = e.target.closest('[data-card]');
                if (card && this.$refs.winTaskBody?.contains(card)) this.openCard(card);
            });
            document.addEventListener('keydown', (e) => {
                if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
                const card = e.target.closest('[data-card][role="button"]');
                if (!card || !this.$refs.winTaskBody?.contains(card)) return;
                e.preventDefault();
                this.openCard(card);
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

        // employeeId omitted (top-of-page button): falls back to the
        // roster's first person. Provided (window header button): always
        // wins, even if a previous open left a different person selected.
        openAssign(employeeId = null, triggerEl = null) {
            this.assign.employeeId = employeeId ?? assignInit.defaultId;
            this.assign.trigger = triggerEl;
            clearTimeout(this.assign._closeTimer);
            this.assign.show = true;
            this.$nextTick(() => requestAnimationFrame(() => {
                this.assign.open = true;
            }));
            this.$nextTick(() => {
                this.$refs.assignTitleEl?.focus({ preventScroll: true });
            });
        },

        closeAssign() {
            if (!this.assign.show) return;
            this.assign.open = false;
            const trigger = this.assign.trigger;
            clearTimeout(this.assign._closeTimer);
            this.assign._closeTimer = setTimeout(() => {
                this.assign.show = false;
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

        // ── Card detail drawer ─────────────────────────────────────
        async api(url, opts = {}) {
            const headers = { 'X-CSRF-TOKEN': this.token, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
            if (opts.body) headers['Content-Type'] = 'application/json';
            const res = await fetch(url, { headers, ...opts });
            if (!res.ok) throw new Error('Request failed: ' + res.status);
            return res.status === 204 ? null : res.json();
        },

        // Swaps a card's whole DOM node for freshly server-rendered markup, so
        // the small card's comment-count badge reflects a comment just posted
        // from inside the drawer. Scoped to winTaskBody, not $root — see the
        // click-listener comment in init() for why (x-teleport).
        repaintNode(html) {
            const node = this.drawer.node;
            if (!node || !html) return;
            const id = node.dataset.id;
            node.outerHTML = html;
            this.drawer.node = this.$refs.winTaskBody?.querySelector(`[data-card][data-id="${id}"]`) ?? null;
        },

        subline(card) {
            if (!card.opened_at) return '';
            const verb = card.assigned_by ? this.t('Assigned', 'Ditugaskan') : this.t('Opened', 'Dibuka');
            const by = this.t('by', 'oleh');
            return `${verb} ${card.opened_at} ${by} ${card.owner_name || ''} · #${card.id}`;
        },

        async openCard(node) {
            const id = node.dataset.id;
            this.drawer.trigger = node;
            this.drawer.node = node;
            this.drawer.id = id;
            this.drawer.loading = true;
            this.drawer.error = '';
            this.drawer.newComment = '';
            this.drawer.labelMenuOpen = false;
            this.closeMention();
            this.drawer.show = true;
            this.$nextTick(() => requestAnimationFrame(() => {
                this.drawer.open = true;
            }));
            try {
                const { card, comments } = await this.api(`/app/board/${id}`);
                this.drawer.card = {
                    ...card,
                    description: card.description ?? '',
                    due_at: card.due_at ?? '',
                    labels: card.labels ?? [],
                    links: card.links ?? [],
                    participants: card.participants ?? [],
                    mentionable: card.mentionable ?? [],
                    project_id: card.project?.id ?? '',
                };
                // Always locked here, regardless of card.can_manage — the team
                // board is view + comment only for everyone, even a manager who
                // could edit this same card from their own personal board.
                this.drawer.locked = true;
                this.drawer.lockedReason = this.t(
                    'This is the company-wide board — view and comment only. Open this card from its owner\'s own board to edit it.',
                    'Ini papan seluruh syarikat — hanya boleh lihat dan komen. Buka kad ini dari papan pemiliknya sendiri untuk menyuntingnya.',
                );
                this.drawer.sub = this.subline(card);
                this.drawer.comments = comments;
            } catch (err) {
                this.drawer.error = this.t('Could not load this card.', 'Tidak dapat memuatkan kad ini.');
            } finally {
                this.drawer.loading = false;
                if (this.drawer.show) {
                    this.$nextTick(() => {
                        this.$refs.drawerEl?.focus({ preventScroll: true });
                    });
                }
            }
        },

        closeDrawer() {
            if (!this.drawer.show) return;
            this.drawer.open = false;
            this.drawer.labelMenuOpen = false;
            this.closeMention();
            const trigger = this.drawer.trigger;
            clearTimeout(this.drawer._closeTimer);
            this.drawer._closeTimer = setTimeout(() => {
                this.drawer.show = false;
                this.drawer.node = null;
                trigger?.focus?.({ preventScroll: true });
            }, 280);
        },

        // Keeps Tab cycling inside the drawer while it is open (WAI-ARIA
        // dialog pattern) — identical algorithm to work-board.js's trapFocus().
        trapFocus(e) {
            if (e.key !== 'Tab') return;
            const root = this.$refs.drawerEl;
            if (!root) return;
            const nodes = root.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"]), [contenteditable="true"]');
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

        // ── Mention picker — identical algorithm to work-board.js's own. ──
        closeMention() {
            this.drawer.mention.open = false;
            this.drawer.mention.hits = [];
            this.drawer.mention.idx = 0;
        },

        mentionActiveQuery(el) {
            const upto = el.value.slice(0, el.selectionStart);
            const m = upto.match(/(?:^|\s)@([\p{L}\s]{0,30})$/u);
            return m ? m[1] : null;
        },

        paintMention(q) {
            this.drawer.mention.hits = this.mentionPool.filter((p) => p.name.toLowerCase().includes(q.trim().toLowerCase()));
            this.drawer.mention.idx = 0;
            this.drawer.mention.open = true;
        },

        onCommentInput(e) {
            const q = this.mentionActiveQuery(e.target);
            q === null ? this.closeMention() : this.paintMention(q);
        },

        onCommentKeydown(e) {
            if (!this.drawer.mention.open) return;
            if (e.key === 'Escape') {
                e.stopPropagation();
                this.closeMention();
                return;
            }
            if (!this.drawer.mention.hits.length) return;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                const n = this.drawer.mention.hits.length;
                this.drawer.mention.idx = (this.drawer.mention.idx + (e.key === 'ArrowDown' ? 1 : n - 1)) % n;
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                e.stopPropagation();
                this.insertMention(this.drawer.mention.hits[this.drawer.mention.idx]);
            }
        },

        insertMention(p) {
            if (!p) return;
            const el = this.$refs.newCommentEl;
            const caret = el ? el.selectionStart : this.drawer.newComment.length;
            const value = this.drawer.newComment;
            const upto = value.slice(0, caret).replace(/(^|\s)@[\p{L}\s]{0,30}$/u, '$1');
            const rest = value.slice(caret);
            const inserted = `${upto}@${p.name} `;
            this.drawer.newComment = inserted + rest;
            this.closeMention();
            this.$nextTick(() => {
                if (!el) return;
                el.focus();
                el.setSelectionRange(inserted.length, inserted.length);
            });
        },

        escapeHtml(s) {
            return (s || '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
        },

        // Escape FIRST, then tint — see work-board.js's own renderCommentBody()
        // for why the order matters (c.body is user input, rendered via x-html).
        renderCommentBody(body) {
            let html = this.escapeHtml(body);
            for (const p of this.mentionPool) {
                const at = '@' + this.escapeHtml(p.name);
                html = html.split(at).join(`<span class="wd-at">${at}</span>`);
            }
            return html;
        },

        async addComment() {
            const body = this.drawer.newComment.trim();
            if (!body) return;
            this.closeMention();
            try {
                const { comment, count, html } = await this.api(`/app/board/${this.drawer.id}/comments`, {
                    method: 'POST',
                    body: JSON.stringify({ body }),
                });
                this.drawer.comments.push(comment);
                this.drawer.newComment = '';
                this.drawer.card.comments_count = count;
                this.repaintNode(html);
            } catch (err) {
                this.drawer.error = this.t('Could not post comment.', 'Tidak dapat hantar komen.');
            }
        },

        async deleteComment(id) {
            try {
                const { count, html } = await this.api(`/app/board/comments/${id}`, { method: 'DELETE' });
                this.drawer.comments = this.drawer.comments.filter((c) => c.id !== id);
                this.drawer.card.comments_count = count;
                this.repaintNode(html);
            } catch (err) {
                this.drawer.error = this.t('Could not delete comment.', 'Tidak dapat padam komen.');
            }
        },
    }));
}
