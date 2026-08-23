{{--
    Coachmark — a one-time chat bubble that points at one control and explains it.

    Different from partials/guide.blade.php on purpose. The guide is the whole
    screen's manual, always available behind "What is this screen for?", and it
    only opens when asked. A coachmark opens itself, says one thing about one
    control, and never comes back once closed.

    Place it directly after the element it talks about; the bubble's tail points
    up at whatever sits above it. The host div is a zero-height marker in normal
    flow, so it tracks its anchor through any layout change, while the bubble
    floats on its own layer over the content below. Opening and closing it
    reflows nothing.

    Dismissal is per key in localStorage (`amanahku-coach-<key>`), matching the
    profile banner and the welcome modal. Bump the key when the copy changes
    materially, otherwise everyone who already closed it never sees the new text.

    Usage:
        @include('partials.coachmark', [
            'key' => 'attendance-work-mode',
            'en'  => ['title' => '...', 'body' => '...'],
            'ms'  => ['title' => '...', 'body' => '...'],
        ])

    Optional $after: another coachmark's key. This one stays shut until that one has
    been dismissed, so two bubbles on the same screen queue instead of overlapping —
    they float on their own layer and would otherwise sit on top of each other.

    Optional $anchor: a CSS selector for the exact control, looked up among the marker's
    siblings. The bubble still hangs off the left edge (that is what keeps it on screen on
    a phone), but the tail slides across to sit under that control — needed when the
    control floats to the far right of a wide row and the default 22px tail would point
    at whatever happens to sit at the row's start.

    Optional $side: put the bubble beside the marker's column with the tail on its left
    edge, instead of below the control with the tail on top. For a bubble about a narrow
    column — the sidebar — where hanging underneath would cover the very rows it names.

    Required: $key, $en. $ms, $after, $anchor and $side optional; $ms falls back to English.
--}}
@php
    $ms = $ms ?? $en;
    $after = $after ?? null;
    $anchor = $anchor ?? null;
@endphp
<div x-data="{
        show: localStorage.getItem('amanahku-coach-{{ $key }}') !== '1'@if ($after) && localStorage.getItem('amanahku-coach-{{ $after }}') === '1'@endif,
        copy: {{ \Illuminate\Support\Js::from(['en' => $en, 'ms' => $ms]) }},
        get c() { return this.copy[$store.ui.lang] ?? this.copy.en; },
        @if ($anchor)
        /**
         * Slide the tail under the anchored control. Clamped so it never runs off the
         * bubble's own corners, and re-run whenever the control moves or resizes — it is
         * hidden until the first GPS fix lands, and it changes width with the answer.
         */
        placeTail() {
            // Twice on purpose. The inline read is what a backgrounded tab gets, where
            // requestAnimationFrame does not run at all; the framed one is the accurate
            // read, because the callers fire while the row is still settling and a rect
            // taken there describes a layout on its way to somewhere else.
            this.measureTail();
            requestAnimationFrame(() => this.measureTail());
        },
        /**
         * Everything that can move the control after the first measurement.
         *
         * The size observers are the obvious half. The other half is why this bubble was
         * wrong on a first visit and right on every refresh: web fonts arrive after the
         * first paint, the text beside the control re-flows to its real width, and the
         * control slides sideways without changing size or changing the size of anything
         * being observed. On a refresh the fonts are already cached, the first measurement
         * is taken on final metrics, and nothing looks broken. document.fonts.ready is
         * that missing event.
         */
        trackTail() {
            const anchor = this.$root.parentElement?.querySelector('{{ $anchor }}');
            const ro = new ResizeObserver(() => this.placeTail());
            ro.observe(this.$root.parentElement);
            ro.observe(this.$refs.bubble);
            if (anchor) { ro.observe(anchor); }
            document.fonts?.ready.then(() => this.placeTail());
            window.addEventListener('resize', () => this.placeTail());
        },
        measureTail() {
            const el = this.$root.parentElement?.querySelector('{{ $anchor }}');
            if (!el) { return; }
            const a = el.getBoundingClientRect();
            const b = this.$refs.bubble.getBoundingClientRect();
            // Either box can be zero-width here: the bubble while the tip is still closed,
            // the control while it waits on whatever makes it appear. Measuring then pins
            // the tail to the clamp floor and it never recovers, so wait to be asked again.
            if (!a.width || !b.width) { return; }
            const x = a.left + a.width / 2 - b.left;
            this.$root.style.setProperty('--coach-tail', Math.max(16, Math.min(b.width - 16, x)) + 'px');
        },
        @endif
        dismiss() {
            this.show = false;
            localStorage.setItem('amanahku-coach-{{ $key }}', '1');
            // Wakes any coachmark queued behind this one ($after). Without it the next
            // bubble waits for a page load the staff member has no reason to perform.
            window.dispatchEvent(new CustomEvent('coach-dismissed'));
        }
     }"
     @if ($anchor)
     x-init="$nextTick(() => { placeTail(); trackTail(); })"
     @endif
     @if ($after) @coach-dismissed.window="show = localStorage.getItem('amanahku-coach-{{ $key }}') !== '1' && localStorage.getItem('amanahku-coach-{{ $after }}') === '1'" @endif
     x-show="show"
     x-cloak
     x-transition:enter="uj-coach-in"
     x-transition:enter-start="uj-coach-in-start"
     x-transition:enter-end="uj-coach-in-end"
     class="uj-coach{{ ($side ?? false) ? ' uj-coach-side' : '' }}"
     role="note">
    <div class="uj-coach-bubble" x-ref="bubble">
        <div class="uj-coach-head">
            <span class="uj-coach-dot" aria-hidden="true">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.8-.9L3 21l1.9-5.1A8.4 8.4 0 0 1 4 11.5a8.4 8.4 0 0 1 8.5-8.4h.5a8.4 8.4 0 0 1 8 8z"></path></svg>
            </span>
            <span class="uj-coach-title" x-text="c.title"></span>
            {{-- type="button": this partial is often dropped inside a form, and a
                 bare button there submits it. --}}
            <button type="button" class="uj-coach-x" @click="dismiss()"
                    :aria-label="$store.ui.lang==='en' ? 'Close tip' : 'Tutup petua'">&times;</button>
        </div>
        <p class="uj-coach-body" x-text="c.body"></p>
        <button type="button" class="uj-coach-ok" @click="dismiss()"
                x-text="$store.ui.lang==='en' ? 'Got it' : 'Faham'">Got it</button>
    </div>
</div>
