@extends('layouts.app')

@section('screen')
@php $fs = 'height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;'; @endphp
@include('partials.guide', [
    'key' => 'staff-load',
    'en'  => [
        'title' => 'Add & import staff',
        'body'  => 'The HR loading bay for getting people into the system. Add one employee at a time, bulk-import many from a CSV, or provision logins for staff who already exist. Department, branch, staff level and employment type are matched by name — set those up in Company Settings first. Everyone you add appears in People → Employees.',
    ],
    'ms'  => [
        'title' => 'Tambah & import staf',
        'body'  => 'Ruang muat naik HR untuk memasukkan orang ke dalam sistem. Tambah seorang pekerja pada satu masa, import ramai sekali gus daripada CSV, atau sediakan login untuk staf sedia ada. Jabatan, cawangan, tahap staf dan jenis pekerjaan dipadankan mengikut nama — sediakan dahulu di Tetapan Syarikat. Semua yang ditambah muncul dalam Orang → Pekerja.',
    ],
])

{{-- ── Add one employee ─────────────────────────────────────────────── --}}
<div class="uj-card" style="padding:20px;margin-bottom:16px;">
    <h3 class="uj-card-title" style="margin-bottom:14px;"><span x-text="$store.ui.lang==='en' ? 'Add employee' : 'Tambah pekerja'">Add employee</span></h3>
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

{{-- ── Bulk import from CSV ──────────────────────────────────────────── --}}
<div class="uj-card" style="padding:20px;margin-bottom:16px;">
    <h3 class="uj-card-title" style="margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Bulk import staff' : 'Import staf pukal'">Bulk import staff</span></h3>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px;"><span x-text="$store.ui.lang==='en' ? 'Upload a CSV to add many staff at once. Department, branch, staff level and employment type are matched by name. Fill the reports_to column with each person\'s manager name to build the org chart in one go.' : 'Muat naik CSV untuk tambah ramai staf sekali gus. Jabatan, cawangan, tahap staf dan jenis pekerjaan dipadankan mengikut nama. Isi lajur reports_to dengan nama pengurus setiap orang untuk bina carta organisasi sekali gus.'">Upload a CSV to add many staff at once. Department, branch, staff level and employment type are matched by name. Fill the reports_to column with each person's manager name to build the org chart in one go.</span></p>
    <form method="post" action="{{ route('employees.import') }}" enctype="multipart/form-data" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        @csrf
        <input type="file" name="file" accept=".csv,text/csv" required style="font-size:13px;" />
        <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? 'Import' : 'Import'">Import</span></button>
        <a href="{{ route('employees.import.template') }}" style="font-size:12.5px;color:var(--red);text-decoration:none;"><span x-text="$store.ui.lang==='en' ? 'Download template' : 'Muat turun templat'">Download template</span></a>
    </form>
</div>

{{-- ── Provision logins ──────────────────────────────────────────────── --}}
<div class="uj-card" style="padding:20px;">
    <h3 class="uj-card-title" style="margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Create logins' : 'Cipta login'">Create logins</span></h3>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px;"><span x-text="$store.ui.lang==='en' ? 'Provision login accounts for every staff member who has an email but no account yet. Each is emailed an invite to activate their account and set their own password.' : 'Sediakan akaun log masuk untuk setiap staf yang ada emel tetapi belum ada akaun. Setiap seorang dihantar jemputan emel untuk mengaktifkan akaun dan menetapkan kata laluan sendiri.'">Provision login accounts for every staff member who has an email but no account yet.</span></p>
    <form method="post" action="{{ route('members.provision') }}"
          @submit="if (! confirm($store.ui.lang==='en' ? @js('Create login accounts for all staff who have an email but no login yet? Each is emailed an invite to activate their account and set their own password.') : @js('Cipta akaun log masuk untuk semua staf yang ada emel tetapi belum ada login? Setiap seorang dihantar jemputan emel untuk mengaktifkan akaun dan menetapkan kata laluan sendiri.'))) $event.preventDefault();">
        @csrf
        <button type="submit" class="uj-btn-ghost" style="height:40px;padding:0 18px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? 'Create logins' : 'Cipta login'">Create logins</span></button>
    </form>
</div>
@endsection
