import { test, expect } from 'bun:test';
import { isPartialLink, shouldRefetchOnPopstate } from './partial-nav';

const ORIGIN = 'http://localhost:9100';

/** Minimal anchor stand-in — only the properties isPartialLink reads. */
function link(href, attrs = {}) {
    return {
        origin: attrs.origin ?? ORIGIN,
        target: attrs.target ?? '',
        getAttribute: (n) => (n === 'href' ? href : null),
        hasAttribute: (n) => Boolean(attrs[n]),
    };
}

const click = (over = {}) => ({ button: 0, defaultPrevented: false, ...over });

test('takes over a plain left click on an in-app link', () => {
    expect(isPartialLink(click(), link('/app/dash'), ORIGIN)).toBe(true);
});

test('leaves modifier clicks alone so open-in-new-tab still works', () => {
    for (const mod of ['metaKey', 'ctrlKey', 'shiftKey', 'altKey']) {
        expect(isPartialLink(click({ [mod]: true }), link('/app/dash'), ORIGIN)).toBe(false);
    }
});

test('leaves middle click alone', () => {
    expect(isPartialLink(click({ button: 1 }), link('/app/dash'), ORIGIN)).toBe(false);
});

test('leaves external links to the browser', () => {
    expect(isPartialLink(click(), link('https://example.com/x', { origin: 'https://example.com' }), ORIGIN)).toBe(false);
});

test('leaves downloads, new-tab targets and opted-out links to the browser', () => {
    expect(isPartialLink(click(), link('/f.pdf', { download: true }), ORIGIN)).toBe(false);
    expect(isPartialLink(click(), link('/app/dash', { target: '_blank' }), ORIGIN)).toBe(false);
    expect(isPartialLink(click(), link('/app/dash', { 'data-full-nav': true }), ORIGIN)).toBe(false);
});

test('leaves in-page anchors alone', () => {
    expect(isPartialLink(click(), link('#main'), ORIGIN)).toBe(false);
});

test('respects a handler that already claimed the click', () => {
    expect(isPartialLink(click({ defaultPrevented: true }), link('/app/dash'), ORIGIN)).toBe(false);
});

test('ignores a click that hit no link', () => {
    expect(isPartialLink(click(), null, ORIGIN)).toBeFalsy();
});

test('refetches on a real partial-nav back/forward to a different URL', () => {
    expect(shouldRefetchOnPopstate({ partialNav: true }, '/app/dash', '/app/board')).toBe(true);
});

test('skips the refetch when the URL did not actually change (e.g. a same-page pushState some other component made just to give Back something to pop)', () => {
    expect(shouldRefetchOnPopstate({ partialNav: true }, '/app/timesheets', '/app/timesheets')).toBe(false);
});

test('skips history states partial-nav never stamped', () => {
    expect(shouldRefetchOnPopstate(null, '/app/dash', '/app/board')).toBe(false);
    expect(shouldRefetchOnPopstate({}, '/app/dash', '/app/board')).toBe(false);
});
