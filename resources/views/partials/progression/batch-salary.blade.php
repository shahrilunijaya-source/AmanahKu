@php
    $lbl = 'display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;';
    $th = 'padding:6px 8px;';
@endphp
<form method="post" action="{{ route('progression.batch.salary') }}" style="display:flex;flex-direction:column;gap:18px;"
      x-data="{ ids: @js(array_map('strval', (array) old('employee_ids', []))), f: { q: '', dept: '', branch: '', pos: '', status: '' }, mode: @js(old('mode', 'increase_percent')), value: @js(old('value', '')),
                show(row) { const d = row.dataset; return (!this.f.q || d.name.toLowerCase().includes(this.f.q.toLowerCase())) && (!this.f.dept || d.dept === this.f.dept) && (!this.f.branch || d.branch === this.f.branch) && (!this.f.pos || d.pos === this.f.pos) && (!this.f.status || d.status === this.f.status); },
                visible() { return [...$el.querySelectorAll('tr[data-emp]')].filter(r => this.show(r)).map(r => r.dataset.emp); },
                rows() { return [...$el.querySelectorAll('tr[data-emp]')].filter(r => this.ids.includes(r.dataset.emp)); },
                calc(cur) { const v = parseFloat(this.value) || 0; const n = this.mode === 'increase_amount' ? cur + v : this.mode === 'increase_percent' ? cur * (1 + v / 100) : v; return Math.round(n * 100) / 100; },
                rm(n) { return 'RM ' + n.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); } }">
    @csrf
    @if ($errors->any())
        <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>
    @endif
    @include('partials.progression.batch-picker')

    <div class="uj-section-head">{!! $L('2 · Adjustment', '2 · Pelarasan') !!}</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px 16px;">
        <div><label style="{{ $lbl }}">{!! $L('Effective date', 'Tarikh berkuat kuasa') !!}</label><input type="date" name="effective_on" required value="{{ old('effective_on', now()->toDateString()) }}" style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('Mode', 'Mod') !!}</label>
            <select name="mode" x-model="mode" style="{{ $fs }}"><option value="increase_percent">Increase by %</option><option value="increase_amount">Increase by RM</option><option value="set_amount">Set to RM</option></select></div>
        <div><label style="{{ $lbl }}" x-text="mode === 'increase_percent' ? '%' : 'RM'"></label><input type="number" step="0.01" min="0" name="value" x-model="value" required style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('Remark', 'Catatan') !!}</label><input name="remark" maxlength="2000" value="{{ old('remark') }}" style="{{ $fs }}" /></div>
    </div>
    <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--body);"><input type="hidden" name="override_band" value="0" /><input type="checkbox" name="override_band" value="1" @checked(old('override_band')) /> {!! $L('Allow salaries above the position band maximum (logged)', 'Benarkan gaji melebihi had maksimum jawatan (direkodkan)') !!}</label>

    <div class="uj-section-head">{!! $L('3 · Preview', '3 · Pratonton') !!}</div>
    <div x-show="!ids.length" style="font-size:12.5px;color:var(--muted);">{!! $L('Pick staff to see the preview.', 'Pilih staf untuk melihat pratonton.') !!}</div>
    <div x-show="ids.length" x-cloak style="overflow:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="color:var(--muted);font-size:11px;text-align:left;"><th style="{{ $th }}">{!! $L('Name', 'Nama') !!}</th><th style="{{ $th }}">{!! $L('Current', 'Semasa') !!}</th><th style="{{ $th }}">{!! $L('New', 'Baharu') !!}</th><th style="{{ $th }}">{!! $L('Change', 'Perubahan') !!}</th></tr></thead>
            <tbody><template x-for="r in rows()" :key="r.dataset.emp"><tr style="border-top:1px solid var(--hairline-soft);">
                <td style="{{ $th }}color:var(--ink);font-weight:500;" x-text="r.dataset.name"></td>
                <td style="{{ $th }}" x-text="rm(parseFloat(r.dataset.salary))"></td>
                <td style="{{ $th }}color:var(--ink);font-weight:600;" x-text="rm(calc(parseFloat(r.dataset.salary)))"></td>
                <td style="{{ $th }}" :style="calc(parseFloat(r.dataset.salary)) < parseFloat(r.dataset.salary) ? 'color:var(--red);' : 'color:var(--success-ink);'" x-text="rm(calc(parseFloat(r.dataset.salary)) - parseFloat(r.dataset.salary))"></td>
            </tr></template></tbody>
        </table>
    </div>
    <button type="submit" class="uj-btn-primary" :disabled="!ids.length || ids.length > 200" style="height:40px;padding:0 24px;font-size:13px;align-self:flex-start;">{!! $L('Confirm salary adjustment', 'Sahkan pelarasan gaji') !!}</button>
</form>
