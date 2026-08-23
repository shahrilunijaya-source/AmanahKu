{{-- Everything a filter change actually alters: the controls, the exception chips,
     the period totals and the rows.

     Split out so changing a filter fetches this alone. Re-rendering the screen sent
     the sidebar, header and app shell every time — 220KB and a rebuild of the whole
     page to swap nine table rows. The guide above it keeps its open/closed state
     because it stays outside. --}}
@php
    /* Defined here rather than in the screen: this partial is also rendered on
       its own by the body fragment route, where the screen's locals do not exist. */
    /* BM short names. Carbon ships no bundled BM locale here — same hand-map
       ReportPeriod keeps for the period label, for the same reason. */
    $msDays = ['Ahd', 'Isn', 'Sel', 'Rab', 'Kha', 'Jum', 'Sab'];
    $msMonths = [1 => 'Jan', 'Feb', 'Mac', 'Apr', 'Mei', 'Jun', 'Jul', 'Ogos', 'Sep', 'Okt', 'Nov', 'Dis'];

    /* Rendered vocabulary, not the database's. attendance_records.status only stores
       on_time | late | pending; everything else here is inferred by LedgerBuilder.
       "No punch" rather than "Absent": somebody with no record is not necessarily
       absent, they may simply not have clocked yet. */
    $statusLabel = [
        'ontime' => ['On time', 'Tepat masa'],
        'late' => ['Late', 'Lewat'],
        'miss' => ['Missing out', 'Tiada clock out'],
        'absent' => ['No punch', 'Tiada clock in'],
        'leave' => ['On leave', 'Bercuti'],
        'half' => ['Half day', 'Separuh hari'],
        'pending' => ['Pending', 'Menunggu'],
    ];

    $flagLabel = [
        'off' => ['Off-site', 'Luar lokasi'],
        'visit' => ['Site visit', 'Lawatan tapak'],
        'short' => ['Short hours', 'Jam kurang'],
        'early' => ['Left early', 'Balik awal'],
        'noloc' => ['No location', 'Tiada lokasi'],
        'amended' => ['Clock-out amended', 'Clock out dipinda'],
    ];

    $lensLabel = [
        'all' => ['all records', 'semua rekod'],
        'miss' => ['missing clock-out', 'tiada clock out'],
        'absent' => ['no punch', 'tiada clock in'],
        'short' => ['short hours', 'jam kurang'],
        'late' => ['clocked in late', 'clock in lewat'],
    ];

    /* Every filter is a link carrying the rest of the query, so the screen works
       with JavaScript off and partial-nav swaps only the screen body when it is on.
       `day` is dropped on any filter change: it deep-links one expanded day, and the
       day it named may not be in the new period. `emp` is kept — the drawer just
       re-renders for whatever the new period holds. */
    $url = function (array $overrides = []) {
        $query = array_merge(request()->query(), $overrides);
        unset($query['day']);
        $query = array_filter($query, fn ($v) => $v !== null && $v !== '');
        ksort($query);

        return route('app.screen', ['screen' => 'attendance-report'] + $query);
    };

    $exportUrl = route('attendance.report.export', array_filter(
        request()->query(),
        fn ($v) => $v !== null && $v !== ''
    ));

    $grans = ['day' => ['Day', 'Hari'], 'week' => ['Week', 'Minggu'], 'month' => ['Month', 'Bulan']];
    $sorts = ['date' => ['Date', 'Tarikh'], 'person' => ['Person', 'Staf']];
@endphp

{{-- The scrim only exists at phone width, where the filter block is a sheet. --}}
<div class="uj-ar-scrim uj-ar-mobile-only" x-show="filters" x-cloak
     x-transition.opacity.duration.280ms @click="filters = false"></div>

<form method="get" action="{{ route('app.screen', 'attendance-report') }}"
      class="uj-ar-filter" :data-open="filters || null" @keydown.escape.window="filters = false">
    <span class="uj-ar-grab uj-ar-mobile-only" aria-hidden="true"></span>
    <h3 class="uj-ar-mobile-only" x-text="$store.ui.lang==='en' ? 'Filters' : 'Penapis'">Filters</h3>
    {{-- The link controls carry their own state; these hidden fields keep it when the
         department or the name search submits this form instead. --}}
    <input type="hidden" name="gran" value="{{ $gran }}" :value="gran">
    <input type="hidden" name="offset" value="{{ $offset }}" :value="offset"
           @if($gran === 'custom') disabled @endif :disabled="gran === 'custom'">
    {{-- A custom range survives a dept or name change, but not a switch to
         Day/Week/Month — disabled fields are not submitted. --}}
    <input type="hidden" name="from" value="{{ $from }}"
           @if($gran !== 'custom') disabled @endif :disabled="gran !== 'custom'">
    <input type="hidden" name="to" value="{{ $to }}"
           @if($gran !== 'custom') disabled @endif :disabled="gran !== 'custom'">
    <input type="hidden" name="sort" value="{{ $sort }}" :value="sort">
    @if ($lens)
        <input type="hidden" name="lens" value="{{ $lens }}">
    @endif

    <div class="fld">
        <label class="uj-ar-mobile-only" x-text="$store.ui.lang==='en' ? 'Period' : 'Tempoh'">Period</label>
        <div class="uj-ar-seg">
            {{-- Not $label: the payload's own $label is the period name, and a loop
                 variable of the same name would clobber it for the rest of the view. --}}
            @foreach ($grans as $key => $granLabel)
                <a href="{{ $url(['gran' => $key, 'offset' => 0, 'from' => null, 'to' => null]) }}"
                   @if($gran === $key) data-on @endif
                   :data-on="gran === @js($key) ? '' : null"
                   @click="if (filters) { $event.preventDefault(); gran = @js($key); offset = 0 }"
                   x-text="$store.ui.lang==='en' ? @js($granLabel[0]) : @js($granLabel[1])">{{ $granLabel[0] }}</a>
            @endforeach
        </div>

        <div class="uj-ar-month">
            @if ($canPrev)
                <a class="nav" href="{{ $url(['offset' => $offset - 1]) }}"
                   @click="if (filters) { $event.preventDefault(); if (offset > -12) offset-- }"
                   :aria-label="$store.ui.lang==='en' ? 'Previous period' : 'Tempoh sebelum'">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                </a>
            @else
                <span class="nav" data-off aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                </span>
            @endif
            {{-- periodLabel comes from the server's own labels for every offset the
                 sheet can reach, so a staged step never shows a stale month and the
                 date maths lives in exactly one place. --}}
            <span class="lbl" x-text="periodLabel ?? ($store.ui.lang==='en' ? @js($label['en']) : @js($label['ms']))">{{ $label['en'] }}</span>
            @if ($canNext)
                <a class="nav" href="{{ $url(['offset' => $offset + 1]) }}"
                   @click="if (filters) { $event.preventDefault(); if (offset < 0) offset++ }"
                   :aria-label="$store.ui.lang==='en' ? 'Next period' : 'Tempoh seterusnya'">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
            @else
                <span class="nav" data-off aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </span>
            @endif
            <span class="div"></span>
            {{-- Two date boxes are the rare filter, and parked permanently in the bar
                 they read as loudly as Day/Week/Month. The popover is the approved
                 shape: the button says what it opens, the panel anchors to it, and
                 Escape or a click outside puts it away. --}}
            {{-- click.outside sits on the WRAPPER, not the panel: on the panel it
                 counts the button as outside, so it closes a moment before the
                 button's own handler reopens it and the toggle never shuts. --}}
            <span class="uj-ar-customwrap" x-data="{ range: false }"
                  @keydown.escape="range = false" @click.outside="range = false">
                <button type="button" class="custom nav" @click="range = ! range"
                        :aria-expanded="range" aria-haspopup="dialog"
                        @if($gran === 'custom') data-on @endif>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span x-text="$store.ui.lang==='en' ? 'Custom' : 'Tersuai'">Custom</span>
                </button>

                <div class="uj-ar-pop" role="dialog" aria-modal="false" aria-labelledby="uj-ar-rangetitle"
                     x-show="range" x-cloak x-transition.opacity.duration.160ms>
                    <h4 id="uj-ar-rangetitle"
                        x-text="$store.ui.lang==='en' ? 'Custom range' : 'Julat tersuai'">Custom range</h4>
                    <p x-text="$store.ui.lang==='en'
                        ? 'Pick any two dates. Overrides the month above.'
                        : 'Pilih dua tarikh. Menggantikan bulan di atas.'">Pick any two dates. Overrides the month above.</p>
                    <div class="rng">
                        <span>
                            <label for="uj-ar-from" x-text="$store.ui.lang==='en' ? 'From' : 'Dari'">From</label>
                            {{-- form=: these belong to the range form below, not to the
                                 filter form they sit inside. Nested forms are invalid. --}}
                            <input type="date" id="uj-ar-from" form="uj-ar-range-form" name="from"
                                   value="{{ $from }}" max="{{ now()->toDateString() }}">
                        </span>
                        <span>
                            <label for="uj-ar-to" x-text="$store.ui.lang==='en' ? 'To' : 'Hingga'">To</label>
                            <input type="date" id="uj-ar-to" form="uj-ar-range-form" name="to"
                                   value="{{ $to }}" max="{{ now()->toDateString() }}">
                        </span>
                    </div>
                    <div class="acts">
                        <button type="button" class="uj-ar-btn" @click="range = false"
                                x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                        <button type="submit" form="uj-ar-range-form" class="uj-ar-btn uj-ar-btn-primary"
                                x-text="$store.ui.lang==='en' ? 'Apply' : 'Guna'">Apply</button>
                    </div>
                </div>
            </span>
        </div>
    </div>

    <div class="fld">
        <label class="uj-ar-mobile-only" x-text="$store.ui.lang==='en' ? 'Department' : 'Jabatan'">Department</label>
        <select name="dept" class="uj-ar-sel" @change="if (! filters) $el.form.requestSubmit()">
            <option value="" x-text="$store.ui.lang==='en' ? 'All departments' : 'Semua jabatan'">All departments</option>
            @foreach ($departments as $name)
                <option value="{{ $name }}" @selected($dept === $name)>{{ $name }}</option>
            @endforeach
        </select>
    </div>

    <div class="fld">
        <label class="uj-ar-mobile-only" x-text="$store.ui.lang==='en' ? 'Staff' : 'Staf'">Staff</label>
        <div class="uj-ar-search" @if($q !== '') data-has @endif>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="20" y1="20" x2="16.65" y2="16.65"/></svg>
            <input type="search" name="q" value="{{ $q }}"
                   :placeholder="$store.ui.lang==='en' ? 'Search a name' : 'Cari nama'"
                   placeholder="Search a name">
            @if ($q !== '')
                <a class="clr" href="{{ $url(['q' => null]) }}"
                   :aria-label="$store.ui.lang==='en' ? 'Clear search' : 'Kosongkan carian'">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </a>
            @endif
        </div>
    </div>

    <div class="uj-ar-sortwrap fld">
        <label class="uj-ar-mobile-only" x-text="$store.ui.lang==='en' ? 'Sort by' : 'Susun ikut'">Sort by</label>
        <span class="uj-ar-desktop-only" x-text="$store.ui.lang==='en' ? 'Sort' : 'Susun'">Sort</span>
        <div class="uj-ar-seg">
            @foreach ($sorts as $key => $lbl)
                <a href="{{ $url(['sort' => $key]) }}" @if($sort === $key) data-on @endif
                   :data-on="sort === @js($key) ? '' : null"
                   @click="if (filters) { $event.preventDefault(); sort = @js($key) }"
                   x-text="$store.ui.lang==='en' ? @js($lbl[0]) : @js($lbl[1])">{{ $lbl[0] }}</a>
            @endforeach
        </div>
    </div>

    <button type="submit" class="uj-ar-btn uj-ar-btn-primary done uj-ar-mobile-only"
            x-text="$store.ui.lang==='en' ? 'Show results' : 'Tunjuk hasil'">Show results</button>
</form>

<form method="get" id="uj-ar-range-form" action="{{ route('app.screen', 'attendance-report') }}" hidden>
    <input type="hidden" name="gran" value="custom">
    <input type="hidden" name="sort" value="{{ $sort }}">
    @if ($dept)<input type="hidden" name="dept" value="{{ $dept }}">@endif
    @if ($q !== '')<input type="hidden" name="q" value="{{ $q }}">@endif
</form>

<div class="uj-ar-lensrow">
    {{-- A segmented control, not a row of numbers: as bare figures on the canvas
         nobody realised these were clickable. --}}
    {{-- Opens the filter form as a sheet. Sits with the chips because it is the
         other half of "which rows am I looking at", and the screen has no
         heading of its own to hang it off. --}}
    <button type="button" class="uj-ar-btn uj-ar-mobile-only uj-ar-filterbtn" @click="filters = true">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/></svg>
        <span x-text="$store.ui.lang==='en' ? 'Filters' : 'Penapis'">Filters</span>
    </button>
    <div class="uj-ar-chips" role="group"
         :aria-label="$store.ui.lang==='en' ? 'Filter rows' : 'Tapis baris'">
        @foreach (['all', 'miss', 'absent', 'short', 'late'] as $key)
            <a class="uj-ar-chip" data-t="{{ $key }}"
               href="{{ $url(['lens' => $key === 'all' ? null : $key]) }}"
               @if(($lens ?? 'all') === $key) data-on aria-current="true" @endif>
                <b>{{ $counts[$key] }}</b>
                <span x-text="$store.ui.lang==='en' ? @js($lensLabel[$key][0]) : @js($lensLabel[$key][1])">{{ $lensLabel[$key][0] }}</span>
            </a>
        @endforeach
    </div>
    {{-- data-full-nav: partial-nav would otherwise fetch this with JavaScript and
         swallow the file instead of letting the browser save it. --}}
    <a class="uj-ar-btn uj-ar-btn-primary uj-ar-export" href="{{ $exportUrl }}" data-full-nav>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <span x-text="$store.ui.lang==='en' ? 'Export' : 'Eksport'">Export</span>
    </a>
</div>

{{-- Scope, never lens. A block captioned "month to date" that reads 19 present
     because somebody clicked "missing clock-out" is not a total, it is a lie. --}}
<div class="uj-ar-sum">
    <span class="cap" x-text="$store.ui.lang==='en' ? @js($totals['caption']['en']) : @js($totals['caption']['ms'])">{{ $totals['caption']['en'] }}</span>
    <span class="stat"><b>{{ $totals['present'] }}</b><span x-text="$store.ui.lang==='en' ? 'present' : 'hadir'">present</span></span>
    <span class="stat" data-t="absent"><b>{{ $totals['absent'] }}</b><span x-text="$store.ui.lang==='en' ? 'absent' : 'tidak hadir'">absent</span></span>
    <span class="stat" data-t="late"><b>{{ $totals['late'] }}</b><span x-text="$store.ui.lang==='en' ? 'late' : 'lewat'">late</span></span>
    <span class="stat"><b>{{ $totals['leave'] }}</b><span x-text="$store.ui.lang==='en' ? 'on leave' : 'bercuti'">on leave</span></span>
    <span class="stat"><b>{{ $totals['staff'] }}</b><span x-text="$store.ui.lang==='en' ? 'staff' : 'staf'">staff</span></span>
    <span class="stat hours"><b>{{ number_format($totals['hours'], 1) }}</b><span x-text="$store.ui.lang==='en' ? 'total hours' : 'jumlah jam'">total hours</span></span>
    @if ($totals['leaveByType'])
        <div class="lv">
            @foreach ($totals['leaveByType'] as $type => $n)
                <span><i>{{ $n }}</i>{{ $type }}</span>
            @endforeach
        </div>
    @endif
</div>

<div class="uj-ar-tbl">
    {{-- On a single day every row repeats the same date, so the column stops being
         information and becomes 34 lines of repetition. The CSS drops it. --}}
    <div class="uj-ar-cols uj-ar-thead uj-ar-desktop-only">
        <span class="c-date" x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</span>
        <span x-text="$store.ui.lang==='en' ? 'Staff' : 'Staf'">Staff</span>
        <span class="uj-ar-num" x-text="$store.ui.lang==='en' ? 'In' : 'Masuk'">In</span>
        <span class="uj-ar-num" x-text="$store.ui.lang==='en' ? 'Out' : 'Keluar'">Out</span>
        <span class="uj-ar-num" x-text="$store.ui.lang==='en' ? 'Hours' : 'Jam'">Hours</span>
        <span x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</span>
        <span x-text="$store.ui.lang==='en' ? 'Flags' : 'Tanda'">Flags</span>
        <span></span>
    </div>

    <div class="uj-ar-scroll">
        @forelse ($rows as $row)
            @php
                $at = \Illuminate\Support\Carbon::parse($row['date']);
                $dateEn = $at->format('j M');
                $dateMs = $at->day.' '.$msMonths[(int) $at->month];
                $dowEn = $at->format('D');
                $dowMs = $msDays[$row['dow']];
                $st = $statusLabel[$row['status']] ?? [$row['status'], $row['status']];
                $personUrl = $url(['emp' => $row['employeeId']]);
            @endphp
            <div class="uj-ar-cols uj-ar-row" data-staff="{{ $row['employeeId'] }}"
                 @if($row['status'] === 'miss') data-alert @endif>
                <span class="c-date uj-ar-date">
                    <span x-text="$store.ui.lang==='en' ? @js($dateEn) : @js($dateMs)">{{ $dateEn }}</span><em
                        x-text="$store.ui.lang==='en' ? @js($dowEn) : @js($dowMs)">{{ $dowEn }}</em>
                </span>
                <span class="c-who uj-ar-who">
                    <a href="{{ $personUrl }}" class="uj-ar-person"
                       @click.prevent="openPerson($el.href)"
                       :aria-busy="loadingPerson === '{{ $row['employeeId'] }}'">
                        <span class="uj-ar-av" style="background:{{ $row['color'] }}">{{ $row['initials'] }}</span>
                        {{-- title: the column ellipsises long names, and two people
                             truncated to the same string is exactly the confusion this
                             screen exists to prevent. --}}
                        <span class="nm"><b title="{{ $row['name'] }}">{{ $row['name'] }}</b><span>{{ $row['dept'] ?? '—' }}</span></span>
                    </a>
                </span>
                <span class="c-in uj-ar-t uj-ar-num" @if(! $row['in']) data-nil @endif>{{ $row['in'] ?? '—' }}</span>
                <span class="c-out uj-ar-num">
                    @if ($row['out'])
                        <span class="uj-ar-t">{{ $row['out'] }}</span>
                    @elseif ($row['status'] === 'miss')
                        <span class="uj-ar-stamp" data-t="miss"><i></i><span
                            x-text="$store.ui.lang==='en' ? 'Missing' : 'Tiada'">Missing</span></span>
                    @else
                        <span class="uj-ar-t" data-nil>—</span>
                    @endif
                </span>
                <span class="c-hours uj-ar-hrs uj-ar-num"
                      @if($row['hours'] === null) data-nil @elseif(in_array('short', $row['flags'], true)) data-short @endif>{{ $row['hours'] !== null ? number_format($row['hours'], 2) : '—' }}</span>
                <span class="c-status">
                    <span class="uj-ar-stamp" data-t="{{ $row['status'] }}"><i></i><span
                        x-text="$store.ui.lang==='en' ? @js($st[0]) : @js($st[1])">{{ $st[0] }}</span></span>
                </span>
                <span class="c-flags uj-ar-flags">
                    @if ($row['leaveType'])
                        <span class="uj-ar-flag" data-t="leave">{{ $row['leaveType'] }}</span>
                    @endif
                    @foreach ($row['flags'] as $flag)
                        @php $fl = $flagLabel[$flag] ?? [$flag, $flag]; @endphp
                        {{-- Decision 9: an off-site or site-visit chip is the only row element
                             with real coordinates behind it, so it IS the control that opens
                             the map. No extra column, and the affordance sits on the thing it
                             explains. --}}
                        @if ($row['hasPoint'] && in_array($flag, ['off', 'visit'], true))
                            <button type="button" class="uj-ar-flag" data-t="{{ $flag }}" x-data
                                    @click="window.dispatchEvent(new CustomEvent('open-map-view', { detail: {
                                        title: @js($row['name'].' · '.$dateEn),
                                        points: @js($row['points']),
                                        site: @js($row['site'])
                                    } }))">
                                <span x-text="$store.ui.lang==='en' ? @js($fl[0]) : @js($fl[1])">{{ $fl[0] }}</span>
                                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            </button>
                        @else
                            <span class="uj-ar-flag" data-t="{{ $flag }}"
                                  x-text="$store.ui.lang==='en' ? @js($fl[0]) : @js($fl[1])">{{ $fl[0] }}</span>
                        @endif
                    @endforeach
                </span>
                <span class="c-fix">
                    {{-- Deep-links the drawer with that day already open, so the one-click
                         path and the considered path land on the same screen. --}}
                    @if ($row['status'] === 'miss' && $canReversePunch)
                        <a class="uj-ar-fix" href="{{ $personUrl.'&day='.$row['date'] }}"
                           @click.prevent="openPerson($el.href)"
                           x-text="$store.ui.lang==='en' ? 'Fix' : 'Betulkan'">Fix</a>
                    @endif
                </span>
            </div>
        @empty
            <div class="uj-ar-empty">
                <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>
                <b x-text="$store.ui.lang==='en' ? 'Nothing matches' : 'Tiada padanan'">Nothing matches</b>
                <p x-text="$store.ui.lang==='en'
                    ? 'No attendance record fits these filters. Widen the period, or clear a filter to see the rest of the month.'
                    : 'Tiada rekod kehadiran yang menepati penapis ini. Luaskan tempoh, atau buang satu penapis untuk lihat baki bulan ini.'">No attendance record fits these filters.</p>
                <a class="uj-ar-btn" href="{{ route('app.screen', 'attendance-report') }}"
                   x-text="$store.ui.lang==='en' ? 'Clear filters' : 'Buang penapis'">Clear filters</a>
            </div>
        @endforelse
    </div>
</div>
