{{-- Worksy employment field set. Every input name is in Employee::EMPLOYMENT_FIELDS.
     Expects $e (Employee), $canSeeSalary, $fs and allDepartments/allBranches/allPositions/allEmploymentTypes/allManagers. --}}
@php
    $L = fn ($en, $ms) => '<span x-text="$store.ui.lang===\'en\' ? '.json_encode($en).' : '.json_encode($ms).'">'.e($en).'</span>';
    $lbl = 'display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;';
    $bandsByDept = ($allPositions ?? collect())->groupBy(fn ($pos) => $pos->department?->name ?? '—');
    $v = fn (string $k) => old($k, $e->{$k});
@endphp
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 16px;">
    <div><label style="{{ $lbl }}">{!! $L('Department', 'Jabatan') !!}</label>
        <select name="department_id" style="{{ $fs }}"><option value="">—</option>@foreach ($allDepartments ?? [] as $o)<option value="{{ $o->id }}" @selected((string) $v('department_id') === (string) $o->id)>{{ $o->name }}</option>@endforeach</select></div>
    <div><label style="{{ $lbl }}">{!! $L('Branch', 'Cawangan') !!}</label>
        <select name="branch_id" style="{{ $fs }}"><option value="">—</option>@foreach ($allBranches ?? [] as $o)<option value="{{ $o->id }}" @selected((string) $v('branch_id') === (string) $o->id)>{{ $o->name }}</option>@endforeach</select></div>
    <div><label style="{{ $lbl }}">{!! $L('Position', 'Jawatan') !!}</label>
        <select name="position_id" style="{{ $fs }}"><option value="">—</option>@foreach ($bandsByDept as $dept => $bands)<optgroup label="{{ $dept }}">@foreach ($bands as $pos)<option value="{{ $pos->id }}" @selected((string) $v('position_id') === (string) $pos->id)>{{ $pos->title }}</option>@endforeach</optgroup>@endforeach</select></div>
    <div><label style="{{ $lbl }}">{!! $L('Employment Type', 'Jenis Pekerjaan') !!}</label>
        <select name="employment_type_id" style="{{ $fs }}"><option value="">—</option>@foreach ($allEmploymentTypes ?? [] as $o)<option value="{{ $o->id }}" @selected((string) $v('employment_type_id') === (string) $o->id)>{{ $o->name }}</option>@endforeach</select></div>
    <div><label style="{{ $lbl }}">{!! $L('Reporting To', 'Melapor Kepada') !!}</label>
        <select name="reports_to_id" style="{{ $fs }}"><option value="">—</option>@foreach ($allManagers ?? [] as $o)@continue($o->id === $e->id)<option value="{{ $o->id }}" @selected((string) $v('reports_to_id') === (string) $o->id)>{{ $o->name }}</option>@endforeach</select></div>
    <div><label style="{{ $lbl }}">{!! $L('Division', 'Bahagian') !!}</label><input name="division" value="{{ $v('division') }}" maxlength="80" style="{{ $fs }}" /></div>
    <div><label style="{{ $lbl }}">{!! $L('Section', 'Seksyen') !!}</label><input name="section" value="{{ $v('section') }}" maxlength="80" style="{{ $fs }}" /></div>
    <div><label style="{{ $lbl }}">{!! $L('Job Grade', 'Gred') !!}</label><input name="job_grade" value="{{ $v('job_grade') }}" maxlength="40" style="{{ $fs }}" /></div>
    <div><label style="{{ $lbl }}">{!! $L('Category', 'Kategori') !!}</label><input name="category" value="{{ $v('category') }}" maxlength="40" style="{{ $fs }}" /></div>
    <div><label style="{{ $lbl }}">{!! $L('Line', 'Barisan') !!}</label><input name="line" value="{{ $v('line') }}" maxlength="40" style="{{ $fs }}" /></div>

    @foreach ([['probation', 'Probation Period', 'Tempoh Percubaan'], ['resign_notice', 'Resign Notice Period', 'Tempoh Notis Berhenti'], ['short_notice', 'Short Notice Period', 'Tempoh Notis Singkat']] as [$k, $en, $ms])
        <div><label style="{{ $lbl }}">{!! $L($en, $ms) !!}</label>
            <div style="display:flex;gap:6px;align-items:center;">
                <input type="number" name="{{ $k }}_months" value="{{ $v($k.'_months') }}" min="0" max="24" placeholder="0" style="{{ $fs }}" /><span style="font-size:11.5px;color:var(--muted);white-space:nowrap;">{!! $L('Month(s)', 'Bulan') !!}</span>
                <input type="number" name="{{ $k }}_days" value="{{ $v($k.'_days') }}" min="0" max="31" placeholder="0" style="{{ $fs }}" /><span style="font-size:11.5px;color:var(--muted);white-space:nowrap;">{!! $L('Day(s)', 'Hari') !!}</span>
            </div></div>
    @endforeach

    @if ($canSeeSalary ?? false)
        <div><label style="{{ $lbl }}">{!! $L('Basic Salary (MYR)', 'Gaji Pokok (MYR)') !!}</label><input type="number" name="salary" value="{{ $v('salary') }}" min="0" step="0.01" style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('Pay Mode', 'Mod Gaji') !!}</label>
            <select name="pay_mode" style="{{ $fs }}">@foreach (['monthly' => 'Monthly Rate', 'daily' => 'Daily Rate', 'hourly' => 'Hourly Rate'] as $key => $t)<option value="{{ $key }}" @selected(($v('pay_mode') ?? 'monthly') === $key)>{{ $t }}</option>@endforeach</select></div>
    @endif
    <div><label style="{{ $lbl }}">{!! $L('Payment Term', 'Tempoh Bayaran') !!}</label>
        <select name="payment_term" style="{{ $fs }}">@foreach (['monthly' => 'Monthly', 'biweekly' => 'Bi-Weekly', 'weekly' => 'Weekly', 'daily' => 'Daily'] as $key => $t)<option value="{{ $key }}" @selected(($v('payment_term') ?? 'monthly') === $key)>{{ $t }}</option>@endforeach</select></div>
    <div><label style="{{ $lbl }}">{!! $L('Payment Method', 'Kaedah Bayaran') !!}</label>
        <select name="payment_method" style="{{ $fs }}">@foreach (['bank' => 'Bank', 'cash' => 'Cash', 'cheque' => 'Cheque'] as $key => $t)<option value="{{ $key }}" @selected(($v('payment_method') ?? 'bank') === $key)>{{ $t }}</option>@endforeach</select></div>
</div>
<div><label style="{{ $lbl }}">{!! $L('Remark', 'Catatan') !!}</label><textarea name="employment_remark" rows="2" maxlength="2000" style="{{ $fs }}height:auto;padding:8px 11px;">{{ $v('employment_remark') }}</textarea></div>
