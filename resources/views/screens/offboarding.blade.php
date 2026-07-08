@extends('layouts.app')

@php
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';
    $reasonLabels = ['resignation' => 'Resignation', 'end_of_contract' => 'End of contract', 'termination' => 'Termination', 'retirement' => 'Retirement'];
    $reasonLabelsMs = ['resignation' => 'Peletakan jawatan', 'end_of_contract' => 'Tamat kontrak', 'termination' => 'Penamatan', 'retirement' => 'Persaraan'];
    $deptLabelsMs = ['IT' => 'IT', 'Finance' => 'Kewangan', 'HR' => 'HR', 'Manager' => 'Pengurus', 'Admin' => 'Pentadbiran'];
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'offboarding',
    'en'  => [
        'title' => 'Exit clearance',
        'body'  => 'Run the clearance checklist for a departing employee — making sure every department (IT, Finance, HR, Admin) signs off before their last day. The percentage bar shows how much is cleared so nothing is missed at handover.',
        'who'   => 'HR & management run clearance',
        'steps' => [
            'Click "Open case", pick the leaver, set their last day and the reason for leaving.',
            'A clearance checklist is created, grouped by department.',
            'Tick each item off as that department confirms it (return laptop, revoke access, final pay, etc.).',
            'Aim for 100% cleared before the last day — the countdown turns red when the exit is within a week.',
        ],
    ],
    'ms'  => [
        'title' => 'Pelepasan keluar',
        'body'  => 'Jalankan senarai semak pelepasan untuk pekerja yang akan keluar — pastikan setiap jabatan (IT, Kewangan, HR, Admin) mengesahkan sebelum hari terakhir mereka. Bar peratusan menunjukkan berapa banyak sudah dilepaskan supaya tiada yang tertinggal semasa serah tugas.',
        'who'   => 'HR & pengurusan jalankan pelepasan',
        'steps' => [
            'Klik "Open case", pilih pekerja yang keluar, tetapkan hari terakhir dan sebab keluar.',
            'Satu senarai semak pelepasan dicipta, dikumpulkan mengikut jabatan.',
            'Tanda setiap item apabila jabatan itu mengesahkannya (pulang laptop, batal akses, gaji akhir, dll.).',
            'Sasarkan 100% dilepaskan sebelum hari terakhir — kiraan detik bertukar merah apabila keluar dalam seminggu.',
        ],
    ],
])
@if (! $case && ! $privileged)
    @include('partials.empty-state', ['variantNote' => 'Offboarding'])
@else
<div x-data="{ open: {{ ($privileged && (! $case || $errors->any())) ? 'true' : 'false' }} }">

@if ($privileged)
    <div x-show="open" x-cloak class="uj-card" style="padding:20px;margin-bottom:16px;">
        <h3 class="uj-card-title" style="margin-bottom:14px;"><span x-text="$store.ui.lang==='en' ? 'Open an exit-clearance case' : 'Buka kes pelepasan keluar'">Open an exit-clearance case</span></h3>
        <form method="post" action="{{ route('offboarding.store') }}">
            @csrf
            @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:start;">
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Employee' : 'Pekerja'">Employee</span> *</label><select name="employee_id" required style="{{ $fs }}width:100%;"><option value="" x-text="$store.ui.lang==='en' ? 'Select…' : 'Pilih…'">Select…</option>@foreach ($employees as $e)<option value="{{ $e->id }}" @selected(old('employee_id') == $e->id)>{{ $e->name }}@if ($e->position) · {{ $e->position }}@endif</option>@endforeach</select></div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Last day' : 'Hari terakhir'">Last day</span> *</label><input name="last_day" type="date" value="{{ old('last_day') }}" required style="{{ $fs }}margin-bottom:6px;width:100%;" />@include('partials.hint', ['en' => 'Their final working day. The exit countdown and clearance deadline are measured against this date.', 'ms' => 'Hari kerja terakhir mereka. Kiraan detik keluar dan tarikh akhir pelepasan diukur berdasarkan tarikh ini.'])</div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Reason' : 'Sebab'">Reason</span> *</label><select name="reason" required style="{{ $fs }}margin-bottom:6px;width:100%;">@foreach ($reasonLabels as $val => $label)<option value="{{ $val }}" @selected(old('reason') === $val) x-text="$store.ui.lang==='en' ? @json($label) : @json($reasonLabelsMs[$val] ?? $label)">{{ $label }}</option>@endforeach</select>@include('partials.hint', ['en' => 'Why they are leaving. Resignation = they chose to go; End of contract = fixed term finished; Termination = ended by the company; Retirement = reached retirement age.', 'ms' => 'Sebab mereka keluar. Resignation = mereka pilih untuk berhenti; End of contract = tempoh tetap tamat; Termination = ditamatkan oleh syarikat; Retirement = mencapai umur persaraan.'])</div>
            </div>
            <div style="margin-top:12px;"><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Notes' : 'Nota'">Notes</span></label><textarea name="notes" rows="2" maxlength="2000" style="{{ $fs }}width:100%;height:auto;padding:9px 11px;resize:vertical;">{{ old('notes') }}</textarea></div>
            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;"><span x-text="$store.ui.lang==='en' ? 'Open case' : 'Buka kes'">Open case</span></button>
        </form>
    </div>
@endif

@if ($case)
    @php
        $cleared = $case->clearanceItems->where('done', true)->count();
        $totalItems = $case->clearanceItems->count();
        $pct = $totalItems ? (int) round($cleared / $totalItems * 100) : 0;
        $daysToExit = (int) round(now()->startOfDay()->diffInDays($case->last_day->copy()->startOfDay(), false));
        $exitLabel = $daysToExit > 0 ? $daysToExit.' days to exit' : ($daysToExit === 0 ? 'Last day today' : abs($daysToExit).' days since exit');
        $exitLabelMs = $daysToExit > 0 ? $daysToExit.' hari ke tarikh keluar' : ($daysToExit === 0 ? 'Hari terakhir hari ini' : abs($daysToExit).' hari sejak keluar');
        $exitColor = $daysToExit < 0 ? 'var(--muted)' : ($daysToExit <= 7 ? 'var(--red)' : ($daysToExit <= 14 ? 'var(--amber)' : 'var(--ink)'));
        $byDept = $case->clearanceItems->groupBy('department');
        $isCompleted = $case->status === 'completed';
        $outstanding = $totalItems - $cleared;
        $fromResignation = (bool) $case->resignation_id;
    @endphp

    <div class="uj-card" style="padding:24px;margin-bottom:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:48px;height:48px;border-radius:50%;background:{{ $case->employee?->avatar_color ?? '#c08532' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:600;">{{ $case->employee?->initials }}</div>
                <div>
                    <h3 style="font-size:17px;font-weight:600;color:var(--ink);margin:0;">{{ $case->employee?->name }}</h3>
                    <p style="font-size:12.5px;color:var(--muted);margin:3px 0 0;">{{ $case->employee?->position }} · <span x-text="$store.ui.lang==='en' ? 'Last day' : 'Hari terakhir'">Last day</span> {{ $case->last_day->format('j M Y') }} · <span x-text="$store.ui.lang==='en' ? @json($reasonLabels[$case->reason] ?? $case->reason) : @json($reasonLabelsMs[$case->reason] ?? ($reasonLabels[$case->reason] ?? $case->reason))">{{ $reasonLabels[$case->reason] ?? $case->reason }}</span>@if ($fromResignation) · <span style="font-size:10.5px;font-weight:600;color:var(--muted);background:var(--paper);border:1px solid var(--hairline);border-radius:999px;padding:1px 7px;"><span x-text="$store.ui.lang==='en' ? 'From resignation' : 'Dari perletakan'">From resignation</span></span>@endif</p>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:24px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ $pct }}%</div>
                <div style="font-size:11.5px;color:var(--muted);">{{ $cleared }}/{{ $totalItems }} <span x-text="$store.ui.lang==='en' ? 'cleared' : 'dilepaskan'">cleared</span></div>
                <div style="font-size:11.5px;font-weight:600;color:{{ $exitColor }};margin-top:3px;"><span x-text="$store.ui.lang==='en' ? @json($exitLabel) : @json($exitLabelMs)">{{ $exitLabel }}</span></div>
            </div>
        </div>
        @if ($case->notes)<p style="font-size:12.5px;color:var(--body);margin:14px 0 0;line-height:1.5;">{{ $case->notes }}</p>@endif
        <div class="uj-progress" style="height:7px;margin-top:16px;"><span style="width:{{ $pct }}%;"></span></div>
    </div>

    @if ($isCompleted)
        <div class="uj-card" style="padding:14px 18px;margin-bottom:16px;border-left:3px solid {{ $outstanding > 0 ? 'var(--red)' : 'var(--muted)' }};">
            <div style="font-size:13px;font-weight:600;color:var(--ink);">
                <span x-text="$store.ui.lang==='en' ? 'Departed · archived' : 'Telah keluar · diarkib'">Departed · archived</span>@if ($case->completed_at) {{ $case->completed_at->format('j M Y') }}@endif
            </div>
            @if ($outstanding > 0)
                <div style="font-size:12px;color:var(--red);margin-top:4px;"><span x-text="$store.ui.lang==='en' ? @json($outstanding.' item(s) were outstanding at archival') : @json($outstanding.' item belum selesai semasa diarkib')">{{ $outstanding }} item(s) were outstanding at archival</span></div>
            @endif
        </div>
    @endif

    <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
        @foreach ($byDept as $dept => $items)
            <div class="uj-card" style="flex:1;min-width:280px;padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:14px;"><span x-text="$store.ui.lang==='en' ? @json($dept.' clearance') : @json(($deptLabelsMs[$dept] ?? $dept).' — pelepasan')">{{ $dept }} clearance</span></h3>
                @foreach ($items as $it)
                    @php $boxStyle = 'width:20px;height:20px;border-radius:6px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:'.($it->done ? 'var(--success)' : '#fff').';border:1.5px solid '.($it->done ? 'var(--success)' : 'var(--hairline)').';'; @endphp
                    <div style="display:flex;align-items:center;gap:11px;padding:9px 0;border-bottom:1px solid var(--hairline-soft);">
                        @if ($privileged && ! $isCompleted)
                            <form method="post" action="{{ route('offboarding.toggle', $it) }}" style="line-height:0;">@csrf
                                <button type="submit" :aria-label="$store.ui.lang==='en' ? @json($it->done ? 'Reopen item' : 'Mark item cleared') : @json($it->done ? 'Buka semula item' : 'Tanda item dilepaskan')" aria-label="{{ $it->done ? 'Reopen item' : 'Mark item cleared' }}" style="{{ $boxStyle }}cursor:pointer;padding:0;">
                                    @if ($it->done)<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>@endif
                                </button>
                            </form>
                        @else
                            <div style="{{ $boxStyle }}">@if ($it->done)<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>@endif</div>
                        @endif
                        <span style="font-size:13.5px;color:{{ $it->done ? 'var(--muted)' : 'var(--ink)' }};{{ $it->done ? 'text-decoration:line-through;' : '' }}">{{ $it->title }}</span>
                        @if ($privileged && ! $isCompleted)
                            {{-- Remove an item added or seeded in error. Toggle stays the primary action; this is a quiet fix. --}}
                            <form method="post" action="{{ route('offboarding.items.remove', $it) }}" style="line-height:0;margin-left:auto;" @submit="if (! confirm($store.ui.lang==='en' ? 'Remove this clearance item?' : 'Buang item pelepasan ini?')) $event.preventDefault()">@csrf
                                <button type="submit" :aria-label="$store.ui.lang==='en' ? 'Remove item' : 'Buang item'" aria-label="Remove item" style="width:22px;height:22px;border-radius:6px;border:none;background:none;color:var(--muted-soft);cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"></path></svg>
                                </button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    {{-- Ad-hoc item: HR can append a clearance task the standard checklist misses,
         per case. A new department string becomes a new clearance column above. --}}
    @if ($privileged && ! $isCompleted)
        <div class="uj-card" style="padding:18px 20px;margin-top:16px;">
            <h3 class="uj-card-title" style="margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Add a clearance item' : 'Tambah item pelepasan'">Add a clearance item</span></h3>
            <form method="post" action="{{ route('offboarding.items.add', $case) }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                @csrf
                @php $deptOpts = ['IT' => ['IT', 'IT'], 'HR' => ['HR', 'HR'], 'Finance' => ['Finance', 'Kewangan'], 'Manager' => ['Manager', 'Pengurus'], 'Admin' => ['Admin', 'Pentadbiran']]; @endphp
                <div style="flex:0 0 190px;">
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Department' : 'Jabatan'">Department</span> *</label>
                    <select name="department" required style="{{ $fs }}width:100%;">@foreach ($deptOpts as $val => [$en, $ms])<option value="{{ $val }}" @selected(old('department') === $val) x-text="$store.ui.lang==='en' ? @json($en) : @json($ms)">{{ $en }}</option>@endforeach</select>
                </div>
                <div style="flex:1;min-width:220px;">
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Task' : 'Tugas'">Task</span> *</label>
                    <input name="title" required maxlength="120" value="{{ old('title') }}" placeholder="{{ __('Describe the clearance task') }}" style="{{ $fs }}width:100%;" />
                </div>
                <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Add item' : 'Tambah'">Add item</span></button>
            </form>
        </div>
    @endif
@elseif (! $privileged)
    @include('partials.empty-state', ['variantNote' => 'Offboarding'])
@endif

</div>
@endif
@endsection
