@if ($selected->status !== 'probation')
    <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('Only staff on probation can be confirmed.', 'Hanya pekerja dalam percubaan boleh disahkan.') !!}{{ $selected->confirmed_at ? ' · '.$selected->confirmed_at->format('d/m/Y') : '' }}</p>
@else
<form method="post" action="{{ route('progression.confirm', $selected) }}" style="display:flex;flex-direction:column;gap:16px;">
    @csrf
    @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
    <div style="max-width:320px;"><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Date Confirmed', 'Tarikh Disahkan') !!} *</label><input type="date" name="confirmed_on" required value="{{ old('confirmed_on', now()->toDateString()) }}" style="{{ $fs }}" /></div>
    @include('partials.progression.current-vs-new')
    <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Remark', 'Catatan') !!}</label><textarea name="remark" rows="2" maxlength="2000" style="{{ $fs }}height:auto;padding:8px 11px;">{{ old('remark') }}</textarea></div>
    <button type="submit" class="uj-btn-primary" style="height:40px;font-size:13px;align-self:flex-start;padding:0 24px;">{!! $L('Confirm', 'Sahkan') !!}</button>
</form>
@endif
@include('partials.progression.history', ['type' => 'confirmed'])
