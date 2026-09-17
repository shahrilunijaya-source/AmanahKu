{{-- Attachment: employee documents grouped by category, upload + delete via the Documents endpoints
     (both back() → this tab). Expects $p, $documents, $fs. --}}
@php
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $lbl = 'display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;';
    $cats = ['Contract' => 'Kontrak', 'Certificate' => 'Sijil', 'ID' => 'Pengenalan', 'Other' => 'Lain-lain'];
    $groups = $documents->sortByDesc('created_at')->groupBy('category');
    $mine = old('_form') === 'attachment';
@endphp

<div x-data="{ add: {{ $mine && $errors->any() ? 'true' : 'false' }} }">
    <div style="display:flex;justify-content:flex-end;"><button type="button" @click="add = !add" class="uj-btn-ghost" style="height:32px;padding:0 14px;font-size:12.5px;">+ {!! $L('Upload', 'Muat naik') !!}</button></div>
    <form x-show="add" x-cloak method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data" class="uj-card" style="margin-top:8px;padding:14px;display:flex;flex-direction:column;gap:10px;"
          x-data="{ over: false, name: '' }">
        @csrf
        <input type="hidden" name="_form" value="attachment" />
        <input type="hidden" name="employee_id" value="{{ $p->id }}" />
        @if ($mine && $errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 14px;">
            <div><label style="{{ $lbl }}">{!! $L('Title', 'Tajuk') !!}</label><input name="title" required maxlength="160" value="{{ $mine ? old('title') : '' }}" style="{{ $fs }}" /></div>
            <div><label style="{{ $lbl }}">{!! $L('Category', 'Kategori') !!}</label><select name="category" style="{{ $fs }}">@foreach ($cats as $en => $ms)<option value="{{ $en }}" @selected(($mine ? old('category') : 'Other') === $en)>{{ $en }}</option>@endforeach</select></div>
        </div>
        <label @dragover.prevent="over = true" @dragleave="over = false" @drop.prevent="over = false; $refs.file.files = $event.dataTransfer.files; name = $refs.file.files[0]?.name ?? ''"
               :style="over ? 'border-color:var(--red);background:var(--red-tint);' : ''"
               style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:22px;border:1.5px dashed var(--hairline);border-radius:10px;cursor:pointer;font-size:12.5px;color:var(--muted);">
            <input type="file" name="file" x-ref="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" @change="name = $event.target.files[0]?.name ?? ''" style="display:none;" />
            <span x-show="!name">{!! $L('Drop a file here or click to choose · PDF, JPG, PNG, DOC, DOCX up to 8 MB', 'Seret fail ke sini atau klik untuk pilih · PDF, JPG, PNG, DOC, DOCX sehingga 8 MB') !!}</span>
            <span x-show="name" x-text="name" style="color:var(--ink);font-weight:500;"></span>
        </label>
        <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;align-self:flex-start;">{!! $L('Upload', 'Muat naik') !!}</button>
    </form>
</div>

@forelse ($groups as $cat => $docs)
    <div>
        <div class="uj-section-head" style="margin-bottom:10px;">{!! $L($cat, $cats[$cat] ?? $cat) !!}</div>
        @foreach ($docs as $doc)
            <div class="uj-row" style="display:flex;align-items:center;gap:12px;padding:10px 0;border-top:1px solid var(--hairline-soft);">
                <span style="font-size:18px;">{{ str_starts_with((string) $doc->mime, 'image/') ? '🖼️' : '📄' }}</span>
                <div style="flex:1;min-width:0;">
                    <a href="{{ route('documents.download', $doc) }}" style="font-size:13px;color:var(--ink);font-weight:500;">{{ $doc->title }}</a>
                    <div style="font-size:11.5px;color:var(--muted);">{{ $doc->original_name }} · {{ number_format($doc->size / 1024) }} KB · {{ $doc->created_at?->format('d/m/Y') }}</div>
                </div>
                <form method="post" action="{{ route('documents.destroy', $doc) }}" onsubmit="return confirm('Delete this document?')">@csrf<button type="submit" class="uj-btn-ghost" style="height:28px;padding:0 10px;font-size:12px;color:var(--red);">{!! $L('Delete', 'Padam') !!}</button></form>
            </div>
        @endforeach
    </div>
@empty
    <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No documents yet.', 'Tiada dokumen lagi.') !!}</p>
@endforelse
