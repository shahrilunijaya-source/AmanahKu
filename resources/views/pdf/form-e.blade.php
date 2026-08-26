<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 20px 26px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1a1a1a; }
    table { width: 100%; border-collapse: collapse; }
    .title { text-align: center; font-size: 12px; font-weight: bold; text-transform: uppercase; margin-bottom: 2px; }
    .subtitle { text-align: center; font-size: 9px; color: #555; margin-bottom: 10px; }
    hr { border: none; border-top: 1px solid #ccc; margin: 6px 0; }
    .header-table td { padding: 2px 6px 2px 0; font-size: 9px; vertical-align: top; }
    .header-table .label { color: #666; }
    .section-title { font-size: 9.5px; font-weight: bold; background: #eee; padding: 3px 6px; margin: 8px 0 4px; }
    .box-table td { border: 1px solid #ccc; padding: 3px 5px; font-size: 8.5px; vertical-align: top; }
    .box-table td.label { width: 62%; }
    .box-table td.value { width: 38%; text-align: right; font-family: DejaVu Sans Mono, monospace; }
    .box-table td.blank { color: #999; }
    .incomplete { margin-top: 10px; border: 1px solid #d9a441; background: #fff9ec; padding: 6px 8px; font-size: 8px; }
    .part-b, .part-c { margin-top: 10px; }
</style>
</head>
<body>
@php
    $bp = $data['basic_particulars'];
    $a = $data['part_a'];
    $blank = fn ($v) => $v === null ? '' : (string) $v;
    $blankClass = fn ($v) => $v === null ? 'blank' : '';
@endphp
<div class="title">Return Form of Employer — Form E</div>
<div class="subtitle">Under subsection 83(1) of the Income Tax Act 1967 — Year of Remuneration {{ $data['year'] }} (C.P.8 - Pin. 2025)</div>
<hr>

<div class="section-title">BASIC PARTICULARS</div>
<table class="header-table">
    <tr><td class="label">1. Name of employer as registered</td><td colspan="3">{{ $bp['name'] }}</td></tr>
    <tr>
        <td class="label">2. Employer's TIN</td><td class="{{ $blankClass($bp['employer_tin']) }}">{{ $bp['employer_tin'] ? 'E'.$bp['employer_tin'] : '' }}</td>
        <td class="label">3. Category of employer</td><td class="blank">{{ $blank($bp['category_of_employer']) }}</td>
    </tr>
    <tr>
        <td class="label">4. Status of employer</td><td class="blank">{{ $blank($bp['status_of_employer']) }}</td>
        <td class="label">5. Tax Identification No. (TIN) type code</td><td class="blank">{{ $blank($bp['tin_type_code']) }}</td>
    </tr>
    <tr>
        <td class="label">6. Identification no.</td><td class="blank">{{ $blank($bp['identification_no']) }}</td>
        <td class="label">7. Passport no.</td><td class="blank">{{ $blank($bp['passport_no']) }}</td>
    </tr>
    <tr>
        <td class="label">8. Registration no. with SSM or others</td><td colspan="3" class="{{ $blankClass($bp['ssm_registration_no']) }}">{{ $blank($bp['ssm_registration_no']) }}</td>
    </tr>
    <tr><td class="label">9. Correspondence address</td><td colspan="3">{{ $bp['address'] }}</td></tr>
    <tr>
        <td class="label">Postcode / City</td><td class="blank">{{ $blank($bp['postcode']) }} {{ $blank($bp['city']) }}</td>
        <td class="label">State / Country</td><td class="blank">{{ $blank($bp['state']) }} {{ $blank($bp['country']) }}</td>
    </tr>
    <tr>
        <td class="label">10. Telephone no.</td><td class="{{ $blankClass($bp['telephone']) }}">{{ $blank($bp['telephone']) }}</td>
        <td class="label">11. Handphone no.</td><td class="blank">{{ $blank($bp['handphone']) }}</td>
    </tr>
    <tr>
        <td class="label">12. E-mail</td><td class="{{ $blankClass($bp['email']) }}">{{ $blank($bp['email']) }}</td>
        <td class="label">13. Furnish of C.P.8D</td><td>1 = Via e-Data Praisi / e-CP8D</td>
    </tr>
</table>

<div class="section-title">PART A — INFORMATION ON NUMBER OF EMPLOYEES FOR THE YEAR ENDED 31 DECEMBER {{ $data['year'] }}</div>
<table class="box-table">
    <tr><td class="label">A1. Number of employees as at 31/12/{{ $data['year'] }}</td><td class="value">{{ $a['a1'] }}</td></tr>
    <tr><td class="label">A2. Number of employees subjected to Monthly Tax Deduction (MTD)</td><td class="value">{{ $a['a2'] }}</td></tr>
    <tr><td class="label">A3. Number of new employees</td><td class="value">{{ $a['a3'] }}</td></tr>
    <tr><td class="label">A4. Number of employees who ceased employment / died</td><td class="value">{{ $a['a4'] }}</td></tr>
    <tr><td class="label">A5. Number of employees who ceased employment and left Malaysia</td><td class="value blank">{{ $blank($a['a5']) }}</td></tr>
    <tr><td class="label">A6. Reported to LHDNM (if A5 is applicable) — 1 = Yes, 2 = No</td><td class="value blank">{{ $blank($a['a6']) }}</td></tr>
</table>

@if (! empty($data['incomplete']))
    <div class="incomplete">
        <strong>Items not filled on this printed form — this application does not yet collect this information.</strong>
        Write these in by hand from your own records before submitting:
        {{ collect($data['incomplete'])->map(fn ($b) => $b['box'].' ('.$b['label'].')')->implode('; ') }}
    </div>
@endif

<div class="section-title part-b">PART B — PARTICULARS OF TAX AGENT WHO COMPLETES THIS RETURN FORM</div>
<table class="box-table">
    <tr><td class="label">B1. Name of tax agent</td><td></td></tr>
    <tr><td class="label">B2. Tax agent's approval no.</td><td></td></tr>
    <tr><td class="label">B3. Name of firm</td><td></td></tr>
</table>
<div style="font-size: 7.5px; color: #999; margin-top: 2px;">For hand completion by the employer's tax agent, if any.</div>

<div class="section-title part-c">PART C — DECLARATION</div>
<div style="font-size: 8.5px; margin-top: 4px;">For hand completion and signature by the employer.</div>

</body>
</html>
