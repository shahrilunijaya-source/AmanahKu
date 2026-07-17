@extends('layouts.app')

@php use App\Support\Amanahku; @endphp

@section('screen')
@php $fs = 'height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;'; @endphp
@include('partials.guide', [
    'key' => 'directory',
    'en'  => [
        'title' => 'Employee directory',
        'body'  => 'The searchable list of everyone in the company across all branches and departments. Search by name, filter by department or status, and click any person to open their full profile. To add or import staff, go to Administration → Add & Import Staff.',
    ],
    'ms'  => [
        'title' => 'Direktori pekerja',
        'body'  => 'Senarai semua orang dalam syarikat merentas semua cawangan dan jabatan yang boleh dicari. Cari ikut nama, tapis ikut jabatan atau status, dan klik mana-mana orang untuk buka profil penuh mereka. Untuk tambah atau import staf, pergi ke Pentadbiran → Tambah & Import Staf.',
    ],
])
<div>

<div class="uj-card">
    <form method="get" action="{{ route('app.screen', 'directory') }}" style="padding:16px 20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;border-bottom:1px solid var(--hairline);">
        @if ($archived ?? false)<input type="hidden" name="view" value="archived" />@endif
        <div style="position:relative;flex:1;min-width:220px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--muted-soft)" stroke-width="2" stroke-linecap="round" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);"><circle cx="11" cy="11" r="8"></circle><path d="M21 21l-4-4"></path></svg>
            <input name="q" value="{{ $filters['q'] }}" :placeholder="$store.ui.lang==='en' ? 'Search employees…' : 'Cari pekerja…'" style="width:100%;height:36px;padding:0 12px 0 33px;background:var(--canvas);border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;color:var(--ink);" />
        </div>
        <select name="dept" onchange="this.form.submit()" style="height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;color:var(--body);background:#fff;">
            <option value="" x-text="$store.ui.lang==='en' ? 'All departments' : 'Semua jabatan'">All departments</option>
            @foreach ($departments as $d)<option value="{{ $d }}" @selected($filters['dept'] === $d)>{{ $d }}</option>@endforeach
        </select>
        <select name="status" onchange="this.form.submit()" style="height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;color:var(--body);background:#fff;">
            <option value="" x-text="$store.ui.lang==='en' ? 'All status' : 'Semua status'">All status</option>
            @foreach (['active' => 'Active', 'probation' => 'Probation', 'on_leave' => 'On Leave'] as $v => $l)<option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>@endforeach
        </select>
        <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 14px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Search' : 'Cari'">Search</span></button>
        @if ($canArchive ?? false)
            @if ($archived ?? false)
                <a href="{{ route('app.screen', 'directory') }}" class="uj-btn-ghost" style="display:inline-flex;align-items:center;height:36px;padding:0 14px;font-size:13px;text-decoration:none;margin-left:auto;"><span x-text="$store.ui.lang==='en' ? 'Back to active' : 'Kembali ke aktif'">Back to active</span></a>
            @else
                <a href="{{ route('app.screen', ['screen' => 'directory', 'view' => 'archived']) }}" class="uj-btn-ghost" style="display:inline-flex;align-items:center;height:36px;padding:0 14px;font-size:13px;text-decoration:none;margin-left:auto;"><span x-text="$store.ui.lang==='en' ? 'Archived' : 'Diarkib'">Archived</span>@if (($archivedCount ?? 0) > 0)<span style="margin-left:7px;background:var(--hairline);color:var(--muted);border-radius:9px;padding:1px 7px;font-size:11px;font-weight:600;">{{ $archivedCount }}</span>@endif</a>
            @endif
        @endif
    </form>

    @php
        $dirGrid = ($canSeeSalary ?? false)
            ? '2fr 1.4fr 1.2fr 1fr 0.9fr 1fr 1.1fr'
            : '2fr 1.4fr 1.2fr 1fr 1fr 1.1fr';
    @endphp
    <div style="display:grid;grid-template-columns:{{ $dirGrid }};gap:8px;padding:12px 20px;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid var(--hairline-soft);"><span x-text="$store.ui.lang==='en' ? 'Employee' : 'Pekerja'">Employee</span><span x-text="$store.ui.lang==='en' ? 'Position' : 'Jawatan'">Position</span><span x-text="$store.ui.lang==='en' ? 'Department' : 'Jabatan'">Department</span><span x-text="$store.ui.lang==='en' ? 'Branch' : 'Cawangan'">Branch</span>@if ($canSeeSalary ?? false)<span x-text="$store.ui.lang==='en' ? 'Salary' : 'Gaji'">Salary</span>@endif<span x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</span>@if ($archived ?? false)<span x-text="$store.ui.lang==='en' ? 'Action' : 'Tindakan'">Action</span>@else<span x-text="$store.ui.lang==='en' ? 'Workload' : 'Beban kerja'">Workload</span>@endif</div>

    @php $isArchived = $archived ?? false; @endphp
    @forelse ($employees as $e)
        @php
            $stColor = ['active' => 'var(--success)', 'probation' => 'var(--amber)', 'on_leave' => 'var(--muted-soft)', 'resigned' => 'var(--error)'][$e->status] ?? 'var(--body)';
            $stLabel = ['active' => 'Active', 'probation' => 'Probation', 'on_leave' => 'On Leave', 'resigned' => 'Resigned'][$e->status] ?? $e->status;
            $rowStyle = 'display:grid;grid-template-columns:'.$dirGrid.';gap:8px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);align-items:center;';
        @endphp
        {{-- Archived rows are non-clickable (they carry a Restore form, so they can't be wrapped in an <a>); active rows link straight to the profile. --}}
        @if ($isArchived)
        <div style="{{ $rowStyle }}">
        @else
        <a href="{{ route('app.screen', ['screen' => 'profile', 'emp' => $e->id]) }}" class="uj-row" style="text-decoration:none;{{ $rowStyle }}">
        @endif
            <div style="display:flex;align-items:center;gap:11px;min-width:0;"><div style="width:34px;height:34px;border-radius:50%;background:{{ $e->avatar_color }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;flex-shrink:0;">{{ $e->initials }}</div><div style="min-width:0;"><div style="font-size:13.5px;color:var(--ink);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $e->name }}</div><div style="font-size:11.5px;color:var(--muted-soft);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">@if ($isArchived && $e->archived_at)<span x-text="$store.ui.lang==='en' ? 'Archived' : 'Diarkib'">Archived</span> {{ $e->archived_at->format('d M Y') }}@else{{ $e->email }}@endif</div></div></div>
            <span style="font-size:13px;color:var(--body);">{{ $e->positionBand?->title ?? '—' }}</span>
            <span style="font-size:13px;color:var(--body);">{{ $e->department?->name }}</span>
            <span style="font-size:13px;color:var(--body);">{{ $e->branch?->name }}</span>
            @if ($canSeeSalary ?? false)<span style="font-size:12.5px;color:var(--body);font-family:var(--font-mono);">{{ $e->salary ? 'RM '.number_format((float) $e->salary, 0) : '—' }}</span>@endif
            <span style="font-size:12px;font-weight:600;color:{{ $stColor }};">{{ $stLabel }}</span>
            @if ($isArchived)
            <div style="display:flex;gap:8px;align-items:center;">
                <form method="post" action="{{ route('employees.restore', $e) }}" @submit="if (! confirm($store.ui.lang==='en' ? 'Restore this staff member to the directory?' : 'Pulihkan staf ini ke direktori?')) $event.preventDefault();">
                    @csrf
                    <button type="submit" class="uj-btn-ghost" style="height:30px;padding:0 14px;font-size:12px;"><span x-text="$store.ui.lang==='en' ? 'Restore' : 'Pulihkan'">Restore</span></button>
                </form>
                {{-- Permanent delete: type-to-confirm because it is irreversible and cascades history. The server refuses if the person has payroll records. --}}
                <form method="post" action="{{ route('employees.force-delete', $e) }}" @submit="if (prompt($store.ui.lang==='en' ? @js('Permanently delete '.$e->name.'? This cannot be undone. Type the name to confirm:') : @js('Padam '.$e->name.' secara kekal? Tindakan ini tidak boleh dibuat asal. Taip nama untuk sahkan:')) !== @js($e->name)) $event.preventDefault();">
                    @csrf
                    <button type="submit" class="uj-btn-ghost" style="height:30px;padding:0 14px;font-size:12px;color:var(--red);border-color:var(--red);"><span x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</span></button>
                </form>
            </div>
            @else
            <span style="display:inline-flex;align-items:center;gap:7px;font-size:12.5px;color:var(--body);"><span style="width:9px;height:9px;border-radius:50%;background:{{ Amanahku::SWATCH[$e->workload] }};"></span>{{ $e->workload_label }}</span>
            @endif
        @if ($isArchived)
        </div>
        @else
        </a>
        @endif
    @empty
        <div style="padding:48px 24px;text-align:center;font-size:13.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? ({{ $isArchived ? "'No archived staff.'" : "'No employees match your search.'" }}) : ({{ $isArchived ? "'Tiada staf diarkibkan.'" : "'Tiada pekerja sepadan dengan carian anda.'" }})"></span></div>
    @endforelse

    <div style="padding:14px 20px;display:flex;align-items:center;justify-content:space-between;">
        <span style="font-size:12.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Showing' : 'Memaparkan'">Showing</span> {{ $employees->count() }} <span x-text="$store.ui.lang==='en' ? 'of' : 'daripada'">of</span> {{ $total }} <span x-text="$store.ui.lang==='en' ? 'employees' : 'pekerja'">employees</span></span>
        <div>{{ $employees->onEachSide(1)->links() }}</div>
    </div>
</div>
</div>
@endsection
