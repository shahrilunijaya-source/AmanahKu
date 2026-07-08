@extends('layouts.app')

@php
    $sc = ['assigned' => 'var(--info)', 'available' => 'var(--success)', 'maintenance' => 'var(--amber)', 'retired' => 'var(--muted-soft)'];
    $statusEn = ['assigned' => 'Assigned', 'available' => 'Available', 'maintenance' => 'Maintenance', 'retired' => 'Retired'];
    $statusMs = ['assigned' => 'Ditugaskan', 'available' => 'Tersedia', 'maintenance' => 'Penyelenggaraan', 'retired' => 'Bersara'];
    $catMs = ['laptop' => 'Laptop', 'phone' => 'Telefon', 'vehicle' => 'Kenderaan', 'furniture' => 'Perabot', 'other' => 'Lain-lain'];
    $catIcon = ['laptop' => '💻', 'phone' => '📱', 'vehicle' => '🚗', 'furniture' => '🪑', 'other' => '📦'];
    $privileged = in_array($role, ['management', 'hr'], true);
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';
@endphp

@section('screen')
@include('partials.guide', [
    'key'   => 'assets',
    'en'  => [
        'title' => 'Company assets',
        'body'  => 'The register of company-owned equipment — laptops, phones, vehicles, furniture — and who currently holds each one. HR and management add assets and assign them to staff.',
        'who'   => 'HR & management manage · Everyone can view',
        'steps' => [
            'Click "+ Add asset", give it a name, category and (if any) serial number.',
            'A new asset starts as "Available". Use "Assign" to give it to an employee.',
            'When the item comes back, use "Return" — it goes back to Available for the next person.',
            'The counters at the top show how many assets are assigned, free or in maintenance.',
        ],
    ],
    'ms'  => [
        'title' => 'Aset syarikat',
        'body'  => 'Daftar peralatan milik syarikat — laptop, telefon, kenderaan, perabot — dan siapa yang memegangnya sekarang. HR dan pengurusan tambah asset dan menetapkannya kepada staf.',
        'who'   => 'HR & pengurusan urus · Semua orang boleh lihat',
        'steps' => [
            'Klik "+ Add asset", beri nama, kategori dan (jika ada) nombor siri.',
            'Asset baru bermula sebagai "Available". Guna "Assign" untuk berikan kepada pekerja.',
            'Bila barang dipulangkan, guna "Return" — ia kembali ke Available untuk orang seterusnya.',
            'Kaunter di bahagian atas menunjukkan berapa banyak asset ditugaskan, bebas atau dalam penyelenggaraan.',
        ],
    ],
])
<div x-data="{ add: {{ $errors->any() ? 'true' : 'false' }} }">
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Total assets' : 'Jumlah aset'">Total assets</div><div class="uj-stat-value">{{ $assets->count() }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Assigned' : 'Ditugaskan'">Assigned</div><div class="uj-stat-value" style="color:var(--info);">{{ $assets->where('status', 'assigned')->count() }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Available' : 'Tersedia'">Available</div><div class="uj-stat-value" style="color:var(--success);">{{ $assets->where('status', 'available')->count() }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'In maintenance' : 'Dalam penyelenggaraan'">In maintenance</div><div class="uj-stat-value" style="color:var(--amber);">{{ $assets->where('status', 'maintenance')->count() }}</div></div>
</div>

@if ($privileged)
    <div x-show="add" x-cloak class="uj-card" style="padding:20px;margin-bottom:16px;">
        <h3 class="uj-card-title" style="margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'New asset' : 'Aset baharu'">New asset</h3>
        <form method="post" action="{{ route('assets.store') }}">
            @csrf
            @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:start;">
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Asset name *' : 'Nama aset *'">Asset name *</label><input name="name" value="{{ old('name') }}" required maxlength="120" style="{{ $fs }}width:100%;" /></div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</label><select name="category" style="{{ $fs }}width:100%;margin-bottom:6px;">@foreach (['laptop' => ['Laptop', 'Laptop'], 'phone' => ['Phone', 'Telefon'], 'vehicle' => ['Vehicle', 'Kenderaan'], 'furniture' => ['Furniture', 'Perabot'], 'other' => ['Other', 'Lain-lain']] as $v => $l)<option value="{{ $v }}" @selected(old('category') === $v) x-text="$store.ui.lang==='en' ? @js($l[0]) : @js($l[1])">{{ $l[0] }}</option>@endforeach</select>@include('partials.hint', ['en' => 'Sets the icon and lets you filter the register. Pick "Other" if nothing fits.', 'ms' => 'Menetapkan ikon dan membolehkan anda tapis daftar. Pilih "Other" jika tiada yang sesuai.'])</div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Serial' : 'Nombor siri'">Serial</label><input name="serial" value="{{ old('serial') }}" maxlength="80" style="{{ $fs }}width:100%;font-family:var(--font-mono);margin-bottom:6px;" />@include('partials.hint', ['en' => 'The maker\'s serial or asset tag. Helps identify the exact unit later — leave blank if there isn\'t one.', 'ms' => 'Nombor siri pembuat atau tag aset. Membantu kenal pasti unit tepat kemudian — biarkan kosong jika tiada.'])</div>
            </div>
            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;" x-text="$store.ui.lang==='en' ? 'Add asset' : 'Tambah aset'">Add asset</button>
        </form>
    </div>
@endif

<div class="uj-card">
    <div class="uj-card-head">
        <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Asset register' : 'Daftar aset'">Asset register</h3>
        @if ($privileged)<button @click="add = ! add" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;"><span x-text="add ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Add asset' : '+ Tambah aset')"></span></button>@endif
    </div>
    <div style="display:grid;grid-template-columns:2fr 1fr 1.2fr 1.4fr 1fr auto;gap:8px;padding:12px 20px;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid var(--hairline-soft);"><span x-text="$store.ui.lang==='en' ? 'Asset' : 'Aset'">Asset</span><span x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</span><span x-text="$store.ui.lang==='en' ? 'Serial' : 'Nombor siri'">Serial</span><span x-text="$store.ui.lang==='en' ? 'Assigned to' : 'Ditugaskan kepada'">Assigned to</span><span x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</span><span></span></div>
    @forelse ($assets as $a)
        <div x-data="{ asg: false }" style="display:grid;grid-template-columns:2fr 1fr 1.2fr 1.4fr 1fr auto;gap:8px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);align-items:center;">
            <div style="display:flex;align-items:center;gap:10px;"><span style="font-size:16px;">{{ $catIcon[$a->category] ?? '📦' }}</span><span style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $a->name }}</span></div>
            <span style="font-size:13px;color:var(--body);text-transform:capitalize;" x-text="$store.ui.lang==='en' ? @js(ucfirst($a->category)) : @js($catMs[$a->category] ?? ucfirst($a->category))">{{ $a->category }}</span>
            <span style="font-size:12.5px;color:var(--muted);font-family:var(--font-mono);">{{ $a->serial ?? '—' }}</span>
            <span style="font-size:13px;color:var(--body);">{{ $a->employee?->name ?? '—' }}</span>
            <span style="display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:600;color:{{ $sc[$a->status] }};"><span style="width:8px;height:8px;border-radius:50%;background:{{ $sc[$a->status] }};"></span><span x-text="$store.ui.lang==='en' ? @js($statusEn[$a->status] ?? ucfirst($a->status)) : @js($statusMs[$a->status] ?? ucfirst($a->status))">{{ ucfirst($a->status) }}</span></span>
            <span style="text-align:right;position:relative;">
                @if ($privileged)
                    @if ($a->status === 'assigned')
                        <form method="post" action="{{ route('assets.release', $a) }}">@csrf<button type="submit" class="uj-btn-ghost" style="height:30px;padding:0 11px;font-size:11.5px;" x-text="$store.ui.lang==='en' ? 'Return' : 'Pulang'">Return</button></form>
                    @else
                        <button @click="asg = ! asg" class="uj-btn-ghost" style="height:30px;padding:0 11px;font-size:11.5px;" x-text="$store.ui.lang==='en' ? 'Assign' : 'Tugaskan'">Assign</button>
                        <form method="post" action="{{ route('assets.assign', $a) }}" x-show="asg" x-cloak @click.outside="asg = false" style="position:absolute;right:0;top:36px;z-index:5;display:flex;gap:6px;background:#fff;border:1px solid var(--hairline);border-radius:9px;padding:10px;box-shadow:0 6px 20px rgba(31,30,26,.1);">
                            @csrf
                            <select name="employee_id" required style="{{ $fs }}height:34px;min-width:160px;"><option value="" x-text="$store.ui.lang==='en' ? 'Select…' : 'Pilih…'">Select…</option>@foreach ($recipients as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select>
                            <button type="submit" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Assign' : 'Tugaskan'">Assign</button>
                        </form>
                    @endif
                @endif
            </span>
        </div>
    @empty
        <div style="padding:28px 20px;text-align:center;">
            <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'No assets in the register yet' : 'Belum ada aset dalam daftar'">No assets in the register yet</div>
            <div style="font-size:12px;color:var(--muted);line-height:1.5;">@if ($privileged)<span x-text="$store.ui.lang==='en' ? 'Click &quot;+ Add asset&quot; above to register your first laptop, phone or other item — then you can assign it to staff.' : 'Klik &quot;+ Add asset&quot; di atas untuk daftar laptop, telefon atau barang pertama anda — kemudian anda boleh tetapkannya kepada staf.'"></span>@else<span x-text="$store.ui.lang==='en' ? 'No company assets have been registered yet. HR will add them here.' : 'Belum ada asset syarikat didaftarkan. HR akan menambahnya di sini.'"></span>@endif</div>
        </div>
    @endforelse
</div>
</div>
@endsection
