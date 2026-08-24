{{-- External TOT: training/events forwarded from outside the company (a partner's
     workshop invite, a vendor webinar). Simple broadcast, not a TOT session — no
     comments, reactions, ratings or watched-tracking, and no Knowledge Bank credit.
     Params: $externalEvents (ExternalTotEvent collection, newest event_date first),
             $canPostExternal (bool — manager, management or hr).

     The post form lives in a side-over panel (the same .wd/.wd-scrim drawer TOT's
     own internal session detail uses — see partials.tot-drawer), not inline: a
     collapsed accordion still pushed the event list down every time it opened,
     and this is a rare action next to the list everyone actually reads. --}}
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
                                <div style="grid-column:span 2;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</label><textarea class="tot-field" name="description" style="height:72px;padding-top:9px;resize:vertical;">{{ old('description') }}</textarea></div>
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
                <p class="ext-desc">{{ $event->description }}</p>
            @endif

            @if ($event->registration_url)
                <a class="tot-btn-p" style="display:inline-block;text-decoration:none;" href="{{ $event->registration_url }}" target="_blank" rel="noopener" x-text="$store.ui.lang==='en' ? 'Register ↗' : 'Daftar ↗'">Register ↗</a>
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
