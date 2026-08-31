@extends('layouts.app')

@php
    $typeLabel = [
        'townhall' => 'Town hall',
        'training' => 'Training',
        'holiday' => 'Holiday',
        'social' => 'Social',
        'meeting' => 'Meeting',
    ];
    $typeLabelMs = [
        'townhall' => 'Town hall',
        'training' => 'Latihan',
        'holiday' => 'Cuti',
        'social' => 'Sosial',
        'meeting' => 'Mesyuarat',
    ];
    $typeColor = [
        'townhall' => 'var(--info)',
        'training' => 'var(--amber)',
        'holiday' => 'var(--success)',
        'social' => 'var(--accent, var(--info))',
        'meeting' => 'var(--muted)',
    ];
    $rsvpLabel = ['going' => 'Going', 'maybe' => 'Maybe', 'declined' => 'Can’t go'];
    $rsvpLabelMs = ['going' => 'Hadir', 'maybe' => 'Mungkin', 'declined' => 'Tak dapat'];

    // @mention roster for external events.
    $mentionRoster = $assignableEmployees->map(fn ($person) => [
        'id' => $person->id,
        'name' => $person->display_name,
        'legal' => $person->name,
    ])->values();
    // A rejected post keeps the @names in the textarea, so it has to keep the ids too.
    $mentionChosen = $mentionRoster->whereIn('id', array_map('intval', (array) old('tagged', [])))->values();
    $mentionNames = $assignableEmployees->keyBy('id');

    // A poster who has since lost the posting role (a demotion) still needs the drawer in
    // the DOM to edit their own past event, even though they can no longer post a new one.
    $allEventRows = $upcomingEvents->concat($recentPastEvents)->concat($olderPastEvents);
    $canOpenDrawer = $privileged || $allEventRows->contains(fn ($row) => $row['event']->created_by_employee_id === $viewerId);
@endphp

@section('screen')
@include('partials.guide', [
    'key'   => 'events',
    'en'  => [
        'title' => 'Company events',
        'body'  => 'See upcoming company events — town halls, training, holidays and socials — and tell the organiser if you\'re coming. External training and workshops forwarded from outside the company show up here too, with a registration link instead of RSVP. You can RSVP once per event, and change it any time before the day.',
        'who'   => 'Everyone RSVPs · HR and managers publish events',
        'steps' => [
            'Read the event details — date, time and location are listed under each title.',
            'Click "Going", "Maybe" or "Can\'t go". Your choice is highlighted.',
            'Need to change your mind? Just click a different option — the latest one counts.',
            'An external event (hosted outside the company) shows a sign-up link instead — click through to the organiser\'s own form.',
            'Organisers see the running headcount of who is going.',
        ],
    ],
    'ms'  => [
        'title' => 'Acara syarikat',
        'body'  => 'Lihat acara syarikat akan datang — town hall, training, cuti dan acara sosial — dan beritahu penganjur jika anda hadir. Latihan dan bengkel luaran yang diterima dari luar syarikat turut dipaparkan di sini, dengan pautan pendaftaran menggantikan RSVP. Anda boleh RSVP sekali bagi setiap acara, dan tukar bila-bila masa sebelum harinya.',
        'who'   => 'Semua orang RSVP · HR dan pengurus terbitkan acara',
        'steps' => [
            'Baca butiran acara — tarikh, masa dan lokasi disenaraikan di bawah setiap tajuk.',
            'Klik "Going", "Maybe" atau "Can\'t go". Pilihan anda akan diserlahkan.',
            'Tukar fikiran? Cuma klik pilihan lain — yang terkini dikira.',
            'Acara luaran (dianjurkan di luar syarikat) menunjukkan pautan pendaftaran sebaliknya — klik untuk pergi ke borang penganjur sendiri.',
            'Penganjur nampak jumlah semasa siapa yang akan hadir.',
        ],
    ],
])

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;"
     x-data="{ postOpen: {{ ($errors->any() && old('_evform')) ? 'true' : 'false' }}, editEvent: null, external: {{ old('host') ? 'true' : 'false' }} }">
    {{-- Upcoming events + RSVP --}}
    <div style="flex:2;min-width:340px;display:flex;flex-direction:column;gap:16px;">
        <div class="uj-card" style="padding:0;">
            <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Upcoming events' : 'Acara akan datang'">Upcoming events</h3><span style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'RSVP once per event' : 'RSVP sekali setiap acara'">RSVP once per event</span></div>
            @forelse ($upcomingEvents as $row)
                @php $e = $row['event']; $counts = $row['counts']; $mine = $row['myRsvp']; @endphp
                <div style="padding:18px 20px;border-bottom:1px solid var(--hairline-soft);">
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;flex-wrap:wrap;">
                            <span class="uj-pill" style="background:var(--hairline-soft);color:{{ $typeColor[$e->type] ?? 'var(--muted)' }};" x-text="$store.ui.lang==='en' ? @js($typeLabel[$e->type] ?? $e->type) : @js($typeLabelMs[$e->type] ?? $typeLabel[$e->type] ?? $e->type)">{{ $typeLabel[$e->type] ?? $e->type }}</span>
                            <span style="font-size:13.5px;font-weight:600;color:var(--ink);">{{ $e->title }}</span>
                        </div>
                        <div style="display:flex;gap:10px;align-items:start;flex-shrink:0;">
                            @if ($e->created_by_employee_id === $viewerId)
                                <button type="button" class="ext-del" @click="editEvent = {{ \Illuminate\Support\Js::from([
                                        'id' => $e->id,
                                        'title' => $e->title,
                                        'type' => $e->type,
                                        'host' => $e->host,
                                        'event_date' => $e->event_date->format('Y-m-d'),
                                        'start_time' => $e->start_time,
                                        'location' => $e->location,
                                        'venue_map_url' => $e->venue_map_url,
                                        'registration_url' => $e->registration_url,
                                        'description' => $e->description,
                                        'tagged' => $e->taggedIds(),
                                    ]) }}; external = {{ \Illuminate\Support\Js::from($e->isExternal()) }}; postOpen = true"
                                        x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</button>
                            @endif
                            @if ($privileged)
                                <form method="post" action="{{ route('events.destroy', $e) }}" onsubmit="return confirm('{{ $e->title }}?')">
                                    @csrf
                                    <button type="submit" class="ext-del" x-text="$store.ui.lang==='en' ? 'Remove' : 'Buang'">Remove</button>
                                </form>
                            @endif
                        </div>
                    </div>
                    <div style="font-size:12.5px;color:var(--muted);margin-bottom:8px;">
                        @if ($e->host)
                            <span x-text="$store.ui.lang==='en' ? 'Hosted by' : 'Dianjurkan oleh'">Hosted by</span> {{ $e->host }} ·
                        @endif
                        {{ $e->event_date->format('D, j M Y') }}
                        @if ($e->start_time) · {{ $e->start_time }}@endif
                        @if ($e->location)
                            ·
                            @if ($e->venue_map_url)
                                <a href="{{ $e->venue_map_url }}" target="_blank" rel="noopener" style="color:var(--info);text-decoration:none;">{{ $e->location }} ↗</a>
                            @else
                                {{ $e->location }}
                            @endif
                        @endif
                    </div>
                    @if ($e->description)
                        @php
                            // Escaped first, then the known @names are wrapped — a description can
                            // never inject markup, only the names this event actually tagged get marked.
                            $desc = e($e->description);
                            foreach ($e->taggedIds() as $taggedId) {
                                $person = $mentionNames->get($taggedId);
                                if (! $person) {
                                    continue;
                                }
                                $handle = '@'.e($person->display_name);
                                $desc = str_replace($handle, '<span class="ext-mention"'.($taggedId === $viewerId ? ' data-me' : '').'>'.$handle.'</span>', $desc);
                            }
                        @endphp
                        <p style="font-size:13px;color:var(--muted);margin:0 0 12px;white-space:pre-line;">{!! $desc !!}</p>
                    @endif

                    @if ($e->isExternal())
                        @if (in_array($viewerId, $e->taggedIds(), true))
                            <p class="tot-note" style="color:var(--red);margin:0 0 12px;"
                               x-text="$store.ui.lang==='en' ? 'You were tagged — you are expected to register for this.' : 'Anda ditag — anda dijangka mendaftar untuk acara ini.'">You were tagged — you are expected to register for this.</p>
                        @endif

                        @if ($e->registration_url)
                            <a class="tot-btn-p ext-reg" href="{{ $e->registration_url }}" target="_blank" rel="noopener" x-text="$store.ui.lang==='en' ? 'Register ↗' : 'Daftar ↗'">Register ↗</a>
                        @endif
                    @else
                        <div style="display:flex;gap:14px;font-size:12px;color:var(--muted);margin-bottom:12px;">
                            <span><strong style="color:var(--success);font-family:var(--font-mono);">{{ $counts['going'] }}</strong> <span x-text="$store.ui.lang==='en' ? 'going' : 'hadir'">going</span></span>
                            <span><strong style="color:var(--amber);font-family:var(--font-mono);">{{ $counts['maybe'] }}</strong> <span x-text="$store.ui.lang==='en' ? 'maybe' : 'mungkin'">maybe</span></span>
                            <span><strong style="color:var(--ink);font-family:var(--font-mono);">{{ $counts['declined'] }}</strong> <span x-text="$store.ui.lang==='en' ? 'declined' : 'tolak'">declined</span></span>
                        </div>

                        @if (! $canRespond)
                            <div style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No employee profile in this workspace — RSVP is disabled.' : 'Tiada profil pekerja dalam ruang kerja ini — RSVP dilumpuhkan.'">No employee profile in this workspace — RSVP is disabled.</div>
                        @else
                            <form method="post" action="{{ route('events.rsvp', $e) }}" style="display:flex;gap:8px;flex-wrap:wrap;">
                                @csrf
                                @foreach (['going', 'maybe', 'declined'] as $opt)
                                    @php $isCurrent = $mine === $opt; @endphp
                                    <button type="submit" name="response" value="{{ $opt }}"
                                        style="height:34px;padding:0 14px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;border:1px solid {{ $isCurrent ? 'var(--ink)' : 'var(--hairline)' }};background:{{ $isCurrent ? 'var(--ink)' : '#fff' }};color:{{ $isCurrent ? '#fff' : 'var(--ink)' }};"
                                        x-text="$store.ui.lang==='en' ? @js($rsvpLabel[$opt]) : @js($rsvpLabelMs[$opt])">
                                        {{ $rsvpLabel[$opt] }}
                                    </button>
                                @endforeach
                            </form>
                        @endif
                    @endif
                </div>
            @empty
                <div style="padding:28px 20px;text-align:center;">
                    <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'No upcoming events' : 'Tiada acara akan datang'">No upcoming events</div>
                    <div style="font-size:12px;color:var(--muted);line-height:1.5;">@if ($privileged)<span x-text="$store.ui.lang==='en' ? 'Use &quot;+ New event&quot; on the right to publish your first one — an internal event staff can RSVP to, or an external training they can register for.' : 'Guna &quot;+ New event&quot; di sebelah kanan untuk terbitkan yang pertama — acara dalaman untuk staf RSVP, atau latihan luaran untuk mereka daftar.'"></span>@else<span x-text="$store.ui.lang==='en' ? 'Nothing is scheduled right now. New events will appear here for you to RSVP.' : 'Tiada apa dijadualkan sekarang. Acara baru akan muncul di sini untuk anda RSVP.'"></span>@endif</div>
                </div>
            @endforelse
        </div>

        {{-- Past events: recent inline for everyone, older ones collapsed behind a toggle. --}}
        @if ($recentPastEvents->isNotEmpty() || $olderPastEvents->isNotEmpty())
            <div class="uj-card" style="padding:0;" x-data="{ showOlder: false }">
                <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Past events' : 'Acara lepas'">Past events</h3><span style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Recent history' : 'Sejarah terkini'">Recent history</span></div>
                @foreach ($recentPastEvents as $row)
                    @include('partials.event-past-row', ['row' => $row, 'typeLabel' => $typeLabel, 'typeLabelMs' => $typeLabelMs])
                @endforeach

                @if ($olderPastEvents->isNotEmpty())
                    <button type="button" @click="showOlder = ! showOlder"
                            style="width:100%;padding:12px 20px;border:0;background:none;text-align:left;font-size:12.5px;font-weight:600;color:var(--muted);cursor:pointer;border-bottom:1px solid var(--hairline-soft);">
                        <span x-show="! showOlder" x-text="$store.ui.lang==='en' ? @js('Older events ('.$olderPastEvents->count().')') : @js('Acara lama ('.$olderPastEvents->count().')')"></span>
                        <span x-show="showOlder" x-cloak x-text="$store.ui.lang==='en' ? 'Hide older events' : 'Sembunyikan acara lama'">Hide older events</span>
                    </button>

                    <div x-show="showOlder" x-cloak>
                        @foreach ($olderPastEvents as $row)
                            @include('partials.event-past-row', ['row' => $row, 'typeLabel' => $typeLabel, 'typeLabelMs' => $typeLabelMs])
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>

    {{-- Privileged: publish an event, internal or external --}}
    <div style="flex:1;min-width:300px;display:flex;flex-direction:column;gap:16px;">
        @if ($canOpenDrawer)
            @if ($privileged)
                <div class="uj-card" style="padding:20px;">
                    <button type="button" class="uj-btn-primary" style="width:100%;height:42px;font-size:13.5px;" @click="postOpen = true; editEvent = null; external = false">
                        <span x-text="$store.ui.lang==='en' ? '+ New event' : '+ Acara baharu'">+ New event</span>
                    </button>
                </div>
            @endif

            <template x-teleport="body">
                <div x-show="postOpen" x-cloak>
                    <div class="wd-scrim" :data-open="postOpen ? '' : null" @click="postOpen = false"></div>
                    <aside class="wd" :data-open="postOpen ? '' : null" role="dialog" aria-modal="true"
                           @keydown.escape.window="postOpen = false"
                           :aria-label="$store.ui.lang==='en' ? 'Publish an event' : 'Terbitkan acara'"
                           x-data="extPasteFill()" x-init="$watch('editEvent', (event) => sync(event))">

                        <div class="wd-head">
                            <span style="font:600 13.5px var(--font-sans);color:var(--ink);"
                                  x-text="editEvent ? ($store.ui.lang==='en' ? 'Edit event' : 'Sunting acara') : ($store.ui.lang==='en' ? 'New event' : 'Acara baharu')">New event</span>
                            <button type="button" class="wd-ico" style="margin-left:auto;" @click="postOpen = false"
                                    :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <div class="wd-body">
                            @if ($errors->any() && old('_evform'))
                                <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>
                            @endif

                            <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--ink);margin-bottom:16px;cursor:pointer;">
                                <input type="checkbox" x-model="external">
                                <span x-text="$store.ui.lang==='en' ? 'External — hosted outside the company' : 'Luaran — dianjurkan di luar syarikat'">External — hosted outside the company</span>
                            </label>

                            {{-- Regex-only helper, no AI call: reads a pasted invite's Date:/Time:/
                                 Venue: lines and its forms.gle/maps.app.goo.gl links, prefills the
                                 fields below. Never submits by itself — the poster still reviews
                                 and can fix or clear anything before Publish. --}}
                            <div x-show="external" x-cloak style="margin-bottom:16px;">
                                <label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Paste invite text (optional)' : 'Tampal teks jemputan (pilihan)'">Paste invite text (optional)</label>
                                <textarea class="tot-field" style="height:64px;padding-top:9px;resize:vertical;" x-model="pasteText"
                                          :placeholder="$store.ui.lang==='en' ? 'Paste the forwarded invite here — we\'ll try to fill the fields below from it.' : 'Tampal jemputan yang diteruskan di sini — kami akan cuba isi medan di bawah daripadanya.'"></textarea>
                                <button type="button" class="tot-btn-g" style="margin-top:8px;" @click="fill()" x-text="$store.ui.lang==='en' ? 'Fill fields' : 'Isi medan'">Fill fields</button>
                            </div>

                            <hr class="wd-rule" style="margin:0 0 16px;">

                            <form method="post"
                                  :action="editEvent ? '{{ url('/app/events') }}/' + editEvent.id : '{{ route('events.store') }}'"
                                  x-ref="extForm">
                                @csrf
                                <input type="hidden" name="_evform" value="1">
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                    <div style="grid-column:span 2;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Title' : 'Tajuk'">Title</label><input class="tot-field" name="title" value="{{ old('title') }}" maxlength="160" required></div>

                                    <div>
                                        <label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</label>
                                        <select class="tot-field" name="type" required>
                                            @foreach ($eventTypes as $t)
                                                <option value="{{ $t }}" @selected(old('type') === $t) x-text="$store.ui.lang==='en' ? @js($typeLabel[$t] ?? $t) : @js($typeLabelMs[$t] ?? $typeLabel[$t] ?? $t)">{{ $typeLabel[$t] ?? $t }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</label>
                                        <input class="tot-field" type="date" name="event_date" value="{{ old('event_date') }}" required>
                                    </div>

                                    <div x-show="external" x-cloak style="grid-column:span 2;">
                                        <label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Host / organiser' : 'Penganjur'">Host / organiser</label>
                                        <input class="tot-field" name="host" value="{{ old('host') }}" maxlength="120" :required="external">
                                    </div>

                                    <div><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Time' : 'Masa'">Time</label><input class="tot-field" name="start_time" value="{{ old('start_time') }}" maxlength="40" placeholder="10:00 AM – 12:00 PM"></div>
                                    <div><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Location' : 'Lokasi'">Location</label><input class="tot-field" name="location" value="{{ old('location') }}" maxlength="160"></div>

                                    <div x-show="external" x-cloak style="grid-column:span 2;">
                                        <label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Map link' : 'Pautan peta'">Map link</label>
                                        <input class="tot-field" type="url" name="venue_map_url" value="{{ old('venue_map_url') }}">
                                    </div>
                                    <div x-show="external" x-cloak style="grid-column:span 2;">
                                        <label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Registration link' : 'Pautan pendaftaran'">Registration link</label>
                                        <input class="tot-field" type="url" name="registration_url" value="{{ old('registration_url') }}">
                                    </div>

                                    {{-- @mention a colleague in the description and — on an external event —
                                         they get a bell (and an email copy) telling them they are expected
                                         to register. Nothing tracks whether they did — registration happens
                                         on the organiser's own form, outside this app — so this is a
                                         summons, not a checklist. --}}
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
                                    }" @click.outside="open = false"
                                       x-init="$watch('editEvent', (event) => {
                                           chosen = event ? roster.filter((p) => (event.tagged || []).includes(p.id)) : [];
                                       })">
                                        <label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Description — type @ to tag someone' : 'Penerangan — taip @ untuk tag seseorang'">Description — type @ to tag someone</label>
                                        <textarea class="tot-field" name="description" x-ref="desc" style="height:72px;padding-top:9px;resize:vertical;" maxlength="2000"
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
                                <button type="submit" class="tot-btn-p" style="margin-top:14px;"
                                        x-text="editEvent ? ($store.ui.lang==='en' ? 'Save changes' : 'Simpan perubahan') : ($store.ui.lang==='en' ? 'Publish event' : 'Terbitkan acara')">Publish event</button>
                            </form>
                        </div>
                    </aside>
                </div>
            </template>
        @endif
    </div>
</div>
@endsection
