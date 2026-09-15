@if ($selected->status === 'resigned')
    <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('Left on', 'Berhenti pada') !!} {{ $selected->last_working_day?->format('d/m/Y') ?? $selected->resigned_at?->format('d/m/Y') ?? '—' }}</p>
@else
<form method="post" action="{{ route('progression.resign', $selected) }}" style="display:flex;flex-direction:column;gap:16px;"
      x-data="{
          resignedOn: @js(old('resigned_on', now()->toDateString())),
          lastDay: @js(old('last_working_day', '')),
          months: @js((int) $selected->resign_notice_months), days: @js((int) $selected->resign_notice_days),
          suggest() {
              if (! this.resignedOn) return;
              const d = new Date(this.resignedOn + 'T00:00:00');
              d.setMonth(d.getMonth() + this.months); d.setDate(d.getDate() + this.days);
              this.lastDay = d.toISOString().slice(0, 10);
          },
      }" x-init="if (! lastDay) suggest()">
    @csrf
    @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 16px;max-width:760px;">
        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Resigned Date', 'Tarikh Berhenti') !!} *</label><input type="date" name="resigned_on" required x-model="resignedOn" @change="suggest()" style="{{ $fs }}" /></div>
        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Last Working Day', 'Hari Terakhir Bekerja') !!} *</label><input type="date" name="last_working_day" required x-model="lastDay" style="{{ $fs }}" />
            <div style="font-size:11px;color:var(--muted);margin-top:3px;">{!! $L('Notice period', 'Tempoh notis') !!}: {{ $selected->resign_notice_months ?: 0 }}M {{ $selected->resign_notice_days ?: 0 }}D</div></div>
        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Reason', 'Sebab') !!} *</label>
            <select name="reason" required style="{{ $fs }}">
                @foreach (['resigned' => ['Resigned', 'Meletak jawatan'], 'contract_ended' => ['Contract ended', 'Kontrak tamat'], 'terminated' => ['Terminated', 'Ditamatkan'], 'retired' => ['Retired', 'Bersara'], 'other' => ['Other', 'Lain-lain']] as $k => [$en, $ms])
                    <option value="{{ $k }}" @selected(old('reason', 'resigned') === $k)>{{ $en }}</option>
                @endforeach
            </select></div>
    </div>
    <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Remark', 'Catatan') !!}</label><textarea name="remark" rows="2" maxlength="2000" style="{{ $fs }}height:auto;padding:8px 11px;">{{ old('remark') }}</textarea></div>
    <button type="submit" class="uj-btn-primary" style="height:40px;font-size:13px;align-self:flex-start;padding:0 24px;">{!! $L('Record resignation', 'Rekod perletakan jawatan') !!}</button>
</form>
@endif
@include('partials.progression.history', ['type' => 'resigned'])
