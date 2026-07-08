// Trello-style work board: drag between columns (SortableJS), inline add-a-card
// per column, and a click-to-open detail modal with an inline comment thread.
// Cards are server-rendered static DOM; Sortable reorders them and we persist the
// destination column order over fetch. The modal + composer state live in Alpine.

const TAGS = {
    assignment: ['Assignment', 'var(--red)'],
    task: ['Task', 'var(--info)'],
    adhoc: ['Adhoc', 'var(--amber)'],
};
const PRI = { high: 'var(--error)', medium: 'var(--amber)', low: 'var(--muted)' };
const PRI_LABEL = { high: 'High', medium: 'Medium', low: 'Low' };
const STATUS_LABEL = { todo: 'To Do', prog: 'In Progress', review: 'In Review', done: 'Done' };

const esc = (s) =>
    String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/**
 * Inner markup of a card — reused for create (new node) and update (repaint).
 * Anatomy classes (.wi-*) are defined once in app.css and shared with the
 * server-rendered blades; keep this template in sync with board.blade.php.
 */
function cardInner(card) {
    const [label, color] = TAGS[card.type] || TAGS.task;
    const pri = card.priority
        ? `<span class="wi-pri"><span class="wi-pri-txt" style="--wi-pri:${PRI[card.priority]};">${PRI_LABEL[card.priority]}</span></span>`
        : '<span class="wi-pri"></span>';
    const est = card.estimate_hours ? `${card.estimate_hours}h` : '';
    const comments = Number(card.comments_count) || 0;
    const commentBadge =
        comments > 0
            ? `<span class="wi-comments"><span class="wi-comment-chip">
                 <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>${comments}</span></span>`
            : '<span class="wi-comments"></span>';
    return `
        <div class="wi-head">
            <span class="wi-tag" style="--wi-tag:${color};">${esc(label)}</span>
            ${pri}
        </div>
        ${card.assigned_by ? `<div class="wi-assigned">Assigned by ${esc(card.assigned_by.name || '—')}</div>` : ''}
        <div class="wi-title">${esc(card.title)}</div>
        <div class="wi-foot">
            <span class="wi-due">${esc(card.due_label || '')}</span>
            <span class="wi-meta">
                ${commentBadge}
                <span class="wi-est">${esc(est)}</span>
            </span>
        </div>`;
}

function buildCardNode(card) {
    const node = document.createElement('div');
    node.className = 'uj-card uj-wi';
    node.dataset.card = '';
    node.dataset.id = card.id;
    node.dataset.status = card.status;
    node.dataset.type = card.type;
    node.style.cssText = 'padding:13px 14px;cursor:pointer;';
    node.innerHTML = cardInner(card);
    return node;
}

export function registerWorkBoard(Alpine) {
    Alpine.data('workBoard', (boardType = 'core') => ({
        boardType,
        // 'all' shows everything; each of 'task' / 'assignment' / 'adhoc' shows that
        // one type only. Landing via a typed sidebar link pre-focuses it; else show all.
        filter: ['task', 'assignment', 'adhoc'].includes(boardType) ? boardType : 'all',
        counts: { all: 0, task: 0, assignment: 0, adhoc: 0 },
        token: document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        open: { todo: false, prog: false, review: false, done: false },
        draft: { todo: '', prog: '', review: '', done: '' },
        busy: false,
        modal: {
            show: false,
            loading: false,
            saving: false,
            error: '',
            locked: false,
            id: null,
            node: null,
            newComment: '',
            card: { title: '', description: '', type: 'task', priority: 'medium', due_label: '', estimate_hours: '', status: 'todo' },
            comments: [],
        },
        statusLabels: STATUS_LABEL,

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
            // Open the detail modal when a card (not the composer) is clicked.
            root.addEventListener('click', (e) => {
                const card = e.target.closest('[data-card]');
                if (card && root.contains(card)) this.openCard(card);
            });
            this.applyFilter();
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

        applyFilter() {
            this.$root.querySelectorAll('[data-card]').forEach((el) => {
                el.style.display = this.typeInFilter(el.dataset.type) ? '' : 'none';
            });
            this.recount();
            this.refreshCounts();
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

        async persistMove(evt) {
            const list = evt.to;
            const status = list.dataset.list;
            const ids = [...list.querySelectorAll('[data-card]')].map((el) => el.dataset.id);
            evt.item.dataset.status = status;
            this.refreshCounts();
            try {
                await this.api(`/app/board/${evt.item.dataset.id}/move`, {
                    method: 'POST',
                    body: JSON.stringify({ status, ids }),
                });
            } catch (err) {
                window.location.reload(); // fall back to a clean server render on failure
            }
        },

        toggleComposer(status) {
            this.open[status] = !this.open[status];
            if (this.open[status]) this.$nextTick(() => this.$refs['draft_' + status]?.focus());
        },

        async submitAdd(status) {
            const title = this.draft[status].trim();
            if (!title || this.busy) return;
            this.busy = true;
            try {
                // New cards take the type of the active filter so they stay visible;
                // on "All work" they default to an assignment (refine later in the modal).
                const type = ['task', 'assignment', 'adhoc'].includes(this.filter) ? this.filter : 'assignment';
                const { card } = await this.api('/app/board', {
                    method: 'POST',
                    body: JSON.stringify({ title, status, type, priority: 'medium' }),
                });
                const list = this.$root.querySelector(`[data-list="${status}"]`);
                const empty = list.querySelector('[data-empty]');
                if (empty) empty.remove();
                list.appendChild(buildCardNode(card));
                this.draft[status] = '';
                this.applyFilter();
                this.$nextTick(() => this.$refs['draft_' + status]?.focus());
            } finally {
                this.busy = false;
            }
        },

        async openCard(node) {
            this.modal.node = node;
            this.modal.id = node.dataset.id;
            this.modal.show = true;
            this.modal.loading = true;
            this.modal.error = '';
            this.modal.newComment = '';
            try {
                const { card, comments } = await this.api(`/app/board/${node.dataset.id}`);
                this.modal.card = { ...card, description: card.description ?? '', estimate_hours: card.estimate_hours ?? '' };
                // An assigned tac on this board is opened by the assignee, who may only
                // move it and comment — core fields belong to the assigner.
                this.modal.locked = !!card.assigned_by;
                this.modal.comments = comments;
            } catch (err) {
                this.modal.error = this.t('Could not load this card.', 'Tidak dapat memuatkan kad ini.');
            } finally {
                this.modal.loading = false;
            }
        },

        closeModal() {
            this.modal.show = false;
            this.modal.node = null;
        },

        repaintNode() {
            const node = this.modal.node;
            if (!node) return;
            node.dataset.type = this.modal.card.type;
            node.dataset.status = this.modal.card.status;
            node.innerHTML = cardInner(this.modal.card);
        },

        async saveCard() {
            if (this.modal.saving || this.modal.locked) return;
            this.modal.saving = true;
            this.modal.error = '';
            try {
                const c = this.modal.card;
                const { card } = await this.api(`/app/board/${this.modal.id}`, {
                    method: 'PATCH',
                    body: JSON.stringify({
                        title: c.title,
                        description: c.description || null,
                        type: c.type,
                        priority: c.priority,
                        due_label: c.due_label || null,
                        estimate_hours: c.estimate_hours === '' ? null : c.estimate_hours,
                    }),
                });
                this.modal.card = { ...this.modal.card, ...card, description: card.description ?? '' };
                this.repaintNode();
                this.applyFilter(); // type may have changed → re-apply visibility + counts
                this.closeModal();
            } catch (err) {
                this.modal.error = this.t('Save failed. Check the fields and try again.', 'Gagal simpan. Semak medan dan cuba lagi.');
            } finally {
                this.modal.saving = false;
            }
        },

        async changeStatus(status) {
            try {
                await this.api(`/app/board/${this.modal.id}/move`, { method: 'POST', body: JSON.stringify({ status }) });
                this.modal.card.status = status;
                const node = this.modal.node;
                if (node) {
                    node.dataset.status = status;
                    const list = this.$root.querySelector(`[data-list="${status}"]`);
                    const empty = list?.querySelector('[data-empty]');
                    if (empty) empty.remove();
                    list?.appendChild(node);
                    this.refreshCounts();
                }
            } catch (err) {
                this.modal.error = this.t('Could not move this card.', 'Tidak dapat gerakkan kad ini.');
            }
        },

        async deleteCard() {
            if (this.modal.locked) return;
            if (!window.confirm(this.t('Delete this card? This cannot be undone.', 'Padam kad ini? Ini tidak boleh dibatalkan.'))) return;
            try {
                await this.api(`/app/board/${this.modal.id}`, { method: 'DELETE' });
                this.modal.node?.remove();
                this.closeModal();
                this.recount();
                this.refreshCounts();
            } catch (err) {
                this.modal.error = this.t('Delete failed.', 'Gagal padam.');
            }
        },

        async addComment() {
            const body = this.modal.newComment.trim();
            if (!body) return;
            try {
                const { comment, count } = await this.api(`/app/board/${this.modal.id}/comments`, {
                    method: 'POST',
                    body: JSON.stringify({ body }),
                });
                this.modal.comments.push(comment);
                this.modal.newComment = '';
                this.modal.card.comments_count = count;
                this.repaintNode();
            } catch (err) {
                this.modal.error = this.t('Could not post comment.', 'Tidak dapat hantar komen.');
            }
        },

        async deleteComment(id) {
            try {
                const { count } = await this.api(`/app/board/comments/${id}`, { method: 'DELETE' });
                this.modal.comments = this.modal.comments.filter((c) => c.id !== id);
                this.modal.card.comments_count = count;
                this.repaintNode();
            } catch (err) {
                this.modal.error = this.t('Could not delete comment.', 'Tidak dapat padam komen.');
            }
        },
    }));
}
