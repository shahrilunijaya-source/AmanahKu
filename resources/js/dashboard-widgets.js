// The dashboard's widget picker and drag-to-rearrange.
//
// Turning a widget on or off needs a re-render — the payload is built server-side
// and is not in the page — so Save posts and then reloads. Reordering does not:
// the cards are already here, so a drag moves the node and posts the new order.
export function registerDashboardWidgets(Alpine) {
    Alpine.data('ujDashboard', (config) => ({
        picking: false,
        filter: 'All',
        hidden: config.hidden,
        catalog: config.catalog,
        prefsUrl: config.prefsUrl,
        widgetUrl: config.widgetUrl,
        draft: [],

        openPicker() {
            this.draft = this.catalog.map((i) => i.id).filter((id) => !this.hidden.includes(id));
            this.filter = 'All';
            this.picking = true;
        },

        cancelPicker() {
            this.picking = false;
        },

        shown() {
            return this.filter === 'All'
                ? this.catalog
                : this.catalog.filter((i) => i.category === this.filter);
        },

        toggle(id) {
            this.draft = this.draft.includes(id)
                ? this.draft.filter((x) => x !== id)
                : this.draft.concat(id);
        },

        savePicker() {
            this.hidden = this.catalog.map((i) => i.id).filter((id) => !this.draft.includes(id));
            this.picking = false;
            this.save().then(() => window.location.reload());
        },

        /** The order as the DOM currently has it, per column. */
        currentOrder() {
            const order = {};
            this.$el.querySelectorAll('.uj-dw-col').forEach((col) => {
                order[col.dataset.col] = Array.from(col.children)
                    .filter((w) => w.dataset.widget)
                    .map((w) => w.dataset.widget);
            });
            return order;
        },

        // Rebuild one card for another period. Only that card's insides are
        // replaced, so the page keeps its scroll, its open folds and whatever
        // period the other cards are on. Alpine initialises the new markup by
        // itself; `scope` and the drag handle live outside the swap and survive it.
        async shiftPeriod(el, at) {
            const card = el.closest('.uj-dw');
            if (!card || !this.widgetUrl) {
                return;
            }

            const url = new URL(this.widgetUrl.replace('__id__', card.dataset.widget), window.location.origin);
            if (at) {
                url.searchParams.set('at', at);
            }

            card.dataset.busy = '';
            try {
                const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (res.ok) {
                    card.innerHTML = await res.text();
                }
            } catch {
                // A failed fetch leaves the card exactly as it was, which reads as
                // "the arrow did nothing" — better than blanking somebody's data.
            } finally {
                delete card.dataset.busy;
            }
        },

        save() {
            if (!this.prefsUrl) {
                return Promise.resolve();
            }

            return fetch(this.prefsUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    Accept: 'application/json',
                },
                body: JSON.stringify({ hidden: this.hidden, order: this.currentOrder() }),
            }).catch(() => {});
        },

        // Native HTML5 drag and drop, no library. The card header is the handle,
        // the way Worksy drags a widget by its title: no grip button had to be
        // added to eleven sections. Cards move within and between the two columns;
        // the drop point is the first card whose midpoint sits below the cursor.
        // Pointer-based, so this is desktop only — the CSS hides the grip dots on
        // touch rather than promising a gesture that cannot fire.
        initDrag() {
            let dragged = null;
            const root = this.$el;

            // Delegated rather than bound per header: a period arrow replaces its
            // card's markup, and a listener attached to the old header would go with it.
            root.addEventListener('mousedown', (e) => {
                const head = e.target.closest && e.target.closest('.uj-dw-hd');
                // Controls living in the header keep working; only a bare header grabs.
                if (!head || e.target.closest('button,a,input,select')) {
                    return;
                }
                head.closest('.uj-dw').draggable = true;
            });

            root.addEventListener('mouseup', () => {
                root.querySelectorAll('.uj-dw[draggable]').forEach((card) => {
                    card.draggable = false;
                });
            });

            root.addEventListener('dragstart', (e) => {
                const card = e.target.closest && e.target.closest('.uj-dw');
                if (!card || !card.draggable) {
                    return;
                }
                dragged = card;
                card.dataset.dragging = '';
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', card.dataset.widget);
            });

            root.addEventListener('dragend', () => {
                if (!dragged) {
                    return;
                }
                delete dragged.dataset.dragging;
                dragged.draggable = false;
                dragged = null;
                this.save();
            });

            root.querySelectorAll('.uj-dw-col').forEach((col) => {
                col.addEventListener('dragover', (e) => {
                    if (!dragged) {
                        return;
                    }
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    const after = Array.from(col.children)
                        .filter((w) => w.dataset.widget && w !== dragged)
                        .find((w) => {
                            const r = w.getBoundingClientRect();
                            return e.clientY < r.top + r.height / 2;
                        });
                    if (after) {
                        col.insertBefore(dragged, after);
                    } else {
                        col.appendChild(dragged);
                    }
                });
                col.addEventListener('drop', (e) => e.preventDefault());
            });
        },
    }));
}
