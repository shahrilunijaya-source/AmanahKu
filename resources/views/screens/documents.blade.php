@extends('layouts.app')

@php
    // Row icon tile per category — same tinted-tile convention as the helpdesk
    // $categoryMeta (resources/views/screens/helpdesk.blade.php) and Leave/Claims
    // disclosure rows. Reuses the same info/amber/success tint tokens as helpdesk
    // for visual consistency across screens.
    $catMeta = [
        'Contract'    => ['tint' => 'var(--info)', 'bg' => 'var(--info-tint,#eef4fb)', 'icon' => '<path d="M14 3v5h5"/><path d="M6 3h8l6 6v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M9 13h6M9 17h6"/>'],
        'Certificate' => ['tint' => 'var(--success)', 'bg' => 'var(--success-tint,#e7f4ec)', 'icon' => '<circle cx="12" cy="8" r="5"/><path d="M8.5 13 7 21l5-3 5 3-1.5-8"/>'],
        'ID'          => ['tint' => 'var(--amber)', 'bg' => 'var(--amber-tint,#f7efe0)', 'icon' => '<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="9" cy="12" r="2"/><path d="M14 10h5M14 14h5"/>'],
        'Other'       => ['tint' => 'var(--muted)', 'bg' => 'var(--shelf)', 'icon' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/>'],
    ];
    // .uj-stamp only supports red/amber/success/error tones (resources/css/app.css:353-364);
    // Contract and Other fall back to the stamp's neutral default rather than an
    // unsupported "info" tone.
    $catTone = ['Contract' => null, 'Certificate' => 'success', 'ID' => 'amber', 'Other' => null];
    $totalDocs = $documents->flatten()->count();
    $fmtSize = fn ($b) => $b >= 1048576 ? round($b / 1048576, 1).' MB' : ($b >= 1024 ? round($b / 1024).' KB' : $b.' B');
    $scopeEn = $privileged ? 'All employees' : 'My documents';
    $scopeMs = $privileged ? 'Semua pekerja' : 'Dokumen saya';
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'documents',
    'en'  => [
        'title' => 'Document vault',
        'body'  => $privileged
            ? 'A private store for each employee\'s files — contracts, certificates, IDs and the like. Documents belong to one employee and are visible only to that person and authorised HR. Always confirm you are uploading to the correct owner.'
            : 'Your own private file store — contracts, certificates, IDs and the like. Only you and authorised HR can see these. Files are stored securely and opened only through a protected download link.',
    ],
    'ms'  => [
        'title' => 'Peti dokumen',
        'body'  => $privileged
            ? 'Simpanan peribadi untuk fail setiap pekerja — kontrak, sijil, ID dan seumpamanya. Dokumen milik seorang pekerja sahaja dan hanya boleh dilihat oleh orang itu dan HR yang dibenarkan. Sentiasa sahkan anda memuat naik kepada pemilik yang betul demi menjaga privasi.'
            : 'Simpanan fail peribadi anda sendiri — kontrak, sijil, ID dan seumpamanya. Hanya anda dan HR yang dibenarkan boleh melihatnya. Fail disimpan dengan selamat dan dibuka hanya melalui pautan muat turun yang dilindungi.',
    ],
])
@php
    $catLabels = ['Contract' => ['en' => 'Contracts', 'ms' => 'Kontrak'], 'Certificate' => ['en' => 'Certificates', 'ms' => 'Sijil'], 'ID' => ['en' => 'IDs', 'ms' => 'ID'], 'Other' => ['en' => 'Other', 'ms' => 'Lain-lain']];
    $catCounts = $documents->map->count();
@endphp
<div x-data="{
        add: {{ $errors->any() ? 'true' : 'false' }},
        q: '',
        cat: '',
        folded: {},
        hit(text) { const q = this.q.trim().toLowerCase(); return ! q || text.toLowerCase().includes(q); },
        showCat(c) { return ! this.cat || this.cat === c; },
    }">
<div x-show="add" x-cloak class="uj-card" style="padding:20px;margin-bottom:16px;">
    <h3 class="uj-card-title" style="margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'Upload document' : 'Muat naik dokumen'">Upload document</h3>
    <form method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data">
        @csrf
        @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:start;">
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Title *' : 'Tajuk *'">Title *</label><input name="title" value="{{ old('title') }}" required maxlength="160" class="uj-lv-in" /></div>
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</label><select name="category" class="uj-lv-in" style="margin-bottom:6px;">@foreach ($categories as $c)<option value="{{ $c }}" @selected(old('category') === $c)>{{ $c }}</option>@endforeach</select>@include('partials.hint', ['en' => 'Helps people find the file later. Contract for offer letters and agreements, Certificate for qualifications, ID for IC/passport.', 'ms' => 'Membantu orang mencari fail kemudian. Contract untuk surat tawaran dan perjanjian, Certificate untuk kelayakan, ID untuk IC/pasport.'])</div>
            @if ($privileged)
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Owner *' : 'Pemilik *'">Owner *</label><select name="employee_id" required class="uj-lv-in" style="margin-bottom:6px;"><option value="" x-text="$store.ui.lang==='en' ? 'Select employee…' : 'Pilih pekerja…'">Select employee…</option>@foreach ($employees as $e)<option value="{{ $e->id }}" @selected((string) old('employee_id') === (string) $e->id)>{{ $e->name }}</option>@endforeach</select>@include('partials.hint', ['en' => 'Whose private file this is. Double-check — only this person and HR will see it, so the wrong choice leaks personal data.', 'ms' => 'Fail peribadi ini milik siapa. Semak dua kali — hanya orang ini dan HR akan melihatnya, jadi pilihan yang salah membocorkan data peribadi.', 'tone' => 'warn'])</div>
            @else
                <input type="hidden" name="employee_id" value="{{ $employee?->id }}" />
            @endif
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'File *' : 'Fail *'">File *</label><div class="uj-lv-file"><input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" /></div>@include('partials.hint', ['en' => 'PDF, JPG, PNG, DOC or DOCX, up to 8 MB. Scans and photos of documents are fine.', 'ms' => 'PDF, JPG, PNG, DOC atau DOCX, sehingga 8 MB. Imbasan dan gambar dokumen pun boleh.'])</div>
        </div>
        <p style="font-size:11.5px;color:var(--muted);margin-top:10px;" x-text="$store.ui.lang==='en' ? 'PDF, JPG, PNG, DOC or DOCX · max 8 MB. Files are stored privately and downloaded only through a secure link.' : 'PDF, JPG, PNG, DOC atau DOCX · maksimum 8 MB. Fail disimpan secara peribadi dan dimuat turun hanya melalui pautan selamat.'">PDF, JPG, PNG, DOC or DOCX · max 8 MB. Files are stored privately and downloaded only through a secure link.</p>
        <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:12px;" x-text="$store.ui.lang==='en' ? 'Upload' : 'Muat naik'">Upload</button>
    </form>
</div>

<div class="uj-card uj-doc-list">
    <div class="uj-card-head" style="flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Document vault' : 'Peti dokumen'">Document vault</h3>
            <span class="uj-pill" style="background:var(--canvas);color:var(--muted);">{{ $totalDocs }}</span>
            <span class="uj-pill" style="background:var(--canvas);color:var(--muted);" x-text="$store.ui.lang==='en' ? @js($scopeEn) : @js($scopeMs)">{{ $scopeEn }}</span>
        </div>
        <button @click="add = ! add" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;" data-tip-end :data-tip="$store.ui.lang==='en' ? 'Add a file to the vault' : 'Tambah fail ke peti'"><span x-text="add ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Upload' : '+ Muat naik')"></span></button>
    </div>

    @if ($totalDocs > 0)
        {{-- Filter row: a search over title and owner, plus one chip per category. --}}
        <div class="uj-doc-tools">
            <label class="uj-mgmt-search">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                <input type="search" x-model="q" :placeholder="$store.ui.lang==='en' ? @js($privileged ? 'Filter by title or person' : 'Filter by title') : @js($privileged ? 'Tapis ikut tajuk atau nama' : 'Tapis ikut tajuk')" autocomplete="off">
            </label>
            <div class="uj-seg">
                <button type="button" :data-on="cat === '' ? '' : null" @click="cat = ''" data-tip-below :data-tip="$store.ui.lang==='en' ? 'Show every category' : 'Tunjuk semua kategori'"><span x-text="$store.ui.lang==='en' ? 'All' : 'Semua'">All</span>&nbsp;<span class="uj-doc-n">{{ $totalDocs }}</span></button>
                @foreach ($documents as $category => $docs)
                    <button type="button" :data-on="cat === @js($category) ? '' : null" @click="cat = cat === @js($category) ? '' : @js($category)" data-tip-below :data-tip="$store.ui.lang==='en' ? 'Only this category, click again to clear' : 'Kategori ini sahaja, klik lagi untuk kosongkan'"><span x-text="$store.ui.lang==='en' ? @js($catLabels[$category]['en'] ?? $category) : @js($catLabels[$category]['ms'] ?? $category)">{{ $category }}</span>&nbsp;<span class="uj-doc-n">{{ $docs->count() }}</span></button>
                @endforeach
            </div>
        </div>
    @endif

    @forelse ($documents as $category => $docs)
        @php $cm = $catMeta[$category] ?? $catMeta['Other']; @endphp
        @php $searchable = $docs->map(fn ($d) => $d->title.' '.($privileged ? ($d->employee?->name ?? '') : ''))->all(); @endphp
        <button type="button" class="uj-doc-cat" data-tip-start :data-tip="folded[@js($category)] ? ($store.ui.lang==='en' ? 'Expand' : 'Kembangkan') : ($store.ui.lang==='en' ? 'Collapse' : 'Lipat')" x-show="showCat(@js($category)) && @js($searchable).some(t => hit(t))" @click="folded[@js($category)] = ! folded[@js($category)]" :aria-expanded="! folded[@js($category)]">
            <span class="uj-mgmt-chev" aria-hidden="true" :data-open="folded[@js($category)] ? null : ''">&#9656;</span>
            <span style="color:{{ $cm['tint'] }};">{{ $category }}</span>
            <span class="uj-doc-n">{{ $docs->count() }}</span>
        </button>
        @foreach ($docs as $i => $doc)
            <div class="uj-lv-rw" x-data="{ drawerOpen: false }" x-show="showCat(@js($category)) && ! folded[@js($category)] && hit(@js($searchable[$i]))">
                <button type="button" class="uj-lv-rw-head" @click="drawerOpen = true">
                    <span class="uj-lv-rw-ico" style="background:{{ $cm['bg'] }};color:{{ $cm['tint'] }};">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">{!! $cm['icon'] !!}</svg>
                    </span>
                    <span class="uj-lv-rw-t">
                        <span class="uj-lv-rw-1">{{ $doc->title }}</span>
                        <span class="uj-lv-rw-2">
                            @if ($privileged){{ $doc->employee?->name ?? '—' }} · @endif
                            {{ $fmtSize($doc->size) }} · {{ $doc->created_at?->format('d M Y') }}
                        </span>
                    </span>
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;color:var(--muted-soft);"><path d="M9 6l6 6-6 6"/></svg>
                </button>

                @include('partials.document-drawer', ['doc' => $doc, 'catTone' => $catTone, 'privileged' => $privileged, 'fmtSize' => $fmtSize])
            </div>
        @endforeach
    @empty
        <div class="uj-lv-empty">
            <b x-text="$store.ui.lang==='en' ? 'No documents yet' : 'Belum ada dokumen'">No documents yet</b>
            @php
                $emptyEn = 'Click "+ Upload" to add the first file. ' . ($privileged ? 'Pick the right owner so it stays private to them.' : 'Only you and HR will be able to see it.');
                $emptyMs = 'Klik "+ Upload" untuk tambah fail pertama. ' . ($privileged ? 'Pilih pemilik yang betul supaya ia kekal peribadi kepada mereka.' : 'Hanya anda dan HR akan dapat melihatnya.');
            @endphp
            <span x-text="$store.ui.lang==='en' ? @js($emptyEn) : @js($emptyMs)"></span>
        </div>
    @endforelse
</div>
</div>
@endsection
