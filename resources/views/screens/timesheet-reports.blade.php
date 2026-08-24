@extends('layouts.app')

@php
    $canSeeCost = $canSeeCost ?? false;
    $md = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $rm = fn ($v) => 'RM '.number_format((float) $v, 2);
    $totals = $reportTotals ?? ['days' => 0, 'cost' => 0, 'uncostedDays' => 0, 'weeksTotal' => 0, 'weeksNotIn' => 0];

    $fromDate = \Illuminate\Support\Carbon::parse($from);
    $toDate = \Illuminate\Support\Carbon::parse($to);
    if ($fromDate->month === $toDate->month && $fromDate->year === $toDate->year) {
        $dateRange = $fromDate->format('j').' – '.$toDate->format('j M Y');
    } elseif ($fromDate->year === $toDate->year) {
        $dateRange = $fromDate->format('j M').' – '.$toDate->format('j M Y');
    } else {
        $dateRange = $fromDate->format('j M Y').' – '.$toDate->format('j M Y');
    }

    $selCatName = $selCategory ? $filterCategories->firstWhere('id', (int) $selCategory)?->name : null;
    $selProjName = $selProject ? $filterProjects->firstWhere('id', (int) $selProject)?->name : null;
    $activeFilterParts = array_filter([$dept, $selCatName, $selProjName, $q !== '' ? '"'.$q.'"' : null]);
    $activeFilterName = count($activeFilterParts) > 0 ? implode(' + ', $activeFilterParts) : 'This period';

    // Which tab opens. Defaults to this week, so an unfilled sheet is the first
    // thing seen. ?tab=report deep-links the other one.
    $tab = request()->query('tab') === 'report' ? 'report' : 'week';

    // Rows the chase tab is about, so the tab label can carry the number.
    $oweCount = collect($tsRoster ?? [])->where('status', '!=', 'done')->count();

    // Every period link keeps the rest of the query and stays on the report tab. The
    // drill-down params are dropped: they name a row inside the old period, which the
    // new one may not contain.
    $trUrl = function (array $overrides = []) {
        $query = array_merge(request()->query(), $overrides, ['tab' => 'report']);
        unset($query['view'], $query['id'], $query['pid'], $query['emp']);
        $query = array_filter($query, fn ($v) => $v !== null && $v !== '');
        ksort($query);

        return route('app.screen', ['screen' => 'timesheet-reports'] + $query);
    };

    // No Day: a timesheet line is a share of a whole day, so a one-day report has
    // nothing to say that the week does not say better.
    $trGrans = ['week' => ['Week', 'Minggu'], 'month' => ['Month', 'Bulan']];
@endphp

@section('screen')
<div x-data="timesheetReport({
        category: @js($lensCategory),
        project: @js($lensProject),
        staff: @js($lensStaff),
        weeks: @js($staffWeeks),
        tab: @js($tab),
    })"
    @keydown.escape.window="tab === 'report' && sel.view !== 'bars' && back()">
    {{-- Reciprocal of the "see all staff" icon on the personal timesheet screen: this
         report is reached by that one-way shortcut, so offer a one-tap way back to My
         timesheets rather than leaving the browser Back button as the only exit. --}}
    <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
        <a href="{{ route('app.screen', 'timesheets') }}" class="uj-btn-ghost" style="font-size:12px;padding:7px 12px;text-decoration:none;">
            <span x-text="$store.ui.lang==='en' ? '← My timesheets' : '← Timesheet saya'">← My timesheets</span>
        </a>
    </div>

    @include('partials.guide', [
        'key' => 'timesheet-reports',
        'en'  => [
            'title' => 'Timesheet reports',
            'body'  => 'See where staff time and money went, drawn from submitted timesheets. "By category" answers spend questions like how much went to Study or Leave; "by project" shows cost and effort per project; "by staff" breaks one person down. Time is in person-days (a day at 100% = one person-day); cost is RM, derived from each person\'s salary band.',
            'who'   => 'Managers, Management & HR',
            'steps' => [
                'Two tabs. "Where time went" reads a closed period; "This week" chases the sheets still missing, and its number tells you how many.',
                'On the report tab, pick Week or Month and step back with the arrows, or take any two dates with Custom. Narrow by department, category, project or name, then press Apply.',
                'Pick a breakdown: By category, By project, or By person. Bars show each slice as a share of the total.',
                'Click any row to open the panel beside it: a category or project lists the people inside it, and a person shows the weeks and lines behind their number.',
                'On the week tab, each person who still owes a sheet can be reminded, which sends them the same bell the Friday reminder sends. Open week shows the sheet as it stands, read-only.',
            ],
        ],
        'ms'  => [
            'title' => 'Laporan lembaran masa',
            'body'  => 'Lihat ke mana masa dan wang staf pergi, daripada timesheet yang dihantar. "Mengikut kategori" menjawab soalan perbelanjaan seperti berapa untuk Belajar atau Cuti; "mengikut projek" menunjukkan kos dan usaha setiap projek; "mengikut staf" memecahkan seorang. Masa dalam hari-orang (sehari pada 100% = satu hari-orang); kos dalam RM, diambil daripada band gaji setiap orang.',
            'who'   => 'Pengurus, Pengurusan & HR',
            'steps' => [
                'Dua tab. "Ke mana masa pergi" membaca tempoh yang telah tutup; "Minggu ini" mengejar lembaran yang masih belum masuk, dan nombornya memberitahu berapa banyak.',
                'Pada tab laporan, pilih Minggu atau Bulan dan undur dengan anak panah, atau ambil dua tarikh dengan Tersuai. Tapis ikut jabatan, kategori, projek atau nama, kemudian tekan Guna.',
                'Pilih pecahan: Mengikut kategori, projek, atau individu. Bar menunjukkan setiap bahagian sebagai peratus jumlah.',
                'Klik mana-mana baris untuk membuka panel di sebelahnya: kategori atau projek menyenaraikan orang di dalamnya, dan seorang individu menunjukkan minggu dan baris di sebalik nombornya.',
                'Pada tab minggu, setiap orang yang masih belum hantar boleh diingatkan, yang menghantar loceng sama seperti peringatan Jumaat. Buka minggu menunjukkan lembaran orang itu seadanya, baca sahaja.',
            ],
        ],
    ])

    {{-- Two tabs, because this screen does two jobs: chase the week that is open, and read
         the period that is closed. An underline bar, not another uj-tr-pills group — the
         report tab already contains one, and two identical pill rows would read as one
         control with six options. --}}
    <div class="uj-tr-tabs" role="tablist">
        <button type="button" id="tr-tab-week" class="uj-tr-tab" role="tab" :data-on="tab==='week'"
            :aria-selected="tab==='week'" aria-controls="tr-panel-week" :tabindex="tab==='week' ? 0 : -1"
            @click="setTab('week')" @keydown.right.prevent="setTab('report')" @keydown.left.prevent="setTab('report')">
            <span x-text="$store.ui.lang==='en' ? 'This week' : 'Minggu ini'">This week</span>
            @if ($oweCount > 0)
                <span class="uj-tr-tabcount">{{ $oweCount }}</span>
            @endif
        </button>
        <button type="button" id="tr-tab-report" class="uj-tr-tab" role="tab" :data-on="tab==='report'"
            :aria-selected="tab==='report'" aria-controls="tr-panel-report" :tabindex="tab==='report' ? 0 : -1"
            @click="setTab('report')" @keydown.right.prevent="setTab('week')" @keydown.left.prevent="setTab('week')">
            <span x-text="$store.ui.lang==='en' ? 'Where time went' : 'Ke mana masa pergi'">Where time went</span>
        </button>
    </div>

    {{-- This-week compliance roster — who still owes a sheet. Access is the screen's own
         403 gate (management/HR/superiors, see AppController::canSeeAll), not a role check
         here. Always the current week, independent of the report period below. --}}
    <div x-show="tab==='week'" x-cloak role="tabpanel" id="tr-panel-week" aria-labelledby="tr-tab-week" tabindex="0">
    @php
        $tsRoster = collect($tsRoster ?? []);
        $tsDoneCount = $tsRoster->where('status', 'done')->count();
        $tsTotalCount = $tsRoster->count();
        $tsNudged = $tsNudged ?? [];

        $statusOrder = ['late' => 0, 'pending' => 1];
        $tsOweRows = $tsRoster
            ->reject(fn ($r) => $r['status'] === 'done')
            ->sort(function ($a, $b) use ($statusOrder) {
                $sa = $statusOrder[$a['status']] ?? 2;
                $sb = $statusOrder[$b['status']] ?? 2;
                if ($sa !== $sb) {
                    return $sa <=> $sb;
                }
                if ($a['filledDays'] !== $b['filledDays']) {
                    return $a['filledDays'] <=> $b['filledDays'];
                }
                return strcasecmp($a['employee']->display_name, $b['employee']->display_name);
            })
            ->values();

        $tsDeadlineObj = isset($tsDeadline) ? \Illuminate\Support\Carbon::parse($tsDeadline) : null;
        $tsDeadlineIsFuture = $tsDeadlineObj ? $tsDeadlineObj->isFuture() : false;
        $tsDeadlineFormatted = $tsDeadlineObj ? $tsDeadlineObj->format('l j M, H:i') : '';
        $tsDeadlineDiff = $tsDeadlineObj ? $tsDeadlineObj->diffForHumans() : '';
    @endphp
    <div x-show="staffWeekError" x-cloak class="uj-tr-notice">
        <span x-text="$store.ui.lang==='en' ? 'Could not open that week. Check your connection and try again.' : 'Tidak dapat membuka minggu itu. Semak sambungan anda dan cuba lagi.'"></span>
        <button type="button" class="uj-tr-notice-close" @click="staffWeekError=false" :aria-label="$store.ui.lang==='en' ? 'Dismiss' : 'Tutup'">&times;</button>
    </div>

    {{-- Host for the fetched staff week viewer. Alpine initialises what x-html injects. --}}
    <div x-html="staffWeekHtml"></div>

    @if ($tsTotalCount)
    <div class="uj-card" style="margin-bottom:16px;overflow:hidden;" x-show="! staffWeekHtml" x-data="{ open: true, showAll: false }">
        <div style="padding:14px 18px;display:flex;align-items:center;gap:10px;cursor:pointer;" @click="open = !open">
            <strong style="flex:1;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'This week — team status' : 'Minggu ini — status pasukan'">This week — team status</strong>
            <span style="font-size:12.5px;color:var(--body);">
                <b style="font-family:var(--font-mono);font-weight:600;color:var(--ink);">{{ $tsDoneCount }}</b>
                <span x-text="$store.ui.lang==='en' ? 'of' : 'daripada'">of</span>
                <b style="font-family:var(--font-mono);font-weight:600;color:var(--ink);">{{ $tsTotalCount }}</b>
                <span x-text="$store.ui.lang==='en' ? 'sheets in' : 'lembaran masuk'">sheets in</span>
            </span>
            <span x-text="open ? '▾' : '▸'" style="color:var(--muted);"></span>
        </div>

        <div x-show="open">
            @if ($tsOweRows->isEmpty())
                <div style="padding:15px 18px;border-top:1px solid #e2ded4;display:flex;align-items:center;gap:10px;">
                    <span class="uj-stamp" data-tone="success">✓</span>
                    <span style="font-size:13px;color:var(--success-ink);font-weight:500;"
                          x-text="$store.ui.lang==='en' ? 'Every sheet is in for this week.' : 'Semua lembaran sudah masuk untuk minggu ini.'">
                        Every sheet is in for this week.
                    </span>
                </div>
            @else
                <div>
                    @foreach ($tsOweRows as $idx => $row)
                        <div class="uj-tr-owe" @if ($idx >= 8) x-show="showAll" @endif>
                            <span class="uj-tr-who">
                                <span class="uj-tr-av" style="background: {{ $row['employee']->avatar_color ?: config('amanahku.avatar_color', '#1f8a65') }}">
                                    {{ $row['employee']->initials }}
                                </span>
                                <span>
                                    <span class="uj-tr-name">
                                        {{ $row['employee']->display_name }}
                                        @if ($row['status'] === 'late')
                                            <span class="uj-stamp" data-tone="red" style="margin-left:6px;" x-text="$store.ui.lang==='en' ? 'Overdue' : 'Lewat'">Overdue</span>
                                        @endif
                                    </span>
                                    @if ($row['employee']->positionBand?->title)
                                        <span class="uj-tr-sub">{{ $row['employee']->positionBand->title }}</span>
                                    @endif
                                </span>
                            </span>

                            <div class="prog">
                                <div class="cap">
                                    @if ($row['expectedDays'] == 0)
                                        <span x-text="$store.ui.lang==='en' ? 'Nothing expected' : 'Tiada dijangka'">Nothing expected</span>
                                    @elseif ($row['filledDays'] == 0)
                                        <span x-text="$store.ui.lang==='en' ? 'Nothing yet' : 'Belum ada apa-apa'">Nothing yet</span>
                                    @else
                                        <span>
                                            <b style="font-family:var(--font-mono);">{{ $row['filledDays'] }}</b>
                                            <span x-text="$store.ui.lang==='en' ? 'of' : 'daripada'">of</span>
                                            <b style="font-family:var(--font-mono);">{{ $row['expectedDays'] }}</b>
                                            <span x-text="$store.ui.lang==='en' ? 'days at 100%' : 'hari pada 100%'">days at 100%</span>
                                        </span>
                                    @endif
                                </div>
                                @if ($row['expectedDays'] > 0)
                                    @php
                                        $pct = round(($row['filledDays'] / $row['expectedDays']) * 100);
                                        $barColor = $row['status'] === 'late' ? 'var(--red)' : 'var(--amber)';
                                    @endphp
                                    <div class="uj-tr-bar">
                                        <i style="width: {{ max($row['filledDays'] > 0 ? 1.5 : 0, $pct) }}%; background: {{ $barColor }};"></i>
                                    </div>
                                @endif
                                <div style="font-size:var(--t-micro);color:var(--muted);margin-top:4px;">
                                    @if ($row['lastTouched'])
                                        <span x-text="$store.ui.lang==='en' ? 'Saved' : 'Disimpan'">Saved</span> {{ $row['lastTouched']->format('D j M, H:i') }}
                                    @else
                                        <span x-text="$store.ui.lang==='en' ? 'No draft yet' : 'Belum ada draf'">No draft yet</span>
                                    @endif
                                </div>
                            </div>

                            <div class="act">
                                @if ($row['employee']->user_id === null)
                                    <button type="button" class="uj-tr-btn" disabled>
                                        <span x-text="$store.ui.lang==='en' ? 'No login' : 'Tiada akaun'">No login</span>
                                    </button>
                                @elseif (in_array($row['employee']->id, $tsNudged, true))
                                    <button type="button" class="uj-tr-btn" data-done disabled>
                                        <span x-text="$store.ui.lang==='en' ? 'Reminded' : 'Sudah diingatkan'">Reminded</span>
                                    </button>
                                @else
                                    <form method="post" action="{{ route('timesheet.reports.nudge', $row['employee']) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="uj-tr-btn" data-primary>
                                            <span x-text="$store.ui.lang==='en' ? 'Remind' : 'Ingatkan'">Remind</span>
                                        </button>
                                    </form>
                                @endif

                                {{-- Opens THIS person's week beside the roster, read-only. It used
                                     to link to /app/timesheets, which is the reader's own sheet —
                                     wrong person, and an edit form on top of it. --}}
                                <button type="button" class="uj-tr-btn" @click="openStaffWeek({{ $row['employee']->id }})"
                                        :disabled="staffWeekLoading === {{ $row['employee']->id }}">
                                    <span x-show="staffWeekLoading !== {{ $row['employee']->id }}"
                                          x-text="$store.ui.lang==='en' ? 'Open week' : 'Buka minggu'">Open week</span>
                                    <span x-show="staffWeekLoading === {{ $row['employee']->id }}" x-cloak
                                          x-text="$store.ui.lang==='en' ? 'Opening…' : 'Membuka…'">Opening…</span>
                                </button>
                            </div>
                        </div>
                    @endforeach

                    @if ($tsOweRows->count() > 8)
                        <div x-show="!showAll" style="padding:10px 18px;border-top:1px solid #e2ded4;text-align:center;">
                            <button type="button" class="uj-tr-btn" @click="showAll = true">
                                +{{ $tsOweRows->count() - 8 }} <span x-text="$store.ui.lang==='en' ? 'more' : 'lagi'">more</span>
                            </button>
                        </div>
                    @endif
                </div>
            @endif

            @if ($tsDeadlineObj)
                <div class="uj-tr-clock">
                    @if ($tsDeadlineIsFuture)
                        <span x-text="$store.ui.lang==='en' ? 'Locks' : 'Tutup'">Locks</span>
                        <b>{{ $tsDeadlineFormatted }}</b>
                    @else
                        <span x-text="$store.ui.lang==='en' ? 'Locked' : 'Ditutup'">Locked</span>
                        <b>{{ $tsDeadlineFormatted }}</b> · {{ $tsDeadlineDiff }}
                    @endif
                </div>
            @endif
        </div>
    </div>
    @endif
    </div>{{-- /tab: week --}}

    <div x-show="tab==='report'" x-cloak role="tabpanel" id="tr-panel-report" aria-labelledby="tr-tab-report" tabindex="0">
    {{-- Shelf + filters: full while browsing; a one-line summary while drilled in,
         so the thing you drilled into doesn't have to compete with eight controls
         above it. --}}
    <div x-show="sel.view==='bars'">
        {{-- Two rows, because the controls answer to two different things. The period
             is links: pressing one moves you now, and partial-nav swaps the screen and
             nothing else. The pickers below are form fields, so they wait for Apply —
             four of them navigating one at a time would be four page changes. Mixing
             them on one line made Apply look like it governed all seven. --}}
        <div class="uj-tr-period">
            <div class="uj-ar-seg">
                @foreach ($trGrans as $key => $granLabel)
                    <a href="{{ $trUrl(['gran' => $key, 'offset' => 0, 'from' => null, 'to' => null]) }}"
                       @if($gran === $key) data-on @endif
                       x-text="$store.ui.lang==='en' ? @js($granLabel[0]) : @js($granLabel[1])">{{ $granLabel[0] }}</a>
                @endforeach
            </div>

            <div class="uj-ar-month">
                @if ($canPrev)
                    <a class="nav" href="{{ $trUrl(['offset' => $offset - 1]) }}"
                       :aria-label="$store.ui.lang==='en' ? 'Previous period' : 'Tempoh sebelum'">&lsaquo;</a>
                @else
                    <span class="nav" data-off aria-hidden="true">&lsaquo;</span>
                @endif
                <span class="lbl" x-text="$store.ui.lang==='en' ? @js($periodLabel['en']) : @js($periodLabel['ms'])">{{ $periodLabel['en'] }}</span>
                @if ($canNext)
                    <a class="nav" href="{{ $trUrl(['offset' => $offset + 1]) }}"
                       :aria-label="$store.ui.lang==='en' ? 'Next period' : 'Tempoh seterusnya'">&rsaquo;</a>
                @else
                    <span class="nav" data-off aria-hidden="true">&rsaquo;</span>
                @endif
                <span class="div"></span>
                {{-- click.outside on the wrapper, not the panel: on the panel the button
                     itself counts as outside, so the popover shuts a moment before the
                     button reopens it and the toggle never opens at all. --}}
                <span class="uj-ar-customwrap" x-data="{ range: false }"
                      @keydown.escape="range = false" @click.outside="range = false">
                    <button type="button" class="custom nav" @click="range = ! range"
                            :aria-expanded="range" aria-haspopup="dialog"
                            @if($gran === 'custom') data-on @endif>
                        <span x-text="$store.ui.lang==='en' ? 'Custom' : 'Tersuai'">Custom</span>
                    </button>

                    <div class="uj-ar-pop" role="dialog" aria-modal="false" aria-labelledby="tr-rangetitle"
                         x-show="range" x-cloak x-transition.opacity.duration.160ms>
                        <h4 id="tr-rangetitle" x-text="$store.ui.lang==='en' ? 'Custom range' : 'Julat tersuai'">Custom range</h4>
                        <p x-text="$store.ui.lang==='en'
                            ? 'Pick any two dates. Overrides the period above.'
                            : 'Pilih dua tarikh. Menggantikan tempoh di atas.'">Pick any two dates. Overrides the period above.</p>
                        <div class="rng">
                            <span>
                                <label for="tr-from" x-text="$store.ui.lang==='en' ? 'From' : 'Dari'">From</label>
                                {{-- form=: these belong to the range form below, not to the
                                     filter form they sit inside. Nested forms are invalid. --}}
                                <input type="date" id="tr-from" form="tr-range-form" name="from" value="{{ $from }}">
                            </span>
                            <span>
                                <label for="tr-to" x-text="$store.ui.lang==='en' ? 'To' : 'Hingga'">To</label>
                                <input type="date" id="tr-to" form="tr-range-form" name="to" value="{{ $to }}">
                            </span>
                        </div>
                        <div class="acts">
                            <button type="button" class="uj-tr-btn" @click="range = false"
                                    x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                            <button type="submit" form="tr-range-form" class="uj-tr-btn" data-primary
                                    x-text="$store.ui.lang==='en' ? 'Apply' : 'Guna'">Apply</button>
                        </div>
                    </div>
                </span>
            </div>

            <span class="uj-tr-range">{{ $dateRange }}</span>
        </div>

        <form method="get" action="{{ route('app.screen', 'timesheet-reports') }}" class="uj-tr-filter">
            <input type="hidden" name="tab" value="report">
            <input type="hidden" name="gran" value="{{ $gran }}">
            @if ($gran === 'custom')
                <input type="hidden" name="from" value="{{ $from }}">
                <input type="hidden" name="to" value="{{ $to }}">
            @else
                <input type="hidden" name="offset" value="{{ $offset }}">
            @endif

            <select name="dept" class="uj-tr-sel"
                :aria-label="$store.ui.lang==='en' ? 'Department' : 'Jabatan'">
                <option value="" x-text="$store.ui.lang==='en' ? 'All departments' : 'Semua jabatan'">All departments</option>
                @foreach ($departments as $name)
                    <option value="{{ $name }}" @selected($dept === $name)>{{ $name }}</option>
                @endforeach
            </select>

            <select name="category" class="uj-tr-sel"
                :aria-label="$store.ui.lang==='en' ? 'Category' : 'Kategori'">
                <option value="" x-text="$store.ui.lang==='en' ? 'All categories' : 'Semua kategori'">All categories</option>
                @foreach ($filterCategories as $c)
                    <option value="{{ $c->id }}" @selected((string) $selCategory === (string) $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>

            <select name="project" class="uj-tr-sel"
                :aria-label="$store.ui.lang==='en' ? 'Project' : 'Projek'">
                <option value="" x-text="$store.ui.lang==='en' ? 'All projects' : 'Semua projek'">All projects</option>
                @foreach ($filterProjects as $p)
                    <option value="{{ $p->id }}" @selected((string) $selProject === (string) $p->id)>{{ $p->name }}</option>
                @endforeach
            </select>

            <div class="uj-ar-search" @if($q !== '') data-has @endif>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="20" y1="20" x2="16.65" y2="16.65"/></svg>
                <input type="search" name="q" value="{{ $q }}"
                       :placeholder="$store.ui.lang==='en' ? 'Search a name' : 'Cari nama'"
                       placeholder="Search a name">
                @if ($q !== '')
                    <a class="clr" href="{{ $trUrl(['q' => null]) }}"
                       :aria-label="$store.ui.lang==='en' ? 'Clear search' : 'Kosongkan carian'">&times;</a>
                @endif
            </div>

            <button type="submit" class="uj-tr-btn" data-primary><span x-text="$store.ui.lang==='en' ? 'Apply' : 'Guna'">Apply</span></button>
        </form>

        {{-- The custom-range popover's own form, so its two date inputs replace the
             period instead of joining it. Sibling, not nested. --}}
        <form method="get" id="tr-range-form" action="{{ route('app.screen', 'timesheet-reports') }}" hidden>
            <input type="hidden" name="tab" value="report">
            <input type="hidden" name="gran" value="custom">
            @if ($dept)<input type="hidden" name="dept" value="{{ $dept }}">@endif
            @if ($q !== '')<input type="hidden" name="q" value="{{ $q }}">@endif
            @if ($selCategory)<input type="hidden" name="category" value="{{ $selCategory }}">@endif
            @if ($selProject)<input type="hidden" name="project" value="{{ $selProject }}">@endif
        </form>

        <div style="margin-top:20px;">
            <div class="uj-tr-pills" role="group" :aria-label="$store.ui.lang==='en' ? 'Break down by' : 'Pecahkan mengikut'">
                <button type="button" class="uj-tr-pill" :data-on="lens==='category'" :aria-pressed="lens==='category'" @click="setLens('category')">
                    <span x-text="$store.ui.lang==='en' ? 'By category' : 'Mengikut kategori'">By category</span>
                </button>
                <button type="button" class="uj-tr-pill" :data-on="lens==='project'" :aria-pressed="lens==='project'" @click="setLens('project')">
                    <span x-text="$store.ui.lang==='en' ? 'By project' : 'Mengikut projek'">By project</span>
                </button>
                <button type="button" class="uj-tr-pill" :data-on="lens==='staff'" :aria-pressed="lens==='staff'" @click="setLens('staff')">
                    <span x-text="$store.ui.lang==='en' ? 'By person' : 'Mengikut individu'">By person</span>
                </button>
            </div>
        </div>
    </div>

    {{-- Collapsed summary line, shown only while drilled in. --}}
    <div class="uj-tr-summary" x-show="sel.view!=='bars'">
        <span>{{ $dateRange }}</span>
        @if ($selCatName || $selProjName)
            <span>&middot; {{ $activeFilterName }}</span>
        @endif
    </div>

    @if ($reportEmpty)
        <div class="uj-tr-card">
            <div class="uj-tr-empty">
                <b x-text="$store.ui.lang==='en' ? 'No submitted time matches this filter' : 'Tiada masa dihantar yang sepadan dengan tapisan ini'">No submitted time matches this filter</b>
                <div>{{ $activeFilterName }}</div>
                <span x-text="$store.ui.lang==='en' ? 'Clear a filter, or widen the period.' : 'Kosongkan satu tapisan, atau luaskan tempoh.'">Clear a filter, or widen the period.</span>
            </div>
        </div>
    @else
        <div x-show="staleNotice" class="uj-tr-notice">
            <span x-text="$store.ui.lang==='en' ? 'That row is not in the current period or filter.' : 'Baris itu tiada dalam tempoh atau tapisan semasa.'"></span>
            <button type="button" class="uj-tr-notice-close" @click="staleNotice=false" :aria-label="$store.ui.lang==='en' ? 'Dismiss' : 'Tutup'">&times;</button>
        </div>

        <div class="uj-tr-lens">
            {{-- Level 0: bars --}}
            <div x-show="sel.view==='bars'">
                <div class="uj-tr-card" :class="{ 'uj-tr-anim': !hasAnimated }" x-ref="barList" tabindex="-1">
                    <template x-if="rows().length === 0">
                        <div class="uj-tr-empty"
                             x-text="lens === 'category' ? ($store.ui.lang === 'en' ? 'No categorised time in this period.' : 'Tiada masa berkategori dalam tempoh ini.')
                                   : (lens === 'project' ? ($store.ui.lang === 'en' ? 'No project-linked time in this period.' : 'Tiada masa berkaitan projek dalam tempoh ini.')
                                   : ($store.ui.lang === 'en' ? 'Nobody has submitted time in this period.' : 'Tiada sesiapa menghantar masa dalam tempoh ini.'))">
                            No project-linked time in this period.
                        </div>
                    </template>
                    <template x-for="(row, index) in rows()" :key="row.id || row.label || index">
                        <button type="button" class="uj-tr-lensrow" :data-row-id="row.id"
                                @click="lens === 'staff' ? openPerson(row.id, null) : slice(row.id)">
                            <div class="uj-tr-barrow">
                                <span class="lbl">
                                    <span x-text="row.name || row.label"></span>
                                    <span class="uj-tr-sub" style="display:inline;margin-left:6px"
                                          x-text="lens === 'staff' ? (row.title || '')
                                                : ((row.members ? row.members.length : 0) + ' ' +
                                                   ($store.ui.lang === 'en'
                                                       ? ((row.members ? row.members.length : 0) === 1 ? 'person' : 'people')
                                                       : 'orang'))">
                                    </span>
                                </span>
                                <span class="val">
                                    <template x-if="(lens === 'staff' && !row.costed) || !(row.cost > 0)">
                                        <span style="color:var(--amber-ink)" x-text="$store.ui.lang==='en' ? 'uncosted' : 'tanpa kos'">uncosted</span>
                                    </template>
                                    <template x-if="row.cost > 0 && !(lens === 'staff' && !row.costed)">
                                        <b x-text="'RM ' + Number(row.cost || 0).toLocaleString('en-MY', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></b>
                                    </template>
                                    <span x-text="' · ' + (Math.round((row.days || 0) * 100) / 100).toFixed(2).replace(/\.?0+$/, '') + ' md'"></span>
                                    <template x-if="lens === 'staff' && row.weeksIn < row.weeksTotal">
                                        <span class="dim" x-text="' · ' + row.weeksIn + '/' + row.weeksTotal + ' ' + ($store.ui.lang === 'en' ? 'wk' : 'mgu')"></span>
                                    </template>
                                    <span x-text="' · ' + (row.pct || 0) + '%'"></span>
                                </span>
                            </div>
                            <div class="uj-tr-bar">
                                <i aria-hidden="true" :style="'width:' + Math.max(row.pct || 0, 1.5) + '%;background:' + (lens === 'category' ? 'var(--info)' : (lens === 'project' ? 'var(--success)' : (row.color || 'var(--info)'))) + ';animation-delay:' + Math.min(index * 45, 200) + 'ms'"></i>
                            </div>
                        </button>
                    </template>
                </div>
                @if ($totals['weeksNotIn'] > 0)
                    <div class="uj-tr-note" x-text="$store.ui.lang==='en' ? 'A row short of its weeks is short a submitted sheet, not short of work.' : 'Baris yang kurang minggunya kekurangan lembaran dihantar, bukan kekurangan kerja.'">A row short of its weeks is short a submitted sheet, not short of work.</div>
                @endif
            </div>

            {{-- Level 1: a category/project's members, full width --}}
            <template x-if="sel.view==='slice' && currentSlice()">
                <div class="uj-tr-panel" :data-dir="direction">
                    {{-- Own ref name (not shared with the person panel below): two x-if
                         blocks racing to register/unregister the same x-ref name in one
                         reactive flush can leave $refs pointing at neither. --}}
                    <div class="uj-tr-crumb" x-ref="drillHeadingSlice" tabindex="-1">
                        <template x-for="(c, i) in crumbs()" :key="i">
                            <span>
                                <template x-if="c.target !== null">
                                    <button type="button" class="uj-tr-crumb-btn" @click="c.action()">
                                        <span x-show="i === 0">&larr;</span> <span x-text="c.label"></span>
                                    </button>
                                </template>
                                <template x-if="c.target === null">
                                    <span class="uj-tr-crumb-cur" x-text="c.label"></span>
                                </template>
                                <span x-show="i < crumbs().length - 1" class="uj-tr-crumb-sep" aria-hidden="true">/</span>
                            </span>
                        </template>
                        <span class="uj-tr-crumb-share" x-text="formatSliceSubline(currentSlice())"></span>
                    </div>
                    <div class="uj-tr-bar" style="margin-bottom:6px">
                        <i aria-hidden="true" :style="'width:' + Math.max(currentSlice().pct || 0, 1.5) + '%;background:' + (lens === 'category' ? 'var(--info)' : 'var(--success)')"></i>
                    </div>
                    <template x-if="(currentSlice().members || []).length === 0">
                        <div class="uj-tr-empty" x-text="$store.ui.lang==='en' ? 'Nobody in this slice for the current filter.' : 'Tiada sesiapa dalam bahagian ini untuk tapisan semasa.'"></div>
                    </template>
                    <template x-for="member in (currentSlice().members || [])" :key="member.id">
                        <button type="button" class="uj-tr-lensrow" :data-row-id="member.id" @click="openPerson(member.id, currentSlice().id)">
                            <div class="uj-tr-barrow">
                                <span class="lbl" x-text="member.name"></span>
                                <span class="val" x-text="(Math.round((member.days || 0) * 100) / 100).toFixed(2).replace(/\.?0+$/, '') + ' md · ' + (member.pct || 0) + '%'"></span>
                            </div>
                            <div class="uj-tr-bar">
                                <i aria-hidden="true" :style="'width:' + Math.max(member.pct || 0, 1.5) + '%;background:' + (lens === 'category' ? 'var(--info)' : 'var(--success)')"></i>
                            </div>
                        </button>
                    </template>
                </div>
            </template>

            {{-- Level 2 (or level 1 for the staff lens): one person's weeks and lines, full width --}}
            <template x-if="sel.view==='person' && personToDisplay()">
                <div class="uj-tr-panel" :data-dir="direction">
                    <div class="uj-tr-crumb" x-ref="drillHeadingPerson" tabindex="-1">
                        <template x-for="(c, i) in crumbs()" :key="i">
                            <span>
                                <template x-if="c.target !== null">
                                    <button type="button" class="uj-tr-crumb-btn" @click="c.action()">
                                        <span x-show="i === 0">&larr;</span> <span x-text="c.label"></span>
                                    </button>
                                </template>
                                <template x-if="c.target === null">
                                    <span class="uj-tr-crumb-cur" x-text="c.label"></span>
                                </template>
                                <span x-show="i < crumbs().length - 1" class="uj-tr-crumb-sep" aria-hidden="true">/</span>
                            </span>
                        </template>
                    </div>
                    {{-- x-ref must live outside this nested scope: Alpine registers a ref to
                         its nearest x-data ancestor, and focusHeading() reads $refs from the
                         OUTER component. A ref inside here would be invisible to it. --}}
                    <div x-data="{
                            get p() { return personToDisplay() },
                            weekIdx: 0,
                            weekDir: 'fwd',
                            get weeksList() { return weeks[this.p.id] || [] },
                            get currentWeek() { return this.weeksList[this.weekIdx] || null },
                            prevWeek() { if (this.weekIdx > 0) { this.weekDir = 'back'; this.weekIdx-- } },
                            nextWeek() { if (this.weekIdx < this.weeksList.length - 1) { this.weekDir = 'fwd'; this.weekIdx++ } },
                        }">
                        {{-- The name lives here, not only in the breadcrumb above it: the
                             breadcrumb is a route, and a panel that reports one person's
                             month should say whose it is without being read as navigation. --}}
                        <div class="uj-tr-person">
                            <span class="uj-tr-av" :style="'background:' + (p.color || 'var(--info)')" x-text="p.initials"></span>
                            <div class="who">
                                <h3 x-text="p.name"></h3>
                                <div class="uj-tr-sub" x-text="(p.title || '') + (p.costed && p.rate ? ((p.title ? ' · ' : '') + rm(p.rate) + '/day') : '')"></div>
                            </div>
                            <div class="tally">
                                <b x-text="p.weeksIn + ' / ' + p.weeksTotal"></b>
                                <span x-text="$store.ui.lang === 'en' ? 'weeks submitted' : 'minggu dihantar'">weeks submitted</span>
                            </div>
                        </div>

                        {{-- Which weeks are absent belongs beside the count that raised the
                             question, not in a footnote under the entries. --}}
                        <template x-if="p.missingWeeks && p.missingWeeks.length > 0">
                            <div class="uj-tr-note uj-tr-note--tight" x-text="formatMissingWeeks(p)"></div>
                        </template>

                        <template x-if="weeksList.length === 0">
                            <div class="uj-tr-empty" x-text="$store.ui.lang==='en' ? 'No submitted lines in this period.' : 'Tiada baris dihantar dalam tempoh ini.'"></div>
                        </template>
                        <template x-if="weeksList.length > 0">
                            <div class="uj-tr-weeknav">
                                <template x-for="wk in (currentWeek ? [currentWeek] : [])" :key="weekIdx">
                                    <div class="uj-tr-wk" :data-dir="weekDir">
                                        {{-- The pager sits in the header of the thing it pages,
                                             rather than floating above it in its own band. --}}
                                        <div class="uj-tr-wk-hd">
                                            <button type="button" class="uj-tr-weeknav-btn" @click="prevWeek()" :disabled="weekIdx === 0"
                                                :aria-label="$store.ui.lang==='en' ? 'Previous week' : 'Minggu sebelum'">&lsaquo;</button>
                                            <div class="lbl">
                                                <b x-text="wk.label"></b>
                                                <span x-text="wk.dates"></span>
                                                {{-- Which of how many. Two disabled arrows say
                                                     "first" and "last" but never say how many
                                                     weeks are behind them. --}}
                                                <span class="pos" x-show="weeksList.length > 1"
                                                    x-text="(weekIdx + 1) + ' / ' + weeksList.length"></span>
                                            </div>
                                            <div class="tot">
                                                <b x-text="md(wk.days) + ' md'"></b>
                                                <template x-if="p.costed && wk.cost > 0">
                                                    <span x-text="rm(wk.cost)"></span>
                                                </template>
                                            </div>
                                            <button type="button" class="uj-tr-weeknav-btn" @click="nextWeek()" :disabled="weekIdx === weeksList.length - 1"
                                                :aria-label="$store.ui.lang==='en' ? 'Next week' : 'Minggu seterusnya'">&rsaquo;</button>
                                        </div>

                                        {{-- One heading per day carrying that day's total, so a
                                             day that does not reach 1 is visible without adding
                                             its lines up by hand. --}}
                                        <template x-for="grp in daysInWeek(wk)" :key="grp.day">
                                            <div class="uj-tr-day-grp">
                                                <div class="uj-tr-day">
                                                    <span class="d" x-text="grp.day"></span>
                                                    <span class="rule" aria-hidden="true"></span>
                                                    <span class="t" :data-short="grp.days < 1 || null" x-text="md(grp.days)"></span>
                                                </div>
                                                <template x-for="(line, lidx) in grp.lines" :key="lidx">
                                                    <div class="uj-tr-ent">
                                                        <div>
                                                            <span x-text="line.label"></span>
                                                            <template x-if="line.note">
                                                                <span class="n" x-html="line.note"></span>
                                                            </template>
                                                        </div>
                                                        <span class="d" x-text="md(line.days)"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <template x-if="!p.costed">
                            <div class="uj-tr-note" style="margin-top:12px" x-text="$store.ui.lang==='en' ? 'You have no position band assigned, so your timesheet cost can\'t be computed. Set it in Administration → Position & Manday Rates.' : 'Anda belum ada band pangkat, jadi kos timesheet anda tidak dapat dikira. Tetapkan di Pentadbiran → Pangkat & Kadar Manday.'"></div>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    @endif
    </div>{{-- /tab: report --}}
</div>
@endsection
