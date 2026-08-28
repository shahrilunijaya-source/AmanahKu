// Trello-style work board: drag between columns (SortableJS), inline add-a-card
// per column, and a right-hand detail drawer with per-field autosave and an
// inline comment thread. Cards are server-rendered: partials.work-card is the
// one template, shared by the initial page render and every write response
// (see cardHtml() on WorkItemController). This file never builds card markup
// itself — a repaint is always `node.outerHTML = html`. Sortable reorders the
// resulting nodes and we persist the destination column order over fetch.
//
// Targeted invalidation (Stage 2 of the board redesign): editing one card no
// longer calls applyFilter() and re-walks all 17-odd cards on the board.
// applyFieldRepaint() below maps a changed field to exactly what depends on
// it — see docs/superpowers/specs/2026-07-29-taa-board-redesign-design.md
// ("Targeted invalidation"). applyFilter() still exists, but only the filter
// controls (setFilter/setLabelFilter/clearFilters/the project select/init)
// may call it; a single card's visibility is applyFilterTo(node) instead.

// Response fields that are *derived from* the field just committed (e.g.
// due_label is computed from due_at). commitField() only ever copies these
// back onto drawer.card — never the whole response — because the server's
// card snapshot reflects only what THIS request changed. Spreading the full
// response over drawer.card would stomp any other field mid-edit in the
// browser: a slow title save's response would carry the priority value as it
// stood when THAT request was built, and applying it wholesale would revert
// a priority the user changed and saved afterwards, even though the database
// already holds the correct one. See the design doc's "Race guard".
const FIELD_DERIVED = {
    due_at: ['due_label'],
    project_id: ['project'],
    participant_ids: ['participants'],
};

export function registerWorkBoard(Alpine) {
    Alpine.data('workBoard', (boardType = 'core', people = [], labels = {}, deepLinkCardId = null, archivedCount = 0) => ({
        boardType,
        // 'all' shows everything; each of 'task' / 'assignment' / 'adhoc' shows that
        // one type only. Landing via a typed sidebar link pre-focuses it; else show all.
        filter: ['task', 'assignment', 'adhoc'].includes(boardType) ? boardType : 'all',
        // Active label filter slug, or null for "any label". ANDs with the type filter.
        labelFilter: null,
        // Active project id as a string, or '' for "any project". ANDs with type + label.
        projectFilter: '',
        // Whether the collapsible secondary-filter panel (label + project) is open.
        filtersOpen: false,
        counts: { all: 0, task: 0, assignment: 0, adhoc: 0 },
        token: document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        busy: false,
        // The roster to pick from when including people on a card. Every role gets
        // it; `drawer.locked` decides per-card whether the picker is usable.
        people,
        // The label palette, from the server (WorkItem::LABELS): slug => [name, color].
        // Passed in as a prop the same way `people` is — never a JS-side twin that
        // could drift from the server-side definition.
        labels,
        // Archived-cards panel (Done column's "Archived (N)" link). archivedCount
        // seeds from the server render; archiveCard()/reopenCard() keep it in sync
        // without a full page reload.
        archivedCount,
        archivedOpen: false,
        archivedItems: [],
        drawer: {
            show: false, // mounted / unmounted (Alpine x-show), toggled first
            open: false, // transform/opacity state, toggled a frame later so the
            // 280ms transition has something to animate from — mirrors the
            // prototype's requestAnimationFrame(() => dataset.open = '') dance.
            loading: false,
            error: '',
            locked: false,
            lockedReason: '',
            // Index of the link row currently forced open for editing, even though
            // it already has both a label and a url (otherwise a saved link renders
            // as its clickable button — see editLink()/finishLinkEdit()).
            editingLinkIdx: null,
            id: null,
            node: null, // the [data-card] DOM node this drawer is showing; re-resolved
            // by [data-id] after every outerHTML repaint, since that swap
            // destroys whatever element held the old reference.
            trigger: null, // the card (or its keyboard focus) that opened the drawer —
            // focus returns here on close.
            sub: '',
            saved: false,
            menuOpen: false,
            labelMenuOpen: false,
            // Monotonic sequence number. Every mutating request increments it and
            // carries its own value; a response is only applied if its sequence is
            // still the newest one issued. See acceptSeq() below.
            seq: 0,
            lastApplied: 0,
            newComment: '',
            card: {
                id: null, title: '', description: '', type: 'task', priority: 'medium',
                due_at: '', due_label: '', status: 'todo', labels: [], participants: [],
                project_id: '', project: null, timesheet_category_id: '', comments_count: 0, mentionable: [],
            },
            comments: [],
            // "@" mention picker state, scoped to the comment composer. See
            // mentionPool / paintMention / insertMention / onCommentKeydown below —
            // lifted from public/_proto-board.html's activeQuery/paintMent/insertMention.
            mention: { open: false, hits: [], idx: 0 },
            // "+ Add someone" picker: menu visibility plus its search box.
            peopleMenuOpen: false,
            peopleQuery: '',
            _timers: {},
            _savedTimer: null,
            _closeTimer: null,
        },

        // Roster minus the people already on the card — feeds the "add someone" select.
        get availablePeople() {
            const on = new Set((this.drawer.card.participants || []).map((p) => p.id));
            return this.people.filter((p) => !on.has(p.id));
        },

        // What the "add someone" menu lists: addable people matching the search box.
        get filteredPeople() {
            const q = this.drawer.peopleQuery.trim().toLowerCase();
            return q ? this.availablePeople.filter((p) => (p.search || p.name.toLowerCase()).includes(q)) : this.availablePeople;
        },

        // Who this card may mention: participants plus the assigner, exactly as
        // WorkItemController::show() sends it. Never the full roster — see the
        // design doc's "Candidate set".
        get mentionPool() {
            return this.drawer.card.mentionable || [];
        },

        init() {
            const root = this.$root;
            // Drag-and-drop per column.
            root.querySelectorAll('[data-list]').forEach((list) => {
                window.Sortable.create(list, {
                    group: 'board',
                    animation: 150,
                    ghostClass: 'uj-drag-ghost',
                    draggable: '[data-card]',
                    onEnd: (evt) => this.persistMove(evt),
                });
            });
            // Open the drawer when a card (not the composer) is clicked...
            root.addEventListener('click', (e) => {
                const card = e.target.closest('[data-card]');
                if (card && root.contains(card)) this.openCard(card);
            });
            // ...or activated from the keyboard. The card is a <div>, so it carries no
            // native activation — role="button" + tabindex="0" on the card (see
            // partials/work-card.blade.php) make it focusable, this makes Enter/Space work.
            root.addEventListener('keydown', (e) => {
                if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
                const card = e.target.closest('[data-card][role="button"]');
                if (!card || !root.contains(card)) return;
                e.preventDefault();
                this.openCard(card);
            });
            this.applyFilter();

            // Mention deep link: /app/board?card={id}, read once on mount — see the
            // design doc's "Deep link". Opened silently: if this viewer can't reach
            // the card (or the id is stale/foreign), the drawer just never appears —
            // no error banner, no 403 for the whole screen. Ordinary card opens never
            // touch this again.
            if (deepLinkCardId) this.openCardById(deepLinkCardId);
        },

        // ── Type filter (all / task / assignment / adhoc) ─────────────
        typeInFilter(type, filter = this.filter) {
            if (filter === 'all') return true;
            return type === filter; // 'task' | 'assignment' | 'adhoc' match exactly
        },

        setFilter(f) {
            this.filter = f;
            this.applyFilter();
        },

        // Toggle the label filter: click an active label to clear it.
        setLabelFilter(key) {
            this.labelFilter = this.labelFilter === key ? null : key;
            this.applyFilter();
        },

        // Count of active SECONDARY filters (label + project). Drives the toggle badge.
        get activeFilterCount() {
            return (this.labelFilter ? 1 : 0) + (this.projectFilter ? 1 : 0);
        },

        // Reset the secondary filters and repaint.
        clearFilters() {
            this.labelFilter = null;
            this.projectFilter = '';
            this.applyFilter();
        },

        labelInFilter(el) {
            if (!this.labelFilter) return true;
            return (el.dataset.labels || '').split(',').includes(this.labelFilter);
        },

        projectInFilter(el) {
            if (!this.projectFilter) return true;
            return (el.dataset.project || '') === this.projectFilter;
        },

        // Full recompute: every card's visibility, plus the type-chip counts and the
        // per-column counts. Expensive by design (it walks every card), so only the
        // filter controls above (and init(), and structural add/delete) may call it.
        // A single card's own field changing must go through applyFilterTo() instead.
        applyFilter() {
            this.$root.querySelectorAll('[data-card]').forEach((el) => this.applyFilterTo(el));
            this.recount();
            this.refreshCounts();
        },

        // Recompute ONE card's visibility from its own data-* attributes. This is
        // the targeted counterpart to applyFilter() — used when a card's `type`
        // changes (the only field that can move a card in or out of the active
        // filter) so autosave never has to re-touch the other cards on the board.
        applyFilterTo(node) {
            if (!node) return;
            node.style.display = this.typeInFilter(node.dataset.type) && this.labelInFilter(node) && this.projectInFilter(node) ? '' : 'none';
        },

        recount() {
            const cards = [...this.$root.querySelectorAll('[data-card]')];
            this.counts = {
                all: cards.length,
                task: cards.filter((el) => el.dataset.type === 'task').length,
                assignment: cards.filter((el) => el.dataset.type === 'assignment').length,
                adhoc: cards.filter((el) => el.dataset.type === 'adhoc').length,
            };
        },

        async api(url, opts = {}) {
            const headers = { 'X-CSRF-TOKEN': this.token, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
            if (opts.body) headers['Content-Type'] = 'application/json';
            const res = await fetch(url, { headers, ...opts });
            if (res.status === 422) {
                // A rejected field carries a message worth showing verbatim (the
                // due-date rule on shared cards). Flagged so commitField() knows
                // it's safe to display, unlike a raw 500.
                const body = await res.json().catch(() => null);
                const err = new Error(body?.message || 'Request failed: 422');
                err.validation = true;
                throw err;
            }
            if (!res.ok) throw new Error('Request failed: ' + res.status);
            return res.status === 204 ? null : res.json();
        },

        t(en, ms) {
            return this.$store.ui.lang === 'en' ? en : ms;
        },

        refreshCounts() {
            this.$root.querySelectorAll('[data-list]').forEach((list) => {
                // Count only cards visible under the active filter.
                const n = [...list.querySelectorAll('[data-card]')].filter((el) => el.style.display !== 'none').length;
                const badge = this.$root.querySelector(`[data-count="${list.dataset.list}"]`);
                if (badge) badge.textContent = n;
                const empty = list.querySelector('[data-empty]');
                if (empty) empty.style.display = n > 0 ? 'none' : '';
            });
        },

        // Swaps a card's whole DOM node for freshly server-rendered markup.
        // outerHTML destroys the original node, so `node` — including
        // drawer.node — goes stale the instant this runs. Re-resolve it by
        // [data-id] afterwards; skipping this leaves the drawer pointing at a
        // detached element that silently stops updating the visible card.
        //
        // Uses `document.querySelector`, not `this.$root` — this runs inside
        // commitField()'s async continuation (after the save's await), and
        // the drawer markup itself lives in a teleported <template
        // x-teleport="body">. Alpine's $root magic isn't reliably resolvable
        // from there past the first await in a drawer session; when it comes
        // back undefined this throws, and the surrounding try/catch in
        // commitField() then reports the save as failed even though the
        // server already committed it (see WorkItemController::update()) —
        // a card ends up saved but the drawer shows "Save failed" and rolls
        // the field back locally until the next reload. data-id is unique
        // page-wide, so a plain document-scoped lookup is exactly as correct.
        repaintNode(html) {
            const node = this.drawer.node;
            if (!node || !html) return;
            const id = node.dataset.id;
            node.outerHTML = html;
            this.drawer.node = document.querySelector(`[data-card][data-id="${id}"]`);
        },

        // The changed-field → blast-radius map. See the design doc's "Targeted
        // invalidation" table. Only `type` reaches beyond the card's own node,
        // because the face renders type unconditionally (so its colour/label can
        // change) and because type is the only field that can move a card in or
        // out of the active filter.
        applyFieldRepaint(field, html) {
            this.repaintNode(html);
            if (field === 'type') {
                this.recount();
                this.applyFilterTo(this.drawer.node);
            }
        },

        nextSeq() {
            return ++this.drawer.seq;
        },

        // Discards a response whose sequence is older than the newest one already
        // applied. Without this, a slow save landing after a fast one would revert
        // whatever the fast save changed, even though the database already holds
        // the fast save's (correct) value — the UI would be lying, not erroring.
        acceptSeq(seq) {
            if (seq < this.drawer.lastApplied) return false;
            this.drawer.lastApplied = seq;
            return true;
        },

        flashSaved() {
            this.drawer.saved = true;
            clearTimeout(this.drawer._savedTimer);
            this.drawer._savedTimer = setTimeout(() => {
                this.drawer.saved = false;
            }, 1600);
        },

        // PATCHes exactly one field. The server validates every field as `sometimes`
        // (see WorkItemController::update()), so a body of `{ priority: 'high' }`
        // touches only the priority column — no full-snapshot overwrite, which is
        // what actually keeps the database correct under out-of-order autosaves.
        async commitField(field, value) {
            if (!this.drawer.id || this.drawer.locked) return;
            const seq = this.nextSeq();
            this.drawer.error = '';
            try {
                const { card, html } = await this.api(`/app/board/${this.drawer.id}`, {
                    method: 'PATCH',
                    body: JSON.stringify({ [field]: value }),
                });
                // A superseded save is not a failed one — a newer save already owns
                // the state, so callers that roll back on failure must not roll back.
                if (!this.acceptSeq(seq)) return true;
                (FIELD_DERIVED[field] || []).forEach((key) => {
                    this.drawer.card[key] = card[key];
                });
                this.applyFieldRepaint(field, html);
                this.flashSaved();

                return true;
            } catch (err) {
                this.drawer.error = err.validation
                    ? err.message
                    : this.t('Save failed. Check the fields and try again.', 'Gagal simpan. Semak medan dan cuba lagi.');

                return false;
            }
        },

        // Title and description autosave on blur AND after 600ms idle, whichever
        // comes first. scheduleCommit() is the idle path; commitFieldFromCard() is
        // both the blur handler and what the idle timer eventually calls.
        scheduleCommit(field) {
            clearTimeout(this.drawer._timers[field]);
            this.drawer._timers[field] = setTimeout(() => this.commitFieldFromCard(field), 600);
        },

        commitFieldFromCard(field) {
            clearTimeout(this.drawer._timers[field]);
            if (field === 'title') {
                const val = (this.drawer.card.title || '').trim();
                if (!val) {
                    this.drawer.error = this.t('Title cannot be empty.', 'Tajuk tidak boleh kosong.');
                    return;
                }
                this.commitField('title', val);
                return;
            }
            if (field === 'description') {
                this.commitField('description', this.drawer.card.description || null);
                return;
            }
            if (field === 'links') {
                // Send a filtered copy so the server never rejects an untouched
                // blank row — but leave drawer.card.links itself alone, so a
                // half-typed row (label filled, url not yet) doesn't disappear
                // from the screen mid-edit.
                const filled = this.drawer.card.links.filter((l) => l.label.trim() || l.url.trim());
                this.commitField('links', filled);
            }
        },

        // The title is a contenteditable heading, not an <input>, so it needs a
        // manual input handler rather than x-model.
        onTitleInput(e) {
            this.drawer.card.title = e.target.textContent;
            this.scheduleCommit('title');
        },

        toggleLabel(key) {
            if (this.drawer.locked) return;
            const cur = this.drawer.card.labels || [];
            const next = cur.includes(key) ? cur.filter((k) => k !== key) : [...cur, key];
            this.drawer.card.labels = next;
            this.drawer.labelMenuOpen = false;
            this.commitField('labels', next);
        },

        addLink() {
            if (this.drawer.locked) return;
            this.drawer.card.links.push({ label: '', url: '' });
        },

        removeLink(idx) {
            if (this.drawer.locked) return;
            this.drawer.card.links.splice(idx, 1);
            if (this.drawer.editingLinkIdx === idx) this.drawer.editingLinkIdx = null;
            else if (this.drawer.editingLinkIdx > idx) this.drawer.editingLinkIdx -= 1;
            this.commitField('links', this.drawer.card.links);
        },

        // Reopens an already-saved link (both label and url filled, otherwise
        // it's already showing as inputs) for editing instead of its button.
        editLink(idx) {
            if (this.drawer.locked) return;
            this.drawer.editingLinkIdx = idx;
        },

        onLinkInput() {
            this.scheduleCommit('links');
        },

        // The links blur handler: saves as usual, then — once both fields are
        // filled — drops the forced-edit flag so the row collapses back to its
        // clickable button. A row with an empty label or url stays on inputs
        // regardless, so this never hides a link the user hasn't finished typing.
        finishLinkEdit(idx) {
            if (this.drawer.editingLinkIdx === idx) this.drawer.editingLinkIdx = null;
            this.commitFieldFromCard('links');
        },

        async addPerson(id) {
            const pid = Number(id);
            if (!pid || this.drawer.locked) return;
            const person = this.people.find((p) => p.id === pid);
            if (person && !this.drawer.card.participants.some((p) => p.id === pid)) {
                this.drawer.card.participants.push(person);
                // The server refuses to share a card that has no due date, so take
                // the person back off the list when it does — otherwise the chip
                // sits there looking saved next to the error.
                const ok = await this.commitField('participant_ids', this.drawer.card.participants.map((p) => p.id));
                if (!ok) {
                    this.drawer.card.participants = this.drawer.card.participants.filter((p) => p.id !== pid);
                }
            }
        },

        removePerson(id) {
            if (this.drawer.locked) return;
            this.drawer.card.participants = this.drawer.card.participants.filter((p) => p.id !== id);
            this.commitField('participant_ids', this.drawer.card.participants.map((p) => p.id));
        },

        // Reveals the native date picker from the formatted-date button, so the
        // drawer can always DISPLAY "30 Jul 2026" (matching the card face) while
        // still using a real <input type="date"> to collect the value — a bare
        // date input renders locale-formatted (07/30/2026 here), which is the
        // inconsistency this button exists to hide.
        openDuePicker() {
            if (this.drawer.locked) return;
            const el = this.$refs.dueInput;
            if (!el) return;
            if (typeof el.showPicker === 'function') {
                try {
                    el.showPicker();
                } catch (err) {
                    el.focus();
                }
            } else {
                el.focus();
                el.click();
            }
        },

        // Status moves through /move (not /update), so it gets its own commit path.
        // "both affected column counts" per the invalidation table means
        // refreshCounts() (cheap: reads, doesn't touch other cards) — never
        // recount() or applyFilter(), which status changes don't need.
        async setStatus(status) {
            if (this.drawer.locked || status === this.drawer.card.status) return;
            const seq = this.nextSeq();
            this.drawer.error = '';
            try {
                const { html } = await this.api(`/app/board/${this.drawer.id}/move`, {
                    method: 'POST',
                    body: JSON.stringify({ status }),
                });
                if (!this.acceptSeq(seq)) return;
                this.drawer.card.status = status;
                // Repaint BEFORE moving columns: the server's html carries the
                // correct wc-when/wc-when--over class for the new status (the card
                // face treats "overdue" as due_at && status !== 'done', so a move
                // to Done must clear the red date — repainting is what applies that).
                this.repaintNode(html);
                this.moveNodeToList(status);
                this.refreshCounts();
                this.flashSaved();
            } catch (err) {
                this.drawer.error = this.t('Could not move this card.', 'Tidak dapat gerakkan kad ini.');
            }
        },

        moveNodeToList(status) {
            const node = this.drawer.node;
            const list = this.$root.querySelector(`[data-list="${status}"]`);
            if (!node || !list) return;
            const empty = list.querySelector('[data-empty]');
            if (empty) empty.remove();
            list.appendChild(node);
        },

        lockedReasonText(card) {
            const who = card.owner_name || this.t('Someone else', 'Orang lain');
            return this.t(
                `${who} owns this card. The details belong to them, so they are read-only here. You can still move it between columns and comment.`,
                `${who} memiliki kad ini. Butirannya milik mereka, jadi ia baca-sahaja di sini. Anda masih boleh gerakkan antara lajur dan komen.`,
            );
        },

        subline(card) {
            if (!card.opened_at) return '';
            const verb = card.assigned_by ? this.t('Assigned', 'Ditugaskan') : this.t('Opened', 'Dibuka');
            const by = this.t('by', 'oleh');
            return `${verb} ${card.opened_at} ${by} ${card.owner_name || ''} · #${card.id}`;
        },

        // Opens by a DOM node — the normal path, from a click or keyboard activation.
        async openCard(node) {
            await this.openCardCore(node.dataset.id, node);
        },

        // Opens by id alone, with no DOM node required — the mention deep-link path
        // (see init()). The card may not even be listed on this viewer's own board
        // (e.g. an assigner mentioned on a tac they assigned away, which doesn't
        // appear in their own columns), so a node is looked up but not required.
        // Failures are silent: a bad id or a card this viewer can't reach must render
        // the board normally, never a visible error.
        async openCardById(id) {
            const node = this.$root.querySelector(`[data-card][data-id="${id}"]`);
            await this.openCardCore(String(id), node, { silent: true });
        },

        async openCardCore(id, node, { silent = false } = {}) {
            this.drawer.trigger = node;
            this.drawer.node = node;
            this.drawer.id = id;
            this.drawer.loading = true;
            this.drawer.error = '';
            this.drawer.newComment = '';
            this.drawer.menuOpen = false;
            this.drawer.labelMenuOpen = false;
            this.drawer.editingLinkIdx = null;
            this.closeMention();
            this.drawer.seq = 0;
            this.drawer.lastApplied = 0;
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
                    timesheet_category_id: card.timesheet_category_id ?? '',
                };
                // Read-only unless the server says this viewer may manage the card. Covers
                // both a tac's assignee (edits belong to the assigner) and a shared card's
                // participant (edits belong to the owner) — either way, move + comment only.
                this.drawer.locked = !!card.assigned_by || card.can_manage === false;
                this.drawer.lockedReason = this.lockedReasonText(card);
                this.drawer.sub = this.subline(card);
                this.drawer.comments = comments;
            } catch (err) {
                if (silent) {
                    // Deep link to an id this viewer can't open (or that doesn't exist):
                    // fold the drawer back down rather than show a 403-shaped error —
                    // "renders the board normally" per the design doc, not "renders the
                    // board with an error banner open".
                    this.drawer.show = false;
                    this.drawer.open = false;
                } else {
                    this.drawer.error = this.t('Could not load this card.', 'Tidak dapat memuatkan kad ini.');
                }
            } finally {
                this.drawer.loading = false;
                if (this.drawer.show) {
                    this.$nextTick(() => {
                        if (this.$refs.titleEl) this.$refs.titleEl.textContent = this.drawer.card.title || '';
                        // Focus the drawer container, not the title: focusing a contenteditable
                        // heading drops the caret in it immediately, so the very first keystroke
                        // (even one meant only to close the drawer or scroll) edits the card's
                        // name and autosaves on the 600ms idle debounce. The container holds
                        // Escape-to-close and is the focus trap's starting point; the title still
                        // gets its :focus treatment normally when the user deliberately clicks
                        // or tabs to it.
                        this.$refs.drawerEl?.focus({ preventScroll: true });
                    });
                }
            }
        },

        closeDrawer() {
            if (!this.drawer.show) return;
            this.drawer.open = false;
            this.drawer.menuOpen = false;
            this.drawer.labelMenuOpen = false;
            this.drawer.editingLinkIdx = null;
            this.closeMention();
            const trigger = this.drawer.trigger;
            clearTimeout(this.drawer._closeTimer);
            this.drawer._closeTimer = setTimeout(() => {
                this.drawer.show = false;
                this.drawer.node = null;
                // The deep-link path (openCardById) can open with no triggering
                // element at all when the card has no node on this viewer's board —
                // trigger is null then. Optional-CALL, not just optional-chain, so
                // that case never throws trying to invoke .focus on null.
                trigger?.focus?.({ preventScroll: true });
            }, 280);
        },

        // Keeps Tab cycling inside the drawer while it is open (WAI-ARIA dialog
        // pattern). Bound to the drawer's own keydown, so it only runs when focus
        // is already somewhere inside it.
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

        async deleteCard() {
            if (this.drawer.locked) return;
            if (!window.confirm(this.t('Delete this card? This cannot be undone.', 'Padam kad ini? Ini tidak boleh dibatalkan.'))) return;
            const node = this.drawer.node;
            try {
                await this.api(`/app/board/${this.drawer.id}`, { method: 'DELETE' });
                this.closeDrawer();
                if (node) {
                    node.classList.add('wc--deleting');
                    node.addEventListener('animationend', () => {
                        node.remove();
                        this.recount();
                        this.refreshCounts();
                    }, { once: true });
                } else {
                    this.recount();
                    this.refreshCounts();
                }
            } catch (err) {
                this.drawer.error = this.t('Delete failed.', 'Gagal padam.');
            }
        },

        // Plays the new-card entrance on a just-inserted node, then drops the class —
        // it's a one-shot animation class, not a persistent state, so a later
        // outerHTML repaint of this same card never accidentally replays it.
        playEnter(node) {
            if (!node) return;
            node.classList.add('wc--entering');
            node.addEventListener('animationend', () => node.classList.remove('wc--entering'), { once: true });
        },

        // Archive a Done card off the board. The API call confirms first — no
        // playing the exit animation on a card that never actually left the
        // database — then a shake (rejects-the-eye's-expectation feedback that
        // something just happened to this card) settles into a fade-and-shrink
        // exit, and only then does the node leave the DOM.
        async archiveCard() {
            if (this.drawer.locked || this.drawer.card.status !== 'done') return;
            const node = this.drawer.node;
            const id = this.drawer.id;
            try {
                await this.api(`/app/board/${id}/archive`, { method: 'POST' });
            } catch (err) {
                this.drawer.error = this.t('Could not archive this card.', 'Tidak dapat arkibkan kad ini.');
                return;
            }
            this.closeDrawer();
            this.archivedCount += 1;
            if (!node) {
                this.recount();
                this.refreshCounts();
                return;
            }
            node.classList.add('wc--archiving');
            node.addEventListener('animationend', () => {
                node.classList.remove('wc--archiving');
                node.classList.add('wc--archiving-out');
                node.addEventListener('animationend', () => {
                    node.remove();
                    this.recount();
                    this.refreshCounts();
                }, { once: true });
            }, { once: true });
        },

        openArchived() {
            this.archivedOpen = true;
            this.loadArchived();
        },

        async loadArchived() {
            try {
                const { items } = await this.api('/app/board/archived');
                this.archivedItems = items;
            } catch (err) {
                this.$store.toast.error(this.t('Could not load archived cards.', 'Tidak dapat memuatkan kad diarkibkan.'));
            }
        },

        // Reopen puts the card back at To Do (the one way back onto the board).
        async reopenCard(id) {
            try {
                const { html } = await this.api(`/app/board/${id}/restore`, { method: 'POST' });
                this.archivedItems = this.archivedItems.filter((a) => a.id !== id);
                this.archivedCount = Math.max(0, this.archivedCount - 1);
                const list = this.$root.querySelector('[data-list="todo"]');
                if (list && html) {
                    const empty = list.querySelector('[data-empty]');
                    if (empty) empty.remove();
                    list.insertAdjacentHTML('afterbegin', html);
                    this.playEnter(list.firstElementChild);
                }
                this.recount();
                this.refreshCounts();
            } catch (err) {
                this.$store.toast.error(this.t('Could not reopen this card.', 'Tidak dapat buka semula kad ini.'));
            }
        },

        // ── Mention picker ───────────────────────────────────────────
        // Opens on "@" at a word boundary in the comment composer and filters as
        // you type. Arrow keys move the selection, Enter/Tab/click insert, and
        // Escape closes the picker only — it must never reach the drawer's own
        // window-level Escape handler. Lifted from public/_proto-board.html's
        // activeQuery()/paintMent()/insertMention()/closeMent().
        closeMention() {
            this.drawer.mention.open = false;
            this.drawer.mention.hits = [];
            this.drawer.mention.idx = 0;
        },

        // The "@word" being typed immediately before the caret, or null.
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
            // Escape must close ONLY the picker. Checked before the hits-length
            // guard below (and before anything else on this key), because an empty
            // pool or a query with no matches still leaves hits at length 0 — if
            // Escape were gated on hits.length, it would fall through and let the
            // drawer's own @keydown.escape.window handler close the whole drawer
            // instead. That is exactly the bug this composer must not have.
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
                // Also stop Tab from reaching the drawer's trapFocus() — otherwise
                // inserting a mention via Tab could immediately steal focus onto the
                // drawer's first/last focusable element instead of staying in the
                // composer.
                e.stopPropagation();
                this.insertMention(this.drawer.mention.hits[this.drawer.mention.idx]);
            }
        },

        // Multi-word names insert whole ("@Nurul Iman binti Hassan", never
        // "@Nurul") — the replaced query and the inserted name both use the same
        // full p.name.
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

        // Escape FIRST, then tint: comment bodies are user input and land in the
        // DOM via x-html, so the raw text is escaped before anything else touches
        // it. Only afterwards do exact-match "@FullName" substrings against the
        // card's own mentionable set get wrapped in a .wd-at span — never the
        // reverse order, which would let a crafted body's HTML survive escaping.
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
            const seq = this.nextSeq();
            try {
                const { comment, count, html } = await this.api(`/app/board/${this.drawer.id}/comments`, {
                    method: 'POST',
                    body: JSON.stringify({ body }),
                });
                // The comment itself is additive, never stale — apply it regardless of
                // sequence. Only the card-face repaint (which carries a full snapshot)
                // is guarded, so it can't revert a field edited after this request fired.
                this.drawer.comments.push(comment);
                this.drawer.newComment = '';
                this.drawer.card.comments_count = count;
                if (this.acceptSeq(seq)) this.repaintNode(html);
            } catch (err) {
                this.drawer.error = this.t('Could not post comment.', 'Tidak dapat hantar komen.');
            }
        },

        async deleteComment(id) {
            const seq = this.nextSeq();
            try {
                const { count, html } = await this.api(`/app/board/comments/${id}`, { method: 'DELETE' });
                this.drawer.comments = this.drawer.comments.filter((c) => c.id !== id);
                this.drawer.card.comments_count = count;
                if (this.acceptSeq(seq)) this.repaintNode(html);
            } catch (err) {
                this.drawer.error = this.t('Could not delete comment.', 'Tidak dapat padam komen.');
            }
        },

        async addCard(status) {
            if (this.busy) return;
            this.busy = true;
            try {
                // Same type-from-active-filter rule the old inline composer used, so
                // a card added while viewing one type of work stays visible under
                // that filter.
                const type = ['task', 'assignment', 'adhoc'].includes(this.filter) ? this.filter : 'assignment';
                const title = this.t('Untitled card', 'Kad tanpa tajuk');
                const { card, html } = await this.api('/app/board', {
                    method: 'POST',
                    body: JSON.stringify({ title, status, type, priority: 'medium' }),
                });
                const list = this.$root.querySelector(`[data-list="${status}"]`);
                const empty = list.querySelector('[data-empty]');
                if (empty) empty.remove();
                list.insertAdjacentHTML('beforeend', html);
                this.playEnter(list.lastElementChild);
                // A brand-new card changes the totals every chip reports, so this is
                // one of the few autosave-adjacent paths that legitimately needs the
                // full applyFilter() rather than the single-card applyFilterTo().
                this.applyFilter();
                await this.openCardCore(String(card.id), list.lastElementChild);
            } finally {
                this.busy = false;
            }
        },

        async persistMove(evt) {
            const list = evt.to;
            const status = list.dataset.list;
            const ids = [...list.querySelectorAll('[data-card]')].map((el) => el.dataset.id);
            const cardId = evt.item.dataset.id;
            evt.item.dataset.status = status;
            this.refreshCounts();
            try {
                const { html } = await this.api(`/app/board/${cardId}/move`, {
                    method: 'POST',
                    body: JSON.stringify({ status, ids }),
                });
                // SortableJS has already placed evt.item in the destination list by the
                // time onEnd fires, and outerHTML destroys whatever node it's assigned
                // to — re-resolve by [data-id] rather than swapping evt.item directly,
                // then confirm the fresh node is still draggable (Sortable binds to the
                // list container, not the item, so it should be — verified in-browser).
                const node = this.$root.querySelector(`[data-card][data-id="${cardId}"]`);
                if (node && html) node.outerHTML = html;
                if (this.drawer.show && String(this.drawer.id) === String(cardId)) {
                    this.drawer.node = this.$root.querySelector(`[data-card][data-id="${cardId}"]`);
                    this.drawer.card.status = status;
                }
                this.refreshCounts();
            } catch (err) {
                // Targeted recovery, not a full-page reload: put the dragged card back
                // where it started, at its original index, and say so via the shared
                // toast — a reload here is exactly what this redesign exists to remove.
                const from = evt.from;
                const ref = from.children[evt.oldIndex] || null;
                from.insertBefore(evt.item, ref);
                evt.item.dataset.status = from.dataset.list;
                this.refreshCounts();
                this.$store.toast.error(this.t('Could not move this card. It has been put back.', 'Tidak dapat gerakkan kad ini. Ia telah dikembalikan.'));
            }
        },
    }));
}
