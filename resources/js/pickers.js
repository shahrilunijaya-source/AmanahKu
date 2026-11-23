/**
 * Custom pickers — the app's own popup for every <select> and every date, time, month
 * and datetime-local input, in place of the browser's.
 *
 * Nothing in the Blade views changes. The native control stays in the page as the
 * real field and the visible trigger: it keeps its name, its page-specific styling,
 * its `required`, and it still shows its own current value, so an Alpine `x-model`
 * that sets `.value` without an event can never leave a stale label behind. This
 * file only stops the browser's popup from opening and opens ours instead; a pick
 * writes the value back and fires bubbling `input` + `change`, which is what
 * `x-model`, `@change` and `onchange="this.form.submit()"` already listen for.
 *
 * Listeners are delegated on `document`, so controls that partial-nav swaps in, or
 * that Alpine renders later, work without any per-element setup.
 *
 * Touch screens keep the phone's own pickers (the iOS wheel and Android calendar are
 * better under a thumb), so everything here is gated on a fine pointer at the moment
 * of the click. A control can opt out with `data-native` on itself or any ancestor.
 *
 * Long lists (more than SEARCH_AFTER options) get a search box. For dates and times
 * focus stays in the native input, so typing a value still works exactly as before;
 * the popup is the mouse path.
 */

const DATE_TYPES = ['date', 'time', 'month', 'datetime-local'];
const SEARCH_AFTER = 8;

/* ── pure helpers (exported for the unit test; no window access) ── */

export const pad = (n) => String(n).padStart(2, '0');

/** "2026-11-05" from a year, a 0-based month and a day. */
export const isoDate = (y, m, d) => `${y}-${pad(m + 1)}-${pad(d)}`;

/**
 * Does a label match what was typed? Every word typed has to appear somewhere in the
 * label, in any order, ignoring case: "kus sut" finds "Ahmad Kussairi Bin Sutikno".
 */
export function matchesQuery(label, query) {
    const hay = label.toLowerCase();
    return query
        .toLowerCase()
        .split(/\s+/)
        .filter(Boolean)
        .every((word) => hay.includes(word));
}

/**
 * The 42 cells (6 weeks, Monday first) of a month view, including the trailing days
 * of the month before and the leading days of the month after.
 *
 * @return {Array<{y: number, m: number, d: number, inMonth: boolean, iso: string}>}
 */
export function monthGrid(year, month) {
    const first = new Date(year, month, 1);
    const lead = (first.getDay() + 6) % 7;
    const cells = [];
    for (let i = 0; i < 42; i++) {
        const day = new Date(year, month, 1 - lead + i);
        const y = day.getFullYear();
        const m = day.getMonth();
        const d = day.getDate();
        cells.push({ y, m, d, inMonth: m === month, iso: isoDate(y, m, d) });
    }
    return cells;
}

/** "14:05" → { h: 14, m: 5 }; anything else → null. */
export function parseTime(value) {
    const match = /^(\d{2}):(\d{2})/.exec(value || '');
    return match ? { h: Number(match[1]), m: Number(match[2]) } : null;
}

/**
 * Minutes offered in the time column. Follows the input's `step` (in seconds) when it
 * is a whole number of minutes, otherwise every 5 minutes. The current minute is always
 * offered even when it is off-step, so opening the picker never hides the saved value.
 */
export function minuteOptions(stepAttr, current) {
    const stepMinutes = Number(stepAttr) >= 60 && Number(stepAttr) % 60 === 0 ? Number(stepAttr) / 60 : 5;
    const minutes = [];
    for (let m = 0; m < 60; m += stepMinutes) {
        minutes.push(m);
    }
    if (current != null && !minutes.includes(current)) {
        minutes.push(current);
        minutes.sort((a, b) => a - b);
    }
    return minutes;
}

/** Is `value` outside the control's min/max? Plain string compare works for ISO formats. */
export function outOfRange(value, min, max) {
    return Boolean((min && value < min) || (max && value > max));
}

/* ── browser side ── */

export function isEnhanceable(el) {
    if (!el || el.disabled || el.closest('[data-native]')) {
        return false;
    }
    if (el.tagName === 'SELECT') {
        return !el.multiple && (Number(el.getAttribute('size')) || 1) <= 1;
    }
    return el.tagName === 'INPUT' && DATE_TYPES.includes(el.type) && !el.readOnly;
}

export function registerPickers() {
    const finePointer = () => window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    const isEn = () => (window.Alpine?.store('ui')?.lang ?? 'en') === 'en';
    const t = (en, ms) => (isEn() ? en : ms);
    const locale = () => (isEn() ? 'en-GB' : 'ms-MY');

    const pop = document.createElement('div');
    pop.className = 'uj-pk';
    pop.setAttribute('popover', 'manual');
    pop.tabIndex = -1;
    const canPopover = typeof pop.showPopover === 'function';

    /** @type {{anchor: HTMLElement, kind: string, view: string, y: number, m: number, query: string, active: number} | null} */
    let state = null;

    function el(tag, attrs = {}, ...children) {
        const node = document.createElement(tag);
        for (const [key, value] of Object.entries(attrs)) {
            if (value === false || value == null) {
                continue;
            }
            if (key.startsWith('on')) {
                node.addEventListener(key.slice(2), value);
            } else {
                node.setAttribute(key, value === true ? '' : value);
            }
        }
        node.append(...children.flat().filter((c) => c != null));
        return node;
    }

    function commit(anchor, value) {
        if (anchor.value === value) {
            return;
        }
        anchor.value = value;
        anchor.dispatchEvent(new Event('input', { bubbles: true }));
        anchor.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function open(anchor) {
        if (state?.anchor === anchor) {
            return;
        }
        close(false);
        const kind = anchor.tagName === 'SELECT' ? 'select' : anchor.type;
        const base = (anchor.value || '').slice(0, 7);
        const now = new Date();
        const [y, m] = /^\d{4}-\d{2}$/.test(base) ? base.split('-').map(Number) : [now.getFullYear(), now.getMonth() + 1];
        state = { anchor, kind, view: kind === 'month' ? 'months' : 'days', y, m: m - 1, query: '', active: anchor.selectedIndex ?? -1 };
        if (!pop.isConnected) {
            document.body.append(pop);
        }
        anchor.setAttribute('data-pk-open', '');
        anchor.setAttribute('aria-expanded', 'true');
        if (canPopover) {
            pop.showPopover();
        } else {
            pop.style.display = 'block';
        }
        render(true);
    }

    function close(refocus = true) {
        if (!state) {
            return;
        }
        const { anchor } = state;
        state = null;
        anchor.removeAttribute('data-pk-open');
        anchor.setAttribute('aria-expanded', 'false');
        if (canPopover) {
            pop.hidePopover();
        } else {
            pop.style.display = 'none';
        }
        pop.replaceChildren();
        if (refocus && anchor.isConnected) {
            anchor.focus({ preventScroll: true });
        }
    }

    function position() {
        if (!state) {
            return;
        }
        const rect = state.anchor.getBoundingClientRect();
        if (!state.anchor.isConnected || (rect.width === 0 && rect.height === 0)) {
            close(false);
            return;
        }
        const width = state.kind === 'select' ? Math.min(Math.max(rect.width, 220), 380) : pop.offsetWidth;
        pop.style.minWidth = state.kind === 'select' ? `${width}px` : '';
        const height = pop.offsetHeight;
        const below = rect.bottom + 4;
        const top = below + height > window.innerHeight - 8 && rect.top - height - 4 > 8 ? rect.top - height - 4 : below;
        const left = Math.min(Math.max(8, rect.left), window.innerWidth - pop.offsetWidth - 8);
        pop.style.top = `${Math.max(8, top)}px`;
        pop.style.left = `${Math.max(8, left)}px`;
    }

    function render(first = false) {
        if (!state) {
            return;
        }
        pop.dataset.kind = state.kind;
        if (state.kind === 'select') {
            renderSelect(first);
        } else {
            const scrolls = [...pop.querySelectorAll('.uj-pk-col')].map((c) => c.scrollTop);
            pop.replaceChildren(...renderDate());
            pop.querySelectorAll('.uj-pk-col').forEach((col, i) => {
                if (first || scrolls[i] === undefined) {
                    // Not scrollIntoView: that can also nudge the page behind the popup.
                    const selected = col.querySelector('[aria-selected="true"]');
                    if (selected) {
                        col.scrollTop = selected.offsetTop - (col.clientHeight - selected.offsetHeight) / 2;
                    }
                } else {
                    col.scrollTop = scrolls[i];
                }
            });
        }
        position();
    }

    /* ── select ── */

    function visibleOptions() {
        const { anchor, query } = state;
        return [...anchor.options]
            .map((option, index) => ({ option, index, label: option.label || option.text }))
            .filter(({ option, label }) => !option.hidden && (!query || matchesQuery(label, query)));
    }

    function renderSelect(first) {
        const { anchor } = state;
        const searchable = anchor.options.length > SEARCH_AFTER;
        if (first) {
            const list = el('div', { class: 'uj-pk-list', role: 'listbox' });
            const search = searchable
                ? el('label', { class: 'uj-pk-search' },
                    el('input', {
                        type: 'text',
                        autocomplete: 'off',
                        spellcheck: 'false',
                        placeholder: t('Search', 'Cari'),
                        'aria-label': t('Search options', 'Cari pilihan'),
                        oninput: (e) => {
                            state.query = e.target.value;
                            state.active = visibleOptions().find(({ option }) => !option.disabled)?.index ?? -1;
                            renderList();
                        },
                    }))
                : null;
            pop.replaceChildren(...[search, list].filter(Boolean));
            renderList();
            (pop.querySelector('.uj-pk-search input') || pop).focus({ preventScroll: true });
            return;
        }
        renderList();
    }

    function renderList() {
        const list = pop.querySelector('.uj-pk-list');
        const { anchor, query, active } = state;
        const rows = [];
        let group = null;
        const options = visibleOptions();
        for (const { option, index, label } of options) {
            const parent = option.parentElement;
            if (parent.tagName === 'OPTGROUP' && parent !== group) {
                group = parent;
                rows.push(el('div', { class: 'uj-pk-group' }, parent.label));
            }
            const selected = index === anchor.selectedIndex;
            rows.push(el('div', {
                class: 'uj-pk-opt',
                role: 'option',
                'data-i': index,
                'aria-selected': String(selected),
                'aria-disabled': option.disabled ? 'true' : false,
                'data-active': index === active ? true : false,
                onclick: () => !option.disabled && pickOption(index),
                onmousemove: () => {
                    if (state.active !== index && !option.disabled) {
                        state.active = index;
                        list.querySelector('[data-active]')?.removeAttribute('data-active');
                        list.querySelector(`[data-i="${index}"]`)?.setAttribute('data-active', '');
                    }
                },
            }, highlight(label || ' ', query)));
        }
        if (!options.length) {
            rows.push(el('div', { class: 'uj-pk-none' }, t('No matches', 'Tiada padanan')));
        }
        list.replaceChildren(...rows);
        list.querySelector('[data-active]')?.scrollIntoView({ block: 'nearest' });
    }

    function highlight(label, query) {
        const word = query.trim().split(/\s+/)[0];
        const at = word ? label.toLowerCase().indexOf(word.toLowerCase()) : -1;
        if (at < 0) {
            return label;
        }
        return [label.slice(0, at), el('mark', {}, label.slice(at, at + word.length)), label.slice(at + word.length)];
    }

    function pickOption(index) {
        const { anchor } = state;
        const changed = anchor.selectedIndex !== index;
        anchor.selectedIndex = index;
        close();
        if (changed) {
            anchor.dispatchEvent(new Event('input', { bubbles: true }));
            anchor.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function moveActive(step) {
        const enabled = visibleOptions().filter(({ option }) => !option.disabled);
        if (!enabled.length) {
            return;
        }
        const at = enabled.findIndex(({ index }) => index === state.active);
        let next = Math.min(Math.max(at + step, 0), enabled.length - 1);
        if (at < 0 || step === -Infinity) {
            next = 0;
        }
        if (step === Infinity) {
            next = enabled.length - 1;
        }
        state.active = enabled[next].index;
        renderList();
    }

    /* ── date / month / time ── */

    function renderDate() {
        const { kind } = state;
        if (kind === 'time') {
            return [renderTime(), footer()];
        }
        if (kind === 'datetime-local') {
            return [el('div', { class: 'uj-pk-split' }, el('div', {}, calendar()), renderTime()), footer()];
        }
        return [calendar(), footer()];
    }

    function nav(title, onPrev, onNext, onTitle) {
        return el('div', { class: 'uj-pk-nav' },
            el('button', { type: 'button', class: 'uj-pk-arrow', 'aria-label': t('Previous', 'Sebelum'), onclick: onPrev }, chevron('M15 6l-6 6 6 6')),
            onTitle ? el('button', { type: 'button', class: 'uj-pk-title', onclick: onTitle }, title) : el('span', { class: 'uj-pk-title' }, title),
            el('button', { type: 'button', class: 'uj-pk-arrow', 'aria-label': t('Next', 'Seterusnya'), onclick: onNext }, chevron('M9 6l6 6-6 6')));
    }

    function chevron(d) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', d);
        svg.append(path);
        return svg;
    }

    function shiftMonth(by) {
        const d = new Date(state.y, state.m + by, 1);
        state.y = d.getFullYear();
        state.m = d.getMonth();
        render();
    }

    /** The "YYYY-MM-DD" part of the anchor's value, and its min/max, whatever the kind. */
    function dateBounds() {
        const { anchor } = state;
        const cut = (v) => (v || '').slice(0, 10);
        return { value: cut(anchor.value), min: cut(anchor.min), max: cut(anchor.max) };
    }

    function calendar() {
        const { view, y, m, anchor, kind } = state;
        const today = new Date();

        if (view === 'years') {
            const start = y - (y % 12);
            return el('div', {},
                nav(`${start} – ${start + 11}`, () => { state.y -= 12; render(); }, () => { state.y += 12; render(); }),
                el('div', { class: 'uj-pk-grid uj-pk-grid--4' }, Array.from({ length: 12 }, (_, i) => start + i).map((year) =>
                    el('button', {
                        type: 'button',
                        'aria-selected': String(year === Number((anchor.value || '').slice(0, 4))),
                        'data-today': year === today.getFullYear() ? true : false,
                        onclick: () => { state.y = year; state.view = 'months'; render(); },
                    }, String(year)))));
        }

        if (view === 'months') {
            const names = Array.from({ length: 12 }, (_, i) => new Date(2000, i, 1).toLocaleString(locale(), { month: 'short' }));
            const current = (anchor.value || '').slice(0, 7);
            const min = (anchor.min || '').slice(0, 7);
            const max = (anchor.max || '').slice(0, 7);
            return el('div', {},
                nav(String(y), () => { state.y--; render(); }, () => { state.y++; render(); }, () => { state.view = 'years'; render(); }),
                el('div', { class: 'uj-pk-grid uj-pk-grid--4' }, names.map((name, i) => {
                    const value = `${y}-${pad(i + 1)}`;
                    return el('button', {
                        type: 'button',
                        disabled: kind === 'month' && outOfRange(value, min, max),
                        'aria-selected': String(value === current),
                        'data-today': y === today.getFullYear() && i === today.getMonth() ? true : false,
                        onclick: () => {
                            if (kind === 'month') {
                                commit(anchor, value);
                                close();
                            } else {
                                state.m = i;
                                state.view = 'days';
                                render();
                            }
                        },
                    }, name);
                })));
        }

        const { value, min, max } = dateBounds();
        const todayIso = isoDate(today.getFullYear(), today.getMonth(), today.getDate());
        const title = new Date(y, m, 1).toLocaleString(locale(), { month: 'long', year: 'numeric' });
        // 2024-01-01 was a Monday, so these are Mon..Sun in the viewer's language.
        const weekdays = Array.from({ length: 7 }, (_, i) => new Date(2024, 0, 1 + i).toLocaleString(locale(), { weekday: 'narrow' }));
        return el('div', {},
            nav(title, () => shiftMonth(-1), () => shiftMonth(1), () => { state.view = 'months'; render(); }),
            el('div', { class: 'uj-pk-grid uj-pk-grid--7' },
                weekdays.map((w) => el('span', { class: 'uj-pk-wd' }, w)),
                monthGrid(y, m).map((cell) => el('button', {
                    type: 'button',
                    disabled: outOfRange(cell.iso, min, max),
                    'aria-selected': String(cell.iso === value),
                    'data-out': cell.inMonth ? false : true,
                    'data-today': cell.iso === todayIso ? true : false,
                    onclick: () => pickDay(cell.iso),
                }, String(cell.d)))));
    }

    function pickDay(iso) {
        const { anchor, kind } = state;
        if (kind === 'date') {
            commit(anchor, iso);
            close();
            return;
        }
        // datetime-local: keep the time already chosen, or start the day at 09:00.
        commit(anchor, `${iso}T${(anchor.value || '').slice(11, 16) || '09:00'}`);
        const [y, m] = iso.split('-').map(Number);
        state.y = y;
        state.m = m - 1;
        render();
    }

    function renderTime() {
        const { anchor, kind } = state;
        const timePart = kind === 'time' ? anchor.value : (anchor.value || '').slice(11, 16);
        const time = parseTime(timePart);
        const pm = time ? time.h >= 12 : false;
        const hour12 = time ? (time.h % 12 || 12) : null;

        const setTime = (h, m) => {
            const hhmm = `${pad(h)}:${pad(m)}`;
            if (kind === 'time') {
                commit(anchor, hhmm);
            } else {
                const { value } = dateBounds();
                const today = new Date();
                commit(anchor, `${value || isoDate(today.getFullYear(), today.getMonth(), today.getDate())}T${hhmm}`);
            }
            render();
        };
        const to24 = (h12, isPm) => (h12 % 12) + (isPm ? 12 : 0);
        const minute = time ? time.m : 0;

        const column = (items, label) => el('div', { class: 'uj-pk-col', role: 'listbox', 'aria-label': label }, items);
        return el('div', { class: 'uj-pk-time' },
            column([12, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11].map((h) => el('button', {
                type: 'button',
                'aria-selected': String(h === hour12),
                onclick: () => setTime(to24(h, pm), minute),
            }, pad(h))), t('Hour', 'Jam')),
            column(minuteOptions(anchor.getAttribute('step'), time?.m).map((mm) => el('button', {
                type: 'button',
                'aria-selected': String(time ? mm === time.m : false),
                onclick: () => setTime(time ? time.h : 9, mm),
            }, pad(mm))), t('Minute', 'Minit')),
            column([[false, t('AM', 'PG')], [true, t('PM', 'PTG')]].map(([isPm, text]) => el('button', {
                type: 'button',
                'aria-selected': String(time ? pm === isPm : false),
                onclick: () => setTime(to24(hour12 ?? (isPm ? 12 : 9), isPm), minute),
            }, text)), 'AM/PM'));
    }

    function footer() {
        const { anchor, kind } = state;
        const now = new Date();
        const today = isoDate(now.getFullYear(), now.getMonth(), now.getDate());
        const hhmm = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
        const quick = {
            date: [t('Today', 'Hari ini'), today],
            month: [t('This month', 'Bulan ini'), today.slice(0, 7)],
            time: [t('Now', 'Sekarang'), hhmm],
            'datetime-local': [t('Now', 'Sekarang'), `${today}T${hhmm}`],
        }[kind];
        return el('div', { class: 'uj-pk-foot' },
            anchor.required ? el('span') : el('button', { type: 'button', onclick: () => { commit(anchor, ''); close(); } }, t('Clear', 'Kosongkan')),
            el('button', { type: 'button', onclick: () => { commit(anchor, quick[1]); close(); } }, quick[0]));
    }

    /* ── wiring ── */

    // Clicks inside the popup must not reach document-level "click outside" handlers
    // (an Alpine modal with @click.outside would close under the user's pick), and
    // mousedown must not steal focus from the date input the user may be typing in.
    pop.addEventListener('mousedown', (e) => {
        if (!e.target.closest('.uj-pk-search')) {
            e.preventDefault();
        }
        e.stopPropagation();
    });
    pop.addEventListener('click', (e) => e.stopPropagation());
    pop.addEventListener('keydown', (e) => {
        if (state?.kind !== 'select') {
            return;
        }
        const keys = { ArrowDown: 1, ArrowUp: -1, PageDown: 8, PageUp: -8, Home: -Infinity, End: Infinity };
        if (e.key in keys) {
            e.preventDefault();
            moveActive(keys[e.key]);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (state.active >= 0 && !state.anchor.options[state.active]?.disabled) {
                pickOption(state.active);
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            close();
        } else if (e.key === 'Tab') {
            close();
        }
    });

    document.addEventListener('mousedown', (e) => {
        const target = e.target;
        if (state && !pop.contains(target) && target !== state.anchor) {
            close(false);
        }
        if (e.button !== 0 || target.tagName !== 'SELECT' || !finePointer() || !isEnhanceable(target)) {
            return;
        }
        e.preventDefault();
        if (state?.anchor === target) {
            close();
        } else {
            target.focus({ preventScroll: true });
            open(target);
        }
    }, true);

    // Date-type inputs open on click (not mousedown) so the click still lands focus on
    // the segment under the pointer and typing keeps working. preventDefault stops
    // Firefox, which opens its own popup on any click in the field.
    document.addEventListener('click', (e) => {
        const target = e.target;
        if (target.tagName === 'INPUT' && finePointer() && isEnhanceable(target)) {
            e.preventDefault();
            open(target);
        }
    }, true);

    document.addEventListener('keydown', (e) => {
        const target = e.target;
        if (state && target === state.anchor && state.kind !== 'select') {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                close();
            } else if (e.key === 'Tab') {
                close(false);
            }
            return;
        }
        if (!finePointer() || !isEnhanceable(target)) {
            return;
        }
        const opener = e.key === 'F4' || (e.altKey && (e.key === 'ArrowDown' || e.key === 'ArrowUp'));
        if (opener || (target.tagName === 'SELECT' && (e.key === ' ' || e.key === 'Enter'))) {
            e.preventDefault();
            open(target);
        }
    }, true);

    // Typing into the date/time input moves the calendar along with it.
    document.addEventListener('input', (e) => {
        if (state && e.target === state.anchor && state.kind !== 'select') {
            const base = (state.anchor.value || '').slice(0, 7);
            if (/^\d{4}-\d{2}$/.test(base)) {
                [state.y, state.m] = [Number(base.slice(0, 4)), Number(base.slice(5, 7)) - 1];
            }
            render();
        }
    }, true);

    window.addEventListener('scroll', (e) => {
        if (state && !pop.contains(e.target)) {
            position();
        }
    }, { capture: true, passive: true });
    window.addEventListener('resize', position);
    window.addEventListener('blur', () => close(false));

    // Code that asks for the browser's picker directly (the work drawer's due date)
    // gets ours instead on desktop.
    for (const proto of [HTMLInputElement.prototype, HTMLSelectElement.prototype]) {
        const native = proto.showPicker;
        if (typeof native !== 'function') {
            continue;
        }
        proto.showPicker = function showPicker() {
            if (finePointer() && isEnhanceable(this)) {
                open(this);
                return;
            }
            return native.call(this);
        };
    }
}
