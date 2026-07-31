@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'messages',
    'en'  => [
        'title' => 'Messages',
        'body'  => 'Private one-to-one messages with anyone in the company. Search the list on the left to open a conversation, or to find a colleague you have not messaged yet. You can also message someone straight from their profile using the Message button.',
    ],
    'ms'  => [
        'title' => 'Mesej',
        'body'  => 'Mesej peribadi satu-dengan-satu dengan sesiapa dalam syarikat. Cari dalam senarai di sebelah kiri untuk buka perbualan, atau untuk jumpa rakan sekerja yang anda belum mesej. Anda juga boleh mesej seseorang terus dari profil mereka guna butang Mesej.',
    ],
])

@php
    $a = $msgActive ?? null;
    // ?draft=… seeds a blank composer only (the dashboard's birthday "Wish" button).
    $draft = (empty($a['conversationId']) && empty($a['messages']) && request()->filled('draft'))
        ? \Illuminate\Support\Str::limit(request('draft'), 5000, '')
        : '';
@endphp

{{-- One component owns the whole screen so opening a conversation, sending, and going
     back all update ONLY the thread column. Nothing here navigates: the URL is kept true
     with pushState, so refresh, deep links, sharing and the browser back button all still
     land on the right thread. Rows stay real <a href> elements, so middle-click and
     "open in new tab" keep working and the screen degrades to plain links without JS. --}}
<div class="uj-msg" @if ($a) data-thread @endif
     x-data="{
        q: '',
        convos: @js($msgConversations),
        people: @js($msgCanSend ?? false ? $msgRecipients : []),
        base: @js(route('app.screen', 'messages')),
        pane: @js(route('messages.pane')),
        activeId: @js($a['conversationId'] ?? null),
        loading: false,
        sending: false,
        error: '',
        rootEl: null,
        threadEl: null,

        hit(text) { return this.q === '' || (text ?? '').toLowerCase().includes(this.q.toLowerCase()); },
        group(bucket) { return this.convos.filter(c => c.bucket === bucket && (this.hit(c.other.name) || this.hit(c.snippet))); },
        fresh() {
            if (this.q === '') { return []; }
            const known = new Set(this.convos.map(c => c.other.id));
            return this.people.filter(p => ! known.has(p.id) && this.hit(p.name)).slice(0, 8);
        },
        get blank() { return this.group('today').length + this.group('week').length + this.group('earlier').length + this.fresh().length === 0; },

        /* ── Swapping one column ──────────────────────────────────────────── */

        /** Replace the thread column with a freshly rendered one. `query` is `c=…`, `to=…`,
            or empty for the no-thread-selected state. One path serves opening, sending,
            going back, and popstate, so all four render through the same partial.

            It works off `rootEl` / `threadEl`, captured once in init(), rather than `$root`
            and `$refs`. Those magics resolve against whichever element the calling
            expression is bound to, and the Back button lives inside the fragment this
            method replaces — from there they came back undefined and every Back fell into
            the catch below, doing the full navigation this screen exists to avoid. */
        async load(query, push = true) {
            this.loading = true;
            this.error = '';
            try {
                const res = await fetch(this.pane + (query ? '?' + query : ''), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (! res.ok) { throw new Error('pane ' + res.status); }
                this.threadEl.innerHTML = await res.text();
                const id = new URLSearchParams(query).get('c');
                this.activeId = id ? Number(id) : null;
                this.rootEl.toggleAttribute('data-thread', query !== '');
                if (push) { history.pushState({ q: query }, '', this.base + (query ? '?' + query : '')); }
            } catch (e) {
                // A failed swap must not strand the user on a stale pane: fall back to
                // the navigation the link would have done anyway.
                window.location = this.base + (query ? '?' + query : '');
            } finally {
                this.loading = false;
            }
        },

        /** Back to the index — a state change on this screen, not a navigation. */
        back(push = true) { return this.load('', push); },

        /** Post the composer and swap the same column back in with the new message. */
        async send(form) {
            if (this.sending) { return; }
            this.sending = true;
            this.error = '';
            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                if (! res.ok) {
                    this.error = Object.values(data.errors ?? {}).flat()[0]
                        ?? (this.$store.ui.lang === 'en' ? 'Could not send that message.' : 'Tidak dapat menghantar mesej itu.');
                    return;
                }
                this.touch(data.conversationId, data.message, form);
                await this.load('c=' + data.conversationId, data.conversationId !== this.activeId);
            } catch (e) {
                this.error = this.$store.ui.lang === 'en' ? 'Could not send that message.' : 'Tidak dapat menghantar mesej itu.';
            } finally {
                this.sending = false;
            }
        },

        /** Move a thread to the top of Today and update its snippet, without refetching
            the index — the row is already reactive, so this is cheaper than a round trip.
            The first message to a colleague has no row yet, so build one from the person
            already in `people`; only a recipient we somehow do not know falls back to a
            real navigation. */
        touch(id, message, form) {
            let c = this.convos.find(c => c.id === id);
            if (! c) {
                const to = Number(form.querySelector('input[name=to]')?.value);
                const person = this.people.find(p => p.id === to);
                if (! person) { window.location = this.base + '?c=' + id; return; }
                c = { id: id, other: person, unread: 0 };
                this.convos.push(c);
            }
            c.snippet = message.body !== '' ? message.body.slice(0, 120) : '📎 Attachment';
            c.lastMine = true;
            c.bucket = 'today';
            c.atShort = message.time;
            c.atShortMs = message.time;
            c.unread = 0;
            this.convos = [c, ...this.convos.filter(x => x.id !== id)];
        },

        init() {
            // Captured here, where $el IS the screen root, and used everywhere after.
            this.rootEl = this.$el;
            this.threadEl = this.$el.querySelector('.uj-msg-thread');
            // The browser's own back/forward must move between threads, not out of the screen.
            window.addEventListener('popstate', () => {
                this.load(window.location.search.replace(/^\?/, ''), false);
            });
        },
     }"
     @msg-read="convos.forEach(c => { if (c.id === $event.detail.id) { c.unread = 0; } })">

    {{-- ── Index ──────────────────────────────────────────────────────────────
         One search field, always present. It filters the conversations you have
         AND offers colleagues you have not messaged yet, so starting a new thread
         is not a separate mode that hides the list. --}}
    <div class="uj-msg-index">
        <label class="uj-msg-search">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.6-3.6"></path></svg>
            <input x-model="q" :placeholder="$store.ui.lang==='en' ? 'Search messages or people…' : 'Cari mesej atau orang…'" />
        </label>

        <div class="uj-msg-list">
            <template x-for="g in [
                    { key: 'today',   en: 'Today',     ms: 'Hari ini' },
                    { key: 'week',    en: 'This week', ms: 'Minggu ini' },
                    { key: 'earlier', en: 'Earlier',   ms: 'Lebih awal' },
                ]" :key="g.key">
                <div x-show="group(g.key).length">
                    <div class="uj-msg-grp" x-text="$store.ui.lang==='en' ? g.en : g.ms"></div>
                    <template x-for="c in group(g.key)" :key="c.id">
                        <a class="uj-msg-row" :href="base + '?c=' + c.id"
                           @click.prevent="load('c=' + c.id)"
                           :data-on="c.id === activeId ? '' : null"
                           :data-unread="c.unread > 0 ? '' : null">
                            <span class="uj-msg-av" :style="'background:' + c.other.color" x-text="c.other.initials"></span>
                            <span class="uj-msg-rmid">
                                <span class="uj-msg-rtop">
                                    <span class="uj-msg-name" x-text="c.other.name"></span>
                                    <span class="uj-msg-at" x-text="$store.ui.lang==='en' ? c.atShort : c.atShortMs"></span>
                                </span>
                                <span class="uj-msg-pos" x-text="c.other.position"></span>
                                <span class="uj-msg-snip">
                                    <span x-show="c.lastMine" x-text="$store.ui.lang==='en' ? 'You: ' : 'Anda: '"></span><span
                                        x-text="c.snippet || ($store.ui.lang==='en' ? 'No messages yet' : 'Belum ada mesej')"></span>
                                </span>
                                <span class="uj-msg-badge" x-show="c.unread > 0">
                                    <span x-text="c.unread"></span>
                                    <span x-text="$store.ui.lang==='en' ? 'unread' : 'belum dibaca'"></span>
                                </span>
                            </span>
                        </a>
                    </template>
                </div>
            </template>

            {{-- Colleagues you have not messaged yet — only once you have typed. --}}
            <div x-show="fresh().length" x-cloak>
                <div class="uj-msg-grp" x-text="$store.ui.lang==='en' ? 'Start a new conversation' : 'Mula perbualan baharu'"></div>
                <template x-for="p in fresh()" :key="p.id">
                    <a class="uj-msg-row" :href="base + '?to=' + p.id" @click.prevent="load('to=' + p.id)">
                        <span class="uj-msg-av" :style="'background:' + p.color" x-text="p.initials"></span>
                        <span class="uj-msg-rmid">
                            <span class="uj-msg-rtop"><span class="uj-msg-name" x-text="p.name"></span></span>
                            <span class="uj-msg-pos" x-text="p.position"></span>
                        </span>
                    </a>
                </template>
            </div>

            <div class="uj-msg-none" x-show="blank" x-cloak
                 x-text="q === ''
                    ? ($store.ui.lang==='en' ? 'No conversations yet. Search for a colleague above to start one.' : 'Belum ada perbualan. Cari rakan sekerja di atas untuk mula.')
                    : ($store.ui.lang==='en' ? 'Nothing matches that search.' : 'Tiada yang sepadan dengan carian itu.')"></div>
        </div>
    </div>

    {{-- ── Thread ───────────────────────────────────────────────────────────
         The only region that changes. Its markup lives in one partial that both the
         first render and MessageController::pane() use, so the two can never drift. --}}
    <div class="uj-msg-thread" :data-loading="loading ? '' : null">
        @include('partials.messages-thread', ['a' => $a, 'draft' => $draft])
    </div>
</div>
@endsection
