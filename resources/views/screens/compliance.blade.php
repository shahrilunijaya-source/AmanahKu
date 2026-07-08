@extends('layouts.app')

@php
    $privileged = in_array($role, ['management', 'hr'], true);
    $tc = ['license' => 'License', 'certification' => 'Certification', 'permit' => 'Permit'];
    $tcMs = ['license' => 'Lesen', 'certification' => 'Sijil', 'permit' => 'Permit'];
    // expiry_color accessor → token map for pills / accents.
    $col = ['error' => 'var(--error)', 'amber' => 'var(--amber)', 'green' => 'var(--success)'];
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'compliance',
    'en'  => [
        'title' => 'Licenses & certifications',
        'body'  => 'Keep track of every licence, certification and permit your staff need to do their job legally — and get warned before any of them expire. The coloured pills show how soon each one runs out so nothing lapses unnoticed.',
        'who'   => 'HR & management add and renew · Staff see their own',
        'steps' => [
            'Open "+ Add item" and pick the employee, then the type (licence, certification or permit).',
            'Fill the name, who issued it, and most importantly the expiry date.',
            'Watch the colour: red = expired or expiring within 30 days, amber = within 60, green = still valid.',
            'When a renewed copy comes in, click "Renew" on that row and enter the new expiry date.',
        ],
    ],
    'ms'  => [
        'title' => 'Lesen & sijil',
        'body'  => 'Pantau setiap license, sijil dan permit yang staf perlukan untuk bekerja secara sah — dan dapat amaran sebelum mana-mana tamat tempoh. Pil berwarna menunjukkan berapa cepat setiap satu akan luput supaya tiada yang terlepas pandang.',
        'who'   => 'HR & pengurusan tambah dan baharui · Staf lihat milik sendiri',
        'steps' => [
            'Buka "+ Add item" dan pilih pekerja, kemudian jenisnya (license, sijil atau permit).',
            'Isi nama, pihak yang mengeluarkan, dan yang paling penting tarikh tamat tempoh.',
            'Perhati warna: merah = tamat tempoh atau akan luput dalam 30 hari, kuning = dalam 60 hari, hijau = masih sah.',
            'Apabila salinan baharu diterima, klik "Renew" pada baris itu dan masukkan tarikh tamat tempoh baharu.',
        ],
    ],
])
<div x-data="{ add: {{ $errors->any() ? 'true' : 'false' }} }">

@if ($privileged)
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
        <div class="uj-card uj-stat" style="flex:1;min-width:140px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Expired' : 'Tamat tempoh'">Expired</span></div><div class="uj-stat-value" style="color:var(--error);">{{ $buckets['expired'] }}</div></div>
        <div class="uj-card uj-stat" style="flex:1;min-width:140px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? '≤ 30 days' : '≤ 30 hari'">≤ 30 days</span></div><div class="uj-stat-value" style="color:var(--error);">{{ $buckets['30'] }}</div></div>
        <div class="uj-card uj-stat" style="flex:1;min-width:140px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? '≤ 60 days' : '≤ 60 hari'">≤ 60 days</span></div><div class="uj-stat-value" style="color:var(--amber);">{{ $buckets['60'] }}</div></div>
        <div class="uj-card uj-stat" style="flex:1;min-width:140px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? '≤ 90 days' : '≤ 90 hari'">≤ 90 days</span></div><div class="uj-stat-value" style="color:var(--success);">{{ $buckets['90'] }}</div></div>
        <div class="uj-card uj-stat" style="flex:1;min-width:140px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Valid' : 'Sah'">Valid</span></div><div class="uj-stat-value" style="color:var(--success);">{{ $buckets['valid'] }}</div></div>
    </div>
@endif

@if ($privileged)
    <div x-show="add" x-cloak class="uj-card" style="padding:20px;margin-bottom:16px;">
        <h3 class="uj-card-title" style="margin-bottom:14px;"><span x-text="$store.ui.lang==='en' ? 'Add compliance item' : 'Tambah item pematuhan'">Add compliance item</span></h3>
        <form method="post" action="{{ route('compliance.store') }}">
            @csrf
            @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:start;">
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Employee' : 'Pekerja'">Employee</span></label><select name="employee_id" required style="{{ $fs }}width:100%;"><option value="" x-text="$store.ui.lang==='en' ? 'Select…' : 'Pilih…'">Select…</option>@foreach ($recipients as $r)<option value="{{ $r->id }}" @selected(old('employee_id') == $r->id)>{{ $r->name }}</option>@endforeach</select></div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</span></label><select name="type" style="{{ $fs }}margin-bottom:6px;width:100%;">@foreach ($tc as $v => $l)<option value="{{ $v }}" @selected(old('type') === $v) x-text="$store.ui.lang==='en' ? @json($l) : @json($tcMs[$v] ?? $l)">{{ $l }}</option>@endforeach</select>@include('partials.hint', ['en' => 'Licence = legal permission to operate (e.g. driving, forklift). Certification = a qualification earned. Permit = time-limited authorisation.', 'ms' => 'License = kebenaran sah untuk beroperasi (cth. memandu, forklift). Sijil = kelayakan yang diperoleh. Permit = kebenaran yang terhad tempoh.'])</div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Name' : 'Nama'">Name</span> *</label><input name="name" value="{{ old('name') }}" required maxlength="160" style="{{ $fs }}width:100%;" /></div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Identifier' : 'Pengecam'">Identifier</span></label><input name="identifier" value="{{ old('identifier') }}" maxlength="120" style="{{ $fs }}width:100%;font-family:var(--font-mono);" /></div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Issuer' : 'Pengeluar'">Issuer</span></label><input name="issuer" value="{{ old('issuer') }}" maxlength="120" style="{{ $fs }}width:100%;" /></div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Issued' : 'Dikeluarkan'">Issued</span></label><input name="issued_at" type="date" value="{{ old('issued_at') }}" style="{{ $fs }}width:100%;" /></div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Expires' : 'Tamat tempoh'">Expires</span> *</label><input name="expires_at" type="date" value="{{ old('expires_at') }}" required style="{{ $fs }}margin-bottom:6px;width:100%;" />@include('partials.hint', ['en' => 'The date this item stops being valid. This is what drives the expiry alerts — get it right.', 'ms' => 'Tarikh item ini tidak lagi sah. Inilah yang mencetuskan amaran tamat tempoh — pastikan ia betul.'])</div>
            </div>
            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;"><span x-text="$store.ui.lang==='en' ? 'Add item' : 'Tambah item'">Add item</span></button>
        </form>
    </div>
@endif

<div class="uj-card">
    <div class="uj-card-head">
        <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? @json($privileged ? 'Licenses & certifications' : 'My licenses & certifications') : @json($privileged ? 'Lesen & sijil' : 'Lesen & sijil saya')">{{ $privileged ? 'Licenses &amp; certifications' : 'My licenses &amp; certifications' }}</span></h3>
        @if ($privileged)<button @click="add = ! add" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;"><span x-text="add ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Add item' : '+ Tambah item')"></span></button>@endif
    </div>
    <div style="display:grid;grid-template-columns:1.4fr 1.8fr 1.2fr 1.2fr 1fr 1.1fr auto;gap:8px;padding:12px 20px;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid var(--hairline-soft);"><span x-text="$store.ui.lang==='en' ? 'Employee' : 'Pekerja'">Employee</span><span x-text="$store.ui.lang==='en' ? 'Item' : 'Perkara'">Item</span><span x-text="$store.ui.lang==='en' ? 'Identifier' : 'Pengecam'">Identifier</span><span x-text="$store.ui.lang==='en' ? 'Issuer' : 'Pengeluar'">Issuer</span><span x-text="$store.ui.lang==='en' ? 'Expires' : 'Tamat tempoh'">Expires</span><span x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</span><span></span></div>
    @forelse ($items as $i)
        @php
            $c = $col[$i->expiry_color] ?? 'var(--success)';
            $days = $i->days_to_expiry;
            $statusLabel = $i->expiry_bucket === 'expired'
                ? abs($days).'d overdue'
                : $days.'d left';
            $statusLabelMs = $i->expiry_bucket === 'expired'
                ? abs($days).'h lewat'
                : $days.'h lagi';
        @endphp
        <div x-data="{ rnw: false }" style="display:grid;grid-template-columns:1.4fr 1.8fr 1.2fr 1.2fr 1fr 1.1fr auto;gap:8px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);align-items:center;">
            <span style="font-size:13px;color:var(--body);">{{ $i->employee?->name }}</span>
            <div><div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $i->name }}</div><div style="font-size:11.5px;color:var(--muted-soft);"><span x-text="$store.ui.lang==='en' ? @json($tc[$i->type] ?? $i->type) : @json($tcMs[$i->type] ?? ($tc[$i->type] ?? $i->type))">{{ $tc[$i->type] ?? $i->type }}</span></div></div>
            <span style="font-size:12.5px;color:var(--muted);font-family:var(--font-mono);">{{ $i->identifier ?? '—' }}</span>
            <span style="font-size:13px;color:var(--body);">{{ $i->issuer ?? '—' }}</span>
            <span style="font-size:12.5px;font-family:var(--font-mono);color:{{ $c }};">{{ $i->expires_at->format('j M Y') }}</span>
            <span style="display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:600;color:{{ $c }};"><span style="width:8px;height:8px;border-radius:50%;background:{{ $c }};"></span><span x-text="$store.ui.lang==='en' ? @json($statusLabel) : @json($statusLabelMs)">{{ $statusLabel }}</span></span>
            <span style="text-align:right;position:relative;">
                @if ($privileged)
                    <button @click="rnw = ! rnw" class="uj-btn-ghost" style="height:30px;padding:0 11px;font-size:11.5px;"><span x-text="$store.ui.lang==='en' ? 'Renew' : 'Baharui'">Renew</span></button>
                    <form method="post" action="{{ route('compliance.destroy', $i) }}" style="display:inline;" @submit="if (! confirm($store.ui.lang==='en' ? 'Delete this item?' : 'Padam item ini?')) $event.preventDefault();">@csrf @method('DELETE')<button type="submit" class="uj-btn-ghost" style="height:30px;padding:0 11px;font-size:11.5px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</span></button></form>
                    <form method="post" action="{{ route('compliance.renew', $i) }}" x-show="rnw" x-cloak @click.outside="rnw = false" style="position:absolute;right:0;top:36px;z-index:5;display:flex;flex-direction:column;gap:8px;background:#fff;border:1px solid var(--hairline);border-radius:9px;padding:12px;box-shadow:0 6px 20px rgba(31,30,26,.1);min-width:200px;">
                        @csrf
                        <label style="font-size:11px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'New expiry date' : 'Tarikh tamat tempoh baharu'">New expiry date</span></label>
                        <input name="expires_at" type="date" required style="{{ $fs }}width:100%;" />
                        <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink);"><input type="checkbox" name="reissue" value="1" /> <span x-text="$store.ui.lang==='en' ? 'Re-issue today' : 'Keluar semula hari ini'">Re-issue today</span></label>
                        <button type="submit" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12px;"><span x-text="$store.ui.lang==='en' ? 'Confirm renewal' : 'Sahkan pembaharuan'">Confirm renewal</span></button>
                    </form>
                @endif
            </span>
        </div>
    @empty
        <div style="padding:28px 20px;text-align:center;">
            <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No compliance items yet' : 'Tiada item pematuhan lagi'"></span></div>
            @if ($privileged)
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Click + Add item above to record a licence, certification or permit. Once added, it will appear here with an expiry countdown.' : 'Klik + Add item di atas untuk merekod license, sijil atau permit. Setelah ditambah, ia akan muncul di sini dengan kiraan detik tamat tempoh.'"></span></div>
            @else
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Nothing here yet. HR will add any licences or certifications tied to your role, and you will see their expiry status here.' : 'Belum ada apa-apa di sini. HR akan menambah mana-mana license atau sijil yang berkaitan dengan tugas anda, dan anda akan lihat status tamat tempohnya di sini.'"></span></div>
            @endif
        </div>
    @endforelse
</div>
</div>
@endsection
