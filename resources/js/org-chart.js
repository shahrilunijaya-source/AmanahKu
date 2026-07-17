// Draggable organisation chart. HR / management flip on "Arrange" and drag a
// person's card into another person's reports zone to set who they report to.
//
// The tree is server-rendered nested lists: every node owns a [data-children] list
// tagged with its own id (data-parent), and all lists share one SortableJS group.
// Dropping a node into a list therefore re-parents it — the destination list's
// data-parent becomes the dragged person's new manager (empty = top level). The
// server validates self/loop moves and is the source of truth; the UI also blocks
// the obvious bad drop (a manager into their own subtree) before any request.

const api = async (url, token, body) => {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': token,
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });
    const data = res.status === 204 ? {} : await res.json().catch(() => ({}));
    if (!res.ok) {
        throw new Error(data.error || 'Request failed.');
    }
    return data;
};

/** Direct [data-node] children of a [data-children] list (not descendants). */
const directNodes = (list) => [...list.children].filter((el) => el.matches?.('[data-node]'));

/** Deepest nesting under a [data-children] list. 0 = empty, 1 = leaf row. */
const listDepth = (list) => {
    const nodes = directNodes(list);
    if (nodes.length === 0) return 0;
    let max = 0;
    nodes.forEach((node) => {
        const childList = node.querySelector(':scope > [data-children]');
        max = Math.max(max, childList ? listDepth(childList) : 0);
    });
    return max + 1;
};

export function registerOrgChart(Alpine) {
    Alpine.data('orgChart', () => ({
        arranging: false,
        busy: false,
        error: '',
        token: document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        sortables: [],
        rootEl: null,

        init() {
            // Capture the true component root NOW. The Arrange button lives inside a nested
            // x-data (the toolbar's `{ orgEdit }` scope), so `this.$root` evaluated from that
            // button resolves to the toolbar div, not this component — enable() then bound
            // Sortable to zero tree lists. init() runs on the component element itself, so
            // $root is correct here; every method uses this.rootEl instead.
            this.rootEl = this.$root;

            // While arranging, a card is a drag handle, not a profile link — swallow the
            // navigation click. Outside arrange mode the listener is a no-op.
            this.rootEl.addEventListener(
                'click',
                (e) => {
                    if (this.arranging && e.target.closest('[data-node] a')) {
                        e.preventDefault();
                    }
                },
                true,
            );
        },

        toggleArrange() {
            this.error = '';
            this.arranging = !this.arranging;
            this.arranging ? this.enable() : this.disable();
        },

        enable() {
            this.rootEl.classList.add('org-arranging');
            this.rootEl.querySelectorAll('[data-children]').forEach((list) => {
                this.sortables.push(
                    window.Sortable.create(list, {
                        group: 'org',
                        draggable: '[data-node]',
                        animation: 150,
                        fallbackOnBody: true,
                        ghostClass: 'uj-drag-ghost',
                        // Cancel a drop into the dragged node's own subtree — that would
                        // make a person their own (grand)manager. Server guards it too.
                        onMove: (evt) => !evt.dragged.contains(evt.to),
                        onEnd: (evt) => this.persist(evt),
                    }),
                );
            });
        },

        disable() {
            this.sortables.forEach((s) => s.destroy());
            this.sortables = [];
            this.rootEl.classList.remove('org-arranging');
        },

        async persist(evt) {
            const employeeId = evt.item.dataset.emp;
            const managerId = evt.to.dataset.parent || null;
            // No structural change (dropped back into the same list) — nothing to save.
            if (evt.from === evt.to && evt.oldIndex === evt.newIndex) return;

            this.busy = true;
            this.error = '';
            try {
                await api('/app/org/move', this.token, {
                    employee_id: Number(employeeId),
                    manager_id: managerId ? Number(managerId) : null,
                });
                this.refresh();
            } catch (err) {
                // The server rejected the move (loop, gone, etc). Reload to the clean
                // server-rendered tree so the DOM never drifts from the saved state.
                this.error = err.message;
                window.location.reload();
            } finally {
                this.busy = false;
            }
        },

        // Recompute the live counters from the DOM after a successful drop, so the
        // "N reports" pills and the summary strip stay correct without a full reload.
        refresh() {
            this.rootEl.querySelectorAll('[data-node]').forEach((node) => {
                const childList = node.querySelector(':scope > [data-children]');
                const count = childList ? directNodes(childList).length : 0;
                const pill = node.querySelector(':scope > [data-row] [data-count]');
                if (pill) {
                    pill.textContent = count > 0 ? `${count} report${count > 1 ? 's' : ''}` : '';
                    pill.style.display = count > 0 ? '' : 'none';
                }
            });

            const rootList = this.rootEl.querySelector('[data-children][data-parent=""]');
            if (rootList) {
                this.setStat('roots', directNodes(rootList).length);
                this.setStat('depth', listDepth(rootList));
            }
        },

        setStat(name, value) {
            const el = this.rootEl.querySelector(`[data-stat="${name}"]`);
            if (el) el.textContent = value;
        },
    }));
}
