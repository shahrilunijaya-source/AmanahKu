<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
{{-- Copies the official LHDN Form EA (C.P.8A) page the way Worksy prints it: the form's
     own wording and dotted lines, figures written onto the dots, 0.00 in every money box
     with nothing behind it. One A4 page per employee.
     dompdf sizes table columns from the FIRST row only (it ignores <col> and
     table-layout: fixed), so every table opens with an empty .sz row that sets the widths. --}}
<style>
    @page { margin: 26pt 52pt 14pt 52pt; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 7.3pt; color: #000; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 0; vertical-align: bottom; }
    .sz td { height: 0; line-height: 0; font-size: 0; }
    .row td { height: 12.8pt; }
    .srow td { height: 11pt; }
    .fill { border-bottom: 0.6pt dotted #000; font-size: 6.4pt; text-align: center; }
    .fill-l { border-bottom: 0.6pt dotted #000; font-size: 6.4pt; padding-left: 6pt; }
    .letter { background: #000; color: #fff; font-weight: bold; text-align: center; font-size: 7.4pt; }
    .sec { font-weight: bold; }
    .in { margin: 0; }
    .in td { height: auto; }
    .gap td { height: 8pt; }
    .bar { background: #000; color: #fff; font-weight: bold; text-align: center; font-size: 7.8pt; padding: 2.5pt 0; }
</style>
</head>
<body>
@foreach ($forms as $d)
    @php
        $money = fn ($v) => number_format((float) ($v ?? 0), 2);
        $date = fn ($v) => $v ? $v->format('d/m/Y') : '';
        $nric = preg_match('/^\d{12}$/', (string) $d['employee']['nric'])
            ? substr($d['employee']['nric'], 0, 6).'-'.substr($d['employee']['nric'], 6, 2).'-'.substr($d['employee']['nric'], 8)
            : $d['employee']['nric'];
        $notes = $d['take_on_notes'] ?? [];
        // A written-in detail replaces the form's own dotted blank.
        $inline = fn ($v, int $dots) => filled($v) ? '<u>'.e($v).'</u>' : str_repeat('.', $dots);
        $addressLines = array_pad(explode("\n", wordwrap(trim(preg_replace('/\s+/', ' ', (string) $d['employer']['address'])), 46, "\n", true)), 3, '');
        $bcTotal = collect($d['b'])->sum() + ($d['c']['c1'] ?? 0) + ($d['c']['c2'] ?? 0);
    @endphp
    <div @if (! $loop->last) style="page-break-after: always;" @endif>

        {{-- Header --}}
        <table>
            <tr class="sz"><td style="width: 155pt;"></td><td style="width: 180pt;"></td><td style="width: 10pt;"></td><td style="width: 113pt;"></td><td style="width: 33pt;"></td></tr>
            <tr>
                <td style="vertical-align: top; font-size: 7pt;">(C.P.8A - Pin. <span style="font-size: 6.2pt;">{{ $d['year'] }}</span>)</td>
                <td style="text-align: center; vertical-align: top;">
                    <div style="font-size: 7pt;">MALAYSIA</div>
                    <div style="font-size: 11.5pt; font-weight: bold;">INCOME TAX</div>
                </td>
                <td></td>
                <td style="background: #000; color: #fff; font-weight: bold; font-size: 7.2pt; line-height: 8.2pt; padding: 1pt 3pt; vertical-align: top;">PRIVATE SECTOR Employee's<br>Statement of Remuneration</td>
                <td style="font-size: 19pt; font-weight: bold; text-align: center; vertical-align: bottom; line-height: 16pt;">EA</td>
            </tr>
            <tr><td></td><td></td><td colspan="3" style="text-align: center; font-size: 7pt;">Employee's Tax Identification No. (TIN)</td></tr>
        </table>
        <table>
            <tr class="sz"><td style="width: 55pt;"></td><td style="width: 8pt;"></td><td style="width: 67pt;"></td><td style="width: 227pt;"></td><td style="width: 54pt;"></td><td style="width: 80pt;"></td></tr>
            <tr class="row">
                <td>Serial No.</td><td></td>
                <td class="fill">{{ $d['header']['serial_no'] }}</td>
                <td style="text-align: center;">STATEMENT OF REMUNERATION FROM EMPLOYMENT</td>
                <td colspan="2" class="fill">{{ $d['header']['employee_tin'] }}</td>
            </tr>
            <tr class="row">
                <td>Employer's No.</td>
                <td style="text-align: right; padding-right: 2pt;">E</td>
                <td class="fill">{{ $d['header']['employer_tin'] }}</td>
                <td style="text-align: center;">FOR THE YEAR ENDED 31 DECEMBER <span style="border-bottom: 0.6pt dotted #000; font-size: 6.2pt;">&nbsp;{{ $d['year'] }}&nbsp;</span></td>
                <td>LHDNM Branch</td>
                <td class="fill">{{ $d['header']['lhdnm_state'] }}</td>
            </tr>
        </table>
        <div class="bar" style="margin-top: 4pt;">THIS FORM EA MUST BE PREPARED AND PROVIDED TO THE EMPLOYEE FOR INCOME TAX PURPOSE</div>

        {{-- A --}}
        <table style="margin-top: 5pt;">
            <tr class="sz"><td style="width: 12pt;"></td><td style="width: 11pt;"></td><td style="width: 17pt;"></td><td style="width: 68pt;"></td><td style="width: 131pt;"></td><td style="width: 12pt;"></td><td style="width: 17pt;"></td><td style="width: 19pt;"></td><td style="width: 86pt;"></td><td style="width: 118pt;"></td></tr>
            <tr class="row"><td class="letter">A</td><td></td><td colspan="8" class="sec">PARTICULARS OF EMPLOYEE</td></tr>
            <tr class="row">
                <td></td><td></td><td>1.</td><td colspan="3">Full Name of Employee / Pensioner (Mr./Miss/Madam)</td>
                <td colspan="4" class="fill-l">{{ $d['employee']['name'] }}</td>
            </tr>
            <tr class="row">
                <td></td><td></td><td>2.</td><td>Job Designation</td><td class="fill-l">{{ $d['employee']['designation'] }}</td>
                <td></td><td>3.</td><td colspan="2">Staff No. / Payroll No.</td><td class="fill-l">{{ $d['employee']['staff_id'] }}</td>
            </tr>
            <tr class="row">
                <td></td><td></td><td>4.</td><td>New I.C. No</td><td class="fill-l">{{ $nric }}</td>
                <td></td><td>5.</td><td colspan="2">Passport No.</td><td class="fill-l">{{ $d['employee']['passport'] }}</td>
            </tr>
            <tr class="row">
                <td></td><td></td><td>6.</td><td>EPF No.</td><td class="fill-l">{{ $d['employee']['epf_no'] }}</td>
                <td></td><td>7.</td><td colspan="2">SOCSO No.</td><td class="fill-l">{{ $d['employee']['socso_no'] }}</td>
            </tr>
            <tr class="row">
                <td></td><td></td><td>8.</td><td colspan="2">Number of children</td>
                <td></td><td>9.</td><td colspan="3">If the period of employment is less than a year, please state:</td>
            </tr>
            <tr class="row">
                <td></td><td></td><td></td><td colspan="2">
                    <table class="in"><tr class="sz"><td style="width: 90pt;"></td><td style="width: 90pt;"></td><td></td></tr>
                        <tr><td>qualified for tax relief</td><td class="fill">{{ $d['employee']['children'] ?? 0 }}</td><td></td></tr></table>
                </td>
                <td></td><td></td><td>(a)</td><td>Date of commencement</td><td class="fill-l">{{ $date($d['employee']['commencement_date']) }}</td>
            </tr>
            <tr class="row">
                <td></td><td></td><td></td><td colspan="2"></td>
                <td></td><td></td><td>(b)</td><td>Date of cessation</td><td class="fill-l">{{ $date($d['employee']['cessation_date']) }}</td>
            </tr>
        </table>

        {{-- B to F share one grid: letter, gap, number, label, RM, amount --}}
        <table style="margin-top: 8pt;">
            <tr class="sz"><td style="width: 12pt;"></td><td style="width: 11pt;"></td><td style="width: 17pt;"></td><td style="width: 367pt;"></td><td style="width: 20pt;"></td><td style="width: 64pt;"></td></tr>
            <tr class="row"><td class="letter">B</td><td></td><td colspan="4" class="sec">EMPLOYMENT INCOME, BENEFITS AND LIVING ACCOMMODATION</td></tr>
            <tr class="row">
                <td></td><td></td><td colspan="3" class="sec" style="font-size: 6.6pt;">(Excluding Tax Exempt Allowances / Perquisites / Gifts / Benefits)</td>
                <td style="text-align: center; font-weight: bold;">RM</td>
            </tr>
            <tr class="row"><td></td><td></td><td>1.</td><td colspan="2">(a)&nbsp; Gross salary, wages or leave pay (including overtime pay)</td><td class="fill">{{ $money($d['b']['b1a']) }}</td></tr>
            <tr class="row"><td></td><td></td><td></td><td colspan="2">(b)&nbsp; Fees (including director fees), commission or bonus</td><td class="fill">{{ $money($d['b']['b1b']) }}</td></tr>
            <tr class="row"><td></td><td></td><td></td><td colspan="2">(c)&nbsp; Gross tips, perquisites, awards / rewards or other allowances (Details of payment: {!! $inline($notes['b1c_details'] ?? null, 22) !!})</td><td class="fill">{{ $money($d['b']['b1c']) }}</td></tr>
            <tr class="row"><td></td><td></td><td></td><td colspan="2">(d)&nbsp; Income tax borne by the employer in respect of his employee</td><td class="fill">{{ $money($d['b']['b1d']) }}</td></tr>
            <tr class="row"><td></td><td></td><td></td><td colspan="2">(e)&nbsp; Employee Share Option Scheme (ESOS) benefit</td><td class="fill">{{ $money($d['b']['b1e']) }}</td></tr>
            <tr class="row"><td></td><td></td><td></td><td colspan="2">(f)&nbsp;&nbsp; Gratuity for the period from {!! $inline($notes['b1f_from'] ?? null, 45) !!} to {!! $inline($notes['b1f_to'] ?? null, 45) !!}</td><td class="fill">{{ $money($d['b']['b1f']) }}</td></tr>
            <tr class="row"><td></td><td></td><td>2.</td><td colspan="2">Details of arrears and others for preceding years paid in the current year</td><td></td></tr>
            <tr class="row"><td></td><td></td><td></td><td colspan="2">Type of income&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(a)&nbsp;&nbsp;&nbsp;&nbsp; {!! $inline($notes['b2_type_a'] ?? null, 55) !!}</td><td></td></tr>
            <tr class="row"><td></td><td></td><td></td><td colspan="2"><span style="color: #fff;">Type of income</span>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(b)&nbsp;&nbsp;&nbsp;&nbsp; {!! $inline($notes['b2_type_b'] ?? null, 55) !!}</td><td class="fill">{{ $money($d['b']['b2']) }}</td></tr>
            <tr class="row"><td></td><td></td><td>3.</td><td colspan="2">Benefits in kind (Specify: {!! $inline($notes['b3_details'] ?? null, 140) !!})</td><td class="fill">{{ $money($d['b']['b3']) }}</td></tr>
            <tr class="row"><td></td><td></td><td>4.</td><td colspan="2">Value of living accommodation provided (Address: {!! $inline($notes['b4_address'] ?? null, 105) !!})</td><td class="fill">{{ $money($d['b']['b4']) }}</td></tr>
            <tr class="row"><td></td><td></td><td>5.</td><td colspan="2">Refund from unapproved Provident / Pension Fund</td><td class="fill">{{ $money($d['b']['b5']) }}</td></tr>
            <tr class="row"><td></td><td></td><td>6.</td><td colspan="2">Compensation for loss of employment</td><td class="fill">{{ $money($d['b']['b6']) }}</td></tr>

            <tr class="gap"><td colspan="6"></td></tr>
            <tr class="row"><td class="letter">C</td><td></td><td colspan="4" class="sec">PENSION AND OTHERS</td></tr>
            <tr class="row"><td></td><td></td><td>1.</td><td colspan="2">Pension</td><td class="fill">{{ $money($d['c']['c1']) }}</td></tr>
            <tr class="row"><td></td><td></td><td>2.</td><td colspan="2">Annuities or other periodical payments</td><td class="fill">{{ $money($d['c']['c2']) }}</td></tr>
            {{-- The form's TOTAL is every B and C box on the page. --}}
            <tr class="row">
                <td></td><td></td><td></td><td colspan="2" class="sec">TOTAL</td>
                <td style="border-top: 0.8pt solid #000; border-bottom: 0.8pt solid #000; text-align: center; font-size: 6.4pt; vertical-align: middle;">{{ $money($bcTotal) }}</td>
            </tr>

            <tr class="gap"><td colspan="6"></td></tr>
            <tr class="row"><td class="letter">D</td><td></td><td colspan="4" class="sec">TOTAL DEDUCTION</td></tr>
            <tr class="row"><td></td><td></td><td>1.</td><td colspan="2">Monthly tax deductions (MTD) remitted to LHDNM</td><td class="fill">{{ $money($d['d']['d1']) }}</td></tr>
            <tr class="row"><td></td><td></td><td>2.</td><td colspan="2">CP38 deductions remitted to LHDNM</td><td class="fill">{{ $money($d['d']['d2']) }}</td></tr>
            <tr class="row"><td></td><td></td><td>3.</td><td colspan="2"><i>Zakat</i> paid via salary deduction</td><td class="fill">{{ $money($d['d']['d3']) }}</td></tr>
            <tr class="row"><td></td><td></td><td>4.</td><td colspan="2">Approved donations / gifts / contributions via salary deduction</td><td class="fill">{{ $money($d['d']['d4']) }}</td></tr>
            <tr class="row"><td></td><td></td><td>5.</td><td colspan="2">Total claim for deduction by employee via Form TP1 in respect of:</td><td></td></tr>
            <tr class="row"><td></td><td></td><td></td><td colspan="2">
                <table class="in"><tr class="sz"><td style="width: 18pt;"></td><td style="width: 243pt;"></td><td style="width: 14pt;"></td><td style="width: 112pt;"></td></tr>
                    <tr><td>(a)</td><td>Relief</td><td>RM</td><td class="fill">{{ $money($d['d']['d5a']) }}</td></tr></table>
            </td><td></td></tr>
            <tr class="row"><td></td><td></td><td></td><td colspan="2">
                <table class="in"><tr class="sz"><td style="width: 18pt;"></td><td style="width: 243pt;"></td><td style="width: 14pt;"></td><td style="width: 112pt;"></td></tr>
                    <tr><td>(b)</td><td><i>Zakat</i> other than that paid via monthly salary deduction</td><td>RM</td><td class="fill">{{ $money($d['d']['d5b']) }}</td></tr></table>
            </td><td></td></tr>
            <tr class="row"><td></td><td></td><td>6.</td><td colspan="2">Total qualifying child relief</td><td class="fill">{{ $money($d['d']['d6']) }}</td></tr>

            <tr class="gap"><td colspan="6"></td></tr>
            <tr class="row"><td class="letter">E</td><td></td><td colspan="4" class="sec">CONTRIBUTIONS PAID BY EMPLOYEE TO APPROVED PROVIDENT / PENSION FUND AND SOCSO</td></tr>
            <tr class="row"><td></td><td></td><td>1.</td><td colspan="3">
                <table class="in"><tr class="sz"><td style="width: 82pt;"></td><td></td></tr>
                    <tr><td>Name of Provident Fund</td><td class="fill-l">{{ $d['e']['e1'] !== null ? 'EPF' : '' }}</td></tr></table>
            </td></tr>
            <tr class="row"><td></td><td></td><td></td><td>Amount of compulsory contribution paid (state the employee's share of contribution only)</td><td>RM</td><td class="fill">{{ $money($d['e']['e1']) }}</td></tr>
            <tr class="row"><td></td><td></td><td>2.</td><td>SOCSO: Amount of compulsory contribution paid (state the employee's share of contribution only)</td><td>RM</td><td class="fill">{{ $money($d['e']['e2']) }}</td></tr>

            <tr class="gap"><td colspan="6"></td></tr>
            <tr class="row"><td class="letter">F</td><td></td><td colspan="2" class="sec">TOTAL TAX EXEMPT ALLOWANCES / PERQUISITES / GIFTS / BENEFITS</td><td class="sec">RM</td><td class="fill">{{ $money($d['f']) }}</td></tr>
        </table>

        {{-- Signature block --}}
        <table style="margin-top: 6pt;">
            <tr class="sz"><td style="width: 138pt;"></td><td style="width: 12pt;"></td><td></td></tr>
            <tr>
                <td style="padding-bottom: 5pt;">
                    <table class="in"><tr class="sz"><td style="width: 22pt;"></td><td style="width: 104pt;"></td><td></td></tr>
                        <tr><td>Date:</td><td class="fill">{{ now()->format('d/m/Y') }}</td><td></td></tr></table>
                </td>
                <td></td>
                <td style="border: 0.8pt solid #000; padding: 2pt 6pt 5pt 8pt;">
                    <table>
                        <tr class="sz"><td style="width: 118pt;"></td><td></td></tr>
                        <tr class="srow"><td>Name of Officer</td><td class="fill-l">{{ $officer['name'] ?? '' }}</td></tr>
                        <tr class="srow"><td>Designation</td><td class="fill-l">{{ $officer['designation'] ?? '' }}</td></tr>
                        <tr class="srow"><td>Name and Address of Employer</td><td class="fill-l">{{ $d['employer']['name'] }}</td></tr>
                        @foreach ($addressLines as $line)
                            <tr class="srow"><td></td><td class="fill-l">{{ $line }}</td></tr>
                        @endforeach
                        <tr class="srow"><td>Employer's Telephone No.</td><td class="fill-l">{{ $d['employer']['telephone'] }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>
@endforeach
</body>
</html>
