{{-- Inner content fields shared by the default + per-position override forms on the
     Onboarding Content editor. Expects: $res (nullable OnboardingResource to prefill). --}}
@php
    $res = $res ?? null;
    $ff = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;width:100%;';
    $lbl = 'display:block;font-size:12px;color:var(--muted);margin-bottom:5px;';
@endphp
<div style="display:flex;flex-direction:column;gap:12px;">
    <div>
        <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Text' : 'Teks'">Text</span></label>
        <textarea name="body" rows="4" maxlength="20000" placeholder="{{ __('Write what the new hire should read…') }}" style="{{ $ff }}height:auto;padding:10px 11px;line-height:1.5;resize:vertical;">{{ $res->body ?? '' }}</textarea>
    </div>
    <div>
        <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Video link (YouTube / Vimeo)' : 'Pautan video (YouTube / Vimeo)'">Video link (YouTube / Vimeo)</span></label>
        <input name="video_url" type="url" value="{{ $res->video_url ?? '' }}" placeholder="https://youtu.be/…" style="{{ $ff }}" />
    </div>
    <div>
        <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Attachment (PDF, slides, doc)' : 'Lampiran (PDF, slaid, dokumen)'">Attachment (PDF, slides, doc)</span></label>
        @if ($res && $res->file_path)
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:7px;font-size:12.5px;">
                <a href="{{ route('onboarding.content.file', $res) }}" style="color:var(--accent, #c08532);text-decoration:none;font-weight:500;">{{ $res->file_name ?? 'Current file' }}</a>
                <label style="display:inline-flex;align-items:center;gap:6px;color:var(--red);cursor:pointer;">
                    <input type="checkbox" name="remove_file" value="1" /> <span x-text="$store.ui.lang==='en' ? 'Remove' : 'Buang'">Remove</span>
                </label>
            </div>
        @endif
        <input name="file" type="file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.png,.jpg,.jpeg" style="font-size:12px;color:var(--muted);" />
    </div>
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink);cursor:pointer;">
        <input type="checkbox" name="requires_ack" value="1" @checked($res && $res->requires_ack) />
        <span x-text="$store.ui.lang==='en' ? 'Require the hire to acknowledge before completing' : 'Wajib pekerja mengaku sebelum tanda siap'">Require the hire to acknowledge before completing</span>
    </label>
</div>
