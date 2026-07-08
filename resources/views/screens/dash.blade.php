@extends('layouts.app')

@php
    use App\Support\Amanahku;
    $tag = [
        'assignment' => ['Assignment', 'var(--red)'],
        'task' => ['Task', 'var(--info)'],
        'adhoc' => ['Adhoc', 'var(--amber)'],
    ];
@endphp

@section('screen')

@include('partials.guide', [
    'key' => 'dash',
    'en'  => [
        'title' => 'Your home screen',
        'body'  => 'This is your daily starting point — it pulls together what needs your attention today: your clock-in status, leave balance, current work, pending requests and company announcements. Tap any card to jump to the full screen.',
    ],
    'ms'  => [
        'title' => 'Skrin utama anda',
        'body'  => 'Ini titik permulaan harian anda — ia kumpulkan apa yang perlu perhatian anda hari ini: status clock-in, baki cuti, kerja semasa, permohonan tertunggak dan pengumuman syarikat. Tekan mana-mana kad untuk pergi ke skrin penuh.',
    ],
])

@if (! empty($setupProgress) && ! $setupProgress['complete'])
    {{-- Company setup progress — only for admins until the wizard is finished. --}}
    <a href="{{ route('app.screen', 'setup') }}" style="text-decoration:none;display:block;margin-bottom:16px;">
        <div class="uj-card" style="padding:18px 22px;display:flex;align-items:center;gap:18px;border-left:3px solid var(--red);">
            <div style="width:46px;height:46px;border-radius:11px;background:var(--red-tint);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:14.5px;font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Finish setting up your workspace' : 'Lengkapkan persediaan ruang kerja anda'">Finish setting up your workspace</div>
                <div style="font-size:12.5px;color:var(--muted);margin-top:2px;">{{ $setupProgress['done'] }} / {{ $setupProgress['total'] }} <span x-text="$store.ui.lang==='en' ? 'steps done — continue the setup wizard' : 'langkah selesai — teruskan bestari persediaan'">steps done — continue the setup wizard</span></div>
                <div style="height:6px;border-radius:9999px;background:var(--hairline-soft);margin-top:8px;overflow:hidden;max-width:320px;">
                    <div style="height:100%;width:{{ $setupProgress['pct'] }}%;background:var(--red);border-radius:9999px;"></div>
                </div>
            </div>
            <div style="font-size:22px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ $setupProgress['pct'] }}%</div>
        </div>
    </a>
@endif

@if (! empty($stuckRequests) && $stuckRequests->count())
    {{-- Requests from staff with no reporting-line superior land in nobody's queue (AK-PROC-04).
         HR/management only — assign a superior in the org chart to route them. --}}
    <div class="uj-card" style="padding:18px 22px;margin-bottom:16px;border-left:3px solid var(--amber);">
        <div style="display:flex;align-items:flex-start;gap:14px;margin-bottom:10px;">
            <div style="width:40px;height:40px;border-radius:11px;background:rgba(214,158,46,.14);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><path d="M12 9v4M12 17h.01"></path></svg>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:14.5px;font-weight:600;color:var(--ink);"><span x-text="$store.ui.lang==='en' ? 'Requests stuck with no approver' : 'Permohonan tersekat tanpa pelulus'">Requests stuck with no approver</span> ({{ $stuckRequests->count() }})</div>
                <div style="font-size:12.5px;color:var(--muted);margin-top:2px;"><span x-text="$store.ui.lang==='en' ? 'These people have no reporting-line superior, so their submitted requests reach nobody. Assign a superior in the org chart to route them (or reject on the request screen).' : 'Orang ini tiada penyelia dalam carta organisasi, jadi permohonan mereka tidak sampai kepada sesiapa. Tetapkan penyelia dalam carta organisasi untuk menghalakannya (atau tolak di skrin permohonan).'">These people have no reporting-line superior, so their submitted requests reach nobody.</span></div>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;">
            @foreach ($stuckRequests->take(6) as $sr)
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:12.5px;padding:7px 0;border-top:1px solid var(--hairline-soft);">
                    <span style="color:var(--ink);">{{ $sr['type'] }} — {{ $sr['employee'] }}</span>
                    @if (! is_null($sr['ageDays']))<span style="color:var(--muted);font-family:var(--font-mono);">{{ $sr['ageDays'] }}d</span>@endif
                </div>
            @endforeach
            @if ($stuckRequests->count() > 6)
                <div style="font-size:12px;color:var(--muted);margin-top:6px;">+{{ $stuckRequests->count() - 6 }} <span x-text="$store.ui.lang==='en' ? 'more' : 'lagi'">more</span></div>
            @endif
        </div>
    </div>
@endif

{{-- Requests awaiting THIS user's verify/approve — shown for every persona (real
     obligations, not the previewed persona). Renders nothing when the queue is empty. --}}
@include('partials.action-needed')

@if ($persona === 'employee')
    {{-- ── EMPLOYEE ── --}}
    @if ($bdayToday->isNotEmpty())
        @php
            $bdayNames = $bdayToday->map(fn ($e) => \Illuminate\Support\Str::of($e->name)->squish()->explode(' ')->first())->implode(', ');
            $bdayOne = $bdayToday->count() === 1 ? $bdayToday->first() : null;
            $bdayFirst = $bdayOne ? \Illuminate\Support\Str::of($bdayOne->name)->squish()->explode(' ')->first() : null;
        @endphp
        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:16px;padding:14px 18px;border-radius:12px;background:linear-gradient(90deg,#fff3f4,#fff8ef);border:1px solid var(--hairline);">
            <span style="font-size:22px;line-height:1;">🎂</span>
            <div style="flex:1;min-width:200px;font-size:13.5px;color:var(--ink);">
                <b>{{ $bdayNames }}</b> — <span x-text="$store.ui.lang==='en' ? 'birthday today! Send a wish 🎉' : 'hari lahir hari ini! Hantar ucapan 🎉'">birthday today! Send a wish 🎉</span>
            </div>
            @if ($bdayOne)
                <a href="{{ route('app.screen', ['screen' => 'messages', 'to' => $bdayOne->id, 'draft' => 'Happy birthday, '.$bdayFirst.'! 🎉']) }}" class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:13px;display:inline-flex;align-items:center;text-decoration:none;"><span x-text="$store.ui.lang==='en' ? 'Wish now' : 'Ucap sekarang'">Wish now</span></a>
            @endif
        </div>
    @endif
    <div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap;">
        @php
            $ci = $todayAttendance?->clock_in ? \Illuminate\Support\Str::of($todayAttendance->clock_in)->limit(5, '') : null;
            $co = $todayAttendance?->clock_out ? \Illuminate\Support\Str::of($todayAttendance->clock_out)->limit(5, '') : null;
        @endphp
        <div class="uj-card" style="flex:1;min-width:200px;padding:18px;display:flex;align-items:center;gap:14px;">
            <div style="width:44px;height:44px;border-radius:10px;background:{{ $ci ? '#e7f4ee' : 'var(--red-tint)' }};display:flex;align-items:center;justify-content:center;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="{{ $ci ? 'var(--success)' : 'var(--red)' }}" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>
            </div>
            <div>
                @if ($co)
                    <div style="font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Clocked out' : 'Sudah clock-out'">Clocked out</span></div>
                    <div style="font-size:13px;font-weight:600;color:var(--ink);">{{ $ci }}–{{ $co }}</div>
                @elseif ($ci)
                    <div style="font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Clocked in' : 'Sudah clock-in'">Clocked in</span> · {{ $ci }}</div>
                    <a href="{{ route('app.screen', 'attendance') }}" style="font-size:13px;font-weight:600;color:var(--success);text-decoration:none;"><span x-text="$store.ui.lang==='en' ? 'Clock out →' : 'Clock-out →'">Clock out →</span></a>
                @else
                    <div style="font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Not clocked in' : 'Belum clock-in'">Not clocked in</span></div>
                    <a href="{{ route('app.screen', 'attendance') }}" style="font-size:13px;font-weight:600;color:var(--red);text-decoration:none;"><span x-text="$store.ui.lang==='en' ? 'Clock in now →' : 'Clock-in sekarang →'">Clock in now →</span></a>
                @endif
            </div>
        </div>
        <div class="uj-card uj-stat" style="flex:1;min-width:200px;">
            <div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Leave balance' : 'Baki cuti'">Leave balance</span></div>
            <div class="uj-stat-value">{{ $employee ? $employee->leaveBalances->sum('balance') : '—' }} <span style="font-size:13px;color:var(--muted-soft);"><span x-text="$store.ui.lang==='en' ? 'days' : 'hari'">days</span></span></div>
        </div>
        @if ($perfEnabled ?? true)
        <div class="uj-card uj-stat" style="flex:1;min-width:200px;">
            <div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'KPI progress · H1' : 'Progres KPI · H1'">KPI progress · H1</span></div>
            <div class="uj-stat-value">{{ $employee?->kpi_pct ?? '—' }}%</div>
        </div>
        @endif
    </div>

    @include('partials.knowledge-reminder')

    <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
        <div style="flex:2;min-width:340px;display:flex;flex-direction:column;gap:16px;">
            <div class="uj-card">
                <div class="uj-card-head">
                    <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'My current work' : 'Kerja semasa saya'">My current work</span></h3>
                    <a href="{{ route('app.screen', 'board') }}" class="uj-link" style="text-decoration:none;"><span x-text="$store.ui.lang==='en' ? 'View board →' : 'Lihat board →'">View board →</span></a>
                </div>
                @forelse ($workItems as $w)
                    @php [$tlabel, $tcolor] = $tag[$w->type] ?? ['Task', 'var(--info)']; $duec = \Illuminate\Support\Str::contains(strtolower($w->due_label ?? ''), ['today', 'tomorrow']) ? 'var(--error)' : 'var(--muted)'; @endphp
                    <div style="padding:14px 20px;border-bottom:1px solid var(--hairline-soft);display:flex;align-items:center;gap:14px;">
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;">
                                <span style="font-size:10.5px;font-weight:600;color:#fff;background:{{ $tcolor }};padding:2px 7px;border-radius:9999px;">{{ $tlabel }}</span>
                                <span style="font-size:12px;color:{{ $duec }};font-weight:500;">{{ $w->due_label }}</span>
                            </div>
                            <div style="font-size:14px;color:var(--ink);font-weight:500;">{{ $w->title }}</div>
                        </div>
                        <div style="width:90px;">
                            <div class="uj-progress"><span style="width:{{ $w->progress }}%;"></span></div>
                            <div style="font-size:11px;color:var(--muted);margin-top:4px;text-align:right;">{{ $w->progress }}%</div>
                        </div>
                    </div>
                @empty
                    <div style="padding:28px 20px;text-align:center;">
                        <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'Nothing on your plate right now' : 'Tiada kerja untuk anda sekarang'"></span></div>
                        <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'When tasks are assigned to you on the board, they show here with their progress. Open the board to pick something up.' : 'Bila tugasan diberi kepada anda di board, ia akan muncul di sini dengan progresnya. Buka board untuk ambil kerja.'"></span></div>
                    </div>
                @endforelse
            </div>

            <div class="uj-card" style="padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:14px;"><span x-text="$store.ui.lang==='en' ? 'Recent achievements' : 'Pencapaian terkini'">Recent achievements</span></h3>
                @foreach ($achievements as $a)
                    <div style="display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--hairline-soft);">
                        <div style="width:34px;height:34px;border-radius:50%;background:{{ $a->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;flex-shrink:0;">{{ $a->employee?->initials ?? '★' }}</div>
                        <div>
                            <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $a->title }}</div>
                            <div style="font-size:12px;color:var(--muted);margin-top:1px;">{{ $a->who }} · {{ $a->date?->diffForHumans() ?? $a->date_label }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div style="flex:1;min-width:280px;display:flex;flex-direction:column;gap:16px;">
            <div class="uj-card" style="padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Announcements' : 'Pengumuman'">Announcements</span></h3>
                @foreach ($announcements as $a)
                    <div style="padding:10px 0;border-bottom:1px solid var(--hairline-soft);">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px;">
                            <span style="font-size:10px;font-weight:600;color:var(--muted);background:var(--hairline-soft);padding:2px 7px;border-radius:9999px;">{{ $a->tag }}</span>
                            <span style="font-size:11px;color:var(--muted-soft);">{{ $a->date->format('d M Y') }}</span>
                        </div>
                        <div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $a->title }}</div>
                    </div>
                @endforeach
            </div>

            <div class="uj-card" style="padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Pending requests' : 'Permohonan tertunggak'">Pending requests</span></h3>
                @forelse ($pendingRequests as $r)
                    @php $sc = ['approved' => 'var(--success)', 'submitted' => 'var(--amber)', 'rejected' => 'var(--error)', 'draft' => 'var(--muted)'][$r->status] ?? 'var(--muted)'; @endphp
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--hairline-soft);">
                        <span style="font-size:13px;color:var(--ink);">{{ $r->leaveType?->name ?? 'Leave' }} · {{ $r->date_from->format('j M') }}–{{ $r->date_to->format('j M') }}</span>
                        <span style="font-size:11px;font-weight:600;color:{{ $sc }};">{{ ucfirst($r->status) }}</span>
                    </div>
                @empty
                    <div style="padding:14px 0;text-align:center;">
                        <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:2px;"><span x-text="$store.ui.lang==='en' ? 'No requests waiting' : 'Tiada permohonan menunggu'"></span></div>
                        <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Leave or other requests you submit will appear here until they are approved or rejected.' : 'Permohonan cuti atau lain yang anda hantar akan muncul di sini sehingga ia diluluskan atau ditolak.'"></span></div>
                    </div>
                @endforelse
            </div>

            <div class="uj-card" style="padding:20px;">
                <div class="uj-card-head" style="padding:0 0 12px;border:0;">
                    <h3 class="uj-card-title" style="margin:0;"><span x-text="$store.ui.lang==='en' ? 'People this month' : 'Orang bulan ini'">People this month</span></h3>
                    <a href="{{ route('app.screen', 'calendar') }}" class="uj-link" style="text-decoration:none;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Calendar →' : 'Kalendar →'">Calendar →</span></a>
                </div>
                @include('partials.people-pulse')
            </div>
        </div>
    </div>

@elseif ($persona === 'manager')
    {{-- ── MANAGER ── --}}
    <div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap;">
        @foreach ($mgrStats as $s)
            <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label">{{ $s['k'] }}</div><div class="uj-stat-value" style="color:{{ $s['c'] }};">{{ $s['v'] }}</div></div>
        @endforeach
    </div>
    <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
        <div class="uj-card" style="flex:2;min-width:380px;">
            <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Team status' : 'Status pasukan'">Team status</span></h3></div>
            <div style="display:grid;grid-template-columns:1.6fr .8fr .9fr;gap:8px;padding:10px 20px;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid var(--hairline-soft);"><span x-text="$store.ui.lang==='en' ? 'Member' : 'Ahli'">Member</span><span x-text="$store.ui.lang==='en' ? 'Today' : 'Hari ini'">Today</span><span x-text="$store.ui.lang==='en' ? 'Workload' : 'Beban kerja'">Workload</span></div>
            @foreach ($team as $m)
                <div style="display:grid;grid-template-columns:1.6fr .8fr .9fr;gap:8px;padding:12px 20px;border-bottom:1px solid var(--hairline-soft);align-items:center;">
                    <div style="display:flex;align-items:center;gap:10px;min-width:0;"><div style="width:30px;height:30px;border-radius:50%;background:{{ $m->avatar_color }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $m->initials }}</div><span style="font-size:13px;color:var(--ink);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $m->name }}</span></div>
                    <span style="font-size:12.5px;font-weight:500;color:{{ $m->status === 'on_leave' ? 'var(--muted-soft)' : 'var(--success)' }};">@if ($m->status === 'on_leave')<span x-text="$store.ui.lang==='en' ? 'Leave' : 'Cuti'">Leave</span>@else<span x-text="$store.ui.lang==='en' ? 'In' : 'Hadir'">In</span>@endif</span>
                    <span style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--body);"><span style="width:9px;height:9px;border-radius:50%;background:{{ Amanahku::SWATCH[$m->workload] }};"></span>{{ $m->workload_label }}</span>
                </div>
            @endforeach
        </div>
        <div style="flex:1;min-width:280px;background:var(--sidebar);border-radius:12px;padding:20px;color:#fff;">
            <div style="display:flex;align-items:center;gap:9px;margin-bottom:14px;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9.5 13.9 11.4 12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"></path></svg><h3 style="font-size:15px;font-weight:600;color:#fff;margin:0;"><span x-text="$store.ui.lang==='en' ? 'AI recommendations' : 'Cadangan AI'">AI recommendations</span></h3></div>
            @include('partials.recs')
        </div>
    </div>

@elseif ($persona === 'management')
    {{-- ── MANAGEMENT ── --}}
    <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
        <div class="uj-card" style="flex:2;min-width:380px;padding:20px;">
            <h3 class="uj-card-title" style="margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Department capacity' : 'Kapasiti jabatan'">Department capacity</span></h3>
            <p style="font-size:12.5px;color:var(--muted);margin:0 0 18px;"><span x-text="$store.ui.lang==='en' ? 'Assigned load vs. available capacity, this week.' : 'Beban diberi berbanding kapasiti tersedia, minggu ini.'">Assigned load vs. available capacity, this week.</span></p>
            @foreach ($deptCap as $d)
                <div style="margin-bottom:14px;"><div style="display:flex;justify-content:space-between;margin-bottom:5px;"><span style="font-size:13px;color:var(--ink);font-weight:500;">{{ $d['name'] }} <span style="color:var(--muted-soft);font-weight:400;">· {{ $d['head'] }} <span x-text="$store.ui.lang==='en' ? 'staff' : 'staf'">staff</span></span></span><span style="font-size:12.5px;font-weight:600;color:{{ Amanahku::SWATCH[$d['color']] }};font-family:var(--font-mono);">{{ $d['cap'] }}%</span></div><div style="height:8px;background:var(--hairline);border-radius:9999px;overflow:hidden;"><div style="height:100%;width:{{ $d['cap'] }}%;background:{{ Amanahku::SWATCH[$d['color']] }};"></div></div></div>
            @endforeach
        </div>
        <div style="flex:1;min-width:280px;display:flex;flex-direction:column;gap:16px;">
            <div class="uj-card" style="padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Operational risks' : 'Risiko operasi'">Operational risks</span></h3>
                @foreach ($risks as $r)
                    <div style="display:flex;gap:10px;padding:10px 0;border-bottom:1px solid var(--hairline-soft);"><span style="font-size:9.5px;font-weight:700;color:{{ Amanahku::SWATCH[$r['sevc']] }};background:{{ $r['sevc'] === 'red' ? 'var(--red-tint)' : '#fbf3e6' }};padding:3px 7px;border-radius:9999px;height:fit-content;white-space:nowrap;">{{ $r['sev'] }}</span><span style="font-size:12.5px;color:var(--ink);line-height:1.4;">{{ $r['t'] }}</span></div>
                @endforeach
            </div>
            <div style="background:var(--sidebar);border-radius:12px;padding:20px;color:#fff;">
                <h3 style="font-size:14px;font-weight:600;color:#fff;margin:0 0 10px;"><span x-text="$store.ui.lang==='en' ? 'What management should do next' : 'Apa pengurusan patut buat seterusnya'">What management should do next</span></h3>
                <p style="font-size:12.5px;color:#b8b6ad;line-height:1.55;margin:0 0 12px;"><span x-text="$store.ui.lang==='en' ? 'Operations and Logistics have run over capacity for 3 weeks. Approving 2 contract hires or redistributing 6 assignments brings both below 85%.' : 'Operasi dan Logistik telah melebihi kapasiti selama 3 minggu. Meluluskan 2 pengambilan kontrak atau mengagih semula 6 tugasan menurunkan kedua-duanya di bawah 85%.'">Operations and Logistics have run over capacity for 3 weeks. Approving 2 contract hires or redistributing 6 assignments brings both below 85%.</span></p>
                <a href="{{ route('app.screen', 'workload') }}" class="uj-btn-primary" style="display:block;text-align:center;font-size:12.5px;padding:9px;text-decoration:none;"><span x-text="$store.ui.lang==='en' ? 'Open full intelligence view →' : 'Buka paparan perisikan penuh →'">Open full intelligence view →</span></a>
            </div>
        </div>
    </div>

@else
    {{-- ── HR ── --}}
    <div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap;">
        @foreach ($hrStats as $s)
            <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label">{{ $s['k'] }}</div><div class="uj-stat-value" style="margin:2px 0;">{{ $s['v'] }}</div><div style="font-size:11.5px;color:{{ $s['subc'] }};">{{ $s['sub'] }}</div></div>
        @endforeach
    </div>
    <div class="uj-card" style="padding:20px;">
        <h3 class="uj-card-title" style="margin-bottom:14px;"><span x-text="$store.ui.lang==='en' ? 'Onboarding in progress' : 'Onboarding sedang berjalan'">Onboarding in progress</span></h3>
        @forelse ($onboarding as $o)
            <div style="margin-bottom:16px;"><div style="display:flex;justify-content:space-between;margin-bottom:5px;"><span style="font-size:13px;color:var(--ink);">{{ $o->employee?->name }} · {{ $o->employee?->position }}</span><span style="font-size:12px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Day' : 'Hari'">Day</span> {{ $o->day_number }}/{{ $o->total_days }}</span></div><div class="uj-progress" style="height:7px;"><span style="width:{{ round($o->day_number / max($o->total_days,1) * 100) }}%;"></span></div></div>
        @empty
            <div style="padding:14px 0;text-align:center;">
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:2px;"><span x-text="$store.ui.lang==='en' ? 'No one is onboarding right now' : 'Tiada sesiapa dalam onboarding sekarang'"></span></div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'When a new hire is added, their onboarding progress (Day X of 90) shows here.' : 'Bila pekerja baharu ditambah, progres onboarding mereka (Hari X daripada 90) akan muncul di sini.'"></span></div>
            </div>
        @endforelse
    </div>

    <div class="uj-card" style="padding:20px;margin-top:16px;" x-data="{ post: {{ $errors->any() ? 'true' : 'false' }} }">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
            <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Company announcements' : 'Pengumuman syarikat'">Company announcements</span></h3>
            <button @click="post = ! post" class="uj-btn-primary" style="height:32px;padding:0 13px;font-size:12px;"><span x-text="post ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Post' : '+ Pos')"></span></button>
        </div>
        <form method="post" action="{{ route('announcements.store') }}" x-show="post" x-cloak style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;">
            @csrf
            @if ($errors->any())<div style="flex-basis:100%;background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
            <input name="title" required maxlength="160" :placeholder="$store.ui.lang==='en' ? 'What is the news?' : 'Apa beritanya?'" style="flex:2;min-width:240px;height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;color:var(--ink);" />
            <input name="tag" maxlength="40" :placeholder="$store.ui.lang==='en' ? 'Tag (e.g. Policy)' : 'Tag (cth. Polisi)'" style="flex:1;min-width:130px;height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;color:var(--ink);" />
            <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Post' : 'Hantar'">Post</span></button>
        </form>
        @forelse ($announcements as $a)
            <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--hairline-soft);">
                <span style="font-size:10px;font-weight:600;color:var(--muted);background:var(--hairline-soft);padding:2px 8px;border-radius:9999px;white-space:nowrap;">{{ $a->tag }}</span>
                <span style="flex:1;font-size:13px;color:var(--ink);font-weight:500;">{{ $a->title }}</span>
                <span style="font-size:11.5px;color:var(--muted-soft);font-family:var(--font-mono);white-space:nowrap;">{{ $a->date->format('d M') }}</span>
            </div>
        @empty
            <div style="padding:14px 0;text-align:center;">
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:2px;"><span x-text="$store.ui.lang==='en' ? 'No announcements yet' : 'Tiada pengumuman lagi'"></span></div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Use the + Post button above to share company news — everyone sees it on their home screen.' : 'Guna butang + Post di atas untuk kongsi berita syarikat — semua orang akan nampak di skrin utama mereka.'"></span></div>
            </div>
        @endforelse
    </div>
@endif
@endsection
