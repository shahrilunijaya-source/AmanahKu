{{--
    Coachmark — a one-time chat bubble that points at one control and explains it.

    Different from partials/guide.blade.php on purpose. The guide is the whole
    screen's manual, always available behind "What is this screen for?", and it
    only opens when asked. A coachmark opens itself, says one thing about one
    control, and never comes back once closed.

    Place it directly before the element it talks about; the bubble's tail points
    down at whatever sits below it. Nothing is positioned absolutely, so it cannot
    drift away from its anchor when the layout moves.

    Dismissal is per key in localStorage (`amanahku-coach-<key>`), matching the
    profile banner and the welcome modal. Bump the key when the copy changes
    materially, otherwise everyone who already closed it never sees the new text.

    Usage:
        @include('partials.coachmark', [
            'key' => 'attendance-work-mode',
            'en'  => ['title' => '...', 'body' => '...'],
            'ms'  => ['title' => '...', 'body' => '...'],
        ])

    Required: $key, $en. $ms optional, falls back to English.
--}}
@php
    $ms = $ms ?? $en;
@endphp
<div x-data="{
        show: localStorage.getItem('amanahku-coach-{{ $key }}') !== '1',
        copy: {{ \Illuminate\Support\Js::from(['en' => $en, 'ms' => $ms]) }},
        get c() { return this.copy[$store.ui.lang] ?? this.copy.en; },
        dismiss() {
            this.show = false;
            localStorage.setItem('amanahku-coach-{{ $key }}', '1');
        }
     }"
     x-show="show"
     x-cloak
     x-transition:enter="uj-coach-in"
     x-transition:enter-start="uj-coach-in-start"
     x-transition:enter-end="uj-coach-in-end"
     class="uj-coach"
     role="note">
    <div class="uj-coach-bubble">
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
