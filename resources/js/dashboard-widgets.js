/** The gap between cards in a column, matching .uj-dw-col's CSS gap. */
const CARD_GAP = 18;

/**
 * How many cards to lift off the bottom of one column and drop on the other so
 * the two end as close in height as they can get.
 *
 * Pure so it can be tested without a layout: takes the card heights of each
 * column, top to bottom, and answers which column gives and how many. Moves stop
 * as soon as another one would overshoot, and a column is never emptied — one
 * tall stack beside nothing is the same blank half, just on the other side.
 *
 * @param {number[]} left
 * @param {number[]} right
 * @returns {{from: 'left'|'right', count: number}|null}
 */
export function planBalance(left, right, gap = CARD_GAP) {
    const cols = { left: left.slice(), right: right.slice() };
    const total = (a) => a.reduce((sum, h) => sum + h + gap, 0);
    let from = null;
    let count = 0;

    // Bounded rather than while(true): a bad height (0, NaN) must not be able to
    // spin this forever.
    for (let i = 0; i < 10; i++) {
        const tall = total(cols.left) >= total(cols.right) ? 'left' : 'right';
        const short = tall === 'left' ? 'right' : 'left';

        if (cols[tall].length < 2) {
            break;
        }

        // Only ever one direction: the first move decides which column gives.
        if (from !== null && tall !== from) {
            break;
        }

        const before = Math.abs(total(cols.left) - total(cols.right));
        const moved = cols[tall][cols[tall].length - 1];
        const after = Math.abs((total(cols[tall]) - moved - gap) - (total(cols[short]) + moved + gap));

        if (!(after < before)) {
            break;
        }

        cols[short].push(cols[tall].pop());
        from = tall;
        count += 1;
    }

    return from === null ? null : { from, count };
}

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
        /** Where the server put each card, before the balancer moved anything. */
        homeLayout: null,
        /** Set once the viewer drags: their arrangement outranks the balancer. */
        arranged: false,

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
                this.balance();
            }
        },

        // The two columns hold fixed sets of cards, so a short left column leaves
        // half the page blank while the right one runs on. After render the
        // trailing cards of the taller column move over until the two end near
        // the same height. Looks only: nothing is saved, and the moment the
        // viewer drags anything themselves the balancer stands down for good.
        initBalance() {
            this.homeLayout = this.currentOrder();
            this.balance();

            // x-init runs before webfonts land and before the last image decodes,
            // and every card is a few pixels taller once they do. Balance again
            // when the page has settled, or the first paint's split sticks with
            // stale measurements behind it.
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(() => this.balance());
            }
            window.addEventListener('load', () => this.balance());

            let pending = false;
            window.addEventListener('resize', () => {
                if (pending) {
                    return;
                }
                pending = true;
                requestAnimationFrame(() => {
                    pending = false;
                    this.balance();
                });
            });
        },

        /** Every card back in the column and order the server rendered it in. */
        resetLayout() {
            const cols = {};
            this.$el.querySelectorAll('.uj-dw-col').forEach((col) => {
                cols[col.dataset.col] = col;
            });

            Object.entries(this.homeLayout).forEach(([col, ids]) => {
                ids.forEach((id) => {
                    const card = this.$el.querySelector('.uj-dw[data-widget="' + id + '"]');
                    if (card && cols[col]) {
                        cols[col].appendChild(card);
                        delete card.dataset.moved;
                    }
                });
            });
        },

        balance() {
            if (!this.homeLayout || this.arranged) {
                return;
            }

            // Start from the server's layout every time, so a resize re-decides
            // rather than piling another round of moves on the last one.
            this.resetLayout();

            // One column below 1020px: everything is already in one stack.
            if (window.matchMedia('(max-width: 1020px)').matches) {
                return;
            }

            const cols = {};
            this.$el.querySelectorAll('.uj-dw-col').forEach((col) => {
                cols[col.dataset.col] = col;
            });

            if (!cols.left || !cols.right) {
                return;
            }

            const heights = (col) => Array.from(col.children).map((card) => card.offsetHeight);
            const plan = planBalance(heights(cols.left), heights(cols.right));

            if (!plan) {
                return;
            }

            for (let i = 0; i < plan.count; i++) {
                const card = cols[plan.from].lastElementChild;
                cols[plan.from === 'left' ? 'right' : 'left'].appendChild(card);
                card.dataset.moved = '';
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
                // Their first drag freezes whatever the balancer arrived at into
                // a real layout: what they see is what gets saved, and nothing
                // shuffles under them afterwards.
                this.arranged = true;
                root.querySelectorAll('.uj-dw[data-moved]').forEach((w) => {
                    delete w.dataset.moved;
                });
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
