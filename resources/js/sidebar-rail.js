/**
 * Section panel for the sidebar.
 *
 * The sidebar is a column of sections; the screens inside one open in a panel
 * beside the row, at both widths — the 248px column and the 64px rail — because
 * the column itself never nests anything on show. `position: fixed` because the
 * nav scrolls (`overflow-y: auto`), and an absolutely positioned panel would be
 * clipped by it.
 *
 * Below 901px it is inert: the sidebar does not render there at all, the bottom
 * dock does.
 */
export function registerSidebarRail(Alpine) {
    Alpine.data('sbSec', () => ({
        fly: false,
        pinned: false,
        hideTimer: null,

        deskOn() {
            return window.matchMedia('(min-width: 901px)').matches;
        },

        show(event) {
            if (! this.deskOn()) {
                return;
            }
            clearTimeout(this.hideTimer);
            this.fly = true;
            this.place(event.currentTarget.getBoundingClientRect());
        },

        // Anchored off the sidebar's outer edge (rows are inset by the nav padding)
        // so the same math works at 64px and 248px. The vertical clamp waits a frame:
        // measured in the same tick it reads a stale height and a tall panel hangs
        // off the window.
        place(row) {
            const bar = this.$root.closest('.uj-sidebar').getBoundingClientRect();

            this.$nextTick(() => {
                const el = this.$refs.fly;
                if (! el) {
                    return;
                }
                el.style.left = `${bar.right + 8}px`;
                el.style.top = `${row.top - 6}px`;
                requestAnimationFrame(() => {
                    el.style.top = `${Math.max(12, Math.min(row.top - 6, window.innerHeight - el.offsetHeight - 12))}px`;
                });
            });
        },

        hide() {
            if (this.pinned) {
                return;
            }
            this.hideTimer = setTimeout(() => { this.fly = false; }, 120);
        },

        toggle(event) {
            if (! this.deskOn()) {
                return;
            }
            this.pinned = ! this.pinned;
            if (this.pinned) {
                this.show(event);
            } else {
                this.fly = false;
            }
        },

        close() {
            this.pinned = false;
            this.fly = false;
        },
    }));

    /**
     * Sub-panel for a group inside a section panel (Oversight, Offboarding).
     *
     * The group holds one cell in the section grid; its own screens open in a panel
     * to the right of that grid rather than sitting loose among the section's other
     * entries. Anchored off the section panel's right edge, so it clears it whatever
     * the panel's width worked out to. This is the last level — nothing opens from
     * a sub-panel.
     */
    Alpine.data('sbSub', () => ({
        sub: false,
        subTimer: null,

        // Offsets are measured from the cell, NOT the viewport: the section panel is
        // transformed (that is what fades it in), and a transformed element becomes
        // the containing block for anything positioned inside it — a fixed sub-panel
        // would land one panel-origin off. So it is absolute within the cell, and the
        // viewport clamp is converted back into cell-relative space at the end.
        openSub(event) {
            clearTimeout(this.subTimer);
            this.sub = true;

            const cell = this.$root.getBoundingClientRect();
            const panel = this.$root.closest('.uj-fly').getBoundingClientRect();

            this.$nextTick(() => {
                const el = this.$refs.sub;
                if (! el) {
                    return;
                }
                el.style.left = `${panel.right - cell.left + 6}px`;
                el.style.top = '-8px';
                requestAnimationFrame(() => {
                    const top = Math.max(12, Math.min(cell.top - 8, window.innerHeight - el.offsetHeight - 12));
                    el.style.top = `${top - cell.top}px`;
                });
            });
        },

        closeSub() {
            this.subTimer = setTimeout(() => { this.sub = false; }, 120);
        },
    }));
}
