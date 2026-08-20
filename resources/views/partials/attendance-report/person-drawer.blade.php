{{-- One person's period, opened by ?emp= from any ledger row.

     Reversing a punch after seeing the selfie and the typed reason is a different
     decision from reversing it blind off a table row, so the reverse and amend
     controls live here and nowhere else. The only write on a table row is Fix,
     which deep-links straight into this drawer with that day already expanded.

     Server-rendered rather than fetched: the row it came from was server-rendered
     too, so there is one shape of truth and no second render path to keep in step. --}}
@if (! empty($person))
    @php
        /* Fully qualified rather than a `use` line: this @php block sits inside an
           @if, and Blade hoists neither — a `use` there is a parse error. */
        $msDays = ['Ahd', 'Isn', 'Sel', 'Rab', 'Kha', 'Jum', 'Sab'];
        $msMonths = [1 => 'Jan', 'Feb', 'Mac', 'Apr', 'Mei', 'Jun', 'Jul', 'Ogos', 'Sep', 'Okt', 'Nov', 'Dis'];

        $statusLabel = [
            'ontime' => ['On time', 'Tepat masa'],
            'late' => ['Late', 'Lewat'],
            'miss' => ['Missing out', 'Tiada clock out'],
            'absent' => ['No punch', 'Tiada clock in'],
            'leave' => ['On leave', 'Bercuti'],
            'half' => ['Half day', 'Separuh hari'],
            'pending' => ['Pending', 'Menunggu'],
        ];

        $days = $person['days'];
        $clocked = $days->whereNotNull('in')->count();
        $lateDays = $days->where('status', 'late')->count();
        $gaps = $days->whereIn('status', ['miss', 'absent'])->count();
        $drawerHours = $days->sum(fn (array $d) => $d['hours'] ?? 0);

        $closeQuery = array_filter(request()->query(), fn ($v) => $v !== null && $v !== '');
        unset($closeQuery['emp'], $closeQuery['day']);
        $closeUrl = route('app.screen', ['screen' => 'attendance-report'] + $closeQuery);
    @endphp

    <div x-data="{ shot: null }">
        <a class="uj-ar-scrim" href="{{ $closeUrl }}" aria-hidden="true" tabindex="-1"></a>

        <aside class="uj-ar-drawer" role="dialog" aria-modal="true" aria-labelledby="uj-ar-dr-name"
               @keydown.escape.window="shot ? shot = null : $refs.close?.click()">
            <header class="uj-ar-dr-head">
                <span class="uj-ar-av" style="background:{{ $person['color'] }}">{{ $person['initials'] }}</span>
                <span style="min-width:0;flex:1">
                    <span class="uj-ar-dr-name" id="uj-ar-dr-name">{{ $person['name'] }}</span>
                    <span class="uj-ar-dr-role">{{ $person['dept'] ?? '—' }}</span>
                </span>
                <a class="uj-ar-dr-close" href="{{ $closeUrl }}" x-ref="close"
                   :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </a>
            </header>

            <div class="uj-ar-dr-sum">
                <span class="cap" x-text="$store.ui.lang==='en' ? @js($label['en']) : @js($label['ms'])">{{ $label['en'] }}</span>
                <span class="s"><b>{{ $clocked }}</b><span x-text="$store.ui.lang==='en' ? 'days clocked' : 'hari clock in'">days clocked</span></span>
                <span class="s"><b>{{ number_format($drawerHours, 1) }}</b><span x-text="$store.ui.lang==='en' ? 'hours' : 'jam'">hours</span></span>
                <span class="s" @if($lateDays) data-t="warn" @endif><b>{{ $lateDays }}</b><span x-text="$store.ui.lang==='en' ? 'late' : 'lewat'">late</span></span>
                <span class="s" @if($gaps) data-t="bad" @endif><b>{{ $gaps }}</b><span x-text="$store.ui.lang==='en' ? 'need a fix' : 'perlu betulkan'">need a fix</span></span>
            </div>

            <div class="uj-ar-dr-days">
                @forelse ($days as $day)
                    @php
                        $at = \Illuminate\Support\Carbon::parse($day['date']);
                        $dEn = $at->format('D').', '.$at->format('j M');
                        $dMs = $msDays[$day['dow']].', '.$at->day.' '.$msMonths[(int) $at->month];
                        $times = $day['in'] === null
                            ? '—'
                            : $day['in'].' → '.($day['out'] ?? '——:——');
                        $st = $statusLabel[$day['status']] ?? [$day['status'], $day['status']];
                        $shots = array_filter([
                            $day['in'] !== null && $day['photoIn'] ? ['url' => $day['photoIn'], 'time' => $day['in'], 'en' => 'Clock in', 'ms' => 'Clock in'] : null,
                            $day['out'] !== null && $day['photoOut'] ? ['url' => $day['photoOut'], 'time' => $day['out'], 'en' => 'Clock out', 'ms' => 'Clock out'] : null,
                        ]);
                        $notes = array_filter([
                            'in' => $day['noteIn'],
                            'out' => $day['noteOut'],
                        ]);
                        $canAct = $canReversePunch && $day['recordId'] !== null;
                    @endphp
                    {{-- The app's standard disclosure: the panel animates
                         grid-template-rows 0fr → 1fr, so nothing has to be measured and
                         nothing jumps. Alpine holds only `open`. --}}
                    <div class="uj-ar-day" x-data="{ open: {{ $person['openDay'] === $day['date'] ? 'true' : 'false' }}, amend: false }"
                         :data-open="open || null" @if($person['openDay'] === $day['date']) data-open @endif>
                        <button type="button" class="uj-ar-day-btn" @click="open = ! open" :aria-expanded="open">
                            <span class="d">
                                <b x-text="$store.ui.lang==='en' ? @js($dEn) : @js($dMs)">{{ $dEn }}</b>
                                <i>{{ $times }}</i>
                            </span>
                            <span class="hh" @if($day['hours'] === null) data-nil @endif>{{ $day['hours'] !== null ? number_format($day['hours'], 2) : '—' }}</span>
                            <span class="uj-ar-stamp" data-t="{{ $day['status'] }}"><i></i><span
                                x-text="$store.ui.lang==='en' ? @js($st[0]) : @js($st[1])">{{ $st[0] }}</span></span>
                            <svg class="cv" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>

                        <div class="uj-ar-day-panel"><div class="in"><div class="uj-ar-day-body">
                            @if ($shots)
                                <div class="uj-ar-shots">
                                    @foreach ($shots as $shot)
                                        <button type="button" class="uj-ar-shot"
                                                @click="shot = @js(['url' => $shot['url'], 'en' => $shot['en'], 'ms' => $shot['ms'], 'time' => $shot['time']])">
                                            <span class="fr"><img src="{{ $shot['url'] }}" alt="" loading="lazy"></span>
                                            <em><span x-text="$store.ui.lang==='en' ? @js($shot['en']) : @js($shot['ms'])">{{ $shot['en'] }}</span>
                                                <span>{{ $shot['time'] }}</span></em>
                                        </button>
                                    @endforeach
                                </div>
                            @endif

                            @forelse ($notes as $slot => $note)
                                <div class="uj-ar-note">
                                    <b x-text="$store.ui.lang==='en' ? @js($slot === 'in' ? 'In' : 'Out') : @js($slot === 'in' ? 'Masuk' : 'Keluar')">{{ $slot === 'in' ? 'In' : 'Out' }}</b>
                                    <span>{{ $note }}</span>
                                </div>
                            @empty
                                <div class="uj-ar-note-empty"
                                     x-text="$store.ui.lang==='en'
                                        ? 'No remark was recorded for this day.'
                                        : 'Tiada catatan direkodkan untuk hari ini.'">No remark was recorded for this day.</div>
                            @endforelse

                            @if ($canSeeLocation && $day['hasPoint'])
                                <div class="uj-ar-day-acts">
                                    <button type="button" class="uj-ar-btn" x-data
                                            @click="window.dispatchEvent(new CustomEvent('open-map-view', { detail: {
                                                title: @js($person['name'].' · '.$at->format('D, j M')),
                                                points: @js($day['points'])
                                            } }))">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                        <span x-text="$store.ui.lang==='en' ? 'View location' : 'Lihat lokasi'">View location</span>
                                    </button>
                                </div>
                            @endif

                            @if ($canAct)
                                <div class="uj-ar-day-acts">
                                    @if ($day['status'] === 'miss')
                                        <button type="button" class="uj-ar-btn" x-show="! amend" @click="amend = true"
                                                x-text="$store.ui.lang==='en' ? 'Add clock-out' : 'Tambah clock out'">Add clock-out</button>
                                        <form method="post" action="{{ route('attendance.admin.records.amend', $day['recordId']) }}"
                                              x-show="amend" x-cloak class="uj-ar-amend">
                                            @csrf
                                            <label for="uj-ar-t-{{ $day['recordId'] }}"
                                                   x-text="$store.ui.lang==='en' ? 'Clock-out time' : 'Masa clock out'">Clock-out time</label>
                                            <input type="time" id="uj-ar-t-{{ $day['recordId'] }}" name="time" value="18:00" required>
                                            <button type="submit" class="uj-ar-btn uj-ar-btn-primary"
                                                    x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                                            <button type="button" class="uj-ar-btn" @click="amend = false"
                                                    x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                                        </form>
                                    @endif

                                    {{-- Offer only the punch the record is actually in for, and name the real
                                         consequence: with a clock-out reversing clears just that; without one
                                         it deletes the record. Matches reversePunch() exactly. --}}
                                    @if ($day['in'] !== null)
                                        @php
                                            $revEn = $day['out'] !== null ? 'Reverse clock-out' : 'Reverse clock-in';
                                            $revMs = $day['out'] !== null ? 'Batal clock out' : 'Batal clock in';
                                            $confirm = $day['out'] !== null
                                                ? 'Clears the clock-out only. '.$person['name'].' stays clocked in and can clock out again.'
                                                : 'Deletes the whole record for this day. '.$person['name'].' can clock in again from scratch.';
                                        @endphp
                                        <form method="post" action="{{ route('attendance.admin.records.reverse', $day['recordId']) }}"
                                              onsubmit="return confirm(@js($confirm))">
                                            @csrf
                                            <button type="submit" class="uj-ar-btn danger"
                                                    x-text="$store.ui.lang==='en' ? @js($revEn) : @js($revMs)">{{ $revEn }}</button>
                                        </form>
                                    @endif
                                </div>
                            @endif
                        </div></div></div>
                    </div>
                @empty
                    <div class="uj-ar-dr-empty"
                         x-text="$store.ui.lang==='en' ? 'No records in this period.' : 'Tiada rekod dalam tempoh ini.'">No records in this period.</div>
                @endforelse
            </div>
        </aside>

        <div class="uj-ar-lightbox" x-show="shot" x-cloak @click="shot = null">
            <figure>
                <span class="fr"><img :src="shot?.url" alt=""></span>
                <figcaption>
                    <span x-text="$store.ui.lang==='en' ? shot?.en : shot?.ms"></span>
                    <span x-text="shot?.time"></span>
                </figcaption>
            </figure>
        </div>
    </div>
@endif
