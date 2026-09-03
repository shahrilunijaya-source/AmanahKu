/**
 * Dark wallpaper: which boxes are light?
 *
 * `.uj-on-dark .uj-main` flips the text tokens to white for everything that sits
 * straight on the picture. Anything with its own light, opaque background must set
 * them back, and there is no CSS selector for "has a light background": every screen
 * has its own card classes. So this measures it. Every element inside <main> whose
 * computed background is at least half opaque and light gets `uj-surface`, and the
 * CSS restores the ink tokens under that class. Runs once on load, again for any
 * subtree Alpine adds or restyles, and on demand when the Appearance card previews a
 * pick (window.ujMarkSurfaces).
 *
 * ponytail: getComputedStyle on every element in <main> once per page; a few
 * thousand nodes is a couple of milliseconds. A screen that re-renders thousands of
 * rows a second would want the observer narrowed, none does today.
 */
export function registerDarkSurfaces() {
    const shell = () => document.getElementById('uj-shell');
    const main = () => document.querySelector('.uj-main');

    function luminance(color) {
        const m = color.match(/[\d.]+/g);
        if (!m) return null;
        const [r, g, b, a = 1] = m.map(Number);
        if (a < 0.5) return null;
        const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
        return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
    }

    function mark(root) {
        const on = shell()?.classList.contains('uj-on-dark');
        const els = root.querySelectorAll ? [root, ...root.querySelectorAll('*')] : [];
        for (const el of els) {
            if (!(el instanceof Element)) continue;
            if (!on) { if (el.classList.contains('uj-surface')) el.classList.remove('uj-surface'); continue; }
            const l = luminance(getComputedStyle(el).backgroundColor);
            el.classList.toggle('uj-surface', l !== null && l > 0.5);
        }
    }

    let queued = new Set();
    let raf = 0;
    function schedule(el) {
        queued.add(el);
        if (raf) return;
        raf = requestAnimationFrame(() => {
            raf = 0;
            const batch = queued; queued = new Set();
            for (const el of batch) mark(el);
        });
    }

    window.ujMarkSurfaces = () => { const m = main(); if (m) mark(m); };

    document.addEventListener('DOMContentLoaded', () => {
        const m = main();
        if (!m) return;
        mark(m);
        // Our own uj-surface toggle is a class mutation too. It must not schedule a
        // re-measure: an element whose background is var(--ink) flips dark once the
        // class restores the token, measures dark, loses the class, flips light, and
        // so on forever (the org chart's active chip flickered). Only outside changes
        // to class or style count.
        const onlySurfaceChanged = (r) => {
            if (r.attributeName !== 'class' || r.oldValue === null) return false;
            const strip = (v) => v.split(/\s+/).filter((c) => c && c !== 'uj-surface').sort().join(' ');
            return strip(r.oldValue) === strip(r.target.getAttribute('class') || '');
        };
        new MutationObserver((records) => {
            for (const r of records) {
                if (r.type === 'childList') r.addedNodes.forEach((n) => n instanceof Element && schedule(n));
                else if (r.target instanceof Element && !onlySurfaceChanged(r)) schedule(r.target);
            }
        }).observe(m, { childList: true, subtree: true, attributes: true, attributeOldValue: true, attributeFilter: ['class', 'style'] });
        // A background that transitions in (the board's filter chips fade from clear to
        // white over 140ms) measures as clear at the frame the mutation lands, and a
        // finished transition is not a mutation. Measure again when it settles.
        // Only for elements not yet marked: a marked element whose background then
        // fades dark is one whose background is var(--ink) and is dark *because* the
        // mark restored the token. Re-measuring it would unmark it and start the loop.
        m.addEventListener('transitionend', (e) => {
            if (e.propertyName === 'background-color' && e.target instanceof Element && !e.target.classList.contains('uj-surface')) schedule(e.target);
        });
    });
}
