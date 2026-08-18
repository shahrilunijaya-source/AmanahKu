@extends('layouts.app')

@php
    use Illuminate\Support\Str;
    use Illuminate\Support\Carbon;

    // Flag badge labels (EN / MS) — mirrors the staff-facing attendance screen.
    $flagLabel = [
        'late' => ['Late', 'Lewat'],
        'out_of_radius_in' => ['Off-site in', 'Clock in luar'],
        'out_of_radius_out' => ['Off-site out', 'Clock out luar'],
        'early_out' => ['Left early', 'Balik awal'],
        'short_hours' => ['Short hours', 'Jam kurang'],
        'no_location' => ['No location', 'Tiada lokasi'],
    ];
    $stColor = ['on_time' => 'var(--success)', 'late' => 'var(--amber)', 'pending' => 'var(--muted)'];
    $stLabel = ['on_time' => ['On time', 'Tepat masa'], 'late' => ['Late', 'Lewat'], 'pending' => ['Pending', 'Menunggu']];

    $periodLabel = [
        'week' => ['This week', 'Minggu ini'],
        'month' => ['Last 30 days', '30 hari lepas'],
        'quarter' => ['Last 90 days', '90 hari lepas'],
    ];
    $baseUrl = route('app.screen', 'attendance-report').'?period='.$period.($dept ? '&dept='.urlencode($dept) : '');

    // Calculations for Roster shelf & coverage bar
    $neverStaff = $roster->where('never', true);
    $stoppedStaff = $roster->where('stopped', true);
    $neverNames = $neverStaff->pluck('name')->values();
    $stoppedFirst = $stoppedStaff->first();

    $hc = max($totals['headcount'], 1);
    $wActive = number_format($totals['bucketClocking'] / $hc * 100, 1).'%';
    $wStopped = number_format($totals['bucketStopped'] / $hc * 100, 1).'%';
    $wLeave = number_format($totals['bucketOnLeave'] / $hc * 100, 1).'%';
    $wNever = number_format($totals['bucketNever'] / $hc * 100, 1).'%';
@endphp

@section('screen')
<div class="uj-ar-wrap">
    {{-- Reciprocal of the "see all staff" icon on the personal attendance screen --}}
    <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
        <a href="{{ route('app.screen', 'attendance') }}" class="uj-btn-ghost" style="font-size:12px;padding:7px 12px;text-decoration:none;">
            <span x-text="$store.ui.lang==='en' ? '← My attendance' : '← Kehadiran saya'">← My attendance</span>
        </a>
    </div>

    @include('partials.guide', [
        'key' => 'attendance-report',
        'en' => [
            'title' => 'Attendance Reports',
            'body'  => 'One row for every active employee, whether they clocked in or not. The strip beside each name is their attendance day by day, so a pattern shows up where a single percentage would hide it. People who never clocked, and people who stopped, sort to the top.',
            'who'   => 'Management and HR only',
            'steps' => [
                'Pick a period, and a department if you want one. Ninety days shows one cell per week instead of one per day.',
                'The block at the top counts who is clocking, who stopped, who is on approved leave, and who never clocked at all. The four add up to your headcount.',
                'Read a strip left to right: green on time, amber late, blue off-site, pale blue approved leave, red no punch, grey pending today.',
                'Click any row to see that person day by day.',
            ],
        ],
        'ms' => [
            'title' => 'Laporan Kehadiran',
            'body'  => 'Satu baris untuk setiap pekerja aktif, sama ada mereka clock in atau tidak. Jalur di sebelah nama menunjukkan kehadiran hari demi hari, jadi corak dapat dilihat walaupun satu peratusan sahaja akan menyembunyikannya. Mereka yang tidak pernah clock in, dan mereka yang berhenti, disusun di atas.',
            'who'   => 'Pengurusan dan HR sahaja',
            'steps' => [
                'Pilih tempoh, dan jabatan jika perlu. 90 hari menunjukkan satu petak bagi setiap minggu, bukan setiap hari.',
                'Blok di atas mengira siapa yang clock in, siapa yang berhenti, siapa yang bercuti diluluskan, dan siapa yang tidak pernah clock in. Empat-empat berjumlah bilangan staf anda.',
                'Baca jalur dari kiri ke kanan: hijau tepat masa, kuning lewat, biru luar lokasi, biru pudar cuti diluluskan, merah tiada clock in, kelabu menunggu hari ini.',
                'Klik mana-mana baris untuk lihat hari demi hari.',
            ],
        ],
    ])

    @if ($drill)
        {{-- ── Single-staff drill-down (UNTOUCHED) ────────────────────────── --}}
        <div class="uj-card">
            <div class="uj-card-head" style="display:flex;align-items:center;gap:12px;">
                <div class="uj-ar-av" style="background:{{ $drill->avatar_color }};">{{ $drill->initials }}</div>
                <div style="min-width:0;">
                    <h3 class="uj-card-title" style="margin:0;">{{ $drill->display_name }}</h3>
                    <div style="font-size:11.5px;color:var(--muted);">{{ trim(($drill->position ?? '').' · '.($drill->department?->name ?? ''), ' ·') }}</div>
                </div>
                <a href="{{ $baseUrl }}" class="uj-btn-ghost" style="margin-left:auto;font-size:12px;padding:7px 12px;text-decoration:none;">
                    <span x-text="$store.ui.lang==='en' ? '← All staff' : '← Semua staf'">← All staff</span>
                </a>
            </div>

            <div class="uj-ar-drow uj-ar-dhead" style="background:none;{{ $canReversePunch ? 'grid-template-columns:minmax(0,1.6fr) minmax(0,.8fr) minmax(0,.7fr) minmax(0,.7fr) minmax(0,.7fr) minmax(0,.9fr) minmax(0,1fr);' : '' }}">
                <span x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</span>
                <span style="text-align:right;">In</span>
                <span style="text-align:right;" class="uj-ar-hide-sm">Out</span>
                <span style="text-align:right;" class="uj-ar-hide-sm"><span x-text="$store.ui.lang==='en' ? 'Worked' : 'Bekerja'">Worked</span></span>
                <span style="text-align:right;"><span x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</span></span>
                <span style="text-align:right;"><span x-text="$store.ui.lang==='en' ? 'Flags' : 'Tanda'">Flags</span></span>
                @if ($canReversePunch)
                    <span style="text-align:right;"></span>
                @endif
            </div>
            @forelse ($drillRecords as $r)
                @php
                    $rin = $r->clock_in ? Str::of($r->clock_in)->limit(5, '') : '—';
                    $rout = $r->clock_out ? Str::of($r->clock_out)->limit(5, '') : '—';
                    $wm = (int) ($r->worked_minutes ?? 0);
                    $worked = $wm > 0 ? intdiv($wm, 60).'h'.($wm % 60 ? ($wm % 60).'m' : '') : '—';
                    $sl = $stLabel[$r->status] ?? [$r->status, $r->status];
                @endphp
                <div class="uj-ar-drow" style="cursor:default;{{ $canReversePunch ? 'grid-template-columns:minmax(0,1.6fr) minmax(0,.8fr) minmax(0,.7fr) minmax(0,.7fr) minmax(0,.7fr) minmax(0,.9fr) minmax(0,1fr);' : '' }}">
                    <span style="font-size:13px;color:var(--ink);font-weight:500;">{{ $r->date->format('D, j M') }}</span>
                    <span class="uj-ar-num">{{ $rin }}</span>
                    <span class="uj-ar-num uj-ar-hide-sm">{{ $rout }}</span>
                    <span class="uj-ar-num uj-ar-hide-sm" style="font-weight:500;color:var(--muted);">{{ $worked }}</span>
                    <span style="text-align:right;font-size:12px;font-weight:600;color:{{ $stColor[$r->status] ?? 'var(--muted)' }};">
                        <span x-text="$store.ui.lang==='en' ? @js($sl[0]) : @js($sl[1])">{{ $sl[0] }}</span>
                    </span>
                    <span style="display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end;">
                        @foreach (array_diff($r->flags ?? [], ['late']) as $f)
                            @php $fl = $flagLabel[$f] ?? [$f, $f]; @endphp
                            <span style="font-size:9px;font-weight:600;color:var(--error);background:var(--red-tint,rgba(214,35,43,.1));padding:2px 5px;border-radius:9999px;white-space:nowrap;" x-text="$store.ui.lang==='en' ? @js($fl[0]) : @js($fl[1])">{{ $fl[0] }}</span>
                        @endforeach
                    </span>
                    @if ($canReversePunch)
                        <span style="text-align:right;">
                            @if ($r->clock_in)
                                @php
                                    $confirmMsg = $r->clock_out
                                        ? "Reverse {$drill->display_name}'s clock-out on {$r->date->format('j M')}? They will be able to clock out again."
                                        : "Reverse {$drill->display_name}'s clock-in on {$r->date->format('j M')}? They will be able to clock in again, and this record will be deleted.";
                                    $revLabel = $r->clock_out ? ['Reverse out', 'Batal keluar'] : ['Reverse in', 'Batal masuk'];
                                @endphp
                                <form method="post" action="{{ route('attendance.admin.records.reverse', $r) }}"
                                      onsubmit="return confirm(@js($confirmMsg))">
                                    @csrf
                                    <button type="submit" class="uj-btn-ghost" style="height:28px;padding:0 10px;font-size:11.5px;color:var(--red-active);border-color:var(--red-active);">
                                        <span x-text="$store.ui.lang==='en' ? @js($revLabel[0]) : @js($revLabel[1])">{{ $revLabel[0] }}</span>
                                    </button>
                                </form>
                            @endif
                        </span>
                    @endif
                    @php
                        $notes = array_filter(['in' => $r->clock_in_justification, 'out' => $r->clock_out_justification]);

                        // Only points that were actually off-site. within() returns null (never
                        // false) when coordinates or the site geofence are missing, so an
                        // out_of_radius_* flag already implies a usable point; the null-checks
                        // guard hand-edited rows, not the normal path.
                        $recFlags = $r->flags ?? [];
                        $locPoints = [];
                        if (in_array('out_of_radius_in', $recFlags, true) && $r->latitude !== null && $r->longitude !== null) {
                            $inTime = $r->clock_in ? Str::of($r->clock_in)->limit(5, '') : '';
                            $locPoints[] = [
                                'lat' => (float) $r->latitude,
                                'lng' => (float) $r->longitude,
                                'labelEn' => 'Clocked in '.$inTime,
                                'labelMs' => 'Clock in '.$inTime,
                            ];
                        }
                        if (in_array('out_of_radius_out', $recFlags, true) && $r->clock_out_latitude !== null && $r->clock_out_longitude !== null) {
                            $outTime = $r->clock_out ? Str::of($r->clock_out)->limit(5, '') : '';
                            $locPoints[] = [
                                'lat' => (float) $r->clock_out_latitude,
                                'lng' => (float) $r->clock_out_longitude,
                                'labelEn' => 'Clocked out '.$outTime,
                                'labelMs' => 'Clock out '.$outTime,
                            ];
                        }
                    @endphp
                    @if ($canSeeLocation && $locPoints)
                        <div style="grid-column:1/-1;margin-top:5px;">
                            <button type="button" class="uj-ar-loc" x-data
                                    @click="window.dispatchEvent(new CustomEvent('open-map-view', { detail: {
                                        title: @js($drill->display_name.' · '.$r->date->format('D, j M')),
                                        points: @js($locPoints)
                                    } }))">
                                📍 <span x-text="$store.ui.lang==='en' ? 'View location' : 'Lihat lokasi'">View location</span>
                            </button>
                        </div>
                    @endif
                    @if ($notes)
                        <div style="grid-column:1/-1;display:flex;flex-direction:column;gap:3px;margin-top:2px;">
                            @foreach ($notes as $slot => $note)
                                <div style="display:flex;gap:7px;font-size:11.5px;line-height:1.45;color:var(--body);">
                                    <span style="flex-shrink:0;font-size:9px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);padding-top:2px;"
                                          x-text="$store.ui.lang==='en' ? @js($slot === 'in' ? 'In' : 'Out') : @js($slot === 'in' ? 'Masuk' : 'Keluar')">{{ $slot === 'in' ? 'In' : 'Out' }}</span>
                                    <span>{{ $note }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <div style="padding:24px;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No attendance in this period.' : 'Tiada kehadiran dalam tempoh ini.'">No attendance in this period.</span></div>
            @endforelse
        @include('partials.map-view')
        </div>
    @else
        {{-- ── Shelf Lead Block ─────────────────────────────────────────────── --}}
        <div class="uj-ar-shelf">
            <div class="uj-ar-kicker">
                <span x-text="$store.ui.lang==='en' ? 'Attendance · ' + @js($rangeLabel) : 'Kehadiran · ' + @js($rangeLabel)">Attendance · {{ $rangeLabel }}</span>
            </div>
            <div class="uj-ar-figrow">
                <span class="uj-ar-fig">{{ $totals['reported'] }}<span style="color:var(--muted-soft)">/{{ $totals['headcount'] }}</span></span>
                <span class="uj-ar-figsub">
                    @php
                        $formatNeverNames = function (\Illuminate\Support\Collection $names, string $lang): string {
                            $count = $names->count();
                            if ($count === 0) {
                                return $lang === 'en' ? '0 staff never clocked in' : '0 staf tidak pernah clock in';
                            }
                            if ($count === 1) {
                                $list = $names[0];
                            } elseif ($count === 2) {
                                $and = $lang === 'en' ? ' and ' : ' dan ';
                                $list = $names[0] . $and . $names[1];
                            } elseif ($count === 3) {
                                $and = $lang === 'en' ? ' and ' : ' dan ';
                                $list = $names[0] . ', ' . $names[1] . $and . $names[2];
                            } else {
                                $others = $count - 3;
                                $and = $lang === 'en' ? " and {$others} others" : " dan {$others} lagi";
                                $list = $names[0] . ', ' . $names[1] . ', ' . $names[2] . $and;
                            }

                            $suffix = $lang === 'en' ? ' never clocked in' : ' tidak pernah clock in';
                            return $list . $suffix;
                        };

                        $neverTextEn = $formatNeverNames($neverNames, 'en');
                        $neverTextMs = $formatNeverNames($neverNames, 'ms');
                        $stoppedTextEn = $stoppedFirst ? $stoppedFirst['name'].' stopped '.$stoppedFirst['gapDays'].' days ago.' : '';
                        $stoppedTextMs = $stoppedFirst ? $stoppedFirst['name'].' terhenti '.$stoppedFirst['gapDays'].' hari lepas.' : '';
                    @endphp
                    <span x-text="$store.ui.lang==='en'
                        ? 'staff clocked in at least once. '
                        : 'staf clock in sekurang-kurangnya sekali. '">staff clocked in at least once. </span>
                    <b><span x-text="$store.ui.lang==='en' ? @js($neverTextEn) : @js($neverTextMs)">{{ $neverTextEn }}</span></b>
                    <span x-text="$store.ui.lang==='en' ? ' and hold no approved leave.' : ' dan tiada cuti diluluskan.'"> and hold no approved leave.</span>
                    @if ($stoppedFirst)
                        <span x-text="$store.ui.lang==='en' ? ' ' : ' '"> </span>
                        <b><span x-text="$store.ui.lang==='en' ? @js($stoppedTextEn) : @js($stoppedTextMs)">{{ $stoppedTextEn }}</span></b>
                    @endif
                </span>
            </div>

            <div class="uj-ar-cov">
                <i style="background:var(--success);width:{{ $wActive }};"></i>
                <i style="background:var(--amber);width:{{ $wStopped }};"></i>
                <i style="background:#c3d5e6;width:{{ $wLeave }};"></i>
                <i style="background:var(--error);width:{{ $wNever }};"></i>
            </div>

            <div class="uj-ar-covkey">
                <span><b style="background:var(--success);"></b><span x-text="$store.ui.lang==='en' ? '{{ $totals['bucketClocking'] }} clocking' : '{{ $totals['bucketClocking'] }} aktif clocking'">{{ $totals['bucketClocking'] }} clocking</span></span>
                <span><b style="background:var(--amber);"></b><span x-text="$store.ui.lang==='en' ? '{{ $totals['bucketStopped'] }} stopped' : '{{ $totals['bucketStopped'] }} terhenti'">{{ $totals['bucketStopped'] }} stopped</span></span>
                <span><b style="background:#c3d5e6;"></b><span x-text="$store.ui.lang==='en' ? '{{ $totals['bucketOnLeave'] }} on leave' : '{{ $totals['bucketOnLeave'] }} bercuti'">{{ $totals['bucketOnLeave'] }} on leave</span></span>
                <span><b style="background:var(--error);"></b><span x-text="$store.ui.lang==='en' ? '{{ $totals['bucketNever'] }} never clocked' : '{{ $totals['bucketNever'] }} tidak pernah clock'">{{ $totals['bucketNever'] }} never clocked</span></span>
            </div>
        </div>

        {{-- ── Filter bar ────────────────────────────────────────────────────── --}}
        <div class="uj-ar-filter">
            <div class="uj-ar-pills">
                @foreach ($periods as $p)
                    <a href="{{ route('app.screen', 'attendance-report').'?period='.$p.($dept ? '&dept='.urlencode($dept) : '') }}"
                       class="uj-ar-pill {{ $p === $period ? 'uj-ar-on' : '' }}"
                       {!! $p === $period ? 'data-on' : '' !!}>
                        <span x-text="$store.ui.lang==='en' ? @js($periodLabel[$p][0]) : @js($periodLabel[$p][1])">{{ $periodLabel[$p][0] }}</span>
                    </a>
                @endforeach
            </div>

            <form method="get" action="{{ route('app.screen', 'attendance-report') }}" style="display:inline-flex;align-items:center;gap:8px;">
                <input type="hidden" name="period" value="{{ $period }}" />
                <select name="dept" onchange="this.form.submit()" class="uj-ar-sel">
                    <option value="" {{ $dept ? '' : 'selected' }} x-text="$store.ui.lang==='en' ? 'All departments' : 'Semua jabatan'">All departments</option>
                    @foreach ($departments as $d)
                        <option value="{{ $d }}" {{ $dept === $d ? 'selected' : '' }}>{{ $d }}</option>
                    @endforeach
                </select>
            </form>

            <span class="uj-ar-range">{{ $rangeLabel }}</span>
        </div>

        {{-- ── Section header & Legend ───────────────────────────────────────── --}}
        @php
            // Both buckets are rendered server-side and toggled with x-show, rather than
            // shipped to Alpine as JSON — the data already lives here, and duplicating it
            // into a JS literal is one more place for the two to drift apart.
            $summaryBuckets = [
                'absent' => [
                    'rows' => $summary['absent'],
                    'tileEn' => 'with no punch', 'tileMs' => 'tiada clock in',
                    'headEn' => 'No punch', 'headMs' => 'Tiada clock in',
                    'emptyEn' => 'Nobody missed a day.', 'emptyMs' => 'Tiada sesiapa terlepas hari.',
                ],
                'late' => [
                    'rows' => $summary['late'],
                    'tileEn' => 'clocked in late', 'tileMs' => 'clock in lewat',
                    'headEn' => 'Clocked in late', 'headMs' => 'Clock in lewat',
                    'emptyEn' => 'Nobody clocked in late.', 'emptyMs' => 'Tiada sesiapa clock in lewat.',
                ],
                // Wording matches the flag badge this screen already renders for the same
                // record ($flagLabel['short_hours'] above), so the dialog and the drill-down
                // call one thing by one name.
                'short' => [
                    'rows' => $summary['short'],
                    'tileEn' => 'short hours', 'tileMs' => 'jam kurang',
                    'headEn' => 'Short hours', 'headMs' => 'Jam kurang',
                    'emptyEn' => 'Nobody worked short hours.', 'emptyMs' => 'Tiada sesiapa bekerja kurang jam.',
                ],
            ];
            // Enough pills to read a pattern; the rest collapse into a +N so a 90-day
            // window can't spill 40 pills into one row.
            $dayPillCap = 6;
        @endphp

        <div x-data="{ summaryOpen: false, bucket: 'absent' }">
            <div class="uj-ar-sect">
                <div class="uj-ar-sect-left">
                    <h2><span x-text="$store.ui.lang==='en' ? 'Everyone' : 'Semua staf'">Everyone</span></h2>
                    <button type="button" class="uj-ar-sumbtn" @click="summaryOpen = true">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-3"/></svg>
                        <span x-text="$store.ui.lang==='en' ? 'View summary' : 'Lihat ringkasan'">View summary</span>
                    </button>
                </div>
                <span class="sum"><span x-text="$store.ui.lang==='en' ? @js(count($days) . ' working days · needs attention first') : @js(count($days) . ' hari bekerja · perlu perhatian dahulu')">{{ count($days) }} working days · needs attention first</span></span>
            </div>

            {{-- ── Summary dialog: who was absent / late, and on which day ──────── --}}
            <div x-show="summaryOpen" x-cloak class="uj-dialog-overlay"
                 @click.self="summaryOpen = false"
                 @keydown.escape.window="summaryOpen = false"
                 style="position:fixed;inset:0;z-index:200;padding:16px;background:rgba(31,30,26,.55);backdrop-filter:blur(2px);">
                <div role="dialog" aria-modal="true" aria-labelledby="uj-ar-sumtitle"
                     x-transition:enter="uj-overlay-enter" x-transition:enter-start="uj-overlay-from" x-transition:enter-end="uj-overlay-to"
                     x-transition:leave="uj-overlay-leave" x-transition:leave-start="uj-overlay-to" x-transition:leave-end="uj-overlay-from"
                     style="width:100%;max-width:460px;margin:auto;background:var(--card);border-radius:16px;overflow:hidden;box-shadow:0 24px 70px rgba(31,30,26,.30);display:flex;flex-direction:column;max-height:min(640px,88vh);">

                    <div class="uj-ar-sumhead">
                        <div>
                            <h3 id="uj-ar-sumtitle"><span x-text="$store.ui.lang==='en' ? 'Attendance summary' : 'Ringkasan kehadiran'">Attendance summary</span></h3>
                            <p>{{ $rangeLabel }} · {{ $totals['headcount'] }} <span x-text="$store.ui.lang==='en' ? 'staff' : 'staf'">staff</span></p>
                        </div>
                        <button type="button" class="uj-ar-sumclose" @click="summaryOpen = false"
                                :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>

                    <div class="uj-ar-sumgrid">
                        @foreach ($summaryBuckets as $key => $b)
                            <button type="button" class="uj-ar-sumtile" data-t="{{ $key }}"
                                    :class="bucket === @js($key) && 'uj-ar-sumon'"
                                    @click="bucket = @js($key)">
                                <span class="uj-ar-sumnum" data-t="{{ $key }}">{{ $b['rows']->count() }}</span>
                                <span class="uj-ar-sumlbl" x-text="$store.ui.lang==='en' ? @js($b['tileEn']) : @js($b['tileMs'])">{{ $b['tileEn'] }}</span>
                            </button>
                        @endforeach
                    </div>

                    @foreach ($summaryBuckets as $key => $b)
                        <div x-show="bucket === @js($key)" class="uj-ar-sumnames">
                            <div class="uj-ar-sumnameshead">
                                <span x-text="$store.ui.lang==='en' ? @js($b['headEn'].' — '.$b['rows']->count().' people') : @js($b['headMs'].' — '.$b['rows']->count().' orang')">{{ $b['headEn'] }} — {{ $b['rows']->count() }} people</span>
                            </div>
                            @forelse ($b['rows'] as $p)
                                @php $extraDays = count($p['days']) - $dayPillCap; @endphp
                                <a href="{{ $baseUrl.'&emp='.$p['id'] }}" class="uj-ar-sumchip">
                                    <span class="uj-ar-av" style="background:{{ $p['color'] }};">{{ $p['initials'] }}</span>
                                    <span style="min-width:0;">
                                        <span class="uj-ar-sumname">{{ $p['name'] }}</span>
                                        <span class="uj-ar-sumdays">
                                            {{-- Most recent days, not oldest: someone who's still a problem today
                                                 matters more than what happened at the start of the window. --}}
                                            @foreach (array_slice($p['days'], -$dayPillCap) as $d)
                                                <span class="uj-ar-sumday" data-t="{{ $key }}"
                                                      x-text="$store.ui.lang==='en' ? @js($d['en']) : @js($d['ms'])">{{ $d['en'] }}</span>
                                            @endforeach
                                            @if ($extraDays > 0)
                                                <span class="uj-ar-sumday">+{{ $extraDays }}</span>
                                            @endif
                                        </span>
                                    </span>
                                </a>
                            @empty
                                <div class="uj-ar-sumempty">
                                    <span x-text="$store.ui.lang==='en' ? @js($b['emptyEn']) : @js($b['emptyMs'])">{{ $b['emptyEn'] }}</span>
                                </div>
                            @endforelse
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="uj-ar-legend">
            <span><b style="background:var(--success);"></b><span x-text="$store.ui.lang==='en' ? 'On time' : 'Tepat masa'">On time</span></span>
            <span><b style="background:var(--amber);"></b><span x-text="$store.ui.lang==='en' ? 'Late' : 'Lewat'">Late</span></span>
            <span><b style="background:var(--info);"></b><span x-text="$store.ui.lang==='en' ? 'Off-site' : 'Luar lokasi'">Off-site</span></span>
            <span><b style="background:#c3d5e6;"></b><span x-text="$store.ui.lang==='en' ? 'Approved leave' : 'Cuti diluluskan'">Approved leave</span></span>
            <span><b style="background:var(--error);"></b><span x-text="$store.ui.lang==='en' ? 'No punch' : 'Tiada clock in'">No punch</span></span>
            <span><b style="background:#dedbd2;"></b><span x-text="$store.ui.lang==='en' ? 'Pending today' : 'Menunggu hari ini'">Pending today</span></span>
        </div>
        @if ($stripUnit === 'week')
            <div style="font-size:var(--t-micro);color:var(--muted);margin-top:6px;">
                <span x-text="$store.ui.lang==='en' ? 'Each cell represents 1 week.' : 'Setiap petak mewakili 1 minggu.'">Each cell represents 1 week.</span>
            </div>
        @endif

        {{-- ── Roster List ──────────────────────────────────────────────────── --}}
        <div class="uj-ar-list">
            @foreach ($roster as $p)
                @php
                    $pTone = $p['pct'] === null ? 'none' : ($p['pct'] >= 90 ? 'hi' : ($p['pct'] >= 75 ? 'mid' : 'lo'));
                    $lastSeenFormatted = $p['lastSeen'] ? Carbon::parse($p['lastSeen'])->format('j M') : '';
                    $daysCount = count($days);
                    $cellsCount = count($cells);
                    $stripChars = str_split($p['strip']);
                @endphp
                <div class="uj-ar-r">
                    <a href="{{ $baseUrl.'&emp='.$p['id'] }}" class="uj-ar-rbtn">
                        <span style="min-width:0">
                            <span class="uj-ar-who">
                                <span class="uj-ar-av" style="background:{{ $p['color'] }};">{{ $p['initials'] }}</span>
                                <span style="min-width:0">
                                    <span class="uj-ar-name">{{ $p['name'] }}</span>
                                    <span class="uj-ar-sub">{{ $p['dept'] ?? '—' }}</span>
                                </span>
                            </span>
                            @if ($p['never'])
                                <div class="uj-ar-flag">
                                    <span x-text="$store.ui.lang==='en' ? 'No record in {{ $daysCount }} days. No leave on file.' : 'Tiada rekod dalam {{ $daysCount }} hari. Tiada cuti didaftarkan.'">No record in {{ $daysCount }} days. No leave on file.</span>
                                </div>
                            @elseif ($p['stopped'])
                                <div class="uj-ar-flag">
                                    <span x-text="$store.ui.lang==='en' ? @js('Stopped clocking. Last punch '.$lastSeenFormatted.', '.$p['gapDays'].' days ago.') : @js('Terhenti clock in. Clock in terakhir '.$lastSeenFormatted.', '.$p['gapDays'].' hari lepas.')">Stopped clocking. Last punch {{ $lastSeenFormatted }}, {{ $p['gapDays'] }} days ago.</span>
                                </div>
                            @elseif ($p['onLeave'])
                                <div class="uj-ar-flag" data-t="leave">
                                    <span x-text="$store.ui.lang==='en' ? 'Approved leave' : 'Cuti diluluskan'">Approved leave</span>
                                </div>
                            @elseif ($p['offsite'] > 0)
                                <div class="uj-ar-flag">
                                    <span x-text="$store.ui.lang==='en' ? @js($p['offsite'].' punch'.($p['offsite'] === 1 ? '' : 'es').' landed off-site.') : @js($p['offsite'].' clock in di luar lokasi.')">{{ $p['offsite'] }} punch{{ $p['offsite'] === 1 ? '' : 'es' }} landed off-site.</span>
                                </div>
                            @endif
                        </span>

                        <span class="uj-ar-strip" role="img" aria-label="{{ $p['clocked'] }} of {{ $daysCount }} working days clocked, {{ $p['late'] }} late">
                            @foreach ($cells as $k => $cell)
                                @php
                                    $c = $stripChars[$k] ?? '-';
                                    $cellDateFormatted = Carbon::parse($cell)->format('j M');
                                    $cellTitleEn = $stripUnit === 'week' ? "Week of {$cellDateFormatted}" : $cellDateFormatted;
                                    $cellTitleMs = $stripUnit === 'week' ? "Minggu {$cellDateFormatted}" : $cellDateFormatted;
                                @endphp
                                <span class="uj-ar-cell" data-s="{{ $c }}" {!! $k === $cellsCount - 1 ? 'data-today' : '' !!}
                                      title="{{ $cellTitleEn }}" aria-label="{{ $cellTitleEn }}"
                                      x-bind:title="$store.ui.lang==='en' ? @js($cellTitleEn) : @js($cellTitleMs)"
                                      x-bind:aria-label="$store.ui.lang==='en' ? @js($cellTitleEn) : @js($cellTitleMs)"></span>
                            @endforeach
                        </span>

                        <span class="uj-ar-pct" data-p="{{ $pTone }}">
                            {{ $p['pct'] === null ? '—' : $p['pct'].'%' }}
                            <em>{{ $p['clocked'] }}/{{ $daysCount }} <span x-text="$store.ui.lang==='en' ? 'days' : 'hari'">days</span></em>
                        </span>
                    </a>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
