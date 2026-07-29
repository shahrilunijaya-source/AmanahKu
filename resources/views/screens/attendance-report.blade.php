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
            'body' => 'See how punctual the workforce is over time, then click any staff member to drill into their daily record. Use the period buttons to switch between this week, the last 30 days, or the last 90 days, and filter by department.',
            'who' => 'Management and HR only',
            'steps' => [
                'Pick a period and (optionally) a department at the top.',
                'The shelf summarises overall attendance coverage and non-clocking staff.',
                'Review each staff member’s daily attendance strip and punctuality rate.',
                'Click a staff row to open their day-by-day attendance.',
            ],
        ],
        'ms' => [
            'title' => 'Laporan Kehadiran',
            'body' => 'Lihat ketepatan masa tenaga kerja dari masa ke masa, kemudian klik mana-mana staf untuk perincikan rekod harian mereka. Guna butang tempoh untuk tukar antara minggu ini, 30 hari lepas, atau 90 hari lepas, dan tapis mengikut jabatan.',
            'who' => 'Pengurusan dan HR sahaja',
            'steps' => [
                'Pilih tempoh dan (pilihan) jabatan di bahagian atas.',
                'Bahagian atas meringkaskan liputan kehadiran dan staf yang tidak melapor.',
                'Semak jalur kehadiran harian dan kadar ketepatan masa setiap staf.',
                'Klik baris staf untuk buka kehadiran harian mereka.',
            ],
        ],
    ])

    @if ($drill)
        {{-- ── Single-staff drill-down (UNTOUCHED) ────────────────────────── --}}
        <div class="uj-card">
            <div class="uj-card-head" style="display:flex;align-items:center;gap:12px;">
                <div class="uj-ar-av" style="background:{{ $drill->avatar_color }};">{{ $drill->initials }}</div>
                <div style="min-width:0;">
                    <h3 class="uj-card-title" style="margin:0;">{{ $drill->name }}</h3>
                    <div style="font-size:11.5px;color:var(--muted);">{{ trim(($drill->position ?? '').' · '.($drill->department?->name ?? ''), ' ·') }}</div>
                </div>
                <a href="{{ $baseUrl }}" class="uj-btn-ghost" style="margin-left:auto;font-size:12px;padding:7px 12px;text-decoration:none;">
                    <span x-text="$store.ui.lang==='en' ? '← All staff' : '← Semua staf'">← All staff</span>
                </a>
            </div>

            <div class="uj-ar-drow uj-ar-dhead" style="background:none;">
                <span x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</span>
                <span style="text-align:right;">In</span>
                <span style="text-align:right;" class="uj-ar-hide-sm">Out</span>
                <span style="text-align:right;" class="uj-ar-hide-sm"><span x-text="$store.ui.lang==='en' ? 'Worked' : 'Bekerja'">Worked</span></span>
                <span style="text-align:right;"><span x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</span></span>
                <span style="text-align:right;"><span x-text="$store.ui.lang==='en' ? 'Flags' : 'Tanda'">Flags</span></span>
            </div>
            @forelse ($drillRecords as $r)
                @php
                    $rin = $r->clock_in ? Str::of($r->clock_in)->limit(5, '') : '—';
                    $rout = $r->clock_out ? Str::of($r->clock_out)->limit(5, '') : '—';
                    $wm = (int) ($r->worked_minutes ?? 0);
                    $worked = $wm > 0 ? intdiv($wm, 60).'h'.($wm % 60 ? ($wm % 60).'m' : '') : '—';
                    $sl = $stLabel[$r->status] ?? [$r->status, $r->status];
                @endphp
                <div class="uj-ar-drow" style="cursor:default;">
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
                    @php $notes = array_filter(['in' => $r->clock_in_justification, 'out' => $r->clock_out_justification]); @endphp
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
        <div class="uj-ar-sect">
            <h2><span x-text="$store.ui.lang==='en' ? 'Everyone' : 'Semua staf'">Everyone</span></h2>
            <span class="sum"><span x-text="$store.ui.lang==='en' ? @js(count($days) . ' working days · needs attention first') : @js(count($days) . ' hari bekerja · perlu perhatian dahulu')">{{ count($days) }} working days · needs attention first</span></span>
        </div>

        <div class="uj-ar-legend">
            <span><b style="background:var(--success);"></b><span x-text="$store.ui.lang==='en' ? 'On time' : 'Tepat masa'">On time</span></span>
            <span><b style="background:var(--amber);"></b><span x-text="$store.ui.lang==='en' ? 'Late' : 'Lewat'">Late</span></span>
            <span><b style="background:var(--error);"></b><span x-text="$store.ui.lang==='en' ? 'Off-site' : 'Luar lokasi'">Off-site</span></span>
            <span><b style="background:#c3d5e6;"></b><span x-text="$store.ui.lang==='en' ? 'Approved leave' : 'Cuti diluluskan'">Approved leave</span></span>
            <span><b style="background:#dedbd2;"></b><span x-text="$store.ui.lang==='en' ? 'No record' : 'Tiada rekod'">No record</span></span>
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
