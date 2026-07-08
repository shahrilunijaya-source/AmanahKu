@extends('layouts.app')

@php use App\Support\Amanahku; @endphp

@section('screen')
@php $fs = 'height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;'; @endphp
@include('partials.guide', [
    'key' => 'directory',
    'en'  => [
        'title' => 'Employee directory',
        'body'  => 'The searchable list of everyone in the company across all branches and departments. Search by name, filter by department or status, and click any person to open their full profile. HR and management can also add new employees here.',
    ],
    'ms'  => [
        'title' => 'Direktori pekerja',
        'body'  => 'Senarai semua orang dalam syarikat merentas semua cawangan dan jabatan yang boleh dicari. Cari ikut nama, tapis ikut jabatan atau status, dan klik mana-mana orang untuk buka profil penuh mereka. HR dan pengurusan juga boleh tambah pekerja baharu di sini.',
    ],
])
<div x-data="{ add: {{ $errors->any() ? 'true' : 'false' }}, imp: false }">
@if (in_array($role, ['management', 'hr'], true) && ! ($archived ?? false))
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:14px;">
        {{-- Bulk-provision logins for directory/CSV-imported staff who have an email but no account yet. --}}
        <form method="post" action="{{ route('members.provision') }}"
              @submit="if (! confirm($store.ui.lang==='en' ? @js('Create login accounts for all staff who have an email but no login yet? Each is emailed an invite to activate their account and set their own password.') : @js('Cipta akaun log masuk untuk semua staf yang ada emel tetapi belum ada login? Setiap seorang dihantar jemputan emel untuk mengaktifkan akaun dan menetapkan kata laluan sendiri.'))) $event.preventDefault();">
            @csrf
            <button type="submit" class="uj-btn-ghost" style="height:38px;padding:0 16px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Create logins' : 'Cipta login'">Create logins</span></button>
        </form>
        <button @click="imp = ! imp; add = false" class="uj-btn-ghost" style="height:38px;padding:0 16px;font-size:13px;"><span x-text="imp ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? 'Import CSV' : 'Import CSV')">Import CSV</span></button>
        <button @click="add = ! add; imp = false" class="uj-btn-primary" style="height:38px;padding:0 16px;font-size:13px;"><span x-text="add ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Add employee' : '+ Tambah pekerja')"></span></button>
    </div>

    <div x-show="imp" x-cloak class="uj-card" style="padding:20px;margin-bottom:16px;">
        <h3 class="uj-card-title" style="margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Bulk import staff' : 'Import staf pukal'">Bulk import staff</span></h3>
        <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px;"><span x-text="$store.ui.lang==='en' ? 'Upload a CSV to add many staff at once. Department, branch, staff level and employment type are matched by name. Fill the reports_to column with each person\'s manager name to build the org chart in one go.' : 'Muat naik CSV untuk tambah ramai staf sekali gus. Jabatan, cawangan, tahap staf dan jenis pekerjaan dipadankan mengikut nama. Isi lajur reports_to dengan nama pengurus setiap orang untuk bina carta organisasi sekali gus.'">Upload a CSV to add many staff at once. Department, branch, staff level and employment type are matched by name. Fill the reports_to column with each person's manager name to build the org chart in one go.</span></p>
        <form method="post" action="{{ route('employees.import') }}" enctype="multipart/form-data" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            @csrf
            <input type="file" name="file" accept=".csv,text/csv" required style="font-size:13px;" />
            <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? 'Import' : 'Import'">Import</span></button>
            <a href="{{ route('employees.import.template') }}" style="font-size:12.5px;color:var(--red);text-decoration:none;"><span x-text="$store.ui.lang==='en' ? 'Download template' : 'Muat turun templat'">Download template</span></a>
        </form>
    </div>
    <div x-show="add" x-cloak class="uj-card" style="padding:20px;margin-bottom:16px;">
        <h3 class="uj-card-title" style="margin-bottom:14px;"><span x-text="$store.ui.lang==='en' ? 'New employee' : 'Pekerja baharu'">New employee</span></h3>
        <form method="post" action="{{ route('employees.store') }}">
            @csrf
            @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif
            @php $bandsByDept = $allPositions->groupBy(fn ($p) => $p->department?->name ?? '—'); @endphp
            <div x-data="{ pid: '{{ old('position_id') }}', max: @js($allPositions->mapWithKeys(fn ($p) => [$p->id => (float) $p->max_salary])) }"
                 style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Full name *' : 'Nama penuh *'">Full name *</span></label><input name="name" value="{{ old('name') }}" required maxlength="120" style="{{ $fs }}width:100%;" /></div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Email' : 'Emel'">Email</span></label><input name="email" type="email" value="{{ old('email') }}" maxlength="160" style="{{ $fs }}width:100%;" /></div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Staff ID' : 'ID Staf'">Staff ID</span></label><input name="staff_id" value="{{ old('staff_id') }}" maxlength="50" placeholder="UR-0000" style="{{ $fs }}width:100%;font-family:var(--font-mono);" /></div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Joined' : 'Menyertai'">Joined</span></label><input name="joined_at" type="date" value="{{ old('joined_at') }}" style="{{ $fs }}width:100%;margin-bottom:6px;" />@include('partials.hint', ['en' => 'Leave blank to default to today. Set the real hire date when adding existing staff.', 'ms' => 'Biar kosong untuk guna hari ini. Tetapkan tarikh sebenar bila menambah staf sedia ada.'])</div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Date of birth' : 'Tarikh lahir'">Date of birth</span></label><input name="date_of_birth" type="date" value="{{ old('date_of_birth') }}" style="{{ $fs }}width:100%;margin-bottom:6px;" />@include('partials.hint', ['en' => 'Used to set the SOCSO/EIS contribution category — staff aged 60 and over fall under a different rate.', 'ms' => 'Digunakan untuk tetapkan kategori caruman PERKESO/SIP — staf berumur 60 tahun ke atas tertakluk kepada kadar berbeza.'])</div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Position band' : 'Band pangkat'">Position band</span></label><select name="position_id" x-model="pid" style="{{ $fs }}width:100%;margin-bottom:6px;"><option value="">—</option>@foreach ($bandsByDept as $deptName => $group)<optgroup label="{{ $deptName }}">@foreach ($group as $p)<option value="{{ $p->id }}" @selected(old('position_id') == $p->id)>{{ $p->title }}@if ($p->staffLevel) · {{ $p->staffLevel->name }}@endif · RM {{ number_format((float) $p->max_salary, 0) }}</option>@endforeach</optgroup>@endforeach</select>@include('partials.hint', ['en' => 'Pick the rate-card band. Department, job title and level all follow the band you choose.', 'ms' => 'Pilih band jadual kadar. Jabatan, jawatan dan peringkat semuanya mengikut band yang dipilih.'])</div>
                @if ($canSeeSalary ?? false)<div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Salary (RM)' : 'Gaji (RM)'">Salary (RM)</span></label><input type="number" step="0.01" min="0" name="salary" value="{{ old('salary') }}" placeholder="0.00" style="{{ $fs }}width:100%;font-family:var(--font-mono);margin-bottom:4px;" /><div x-show="pid && max[pid] !== undefined" x-cloak style="font-size:11px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Band max:' : 'Maks band:'">Band max:</span> RM <span x-text="(max[pid] ?? 0).toLocaleString('en-MY',{minimumFractionDigits:2})"></span></div></div>@endif
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Branch' : 'Cawangan'">Branch</span></label><select name="branch_id" style="{{ $fs }}width:100%;"><option value="">—</option>@foreach ($allBranches as $b)<option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Employment type' : 'Jenis pekerjaan'">Employment type</span></label><select name="employment_type_id" style="{{ $fs }}width:100%;"><option value="">—</option>@foreach ($allEmploymentTypes as $et)<option value="{{ $et->id }}" @selected(old('employment_type_id') == $et->id)>{{ $et->name }}</option>@endforeach</select></div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</span></label><select name="status" style="{{ $fs }}width:100%;margin-bottom:6px;">@foreach (['active' => 'Active', 'probation' => 'Probation', 'on_leave' => 'On Leave', 'resigned' => 'Resigned'] as $v => $l)<option value="{{ $v }}" @selected(old('status', 'active') === $v)>{{ $l }}</option>@endforeach</select>@include('partials.hint', ['en' => 'New hires usually start on "Probation". Use "Active" for confirmed staff.', 'ms' => 'Pekerja baharu biasanya bermula dengan "Probation". Guna "Active" untuk staf yang telah disahkan.'])</div>
            </div>
            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;"><span x-text="$store.ui.lang==='en' ? 'Add employee' : 'Tambah pekerja'">Add employee</span></button>
        </form>
    </div>
@endif

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
