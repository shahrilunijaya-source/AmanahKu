@extends('layouts.app')

@php
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';
    $privileged = $privileged ?? false;
    $trackOpts = ['general' => ['General onboarding', 'Onboarding umum'], 'position' => ['Position-specific', 'Khusus jawatan']];
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'onboarding',
    'en'  => [
        'title' => 'Onboarding checklist',
        'body'  => 'Guides a new hire through their first weeks — a checklist split into general onboarding (paperwork, EPF/SOCSO, tour) and position-specific tasks. The progress bar and "Day X of 90" show how settled in they are.',
        'who'   => 'New hire & HR/managers tick off tasks',
        'steps' => [
            'Each task has a checkbox — click it to mark it complete (or click again to reopen it).',
            'Work through both columns: General onboarding and Position-specific.',
            'The percentage and "Day X of" counter update as tasks are completed.',
            'The new hire can tick their own tasks; managers and HR can tick anyone\'s.',
        ],
    ],
    'ms'  => [
        'title' => 'Senarai semak onboarding',
        'body'  => 'Membimbing pekerja baharu sepanjang minggu pertama mereka — senarai semak yang dibahagikan kepada onboarding umum (dokumen, EPF/SOCSO, lawatan) dan tugas khusus jawatan. Bar kemajuan dan "Day X of 90" menunjukkan sejauh mana mereka sudah selesa.',
        'who'   => 'Pekerja baharu & HR/pengurus tanda tugas',
        'steps' => [
            'Setiap tugas ada kotak semak — klik untuk tanda siap (atau klik semula untuk buka semula).',
            'Selesaikan kedua-dua lajur: General onboarding dan Position-specific.',
            'Peratusan dan kaunter "Day X of" dikemas kini apabila tugas disiapkan.',
            'Pekerja baharu boleh tanda tugas sendiri; pengurus dan HR boleh tanda tugas sesiapa sahaja.',
        ],
    ],
])
@if (! $profile && ! $privileged)
    @include('partials.empty-state', ['variantNote' => 'Onboarding'])
@else
<div x-data="{ open: {{ ($privileged && (! $profile || $errors->any())) ? 'true' : 'false' }} }">

@if ($privileged)
    {{-- Start onboarding: opens a profile for a new hire and seeds the standard checklist.
         Idempotent server-side — re-submitting for someone already onboarding is a no-op. --}}
    <div x-show="open" x-cloak class="uj-card" style="padding:20px;margin-bottom:16px;">
        <h3 class="uj-card-title" style="margin-bottom:14px;"><span x-text="$store.ui.lang==='en' ? 'Start onboarding for a new hire' : 'Mulakan onboarding pekerja baharu'">Start onboarding for a new hire</span></h3>
        <form method="post" action="{{ route('onboarding.start') }}">
            @csrf
            @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:start;">
                <div>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'New hire' : 'Pekerja baharu'">New hire</span> *</label>
                    <select name="employee_id" required style="{{ $fs }}width:100%;"><option value="" x-text="$store.ui.lang==='en' ? 'Select…' : 'Pilih…'">Select…</option>@foreach ($employees as $e)<option value="{{ $e->id }}" @selected(old('employee_id') == $e->id)>{{ $e->name }}@if ($e->position) · {{ $e->position }}@endif</option>@endforeach</select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Start date' : 'Tarikh mula'">Start date</span> *</label>
                    <input name="start_date" type="date" value="{{ old('start_date') }}" required style="{{ $fs }}width:100%;" />
                </div>
                <div>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Mentor' : 'Mentor'">Mentor</span></label>
                    <select name="mentor_id" style="{{ $fs }}width:100%;"><option value="" x-text="$store.ui.lang==='en' ? 'None' : 'Tiada'">None</option>@foreach ($employees as $e)<option value="{{ $e->id }}" @selected(old('mentor_id') == $e->id)>{{ $e->name }}</option>@endforeach</select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Manager' : 'Pengurus'">Manager</span></label>
                    <select name="manager_id" style="{{ $fs }}width:100%;"><option value="" x-text="$store.ui.lang==='en' ? 'None' : 'Tiada'">None</option>@foreach ($employees as $e)<option value="{{ $e->id }}" @selected(old('manager_id') == $e->id)>{{ $e->name }}</option>@endforeach</select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Onboarding window (days)' : 'Tempoh onboarding (hari)'">Onboarding window (days)</span></label>
                    <input name="total_days" type="number" min="1" max="365" value="{{ old('total_days', 90) }}" style="{{ $fs }}width:100%;" />
                </div>
            </div>
            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;"><span x-text="$store.ui.lang==='en' ? 'Start onboarding' : 'Mulakan onboarding'">Start onboarding</span></button>
        </form>
    </div>
@endif

@if ($profile)
@php
    $done = $profile->tasks->where('done', true)->count();
    $totalT = $profile->tasks->count();
    $pct = $totalT ? round($done / $totalT * 100) : 0;
    $canToggle = in_array($role, ['manager', 'management', 'hr'], true) || ($employee && $profile->employee_id === $employee->id);
@endphp
<div class="uj-card" style="padding:24px;margin-bottom:16px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:14px;">
            <div style="width:48px;height:48px;border-radius:50%;background:{{ $profile->employee?->avatar_color ?? '#c08532' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:600;">{{ $profile->employee?->initials }}</div>
            <div>
                <h3 style="font-size:17px;font-weight:600;color:var(--ink);margin:0;">{{ $profile->employee?->name }}</h3>
                <p style="font-size:12.5px;color:var(--muted);margin:3px 0 0;">{{ $profile->employee?->position }} · <span x-text="$store.ui.lang==='en' ? 'Day' : 'Hari'">Day</span> {{ $profile->day_number }} <span x-text="$store.ui.lang==='en' ? 'of' : 'daripada'">of</span> {{ $profile->total_days }}@if ($profile->mentor) · <span x-text="$store.ui.lang==='en' ? 'Mentor:' : 'Mentor:'">Mentor:</span> {{ $profile->mentor?->name }}@endif</p>
            </div>
        </div>
        <div style="text-align:right;"><div style="font-size:24px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ $pct }}%</div><div style="font-size:11.5px;color:var(--muted);">{{ $done }}/{{ $totalT }} <span x-text="$store.ui.lang==='en' ? 'complete' : 'siap'">complete</span></div></div>
    </div>
    <div class="uj-progress" style="height:7px;margin-top:16px;"><span style="width:{{ $pct }}%;"></span></div>
</div>

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    @foreach ($trackOpts as $track => [$label, $labelMs])
        <div class="uj-card" style="flex:1;min-width:300px;padding:20px;">
            <h3 class="uj-card-title" style="margin-bottom:14px;"><span x-text="$store.ui.lang==='en' ? @json($label) : @json($labelMs)">{{ $label }}</span></h3>
            @foreach ($profile->tasks->where('track', $track) as $t)
                @php $boxStyle = 'width:20px;height:20px;border-radius:6px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:'.($t->done ? 'var(--success)' : '#fff').';border:1.5px solid '.($t->done ? 'var(--success)' : 'var(--hairline)').';'; @endphp
                <div style="display:flex;align-items:center;gap:11px;padding:9px 0;border-bottom:1px solid var(--hairline-soft);">
                    @if ($canToggle)
                        <form method="post" action="{{ route('onboarding.toggle', $t) }}" style="line-height:0;">@csrf
                            <button type="submit" :aria-label="$store.ui.lang==='en' ? @json($t->done ? 'Reopen task' : 'Mark task complete') : @json($t->done ? 'Buka semula tugas' : 'Tanda tugas siap')" aria-label="{{ $t->done ? 'Reopen task' : 'Mark task complete' }}" style="{{ $boxStyle }}cursor:pointer;padding:0;">
                                @if ($t->done)<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>@endif
                            </button>
                        </form>
                    @else
                        <div style="{{ $boxStyle }}">@if ($t->done)<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>@endif</div>
                    @endif
                    <span style="font-size:13.5px;color:{{ $t->done ? 'var(--muted)' : 'var(--ink)' }};{{ $t->done ? 'text-decoration:line-through;' : '' }}">{{ $t->title }}</span>
                    @if ($privileged)
                        {{-- Remove a task added or seeded in error. Toggle stays primary; this is a quiet fix. --}}
                        <form method="post" action="{{ route('onboarding.tasks.remove', $t) }}" style="line-height:0;margin-left:auto;" @submit="if (! confirm($store.ui.lang==='en' ? 'Remove this onboarding task?' : 'Buang tugas onboarding ini?')) $event.preventDefault()">@csrf
                            <button type="submit" :aria-label="$store.ui.lang==='en' ? 'Remove task' : 'Buang tugas'" aria-label="Remove task" style="width:22px;height:22px;border-radius:6px;border:none;background:none;color:var(--muted-soft);cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"></path></svg>
                            </button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
</div>

@if ($privileged)
    {{-- Ad-hoc task: HR/manager appends a checklist item the standard template misses. --}}
    <div class="uj-card" style="padding:18px 20px;margin-top:16px;">
        <h3 class="uj-card-title" style="margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Add an onboarding task' : 'Tambah tugas onboarding'">Add an onboarding task</span></h3>
        <form method="post" action="{{ route('onboarding.tasks.add', $profile) }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            @csrf
            <div style="flex:0 0 190px;">
                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Track' : 'Trek'">Track</span> *</label>
                <select name="track" required style="{{ $fs }}width:100%;">@foreach ($trackOpts as $val => [$en, $ms])<option value="{{ $val }}" @selected(old('track') === $val) x-text="$store.ui.lang==='en' ? @json($en) : @json($ms)">{{ $en }}</option>@endforeach</select>
            </div>
            <div style="flex:1;min-width:220px;">
                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Task' : 'Tugas'">Task</span> *</label>
                <input name="title" required maxlength="120" value="{{ old('title') }}" placeholder="{{ __('Describe the onboarding task') }}" style="{{ $fs }}width:100%;" />
            </div>
            <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Add task' : 'Tambah'">Add task</span></button>
        </form>
    </div>
@endif
@endif

</div>
@endif
@endsection
