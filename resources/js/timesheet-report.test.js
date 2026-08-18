import { test, expect } from 'bun:test';
import { backTarget, selFromSearch, selToParams, breadcrumb } from './timesheet-report';

test('backTarget pops person-with-slice to its slice', () => {
    expect(backTarget({ view: 'person', key: '42', from: '3' })).toEqual({ view: 'slice', key: '3', from: null });
});

test('backTarget pops person-without-slice to bars', () => {
    expect(backTarget({ view: 'person', key: '42', from: null })).toEqual({ view: 'bars', key: null, from: null });
});

test('backTarget pops slice to bars', () => {
    expect(backTarget({ view: 'slice', key: '3', from: null })).toEqual({ view: 'bars', key: null, from: null });
});

test('selFromSearch reads bars when view is absent', () => {
    const result = selFromSearch(new URLSearchParams(''), 'category', () => [{ id: 3 }]);
    expect(result).toEqual({ lens: 'category', sel: { view: 'bars', key: null, from: null }, stale: false });
});

test('selFromSearch reads a slice whose id exists', () => {
    const rows = [{ id: 3 }, { id: 4 }];
    const result = selFromSearch(new URLSearchParams('view=slice&lens=category&id=3'), 'category', () => rows);
    expect(result).toEqual({ lens: 'category', sel: { view: 'slice', key: '3', from: null }, stale: false });
});

test('selFromSearch reads a person with a from slice', () => {
    const rows = [{ id: 42 }];
    const result = selFromSearch(new URLSearchParams('view=person&lens=category&id=42&from=3'), 'category', () => rows);
    expect(result).toEqual({ lens: 'category', sel: { view: 'person', key: '42', from: '3' }, stale: false });
});

test('selFromSearch falls back to bars and flags stale when the id is gone', () => {
    const result = selFromSearch(new URLSearchParams('view=slice&lens=category&id=99'), 'category', () => [{ id: 3 }]);
    expect(result).toEqual({ lens: 'category', sel: { view: 'bars', key: null, from: null }, stale: true });
});

test('selFromSearch ignores a slice view for the staff lens (no member-list step there)', () => {
    const result = selFromSearch(new URLSearchParams('view=slice&lens=staff&id=42'), 'staff', () => [{ id: 42 }]);
    expect(result).toEqual({ lens: 'staff', sel: { view: 'bars', key: null, from: null }, stale: false });
});

test('selToParams clears every param at bars', () => {
    expect(selToParams({ view: 'bars', key: null, from: null }, 'category'))
        .toEqual({ view: null, lens: null, id: null, from: null });
});

test('selToParams carries lens and id for a slice', () => {
    expect(selToParams({ view: 'slice', key: '3', from: null }, 'category'))
        .toEqual({ view: 'slice', lens: 'category', id: '3', from: null });
});

test('selToParams carries from only for a person opened from a slice', () => {
    expect(selToParams({ view: 'person', key: '42', from: '3' }, 'category'))
        .toEqual({ view: 'person', lens: 'category', id: '42', from: '3' });
    expect(selToParams({ view: 'person', key: '42', from: null }, 'staff'))
        .toEqual({ view: 'person', lens: 'staff', id: '42', from: null });
});

test('breadcrumb for a slice: root + current slice, only root clickable', () => {
    const crumbs = breadcrumb(
        { view: 'slice', key: '3', from: null }, 'category',
        { id: 3, label: 'Maintenance' }, null, null, true
    );
    expect(crumbs).toEqual([
        { label: 'All categories', target: 'bars' },
        { label: 'Maintenance', target: null },
    ]);
});

test('breadcrumb for a person opened from a slice: root, slice, person', () => {
    const crumbs = breadcrumb(
        { view: 'person', key: '42', from: '3' }, 'category',
        null, { id: 42, name: 'Ahmad Kussairi' }, { id: 3, label: 'Maintenance' }, true
    );
    expect(crumbs).toEqual([
        { label: 'All categories', target: 'bars' },
        { label: 'Maintenance', target: 'slice' },
        { label: 'Ahmad Kussairi', target: null },
    ]);
});

test('breadcrumb for a person opened directly from the staff lens: root, person', () => {
    const crumbs = breadcrumb(
        { view: 'person', key: '42', from: null }, 'staff',
        null, { id: 42, name: 'Ahmad Kussairi' }, null, false
    );
    expect(crumbs).toEqual([
        { label: 'Semua individu', target: 'bars' },
        { label: 'Ahmad Kussairi', target: null },
    ]);
});

test('breadcrumb at bars is empty', () => {
    expect(breadcrumb({ view: 'bars', key: null, from: null }, 'category', null, null, null, true)).toEqual([]);
});
