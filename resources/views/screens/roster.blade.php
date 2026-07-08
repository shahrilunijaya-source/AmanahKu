@extends('layouts.app')

@php
    $sc = ['scheduled' => 'var(--info)', 'confirmed' => 'var(--success)', 'cancelled' => 'var(--muted-soft)'];
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';
    $fmtTime = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('g:ia') : '—';
@endphp

@section('screen')
@include('partials.guide', [
    'key'   => 'roster',
    'en'  => [
        'title' => 'Weekly roster',
        'body'  => 'Plan who works which shift across the week and branches. Staff see only their own upcoming shifts; managers and HR build the full grid and assign shifts.',
        'who'   => 'Managers & HR assign · Staff view their shifts',
        'steps' => [
            'Click "+ Assign shift", then pick the employee and the day.',
            'Set the start and end time, and the location or role (e.g. "PJ HQ" or "Front desk").',
            'Leave status as Scheduled, or set Confirmed once the staff member has agreed.',
            'Assigned shifts fill the grid below. Use "Cancel" on a shift to drop it.',
        ],
    ],
    'ms'  => [
        'title' => 'Roster mingguan',
        'body'  => 'Rancang siapa kerja shift mana sepanjang minggu dan di cawangan mana. Staf nampak shift mereka sendiri sahaja; pengurus dan HR bina grid penuh dan tetapkan shift.',
        'who'   => 'Pengurus & HR tetapkan · Staf lihat shift mereka',
        'steps' => [
            'Klik "+ Assign shift", kemudian pilih pekerja dan harinya.',
            'Tetapkan masa mula dan tamat, dan lokasi atau tugas (cth. "PJ HQ" atau "Front desk").',
            'Biarkan status sebagai Scheduled, atau tukar Confirmed setelah staf bersetuju.',
            'Shift yang ditetapkan mengisi grid di bawah. Guna "Cancel" pada shift untuk membatalkannya.',
        ],
    ],
])
@if ($privileged)
<div x-data="{ add: {{ $errors->any() ? 'true' : 'false' }} }">
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
        <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Week of' : 'Minggu'">Week of</span></div><div class="uj-stat-value" style="font-size:18px;">{{ $weekStart->format('j M') }} – {{ $weekEnd->format('j M') }}</div></div>
        <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Active shifts' : 'Shift aktif'">Active shifts</span></div><div class="uj-stat-value" style="color:var(--info);">{{ $weekCount }}</div></div>
        <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Staff rostered' : 'Staf dijadualkan'">Staff rostered</span></div><div class="uj-stat-value">{{ $grid->count() }}</div></div>
    </div>

    <div x-show="add" x-cloak class="uj-card" style="padding:20px;margin-bottom:16px;">
        <h3 class="uj-card-title" style="margin-bottom:14px;"><span x-text="$store.ui.lang==='en' ? 'Assign shift' : 'Tetapkan shift'">Assign shift</span></h3>
        <form method="post" action="{{ route('roster.store') }}">
            @csrf
            @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:start;">
                <div>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Employee *' : 'Pekerja *'">Employee *</span></label>
                    <select name="employee_id" required style="{{ $fs }}width:100%;">
                        <option value="" x-text="$store.ui.lang==='en' ? 'Select…' : 'Pilih…'">Select…</option>
                        @foreach ($employees as $e)
                            <option value="{{ $e->id }}" @selected((string) old('employee_id') === (string) $e->id)>{{ $e->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Date *' : 'Tarikh *'">Date *</span></label><input type="date" name="date" value="{{ old('date', $weekStart->toDateString()) }}" required style="{{ $fs }}width:100%;" /></div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Start *' : 'Mula *'">Start *</span></label><input type="time" name="start_time" value="{{ old('start_time', '09:00') }}" required style="{{ $fs }}width:100%;margin-bottom:6px;" />@include('partials.hint', ['en' => 'Standard day is 09:00–18:00. Adjust for split or part-time shifts.', 'ms' => 'Hari biasa ialah 09:00–18:00. Laraskan untuk shift berpecah atau separuh masa.'])</div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'End *' : 'Tamat *'">End *</span></label><input type="time" name="end_time" value="{{ old('end_time', '18:00') }}" required style="{{ $fs }}width:100%;margin-bottom:6px;" />@include('partials.hint', ['en' => 'Must be after the start time. Avoid double-booking the same person on overlapping shifts.', 'ms' => 'Mesti selepas masa mula. Elak booking orang yang sama dua kali pada shift bertindih.'])</div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Location / role *' : 'Lokasi / tugas *'">Location / role *</span></label><input name="location" value="{{ old('location') }}" required maxlength="120" :placeholder="$store.ui.lang==='en' ? 'e.g. PJ HQ' : 'cth. PJ HQ'" style="{{ $fs }}width:100%;margin-bottom:6px;" />@include('partials.hint', ['en' => 'Where they work or the duty for that shift — e.g. "PJ HQ", "Client site", "Front desk".', 'ms' => 'Di mana mereka kerja atau tugas untuk shift itu — cth. "PJ HQ", "Client site", "Front desk".'])</div>
                <div>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;">Status</label>
                    <select name="status" style="{{ $fs }}width:100%;">
                        @php $statusLabelsMs = ['scheduled' => 'Dijadualkan', 'confirmed' => 'Disahkan']; @endphp
                        @foreach (['scheduled' => 'Scheduled', 'confirmed' => 'Confirmed'] as $v => $l)
                            <option value="{{ $v }}" @selected(old('status', 'scheduled') === $v) x-text="$store.ui.lang==='en' ? '{{ $l }}' : '{{ $statusLabelsMs[$v] }}'">{{ $l }}</option>
                        @endforeach
                    </select>
                    @include('partials.hint', ['en' => 'Use Scheduled for a planned shift; switch to Confirmed once the staff member has agreed to it.', 'ms' => 'Guna Scheduled untuk shift yang dirancang; tukar ke Confirmed setelah staf bersetuju.'])
                </div>
            </div>
            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;"><span x-text="$store.ui.lang==='en' ? 'Schedule shift' : 'Jadualkan shift'">Schedule shift</span></button>
        </form>
    </div>

    <div class="uj-card">
        <div class="uj-card-head">
            <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Weekly roster' : 'Roster mingguan'">Weekly roster</span></h3>
            <button @click="add = ! add" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;"><span x-text="add ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Assign shift' : '+ Tetapkan shift')"></span></button>
        </div>
        <div style="overflow-x:auto;">
            <div style="min-width:880px;">
                <div style="display:grid;grid-template-columns:180px repeat(7,1fr);gap:8px;padding:12px 20px;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid var(--hairline-soft);">
                    <span x-text="$store.ui.lang==='en' ? 'Employee' : 'Pekerja'">Employee</span>
                    @foreach ($days as $d)
                        <span style="{{ $d['isToday'] ? 'color:var(--info);' : '' }}">{{ $d['dow'] }}<br><span style="font-weight:500;text-transform:none;letter-spacing:0;">{{ $d['dom'] }}</span></span>
                    @endforeach
                </div>
                @forelse ($employees as $e)
                    @php $cells = $grid->get($e->id, collect()); @endphp
                    <div style="display:grid;grid-template-columns:180px repeat(7,1fr);gap:8px;padding:11px 20px;border-bottom:1px solid var(--hairline-soft);align-items:start;">
                        <div style="display:flex;align-items:center;gap:9px;">
                            <span style="width:28px;height:28px;border-radius:50%;background:{{ $e->avatar_color ?? 'var(--info)' }};color:#fff;font-size:11px;font-weight:600;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;">{{ $e->initials }}</span>
                            <span style="font-size:13px;color:var(--ink);font-weight:500;">{{ $e->name }}</span>
                        </div>
                        @foreach ($days as $d)
                            <div style="display:flex;flex-direction:column;gap:5px;">
                                @foreach ($cells->get($d['date'], collect()) as $s)
                                    <div style="border:1px solid var(--hairline);border-left:3px solid {{ $sc[$s->status] }};border-radius:7px;padding:6px 8px;font-size:11px;{{ $s->status === 'cancelled' ? 'opacity:.55;' : '' }}">
                                        <div style="font-weight:600;color:var(--ink);">{{ $fmtTime($s->start_time) }}–{{ $fmtTime($s->end_time) }}</div>
                                        <div style="color:var(--muted);">{{ $s->location }}</div>
                                        @if ($s->status !== 'cancelled')
                                            <form method="post" action="{{ route('roster.cancel', $s) }}" @submit="if (! confirm($store.ui.lang==='en' ? 'Cancel this shift?' : 'Batalkan shift ini?')) $event.preventDefault();" style="margin-top:3px;">@csrf<button type="submit" class="uj-btn-ghost" style="height:22px;padding:0 7px;font-size:10.5px;"><span x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</span></button></form>
                                        @else
                                            <div style="color:var(--muted-soft);font-size:10.5px;margin-top:2px;"><span x-text="$store.ui.lang==='en' ? 'Cancelled' : 'Dibatalkan'">Cancelled</span></div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @empty
                    <div style="padding:28px 20px;text-align:center;">
                        <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No employees to roster yet' : 'Belum ada pekerja untuk dijadualkan'">No employees to roster yet</span></div>
                        <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Add employees in the Directory first — then they will appear here as rows you can assign shifts to.' : 'Tambah pekerja dalam Directory dahulu — kemudian mereka muncul di sini sebagai baris untuk anda tetapkan shift.'"></span></div>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@else
    <div class="uj-card">
        <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'My upcoming shifts' : 'Shift akan datang saya'">My upcoming shifts</span></h3></div>
        <div style="display:grid;grid-template-columns:1.2fr 1fr 1.4fr 1fr;gap:8px;padding:12px 20px;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid var(--hairline-soft);"><span x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</span><span x-text="$store.ui.lang==='en' ? 'Time' : 'Masa'">Time</span><span x-text="$store.ui.lang==='en' ? 'Location / role' : 'Lokasi / tugas'">Location / role</span><span>Status</span></div>
        @forelse ($myShifts as $s)
            <div style="display:grid;grid-template-columns:1.2fr 1fr 1.4fr 1fr;gap:8px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);align-items:center;">
                <span style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $s->date->format('D, j M Y') }}</span>
                <span style="font-size:13px;color:var(--body);">{{ $fmtTime($s->start_time) }} – {{ $fmtTime($s->end_time) }}</span>
                <span style="font-size:13px;color:var(--body);">{{ $s->location }}</span>
                @php $shiftStatusMs = ['scheduled' => 'Dijadualkan', 'confirmed' => 'Disahkan', 'cancelled' => 'Dibatalkan']; @endphp
                <span style="display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:600;color:{{ $sc[$s->status] }};"><span style="width:8px;height:8px;border-radius:50%;background:{{ $sc[$s->status] }};"></span><span x-text="$store.ui.lang==='en' ? '{{ ucfirst($s->status) }}' : '{{ $shiftStatusMs[$s->status] ?? ucfirst($s->status) }}'">{{ ucfirst($s->status) }}</span></span>
            </div>
        @empty
            <div style="padding:28px 20px;text-align:center;">
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No upcoming shifts scheduled' : 'Tiada shift akan datang dijadualkan'">No upcoming shifts scheduled</span></div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Your manager hasn&#39;t rostered you for any upcoming shifts yet. They will show here once scheduled.' : 'Pengurus anda belum tetapkan sebarang shift akan datang untuk anda. Ia akan dipaparkan di sini setelah dijadualkan.'"></span></div>
            </div>
        @endforelse
    </div>
@endif
@endsection
