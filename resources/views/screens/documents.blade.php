@extends('layouts.app')

@php
    $catIcon = ['Contract' => '📄', 'Certificate' => '🎓', 'ID' => '🪪', 'Other' => '📁'];
    $catColor = ['Contract' => 'var(--info)', 'Certificate' => 'var(--success)', 'ID' => 'var(--amber)', 'Other' => 'var(--muted-soft)'];
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';
    $totalDocs = $documents->flatten()->count();
    $fmtSize = fn ($b) => $b >= 1048576 ? round($b / 1048576, 1).' MB' : ($b >= 1024 ? round($b / 1024).' KB' : $b.' B');
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
<div x-data="{ add: {{ $errors->any() ? 'true' : 'false' }} }">
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Total documents' : 'Jumlah dokumen'">Total documents</div><div class="uj-stat-value">{{ $totalDocs }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Categories' : 'Kategori'">Categories</div><div class="uj-stat-value">{{ $documents->count() }}</div></div>
    @if ($privileged)
        <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Scope' : 'Skop'">Scope</div><div class="uj-stat-value" style="color:var(--info);font-size:18px;" x-text="$store.ui.lang==='en' ? 'All employees' : 'Semua pekerja'">All employees</div></div>
    @else
        <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Scope' : 'Skop'">Scope</div><div class="uj-stat-value" style="color:var(--info);font-size:18px;" x-text="$store.ui.lang==='en' ? 'My documents' : 'Dokumen saya'">My documents</div></div>
    @endif
</div>

<div x-show="add" x-cloak class="uj-card" style="padding:20px;margin-bottom:16px;">
    <h3 class="uj-card-title" style="margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'Upload document' : 'Muat naik dokumen'">Upload document</h3>
    <form method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data">
        @csrf
        @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:start;">
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Title *' : 'Tajuk *'">Title *</label><input name="title" value="{{ old('title') }}" required maxlength="160" style="{{ $fs }}width:100%;" /></div>
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</label><select name="category" style="{{ $fs }}width:100%;margin-bottom:6px;">@foreach ($categories as $c)<option value="{{ $c }}" @selected(old('category') === $c)>{{ $c }}</option>@endforeach</select>@include('partials.hint', ['en' => 'Helps people find the file later. Contract for offer letters and agreements, Certificate for qualifications, ID for IC/passport.', 'ms' => 'Membantu orang mencari fail kemudian. Contract untuk surat tawaran dan perjanjian, Certificate untuk kelayakan, ID untuk IC/pasport.'])</div>
            @if ($privileged)
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Owner *' : 'Pemilik *'">Owner *</label><select name="employee_id" required style="{{ $fs }}width:100%;margin-bottom:6px;"><option value="" x-text="$store.ui.lang==='en' ? 'Select employee…' : 'Pilih pekerja…'">Select employee…</option>@foreach ($employees as $e)<option value="{{ $e->id }}" @selected((string) old('employee_id') === (string) $e->id)>{{ $e->name }}</option>@endforeach</select>@include('partials.hint', ['en' => 'Whose private file this is. Double-check — only this person and HR will see it, so the wrong choice leaks personal data.', 'ms' => 'Fail peribadi ini milik siapa. Semak dua kali — hanya orang ini dan HR akan melihatnya, jadi pilihan yang salah membocorkan data peribadi.', 'tone' => 'warn'])</div>
            @else
                <input type="hidden" name="employee_id" value="{{ $employee?->id }}" />
            @endif
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'File *' : 'Fail *'">File *</label><input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style="{{ $fs }}width:100%;padding:7px 11px;margin-bottom:6px;" />@include('partials.hint', ['en' => 'PDF, JPG, PNG, DOC or DOCX, up to 8 MB. Scans and photos of documents are fine.', 'ms' => 'PDF, JPG, PNG, DOC atau DOCX, sehingga 8 MB. Imbasan dan gambar dokumen pun boleh.'])</div>
        </div>
        <p style="font-size:11.5px;color:var(--muted);margin-top:10px;" x-text="$store.ui.lang==='en' ? 'PDF, JPG, PNG, DOC or DOCX · max 8 MB. Files are stored privately and downloaded only through a secure link.' : 'PDF, JPG, PNG, DOC atau DOCX · maksimum 8 MB. Fail disimpan secara peribadi dan dimuat turun hanya melalui pautan selamat.'">PDF, JPG, PNG, DOC or DOCX · max 8 MB. Files are stored privately and downloaded only through a secure link.</p>
        <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:12px;" x-text="$store.ui.lang==='en' ? 'Upload' : 'Muat naik'">Upload</button>
    </form>
</div>

<div class="uj-card">
    <div class="uj-card-head">
        <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Document vault' : 'Peti dokumen'">Document vault</h3>
        <button @click="add = ! add" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;"><span x-text="add ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Upload' : '+ Muat naik')"></span></button>
    </div>

    @forelse ($documents as $category => $docs)
        <div style="padding:13px 20px 6px;display:flex;align-items:center;gap:8px;font-size:11px;font-weight:600;color:{{ $catColor[$category] ?? 'var(--muted)' }};text-transform:uppercase;letter-spacing:0.5px;border-top:1px solid var(--hairline-soft);">
            <span style="font-size:14px;">{{ $catIcon[$category] ?? '📁' }}</span>{{ $category }}<span style="color:var(--muted);font-weight:500;">· {{ $docs->count() }}</span>
        </div>
        @foreach ($docs as $doc)
            <div style="display:grid;grid-template-columns:2.2fr 1.6fr 1fr 1fr auto;gap:8px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);align-items:center;">
                <div style="display:flex;flex-direction:column;gap:2px;"><span style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $doc->title }}</span><span style="font-size:11.5px;color:var(--muted);font-family:var(--font-mono);">{{ $doc->original_name }}</span></div>
                <span style="font-size:13px;color:var(--body);">{{ $doc->employee?->name ?? '—' }}</span>
                <span style="font-size:12.5px;color:var(--muted);">{{ $fmtSize($doc->size) }}</span>
                <span style="font-size:12.5px;color:var(--muted);">{{ $doc->created_at?->format('d M Y') }}</span>
                <span style="text-align:right;display:flex;gap:6px;justify-content:flex-end;">
                    <a href="{{ route('documents.download', $doc) }}" class="uj-btn-ghost" style="height:30px;padding:0 11px;font-size:11.5px;display:inline-flex;align-items:center;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Download' : 'Muat turun'">Download</a>
                    <form method="post" action="{{ route('documents.destroy', $doc) }}" onsubmit="return confirm('Delete this document?');">@csrf<button type="submit" class="uj-btn-ghost" style="height:30px;padding:0 11px;font-size:11.5px;color:var(--red);" x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</button></form>
                </span>
            </div>
        @endforeach
    @empty
        <div style="padding:28px 20px;text-align:center;">
            <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No documents yet' : 'Belum ada dokumen'"></span></div>
            @php
                $emptyEn = 'Click "+ Upload" to add the first file. ' . ($privileged ? 'Pick the right owner so it stays private to them.' : 'Only you and HR will be able to see it.');
                $emptyMs = 'Klik "+ Upload" untuk tambah fail pertama. ' . ($privileged ? 'Pilih pemilik yang betul supaya ia kekal peribadi kepada mereka.' : 'Hanya anda dan HR akan dapat melihatnya.');
            @endphp
            <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? {{ \Illuminate\Support\Js::from($emptyEn) }} : {{ \Illuminate\Support\Js::from($emptyMs) }}"></span></div>
        </div>
    @endforelse
</div>
</div>
@endsection
