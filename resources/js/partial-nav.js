/**
 * Partial navigation — swap only the screen body on in-app link clicks.
 *
 * The sidebar, header and side panels are never re-rendered, so the sidebar keeps
 * its scroll position, its collapsed sections and its rail state while the screen
 * beside it changes. Alpine picks up the new markup by itself (its MutationObserver
 * initialises inserted nodes and tears down removed ones), so nothing is re-registered
 * here.
 *
 * ponytail: the server still renders the whole page and the client throws the shell
 * away. That costs a little bandwidth and buys us zero server changes — no fragment
 * routes, no layout split. Move to a real fragment response only if payload size
 * ever shows up in a profile.
 *
 * Anything that does not come back as an app-shell page (login, the workspace picker,
 * a redirect to a wizard, an error page) falls back to a normal full navigation.
 */

const MAIN = 'main.uj-main';
const NAV_LINKS = '.uj-sb-nav a[href], .uj-sb-today a[href], .uj-dock a[href], .uj-dockmore a[href]';

let controller = null;
// The URL currently rendered into <main>. Tracked separately from window.location so a
// popstate that lands on the SAME document (a pushState some other component made just to
// get a history entry to pop, e.g. the timesheet review pane's open/close) can be told apart
// from a real navigation and skipped — see shouldRefetchOnPopstate() below. Set for real in
// registerPartialNav() (not here at module scope) so importing this file never touches
// `window` — the unit test imports it under bun's DOM-less test runner.
let currentUrl = null;

/**
 * Should this click be handled in-page? Exported for the unit test; `link` only has to
 * quack like an anchor (origin, target, getAttribute, hasAttribute).
 */
export function isPartialLink(event, link, origin = window.location.origin) {
    return (
        event.button === 0 &&
        !event.metaKey &&
        !event.ctrlKey &&
        !event.shiftKey &&
        !event.altKey &&
        !event.defaultPrevented &&
        link &&
        !link.target &&
        !link.hasAttribute('download') &&
        !link.hasAttribute('data-full-nav') &&
        link.origin === origin &&
        link.getAttribute('href')?.startsWith('#') !== true
    );
}

/**
 * Should a popstate event trigger a partial-nav refetch? Exported for the unit test. A
 * popstate whose URL matches what's already rendered came from some other component's own
 * in-page pushState (a history entry pushed only so Back/Escape has something to pop) rather
 * than a real navigation, and must not trigger a full main.innerHTML rebuild.
 */
export function shouldRefetchOnPopstate(eventState, currentHref, trackedUrl) {
    return Boolean(eventState?.partialNav) && currentHref !== trackedUrl;
}

/**
 * Copy the freshly rendered sidebar's active markers onto the live sidebar, matched
 * by href. Cheaper and more honest than guessing which row is current from the URL.
 */
function syncNavState(doc) {
    const fresh = new Map();
    doc.querySelectorAll(NAV_LINKS).forEach((a) => fresh.set(a.getAttribute('href'), a.hasAttribute('data-on')));

    document.querySelectorAll(NAV_LINKS).forEach((a) => {
        const on = fresh.get(a.getAttribute('href'));
        if (on === undefined) { return; }
        a.toggleAttribute('data-on', on);
    });
}

async function go(url, { push = true } = {}) {
    const main = document.querySelector(MAIN);
    if (!main) { window.location.assign(url); return; }

    controller?.abort();
    controller = new AbortController();

    main.setAttribute('data-nav-busy', '');

    let doc;
    try {
        const res = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
            credentials: 'same-origin',
        });
        if (!res.ok) { throw new Error('nav ' + res.status); }
        doc = new DOMParser().parseFromString(await res.text(), 'text/html');
        // A redirect out of the app shell (sign-in, tenant picker, wizard) has no
        // <main class="uj-main"> to swap in. Let the browser do it properly.
        if (!doc.querySelector(MAIN)) { throw new Error('not an app screen'); }
        // Follow any redirect the server actually performed.
        url = res.url || url;
    } catch (e) {
        if (e.name === 'AbortError') { return; }
        main.removeAttribute('data-nav-busy');
        window.location.assign(url);
        return;
    }

    const fresh = doc.querySelector(MAIN);
    // The measure classes (.uj-measured / .uj-main--wide / .uj-main--full) live on <main>
    // itself, so swapping only innerHTML left the previous screen's width in place: a board
    // reached from a 920px screen stayed capped and centred until a full reload.
    main.className = fresh.className;
    main.innerHTML = fresh.innerHTML;
    // The new screen arrives from the side, so the swap reads as one region changing
    // rather than the whole page blinking.
    main.querySelector('.uj-fade')?.classList.add('uj-slide');
    main.removeAttribute('data-nav-busy');
    document.title = doc.title;
    syncNavState(doc);
    currentUrl = url;

    if (push) { history.pushState({ partialNav: true }, '', url); }
    main.scrollTop = 0;

    // Mobile: the dock's More grid covers the screen it just changed. Its own
    // `@click` closes it too, but a partial nav can also start from a link inside a
    // screen, and Alpine's state is the only thing that knows the grid is open.
    if (window.innerWidth <= 900) {
        const grid = document.querySelector('.uj-dockmore')?.parentElement;
        const state = grid && window.Alpine ? window.Alpine.$data(grid) : null;
        if (state) { state.more = false; }
    }
}

export function registerPartialNav() {
    document.addEventListener('click', (event) => {
        const link = event.target.closest?.('a[href]');
        if (!isPartialLink(event, link)) { return; }
        // Same page, different hash — leave it to the browser.
        if (link.pathname === window.location.pathname && link.hash) { return; }

        event.preventDefault();
        go(link.href);
    });

    window.addEventListener('popstate', (event) => {
        if (!shouldRefetchOnPopstate(event.state, window.location.href, currentUrl)) { return; }
        go(window.location.href, { push: false });
    });

    // So the first Back after a partial navigation lands on a state we own.
    currentUrl = window.location.href;
    history.replaceState({ partialNav: true }, '', currentUrl);
}
