@if ($selected->status === 'resigned')
    <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('This person has resigned. Rehire them first.', 'Orang ini telah berhenti. Ambil semula dahulu.') !!}</p>
@else
<form method="post" action="{{ route('progression.update', $selected) }}" style="display:flex;flex-direction:column;gap:16px;">
    @csrf
    @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
    <div style="max-width:320px;"><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Effective Date', 'Tarikh Berkuat Kuasa') !!} *</label><input type="date" name="effective_on" required value="{{ old('effective_on', now()->toDateString()) }}" style="{{ $fs }}" /></div>
    <div style="max-width:320px;"><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Update Type', 'Jenis Kemas Kini') !!} *</label>
        <select name="update_type" required style="{{ $fs }}"><option value="">—</option>@foreach (\App\Services\EmploymentRecordService::UPDATE_TYPES as $k => [$en, $ms])<option value="{{ $k }}" @selected(old('update_type') === $k)>{{ $en }}</option>@endforeach</select></div>
    @include('partials.progression.current-vs-new')
    <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Remark', 'Catatan') !!}</label><textarea name="remark" rows="2" maxlength="2000" style="{{ $fs }}height:auto;padding:8px 11px;">{{ old('remark') }}</textarea></div>
    <button type="submit" class="uj-btn-primary" style="height:40px;font-size:13px;align-self:flex-start;padding:0 24px;">{!! $L('Save update', 'Simpan kemas kini') !!}</button>
</form>
@endif
@include('partials.progression.history', ['type' => 'updated'])
