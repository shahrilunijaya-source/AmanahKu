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
const NAV_LINKS = '.uj-sb-nav a[href], .uj-sb-today a[href]';

let controller = null;

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

    main.innerHTML = doc.querySelector(MAIN).innerHTML;
    // The new screen arrives from the side, so the swap reads as one region changing
    // rather than the whole page blinking.
    main.querySelector('.uj-fade')?.classList.add('uj-slide');
    main.removeAttribute('data-nav-busy');
    document.title = doc.title;
    syncNavState(doc);

    if (push) { history.pushState({ partialNav: true }, '', url); }
    main.scrollTop = 0;

    // Mobile: the nav drawer covers the screen it just changed.
    if (window.innerWidth <= 900) { document.querySelector('.uj-nav-backdrop')?.click(); }
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
        if (!event.state?.partialNav) { return; }
        go(window.location.href, { push: false });
    });

    // So the first Back after a partial navigation lands on a state we own.
    history.replaceState({ partialNav: true }, '', window.location.href);
}
