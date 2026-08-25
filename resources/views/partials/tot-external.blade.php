{{-- External TOT: training/events forwarded from outside the company (a partner's
     workshop invite, a vendor webinar). Simple broadcast, not a TOT session — no
     comments, reactions, ratings or watched-tracking, and no Knowledge Bank credit.
     Params: $externalEvents (ExternalTotEvent collection, newest event_date first),
             $canPostExternal (bool — manager, management or hr),
             $assignableEmployees (active employees, the @mention roster).

     The post form lives in a side-over panel (the same .wd/.wd-scrim drawer TOT's
     own internal session detail uses — see partials.tot-drawer), not inline: a
     collapsed accordion still pushed the event list down every time it opened,
     and this is a rare action next to the list everyone actually reads. --}}
@php
    $mentionRoster = $assignableEmployees->map(fn ($person) => [
        'id' => $person->id,
        'name' => $person->display_name,
        'legal' => $person->name,
    ])->values();
    // A rejected post keeps the @names in the textarea, so it has to keep the ids too.
    $mentionChosen = $mentionRoster->whereIn('id', array_map('intval', (array) old('tagged', [])))->values();
    $mentionNames = $assignableEmployees->keyBy('id');
    $viewerId = request()->attributes->get('employee')?->id;
@endphp
<div class="uj-card" style="padding:20px;"
     x-data="{ postOpen: {{ ($errors->any() && old('_extform')) ? 'true' : 'false' }} }">
    @if ($canPostExternal)
        <div class="tot-rule" style="border-top:0;padding-top:0;margin-top:0;margin-bottom:18px;">
            <button type="button" class="tot-pillbtn" @click="postOpen = true">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                <span x-text="$store.ui.lang==='en' ? 'Post an external training / event' : 'Siar latihan / acara luaran'">Post an external training / event</span>
            </button>
        </div>

        <template x-teleport="body">
            <div x-show="postOpen" x-cloak>
                <div class="wd-scrim" :data-open="postOpen ? '' : null" @click="postOpen = false"></div>
                <aside class="wd" :data-open="postOpen ? '' : null" role="dialog" aria-modal="true"
                       @keydown.escape.window="postOpen = false"
                       :aria-label="$store.ui.lang==='en' ? 'Post an external training or event' : 'Siar latihan atau acara luaran'"
                       x-data="extPasteFill()">

                    <div class="wd-head">
                        <span style="font:600 13.5px var(--font-sans);color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Post an external event' : 'Siar acara luaran'">Post an external event</span>
                        <button type="button" class="wd-ico" style="margin-left:auto;" @click="postOpen = false"
                                :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="wd-body">
                        @if ($errors->any() && old('_extform'))
                            <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>
                        @endif

                        {{-- Regex-only helper, no AI call: reads a pasted invite's Date:/Time:/
                             Venue: lines and its forms.gle/maps.app.goo.gl links, prefills the
                             fields below. Never submits by itself — the poster still reviews
                             and can fix or clear anything before Post event. --}}
                        <div style="margin-bottom:16px;">
                            <label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Paste invite text (optional)' : 'Tampal teks jemputan (pilihan)'">Paste invite text (optional)</label>
                            <textarea class="tot-field" style="height:64px;padding-top:9px;resize:vertical;" x-model="pasteText"
                                      :placeholder="$store.ui.lang==='en' ? 'Paste the forwarded invite here — we\'ll try to fill the fields below from it.' : 'Tampal jemputan yang diteruskan di sini — kami akan cuba isi medan di bawah daripadanya.'"></textarea>
                            <button type="button" class="tot-btn-g" style="margin-top:8px;" @click="fill()" x-text="$store.ui.lang==='en' ? 'Fill fields' : 'Isi medan'">Fill fields</button>
                        </div>

                        <hr class="wd-rule" style="margin:0 0 16px;">

                        <form method="post" action="{{ route('tot.external.store') }}" x-ref="extForm">
                            @csrf
                            <input type="hidden" name="_extform" value="1">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                <div><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Title' : 'Tajuk'">Title</label><input class="tot-field" name="title" value="{{ old('title') }}" required></div>
                                <div><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Host / organiser' : 'Penganjur'">Host / organiser</label><input class="tot-field" name="host" value="{{ old('host') }}"></div>
                                <div><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</label><input class="tot-field" type="date" name="event_date" value="{{ old('event_date') }}" required></div>
                                <div><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Time' : 'Masa'">Time</label><input class="tot-field" name="time_label" value="{{ old('time_label') }}" placeholder="10:00 AM – 12:00 PM"></div>
                                <div style="grid-column:span 2;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Venue' : 'Tempat'">Venue</label><input class="tot-field" name="venue" value="{{ old('venue') }}"></div>
                                <div style="grid-column:span 2;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Map link' : 'Pautan peta'">Map link</label><input class="tot-field" type="url" name="venue_map_url" value="{{ old('venue_map_url') }}"></div>
                                <div style="grid-column:span 2;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Registration link' : 'Pautan pendaftaran'">Registration link</label><input class="tot-field" type="url" name="registration_url" value="{{ old('registration_url') }}"></div>
                                {{-- @mention a colleague in the description and they get a bell (and an
                                     email copy) telling them they are expected to register. Nothing tracks
                                     whether they did — registration happens on the organiser's own form,
                                     outside this app — so this is a summons, not a checklist. --}}
                                <div style="grid-column:span 2;position:relative;" x-data="{
                                    roster: {{ \Illuminate\Support\Js::from($mentionRoster) }},
                                    chosen: {{ \Illuminate\Support\Js::from($mentionChosen) }},
                                    open: false,
                                    query: '',
                                    get matches() {
                                        const q = this.query.trim().toLowerCase();
                                        const list = q
                                            ? this.roster.filter((p) => p.name.toLowerCase().includes(q) || (p.legal || '').toLowerCase().includes(q))
                                            : this.roster;
                                        return list.slice(0, 8);
                                    },
                                    /* A fragment being typed is '@' plus whatever follows it with no space —
                                       enough to search on, and it ends the moment the poster types one. */
                                    scan(el) {
                                        const upto = el.value.slice(0, el.selectionStart);
                                        const match = upto.match(/@([^\s@]*)$/);
                                        this.open = match !== null;
                                        this.query = match ? match[1] : '';
                                    },
                                    pick(person) {
                                        const el = this.$refs.desc;
                                        const caret = el.selectionStart;
                                        const upto = el.value.slice(0, caret).replace(/@[^\s@]*$/, '@' + person.name + ' ');
                                        el.value = upto + el.value.slice(caret);
                                        el.setSelectionRange(upto.length, upto.length);
                                        if (! this.chosen.some((p) => p.id === person.id)) {
                                            this.chosen.push(person);
                                        }
                                        this.open = false;
                                        this.query = '';
                                        el.focus();
                                    },
                                }" @click.outside="open = false">
                                    <label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Description — type @ to tag someone' : 'Penerangan — taip @ untuk tag seseorang'">Description — type @ to tag someone</label>
                                    <textarea class="tot-field" name="description" x-ref="desc" style="height:72px;padding-top:9px;resize:vertical;"
                                              @input="scan($event.target)" @keydown.escape.stop="open = false">{{ old('description') }}</textarea>

                                    <template x-for="person in chosen" :key="person.id">
                                        <input type="hidden" name="tagged[]" :value="person.id">
                                    </template>

                                    <div x-show="open" x-cloak class="wd-menu ext-mention-menu">
                                        <template x-for="person in matches" :key="person.id">
                                            <button type="button" @click="pick(person)"><span x-text="person.name"></span></button>
                                        </template>
                                        <div x-show="! matches.length" class="tot-note" style="padding:6px;"
                                             x-text="$store.ui.lang==='en' ? 'Nobody by that name.' : 'Tiada nama begitu.'">Nobody by that name.</div>
                                    </div>

                                    <p class="tot-note" style="margin-top:6px;"
                                       x-show="chosen.length"
                                       x-text="($store.ui.lang==='en' ? 'Notified as required to attend: ' : 'Dimaklumkan wajib hadir: ') + chosen.map((p) => p.name).join(', ')"></p>
                                </div>
                            </div>
                            <button type="submit" class="tot-btn-p" style="margin-top:14px;" x-text="$store.ui.lang==='en' ? 'Post event' : 'Siarkan acara'">Post event</button>
                        </form>
                    </div>
                </aside>
            </div>
        </template>
    @endif

    @forelse ($externalEvents as $event)
        <div class="ext-item">
            <div class="ext-head">
                <div>
                    <p class="ext-title">{{ $event->title }}</p>
                    @if ($event->host)
                        <p class="ext-host"><span x-text="$store.ui.lang==='en' ? 'Hosted by' : 'Dianjurkan oleh'">Hosted by</span> {{ $event->host }}</p>
                    @endif
                </div>
                @if ($canPostExternal)
                    <form method="post" action="{{ route('tot.external.destroy', $event) }}" onsubmit="return confirm('{{ $event->title }}?')">
                        @csrf
                        <button type="submit" class="ext-del" x-text="$store.ui.lang==='en' ? 'Remove' : 'Buang'">Remove</button>
                    </form>
                @endif
            </div>

            <div class="ext-meta">
                <span class="ext-meta-i">{{ $event->event_date->format('D, j M Y') }}</span>
                @if ($event->time_label)
                    <span class="ext-meta-i">{{ $event->time_label }}</span>
                @endif
                @if ($event->venue)
                    <span class="ext-meta-i">
                        @if ($event->venue_map_url)
                            <a href="{{ $event->venue_map_url }}" target="_blank" rel="noopener">{{ $event->venue }} ↗</a>
                        @else
                            {{ $event->venue }}
                        @endif
                    </span>
                @endif
            </div>

            @if ($event->description)
                @php
                    // Escaped first, then the known @names are wrapped — a description can
                    // never inject markup, only the names this event actually tagged get marked.
                    $desc = e($event->description);
                    foreach ($event->taggedIds() as $taggedId) {
                        $person = $mentionNames->get($taggedId);
                        if (! $person) {
                            continue;
                        }
                        $handle = '@'.e($person->display_name);
                        $desc = str_replace($handle, '<span class="ext-mention"'.($taggedId === $viewerId ? ' data-me' : '').'>'.$handle.'</span>', $desc);
                    }
                @endphp
                <p class="ext-desc">{!! $desc !!}</p>
            @endif

            @if (in_array($viewerId, $event->taggedIds(), true))
                <p class="tot-note" style="color:var(--red);margin:0 0 12px;"
                   x-text="$store.ui.lang==='en' ? 'You were tagged — you are expected to register for this.' : 'Anda ditag — anda dijangka mendaftar untuk acara ini.'">You were tagged — you are expected to register for this.</p>
            @endif

            @if ($event->registration_url)
                <a class="tot-btn-p ext-reg" href="{{ $event->registration_url }}" target="_blank" rel="noopener" x-text="$store.ui.lang==='en' ? 'Register ↗' : 'Daftar ↗'">Register ↗</a>
            @endif
        </div>
    @empty
        @include('partials.list-empty', [
            'en' => ['title' => 'No external events posted', 'body' => 'Training or events forwarded from outside the company show up here.'],
            'ms' => ['title' => 'Tiada acara luaran disiarkan', 'body' => 'Latihan atau acara yang diterima dari luar syarikat akan dipaparkan di sini.'],
            'pad' => '24px 0',
        ])
    @endforelse
</div>
