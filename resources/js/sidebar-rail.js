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
}
