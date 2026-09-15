@php
    $fieldL = [
        'department_id' => ['Department', 'Jabatan'], 'branch_id' => ['Branch', 'Cawangan'], 'division' => ['Division', 'Bahagian'],
        'section' => ['Section', 'Seksyen'], 'position_id' => ['Position', 'Jawatan'], 'reports_to_id' => ['Reporting To', 'Melapor Kepada'],
        'employment_type_id' => ['Employment Type', 'Jenis Pekerjaan'], 'payment_term' => ['Payment Term', 'Tempoh Bayaran'], 'payment_method' => ['Payment Method', 'Kaedah Bayaran'],
    ];
    $lbl = 'display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;';
    $th = 'padding:6px 8px;';
@endphp
<form method="post" action="{{ route('progression.batch.update') }}" style="display:flex;flex-direction:column;gap:18px;"
      x-data="{ ids: @js(array_map('strval', (array) old('employee_ids', []))), f: { q: '', dept: '', branch: '', pos: '', status: '' }, fields: @js(array_values((array) old('fields', []))),
                show(row) { const d = row.dataset; return (!this.f.q || d.name.toLowerCase().includes(this.f.q.toLowerCase())) && (!this.f.dept || d.dept === this.f.dept) && (!this.f.branch || d.branch === this.f.branch) && (!this.f.pos || d.pos === this.f.pos) && (!this.f.status || d.status === this.f.status); },
                visible() { return [...$el.querySelectorAll('tr[data-emp]')].filter(r => this.show(r)).map(r => r.dataset.emp); },
                rows() { return [...$el.querySelectorAll('tr[data-emp]')].filter(r => this.ids.includes(r.dataset.emp)); },
                newText(k) { const el = $el.querySelector('[name=' + k + ']'); if (!el) return ''; return el.tagName === 'SELECT' ? (el.selectedOptions[0]?.text ?? '') : el.value; },
                tick: 0 }"
      @input="tick++" @change="tick++">
    @csrf
    @if ($errors->any())
        <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>
    @endif
    @include('partials.progression.batch-picker')

    <div class="uj-section-head">{!! $L('2 · What changes', '2 · Apa yang berubah') !!}</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 16px;">
        <div><label style="{{ $lbl }}">{!! $L('Effective date', 'Tarikh berkuat kuasa') !!}</label><input type="date" name="effective_on" required value="{{ old('effective_on', now()->toDateString()) }}" style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('Update Type', 'Jenis Kemas Kini') !!}</label><select name="update_type" required style="{{ $fs }}">@foreach (\App\Services\EmploymentRecordService::UPDATE_TYPES as $k => [$en, $ms])<option value="{{ $k }}" @selected(old('update_type', 'role_transfer') === $k)>{{ $en }}</option>@endforeach</select></div>
        <div><label style="{{ $lbl }}">{!! $L('Remark', 'Catatan') !!}</label><input name="remark" maxlength="2000" value="{{ old('remark') }}" style="{{ $fs }}" /></div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px 16px;">
        @foreach ($fieldL as $k => [$en, $ms])
            <div>
                <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--ink);margin-bottom:4px;"><input type="checkbox" name="fields[]" value="{{ $k }}" x-model="fields" /> {!! $L($en, $ms) !!}</label>
                <div x-show="fields.includes('{{ $k }}')" x-cloak>
                    @switch($k)
                        @case('department_id') <select name="department_id" style="{{ $fs }}"><option value="">—</option>@foreach ($allDepartments as $o)<option value="{{ $o->id }}" @selected(old('department_id') == $o->id)>{{ $o->name }}</option>@endforeach</select> @break
                        @case('branch_id') <select name="branch_id" style="{{ $fs }}"><option value="">—</option>@foreach ($allBranches as $o)<option value="{{ $o->id }}" @selected(old('branch_id') == $o->id)>{{ $o->name }}</option>@endforeach</select> @break
                        @case('position_id') <select name="position_id" style="{{ $fs }}"><option value="">—</option>@foreach ($allPositions as $o)<option value="{{ $o->id }}" @selected(old('position_id') == $o->id)>{{ $o->title }}</option>@endforeach</select> @break
                        @case('reports_to_id') <select name="reports_to_id" style="{{ $fs }}"><option value="">—</option>@foreach ($allManagers as $o)<option value="{{ $o->id }}" @selected(old('reports_to_id') == $o->id)>{{ $o->name }}</option>@endforeach</select> @break
                        @case('employment_type_id') <select name="employment_type_id" style="{{ $fs }}"><option value="">—</option>@foreach ($allEmploymentTypes as $o)<option value="{{ $o->id }}" @selected(old('employment_type_id') == $o->id)>{{ $o->name }}</option>@endforeach</select> @break
                        @case('payment_term') <select name="payment_term" style="{{ $fs }}">@foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'biweekly' => 'Bi-Weekly', 'monthly' => 'Monthly'] as $v => $t)<option value="{{ $v }}" @selected(old('payment_term', 'monthly') === $v)>{{ $t }}</option>@endforeach</select> @break
                        @case('payment_method') <select name="payment_method" style="{{ $fs }}">@foreach (['cash' => 'Cash', 'bank' => 'Bank', 'cheque' => 'Cheque'] as $v => $t)<option value="{{ $v }}" @selected(old('payment_method', 'bank') === $v)>{{ $t }}</option>@endforeach</select> @break
                        @default <input name="{{ $k }}" maxlength="80" value="{{ old($k) }}" style="{{ $fs }}" />
                    @endswitch
                </div>
            </div>
        @endforeach
    </div>

    <div class="uj-section-head">{!! $L('3 · Preview', '3 · Pratonton') !!}</div>
    <div x-show="!ids.length || !fields.length" style="font-size:12.5px;color:var(--muted);">{!! $L('Pick staff and tick at least one field to see the preview.', 'Pilih staf dan tanda sekurang-kurangnya satu medan untuk melihat pratonton.') !!}</div>
    <div x-show="ids.length && fields.length" x-cloak style="overflow:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="color:var(--muted);font-size:11px;text-align:left;">
                <th style="{{ $th }}">{!! $L('Name', 'Nama') !!}</th>
                <template x-for="k in fields" :key="k"><th style="{{ $th }}" x-text="@js(array_map(fn ($p) => $p[0], $fieldL))[k]"></th></template>
            </tr></thead>
            <tbody><template x-for="r in rows()" :key="r.dataset.emp"><tr style="border-top:1px solid var(--hairline-soft);">
                <td style="{{ $th }}color:var(--ink);font-weight:500;" x-text="r.dataset.name"></td>
                <template x-for="k in fields" :key="k"><td style="{{ $th }}"><span style="color:var(--muted);" x-text="r.getAttribute('data-cur-' + k)"></span> <span style="color:var(--muted);">›</span> <b x-text="(tick, newText(k) || '—')"></b></td></template>
            </tr></template></tbody>
        </table>
    </div>
    <button type="submit" class="uj-btn-primary" :disabled="!ids.length || !fields.length || ids.length > 200" style="height:40px;padding:0 24px;font-size:13px;align-self:flex-start;">{!! $L('Confirm batch update', 'Sahkan kemas kini berkumpulan') !!}</button>
</form>
