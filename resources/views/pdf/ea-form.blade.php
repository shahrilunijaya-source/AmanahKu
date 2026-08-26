<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 20px 26px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1a1a1a; }
    /* No CSS page-break rule here — dompdf's :last-child support is unreliable, and a
       break left on the final form prints a trailing blank page. The break is added
       inline per-iteration below, only between forms, via Blade's $loop->last. */
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
    .total-row td { font-weight: bold; border-top: 2px solid #999; }
    .incomplete { margin-top: 10px; border: 1px solid #d9a441; background: #fff9ec; padding: 6px 8px; font-size: 8px; }
</style>
</head>
<body>
@foreach ($forms as $d)
    @php
        $money = fn ($v) => $v === null ? '' : number_format((float) $v, 2);
        $date = fn ($v) => $v ? $v->format('d/m/Y') : '';
    @endphp
    <div class="page" @if (! $loop->last) style="page-break-after: always;" @endif>
        <div class="title">Statement of Remuneration From Employment</div>
        <div class="subtitle">For the year ended 31 December {{ $d['year'] }} (Form C.P.8A / Form EA)</div>
        <hr>

        <table class="header-table">
            <tr>
                <td class="label">Serial No.</td><td class="blank">{{ $d['header']['serial_no'] }}</td>
                <td class="label">Employer's TIN</td><td class="{{ $d['header']['employer_tin'] ? '' : 'blank' }}">{{ $d['header']['employer_tin'] ? 'E'.$d['header']['employer_tin'] : '' }}</td>
            </tr>
            <tr>
                <td class="label">Employee's Tax Identification No. (TIN)</td><td>{{ $d['header']['employee_tin'] }}</td>
                <td class="label">LHDNM State</td><td class="blank">{{ $d['header']['lhdnm_state'] }}</td>
            </tr>
        </table>

        <div class="section-title">A. PARTICULARS OF EMPLOYEE</div>
        <table class="header-table">
            <tr><td class="label">1. Full name</td><td colspan="3">{{ $d['employee']['name'] }}</td></tr>
            <tr>
                <td class="label">2. Job designation</td><td>{{ $d['employee']['designation'] }}</td>
                <td class="label">3. Staff/payroll no.</td><td>{{ $d['employee']['staff_id'] }}</td>
            </tr>
            <tr>
                <td class="label">4. New I.C. No.</td><td>{{ $d['employee']['nric'] }}</td>
                <td class="label">5. Passport No.</td><td class="blank">{{ $d['employee']['passport'] }}</td>
            </tr>
            <tr>
                <td class="label">6. EPF No.</td><td>{{ $d['employee']['epf_no'] }}</td>
                <td class="label">7. SOCSO No.</td><td>{{ $d['employee']['socso_no'] }}</td>
            </tr>
            <tr>
                <td class="label">8. No. of children qualified for tax relief</td><td class="blank">{{ $d['employee']['children'] }}</td>
                <td class="label"></td><td></td>
            </tr>
            <tr>
                <td class="label">9(a). Date of commencement</td><td>{{ $date($d['employee']['commencement_date']) }}</td>
                <td class="label">9(b). Date of cessation</td><td>{{ $date($d['employee']['cessation_date']) }}</td>
            </tr>
        </table>

        <div class="section-title">B. EMPLOYMENT INCOME, BENEFITS AND LIVING ACCOMMODATION</div>
        <table class="box-table">
            <tr><td class="label">1(a) Gross salary, wages or leave pay (including overtime pay)</td><td class="value {{ $d['b']['b1a'] === null ? 'blank' : '' }}">{{ $money($d['b']['b1a']) }}</td></tr>
            <tr><td class="label">1(b) Fees (including director fees), commission or bonus</td><td class="value {{ $d['b']['b1b'] === null ? 'blank' : '' }}">{{ $money($d['b']['b1b']) }}</td></tr>
            <tr><td class="label">1(c) Gross tips, perquisites, awards/rewards or other allowances</td><td class="value {{ $d['b']['b1c'] === null ? 'blank' : '' }}">{{ $money($d['b']['b1c']) }}</td></tr>
            <tr><td class="label">1(d) Income tax borne by the employer</td><td class="value blank">{{ $money($d['b']['b1d']) }}</td></tr>
            <tr><td class="label">1(e) ESOS benefit</td><td class="value blank">{{ $money($d['b']['b1e']) }}</td></tr>
            <tr><td class="label">1(f) Gratuity</td><td class="value blank">{{ $money($d['b']['b1f']) }}</td></tr>
            <tr><td class="label">2. Arrears and others for preceding years paid in the current year</td><td class="value blank">{{ $money($d['b']['b2']) }}</td></tr>
            <tr><td class="label">3. Benefits in kind</td><td class="value blank">{{ $money($d['b']['b3']) }}</td></tr>
            <tr><td class="label">4. Value of living accommodation</td><td class="value blank">{{ $money($d['b']['b4']) }}</td></tr>
            <tr><td class="label">5. Refund from unapproved provident/pension fund</td><td class="value blank">{{ $money($d['b']['b5']) }}</td></tr>
            <tr><td class="label">6. Compensation for loss of employment</td><td class="value blank">{{ $money($d['b']['b6']) }}</td></tr>
        </table>

        <div class="section-title">C. PENSION AND OTHERS</div>
        <table class="box-table">
            <tr><td class="label">1. Pension</td><td class="value blank">{{ $money($d['c']['c1']) }}</td></tr>
            <tr><td class="label">2. Annuities or other periodical payments</td><td class="value blank">{{ $money($d['c']['c2']) }}</td></tr>
            <tr class="total-row"><td class="label">TOTAL</td><td class="value blank">{{ $money($d['c']['total']) }}</td></tr>
        </table>

        <div class="section-title">D. TOTAL DEDUCTION</div>
        <table class="box-table">
            <tr><td class="label">1. Monthly tax deductions (MTD) remitted to LHDNM</td><td class="value {{ $d['d']['d1'] === null ? 'blank' : '' }}">{{ $money($d['d']['d1']) }}</td></tr>
            <tr><td class="label">2. CP38 deductions remitted to LHDNM</td><td class="value {{ $d['d']['d2'] === null ? 'blank' : '' }}">{{ $money($d['d']['d2']) }}</td></tr>
            <tr><td class="label">3. Zakat paid via salary deduction</td><td class="value {{ $d['d']['d3'] === null ? 'blank' : '' }}">{{ $money($d['d']['d3']) }}</td></tr>
            <tr><td class="label">4. Approved donations/gifts/contributions via salary deduction</td><td class="value blank">{{ $money($d['d']['d4']) }}</td></tr>
            <tr><td class="label">5(a). Total claim for deduction via Form TP1: Relief</td><td class="value blank">{{ $money($d['d']['d5a']) }}</td></tr>
            <tr><td class="label">5(b). Total claim for deduction via Form TP1: Zakat other than via salary</td><td class="value blank">{{ $money($d['d']['d5b']) }}</td></tr>
            <tr><td class="label">6. Total qualifying child relief</td><td class="value {{ $d['d']['d6'] === null ? 'blank' : '' }}">{{ $money($d['d']['d6']) }}</td></tr>
        </table>

        <div class="section-title">E. CONTRIBUTIONS PAID BY EMPLOYEE TO APPROVED PROVIDENT/PENSION FUND AND SOCSO</div>
        <table class="box-table">
            <tr><td class="label">1. {{ $d['e']['fund_name'] ?? 'Name of provident fund' }} (employee's share only)</td><td class="value {{ $d['e']['e1'] === null ? 'blank' : '' }}">{{ $money($d['e']['e1']) }}</td></tr>
            <tr><td class="label">2. SOCSO (employee's share only, including EIS)</td><td class="value {{ $d['e']['e2'] === null ? 'blank' : '' }}">{{ $money($d['e']['e2']) }}</td></tr>
        </table>

        <div class="section-title">F. TOTAL TAX EXEMPT ALLOWANCES / PERQUISITES / GIFTS / BENEFITS</div>
        <table class="box-table">
            <tr><td class="label">Total tax exempt allowances / perquisites / gifts / benefits (F)</td><td class="value {{ $d['f'] === null ? 'blank' : '' }}">{{ $money($d['f']) }}</td></tr>
        </table>

        @if (! empty($d['incomplete']))
            <div class="incomplete">
                <strong>Boxes not filled on this printed form — this application does not yet collect this information.</strong>
                Write these in by hand from your own records before issuing:
                {{ collect($d['incomplete'])->map(fn ($b) => $b['box'].' ('.$b['label'].')')->implode('; ') }}
            </div>
        @endif

        <table class="header-table" style="margin-top: 16px;">
            <tr><td class="label">Name of Officer</td><td></td><td class="label">Designation</td><td></td></tr>
            <tr><td class="label">Name and Address of Employer</td><td colspan="3">{{ $d['employer']['name'] }}, {{ $d['employer']['address'] }}</td></tr>
            <tr><td class="label">Date</td><td></td><td class="label">Employer's Telephone No.</td><td class="{{ $d['employer']['telephone'] ? '' : 'blank' }}">{{ $d['employer']['telephone'] }}</td></tr>
        </table>
    </div>
@endforeach
</body>
</html>
