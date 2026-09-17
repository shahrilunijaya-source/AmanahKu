{{-- One TP3 year (PayrollOpeningFigure); $o null = add. Inherits $p, $L, $lbl, $fs from experience-tab. --}}
@php
    $ov = fn (string $k, $default = '') => $o?->{$k} ?? $default;
    $num = fn (string $k) => '<input type="number" step="0.01" min="0" name="'.$k.'" value="'.e($o ? number_format((float) $o->{$k}, 2, '.', '') : '').'" style="'.$fs.'" />';
@endphp
<form method="post" action="{{ route('payroll.opening') }}" style="display:flex;flex-direction:column;gap:10px;">
    @csrf
    <input type="hidden" name="employee_id" value="{{ $p->id }}" />
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px 14px;">
        <div><label style="{{ $lbl }}">{!! $L('Year', 'Tahun') !!}</label><input type="number" name="year" required min="2000" max="2100" value="{{ $ov('year', now()->year) }}" {{ $o ? 'readonly' : '' }} style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('Gross', 'Kasar') !!}</label>{!! $num('gross') !!}</div>
        <div><label style="{{ $lbl }}">{!! $L('Income tax (PCB)', 'Cukai (PCB)') !!}</label>{!! $num('pcb_paid') !!}</div>
        <div><label style="{{ $lbl }}">{!! $L('Employee EPF', 'KWSP pekerja') !!}</label>{!! $num('epf') !!}</div>
        <div><label style="{{ $lbl }}">SOCSO</label>{!! $num('socso') !!}</div>
        <div><label style="{{ $lbl }}">EIS</label>{!! $num('eis') !!}</div>
        <div><label style="{{ $lbl }}">Zakat</label>{!! $num('zakat_paid') !!}</div>
        <div><label style="{{ $lbl }}">{!! $L('Additional gross', 'Kasar tambahan') !!}</label>{!! $num('additional_gross') !!}</div>
        <div><label style="{{ $lbl }}">{!! $L('Additional EPF', 'KWSP tambahan') !!}</label>{!! $num('additional_epf') !!}</div>
        <div><label style="{{ $lbl }}">{!! $L('Optional deductions', 'Potongan pilihan') !!}</label>{!! $num('optional_deductions') !!}</div>
        <div><label style="{{ $lbl }}">{!! $L('Exempt allowances', 'Elaun dikecualikan') !!}</label>{!! $num('exempt_allowances') !!}</div>
        <div><label style="{{ $lbl }}">{!! $L('Previous employer', 'Majikan terdahulu') !!}</label><input name="previous_employer" value="{{ $ov('previous_employer') }}" maxlength="120" style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('Employer TIN', 'TIN majikan') !!}</label><input name="previous_employer_tin" value="{{ $ov('previous_employer_tin') }}" maxlength="40" style="{{ $fs }}" /></div>
    </div>
    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;align-self:flex-start;">{!! $L('Save', 'Simpan') !!}</button>
</form>
