@if ($selected->status !== 'resigned')
    <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('Only resigned staff can be rehired.', 'Hanya pekerja yang telah berhenti boleh diambil semula.') !!}</p>
@else
<form method="post" action="{{ route('progression.rehire', $selected) }}" style="display:flex;flex-direction:column;gap:16px;">
    @csrf
    @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
    <div style="max-width:320px;"><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Rehire Date', 'Tarikh Ambil Semula') !!} *</label><input type="date" name="hired_on" required value="{{ old('hired_on', now()->toDateString()) }}" style="{{ $fs }}" /></div>
    @include('partials.profile.employment-form-fields', ['e' => $selected, 'canSeeSalary' => $canSeeSalary])
    <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Remark', 'Catatan') !!}</label><textarea name="remark" rows="2" maxlength="2000" style="{{ $fs }}height:auto;padding:8px 11px;">{{ old('remark') }}</textarea></div>
    <button type="submit" class="uj-btn-primary" style="height:40px;font-size:13px;align-self:flex-start;padding:0 24px;">{!! $L('Rehire', 'Ambil semula') !!}</button>
</form>
@endif
@include('partials.progression.history', ['type' => 'rehired'])
