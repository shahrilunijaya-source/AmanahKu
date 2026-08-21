/**
 * Rail flyout for the collapsed sidebar.
 *
 * When the sidebar is a 64px rail the labels are gone, so hovering an icon opens
 * that row again in full beside it, carrying its child links when it has any. The
 * flyout is `position: fixed` because the nav scrolls (`overflow-y: auto`), and an
 * absolutely positioned panel would be clipped by it.
 *
 * Only mounts while hovering, and only above 900px: below that the sidebar is a
 * full-width off-canvas drawer with its labels intact, so there is nothing to
 * reveal. `sbCollapsed` comes from the shell's own x-data via Alpine's scope chain.
 *
 * @param {boolean} expanded whether this item's children start open in the sidebar
 */
export function registerSidebarRail(Alpine) {
    Alpine.data('sbFly', (expanded = false) => ({
        open: expanded,
        fly: false,
        hideTimer: null,

        railOn() {
            return this.sbCollapsed && window.matchMedia('(min-width: 901px)').matches;
        },

        show(event) {
            if (! this.railOn()) {
                return;
            }
            clearTimeout(this.hideTimer);
            this.fly = true;

            const row = event.currentTarget.getBoundingClientRect();
            const rail = this.$root.closest('.uj-sidebar').getBoundingClientRect();

            // Anchor off the rail's outer edge, since rows are inset by the nav
            // padding. The vertical clamp waits a frame: measured in the same tick
            // it reads a stale height and a tall flyout hangs off the window.
            this.$nextTick(() => {
                const el = this.$refs.fly;
                if (! el) {
                    return;
                }
                el.style.left = `${rail.right + 8}px`;
                el.style.top = `${row.top - 6}px`;
                requestAnimationFrame(() => {
                    el.style.top = `${Math.max(12, Math.min(row.top - 6, window.innerHeight - el.offsetHeight - 12))}px`;
                });
            });
        },

        // Small grace period so the pointer can cross the 8px gap into the flyout.
        hide() {
            this.hideTimer = setTimeout(() => { this.fly = false; }, 120);
        },
    }));

    /**
     * Section panel for the desktop sidebar.
     *
     * The desktop nav is a column of sections; the screens inside one open in a
     * panel beside the row. Unlike `sbFly` this runs at BOTH widths — the 248px
     * column and the 64px rail — because the sidebar no longer nests anything on
     * show, so the panel is the only way in either way. Below 901px it is inert:
     * that is the off-canvas drawer, which still renders the old tree.
     *
     * Hover opens it and leaving closes it. Clicking the row pins it open, which is
     * the keyboard route in (a pointer-only menu is unreachable otherwise) and also
     * what a touch device on a wide screen gets. Escape or a click elsewhere
     * unpins.
     */
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
