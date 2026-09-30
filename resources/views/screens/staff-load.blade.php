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
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Nickname' : 'Nama panggilan'">Nickname</span></label><input name="nickname" value="{{ old('nickname') }}" maxlength="60" style="{{ $fs }}width:100%;" />@include('partials.hint', ['en' => 'The short name colleagues use, such as "Hakime". Used instead of the full name in every list and picker.', 'ms' => 'Nama pendek yang digunakan rakan sekerja, contohnya "Hakime". Digunakan sebagai ganti nama penuh dalam setiap senarai dan pemilih.'])</div>
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Email' : 'Emel'">Email</span></label><input name="email" type="email" value="{{ old('email') }}" maxlength="160" style="{{ $fs }}width:100%;" /></div>
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Staff ID' : 'ID Staf'">Staff ID</span></label><input name="staff_id" value="{{ old('staff_id') }}" maxlength="50" placeholder="Auto if blank" style="{{ $fs }}width:100%;font-family:var(--font-mono);" /></div>
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Joined' : 'Menyertai'">Joined</span></label><input name="joined_at" type="date" value="{{ old('joined_at') }}" style="{{ $fs }}width:100%;margin-bottom:6px;" />@include('partials.hint', ['en' => 'Leave blank to default to today. Set the real hire date when adding existing staff.', 'ms' => 'Biar kosong untuk guna hari ini. Tetapkan tarikh sebenar bila menambah staf sedia ada.'])</div>
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Date of birth' : 'Tarikh lahir'">Date of birth</span></label><input name="date_of_birth" type="date" value="{{ old('date_of_birth') }}" style="{{ $fs }}width:100%;margin-bottom:6px;" />@include('partials.hint', ['en' => 'Used to set the SOCSO/EIS contribution category — staff aged 60 and over fall under a different rate.', 'ms' => 'Digunakan untuk tetapkan kategori caruman PERKESO/SIP — staf berumur 60 tahun ke atas tertakluk kepada kadar berbeza.'])</div>
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Position band' : 'Band jawatan'">Position band</span></label><select name="position_id" x-model="pid" style="{{ $fs }}width:100%;margin-bottom:6px;"><option value="">—</option>@foreach ($bandsByDept as $deptName => $group)<optgroup label="{{ $deptName }}">@foreach ($group as $p)<option value="{{ $p->id }}" @selected(old('position_id') == $p->id)>{{ $p->title }}@if ($p->staffLevel) · {{ $p->staffLevel->name }}@endif · RM {{ number_format((float) $p->max_salary, 0) }}</option>@endforeach</optgroup>@endforeach</select>@include('partials.hint', ['en' => 'Pick the rate-card band. Department, job title and level all follow the band you choose.', 'ms' => 'Pilih band jadual kadar. Jabatan, jawatan dan peringkat semuanya mengikut band yang dipilih.'])</div>
            @if ($canSeeSalary ?? false)<div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Salary (RM)' : 'Gaji (RM)'">Salary (RM)</span></label><input type="number" step="0.01" min="0" name="salary" value="{{ old('salary') }}" placeholder="0.00" style="{{ $fs }}width:100%;font-family:var(--font-mono);margin-bottom:4px;" /><div x-show="pid && max[pid] !== undefined" x-cloak style="font-size:11px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Band max:' : 'Maks band:'">Band max:</span> RM <span x-text="(max[pid] ?? 0).toLocaleString('en-MY',{minimumFractionDigits:2})"></span></div></div>@endif
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Branch' : 'Cawangan'">Branch</span></label><select name="branch_id" style="{{ $fs }}width:100%;"><option value="">—</option>@foreach ($allBranches as $b)<option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Employment type' : 'Jenis pekerjaan'">Employment type</span></label><select name="employment_type_id" style="{{ $fs }}width:100%;"><option value="">—</option>@foreach ($allEmploymentTypes as $et)<option value="{{ $et->id }}" @selected(old('employment_type_id') == $et->id)>{{ $et->name }}</option>@endforeach</select></div>
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</span></label><select name="status" style="{{ $fs }}width:100%;margin-bottom:6px;">@foreach (['active' => 'Active', 'probation' => 'Probation', 'on_leave' => 'On Leave', 'resigned' => 'Resigned'] as $v => $l)<option value="{{ $v }}" @selected(old('status', 'active') === $v)>{{ $l }}</option>@endforeach</select>@include('partials.hint', ['en' => 'New hires usually start on "Probation". Use "Active" for confirmed staff.', 'ms' => 'Pekerja baharu biasanya bermula dengan "Probation". Guna "Active" untuk staf yang telah disahkan.'])</div>
        </div>
        <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;"><span x-text="$store.ui.lang==='en' ? 'Add employee' : 'Tambah pekerja'">Add employee</span></button>
    </form>
    @include('partials.coachmark', [
        'key' => 'guide-staff',
        'when' => "\$store.guide.current === 'staff'",
        'anchor' => 'button[type=submit]',
        'en' => ['title' => 'Add your people', 'body' => 'Fill the form above and click Add employee, or upload a CSV under Bulk import staff below. This step ticks once someone besides you is on the books.'],
        'ms' => ['title' => 'Tambah kakitangan anda', 'body' => 'Isi borang di atas dan klik Tambah pekerja, atau muat naik CSV di bawah Import staf pukal. Langkah ini selesai apabila ada orang selain anda dalam rekod.'],
    ])
</div>

{{-- ── Bulk import from CSV ──────────────────────────────────────────── --}}
@php
    // Column guide for the drop zone, grouped the way HR thinks about a staff sheet.
    $importColumns = [
        ['Required', 'Wajib', ['name']],
        ['Person', 'Peribadi', ['email', 'staff_id', 'nric', 'date_of_birth', 'joined', 'status', 'last_working_day']],
        ['Job', 'Kerja', ['position_band', 'branch', 'employment_type', 'reports_to', 'salary']],
        ['Pay details', 'Butiran gaji', ['bank_name', 'bank_account_no', 'epf_no', 'socso_no', 'tax_no']],
    ];
    // Last import's per-row results: skipped rows first, then rows with a warning, then the rest.
    $importReport = collect(session('import_report', []))
        ->sortBy(fn (array $r) => [$r['outcome'] === 'skipped' ? 0 : (collect($r['notes'])->contains(fn ($n) => str_contains($n, 'not ')) ? 1 : 2), $r['row']])
        ->values();
    $importCounts = $importReport->countBy('outcome');
    $importPaySet = $importReport->filter(fn (array $r) => collect($r['notes'])->contains(fn ($n) => str_starts_with($n, 'Pay details set up') || str_starts_with($n, 'Pay details updated')))->count();
    $outcomeStamp = ['created' => ['success', 'Added', 'Ditambah'], 'updated' => ['', 'Updated', 'Dikemas kini'], 'unchanged' => ['', 'No change', 'Tiada perubahan'], 'skipped' => ['error', 'Skipped', 'Dilangkau']];
@endphp
<style>
    .sl-drop { display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;text-align:center;padding:26px 18px;border:1.5px dashed color-mix(in srgb, var(--ink) 22%, var(--hairline));border-radius:12px;background:var(--canvas);cursor:pointer;transition:border-color .15s, background-color .15s; }
    .sl-drop:hover, .sl-drop[data-over] { border-color:var(--red);background:color-mix(in srgb, var(--red) 4%, #fff); }
    .sl-drop:has(input:focus-visible) { outline:2px solid var(--red);outline-offset:2px; }
    .sl-drop[data-picked] { border-style:solid;border-color:color-mix(in srgb, var(--success) 45%, var(--hairline));background:color-mix(in srgb, var(--success) 5%, #fff); }
    .sl-cols summary { cursor:pointer;font-size:12.5px;color:var(--red);width:max-content; }
    .sl-cols code { display:inline-block;font-family:var(--font-mono);font-size:11.5px;padding:2px 7px;border-radius:6px;background:var(--canvas);border:1px solid var(--hairline);color:var(--ink);margin:0 4px 4px 0; }
    .sl-report { width:100%;border-collapse:collapse;font-size:12.5px; }
    .sl-report th { text-align:left;font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);padding:8px 10px;border-bottom:1px solid var(--hairline);position:sticky;top:0;background:#fff; }
    .sl-report td { padding:9px 10px;border-bottom:1px solid var(--hairline);vertical-align:top;color:var(--ink); }
    .sl-report tr:last-child td { border-bottom:0; }
</style>
<div class="uj-card" style="padding:20px;margin-bottom:16px;"
     x-data="{ file: null, over: false, busy: false,
               take(f) { if (! f) return; const dt = new DataTransfer(); dt.items.add(f); $refs.file.files = dt.files; this.file = f; },
               size(b) { return b < 1024 ? b + ' B' : (b / 1024).toFixed(b < 10240 ? 1 : 0) + ' KB'; } }">
    <h3 class="uj-card-title" style="margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Bulk import staff' : 'Import staf pukal'">Bulk import staff</span></h3>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px;max-width:75ch;"><span x-text="$store.ui.lang==='en' ? 'Add or update many staff from one CSV. Department, branch, staff level and employment type are matched by name. Fill reports_to with each manager\'s name to build the org chart, and the pay columns to get people ready for payroll in the same upload. Blank cells never wipe what is already saved.' : 'Tambah atau kemas kini ramai staf daripada satu CSV. Jabatan, cawangan, tahap staf dan jenis pekerjaan dipadankan mengikut nama. Isi reports_to dengan nama pengurus untuk bina carta organisasi, dan lajur gaji supaya staf sedia untuk penggajian dalam muat naik yang sama. Sel kosong tidak memadam data sedia ada.'">Add or update many staff from one CSV.</span></p>
    <form method="post" action="{{ route('employees.import') }}" enctype="multipart/form-data" @submit="busy = true">
        @csrf
        <label class="sl-drop" :data-over="over || null" :data-picked="file ? '' : null"
               @dragover.prevent="over = true" @dragleave.prevent="over = false" @drop.prevent="over = false; take($event.dataTransfer.files[0])">
            <input x-ref="file" type="file" name="file" accept=".csv,text/csv" required class="sr-only" @change="file = $event.target.files[0] || null" />
            <svg x-show="! file" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V4"/><path d="m7.5 8.5 4.5-4.5 4.5 4.5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></svg>
            <svg x-show="file" x-cloak width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="m9 14 2 2 4-4"/></svg>
            <template x-if="! file">
                <div>
                    <div style="font-size:13.5px;font-weight:500;color:var(--ink);"><span x-text="$store.ui.lang==='en' ? 'Drop your CSV here, or ' : 'Lepaskan CSV di sini, atau '"></span><span style="color:var(--red);text-decoration:underline;text-underline-offset:3px;" x-text="$store.ui.lang==='en' ? 'choose a file' : 'pilih fail'"></span></div>
                    <div style="font-size:12px;color:var(--muted);margin-top:2px;" x-text="$store.ui.lang==='en' ? 'CSV only, up to 2 MB and 1,000 rows' : 'CSV sahaja, sehingga 2 MB dan 1,000 baris'"></div>
                </div>
            </template>
            <template x-if="file">
                <div>
                    <div style="font-size:13.5px;font-weight:500;color:var(--ink);word-break:break-all;" x-text="file.name"></div>
                    <div style="font-size:12px;color:var(--muted);margin-top:2px;"><span style="font-family:var(--font-mono);" x-text="size(file.size)"></span> · <span x-text="$store.ui.lang==='en' ? 'Ready to import. Click to pick a different file.' : 'Sedia untuk import. Klik untuk pilih fail lain.'"></span></div>
                </div>
            </template>
        </label>
        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-top:14px;">
            <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 20px;font-size:13.5px;" :disabled="! file || busy" :style="! file || busy ? { opacity: .5, cursor: 'not-allowed' } : {}"
                    x-text="busy ? ($store.ui.lang==='en' ? 'Importing…' : 'Mengimport…') : ($store.ui.lang==='en' ? 'Import' : 'Import')">Import</button>
            <a href="{{ route('employees.import.template') }}" style="font-size:12.5px;color:var(--red);text-decoration:none;"><span x-text="$store.ui.lang==='en' ? 'Download template' : 'Muat turun templat'">Download template</span></a>
        </div>
    </form>
    <details class="sl-cols" style="margin-top:14px;">
        <summary x-text="$store.ui.lang==='en' ? 'Which columns can I use?' : 'Lajur apa yang boleh digunakan?'">Which columns can I use?</summary>
        <div style="display:grid;gap:10px;margin-top:10px;">
            @foreach ($importColumns as [$en, $ms, $cols])
                <div><div style="font-size:11.5px;font-weight:600;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? @js($en) : @js($ms)">{{ $en }}</div>@foreach ($cols as $c)<code>{{ $c }}</code>@endforeach</div>
            @endforeach
            <div style="font-size:12px;color:var(--muted);max-width:75ch;" x-text="$store.ui.lang==='en' ? 'bank_name must match a bank on the profile\'s Bank list, such as Maybank or CIMB Bank. Pay columns only save for HR and management.' : 'bank_name mesti sepadan dengan bank dalam senarai Bank di profil, contohnya Maybank atau CIMB Bank. Lajur gaji hanya disimpan untuk HR dan pengurusan.'"></div>
        </div>
    </details>

    @if ($importReport->isNotEmpty())
        <section aria-labelledby="sl-report-title" style="margin-top:18px;padding-top:16px;border-top:1px solid var(--hairline);">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                <h4 id="sl-report-title" style="font-size:14px;font-weight:600;color:var(--ink);margin:0 6px 0 0;" x-text="$store.ui.lang==='en' ? 'Last import' : 'Import terakhir'">Last import</h4>
                @foreach (['created', 'updated', 'skipped'] as $o)
                    @if ($importCounts->get($o))<span class="uj-stamp" data-tone="{{ $outcomeStamp[$o][0] }}"><span style="font-family:var(--font-mono);margin-right:4px;">{{ $importCounts->get($o) }}</span><span x-text="$store.ui.lang==='en' ? @js(strtolower($outcomeStamp[$o][1])) : @js(strtolower($outcomeStamp[$o][2]))"></span></span>@endif
                @endforeach
                @if ($importPaySet)<span class="uj-stamp" data-tone="success"><span style="font-family:var(--font-mono);margin-right:4px;">{{ $importPaySet }}</span><span x-text="$store.ui.lang==='en' ? 'ready for payroll' : 'sedia untuk gaji'"></span></span>@endif
            </div>
            <div style="max-height:380px;overflow:auto;border:1px solid var(--hairline);border-radius:10px;">
                <table class="sl-report">
                    <thead><tr>
                        <th style="width:56px;" x-text="$store.ui.lang==='en' ? 'Row' : 'Baris'">Row</th>
                        <th x-text="$store.ui.lang==='en' ? 'Name' : 'Nama'">Name</th>
                        <th style="width:120px;" x-text="$store.ui.lang==='en' ? 'Result' : 'Keputusan'">Result</th>
                        <th x-text="$store.ui.lang==='en' ? 'Notes' : 'Catatan'">Notes</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($importReport as $r)
                            <tr>
                                <td style="font-family:var(--font-mono);color:var(--muted);">{{ $r['row'] }}</td>
                                <td style="font-weight:500;">{{ $r['name'] !== '' ? $r['name'] : '—' }}</td>
                                <td><span class="uj-stamp" data-tone="{{ $outcomeStamp[$r['outcome']][0] }}" x-text="$store.ui.lang==='en' ? @js($outcomeStamp[$r['outcome']][1]) : @js($outcomeStamp[$r['outcome']][2])">{{ $outcomeStamp[$r['outcome']][1] }}</span></td>
                                <td style="color:{{ $r['outcome'] === 'skipped' ? 'var(--error)' : 'var(--muted)' }};">
                                    @forelse ($r['notes'] as $note)
                                        <div style="{{ str_contains($note, 'not ') && $r['outcome'] !== 'skipped' ? 'color:var(--amber-ink);' : '' }}">{{ $note }}</div>
                                    @empty
                                        —
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
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
