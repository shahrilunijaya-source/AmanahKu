<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 26px 30px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; }
    table { width: 100%; border-collapse: collapse; }
    .title { font-size: 13px; font-weight: bold; text-transform: uppercase; }
    .sub { font-size: 9.5px; color: #555; margin-top: 2px; }
    hr { border: none; border-top: 1px solid #ccc; margin: 10px 0; }
    .section { font-size: 10px; font-weight: bold; text-transform: uppercase; background: #eee; padding: 4px 6px; margin: 12px 0 5px; }
    td { padding: 3px 6px 3px 0; vertical-align: top; }
    td.label { color: #555; width: 190px; }
    td.num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
    .note { font-size: 8.5px; color: #666; margin-top: 14px; }
</style>
</head>
<body>
    <div class="title">Borang PCB 2(II)</div>
    <div class="sub">Statement of tax deduction by employer under the Income Tax (Deduction from Remuneration) Rules 1994 · Penyata potongan cukai oleh majikan</div>
    <hr>

    <div class="section">Employer · Majikan</div>
    <table>
        <tr><td class="label">Name · Nama</td><td>{{ $tenant->name }}</td></tr>
        <tr><td class="label">Employer's TIN (E number)</td><td>{{ $tenant->employer_tin ?: '—' }}</td></tr>
        <tr><td class="label">Address · Alamat</td><td>{{ $tenant->address ?: '—' }}</td></tr>
    </table>

    <div class="section">Employee · Pekerja</div>
    <table>
        @foreach ($prefill as $label => $value)
            <tr><td class="label">{{ $label }}</td><td>{{ $value }}</td></tr>
        @endforeach
    </table>

    <div class="section">Year of remuneration · Tahun saraan: {{ $year }}</div>
    <table>
        <tr><td class="label">Gross remuneration · Saraan kasar</td><td class="num">{{ number_format((float) $ea['employment_income']['taxable_total'], 2) }}</td></tr>
        <tr><td class="label">Tax exempt · Dikecualikan cukai</td><td class="num">{{ number_format((float) $ea['employment_income']['tax_exempt_total'], 2) }}</td></tr>
        <tr><td class="label">EPF · KWSP</td><td class="num">{{ number_format((float) $ea['deductions']['epf_employee'], 2) }}</td></tr>
        <tr><td class="label">Zakat via salary deduction</td><td class="num">{{ number_format((float) $ea['deductions']['zakat'], 2) }}</td></tr>
        <tr><td class="label">MTD / PCB deducted · PCB dipotong</td><td class="num">{{ number_format((float) $ea['deductions']['pcb_total'], 2) }}</td></tr>
        <tr><td class="label">CP38 deducted · CP38 dipotong</td><td class="num">{{ number_format((float) $ea['deductions']['cp38'], 2) }}</td></tr>
    </table>

    <div class="section">Cessation notice · Notis pemberhentian</div>
    <table>
        <tr><td class="label">CP22A due on · Perlu difailkan</td><td>{{ $notice->due_on?->toDateString() ?: '—' }}</td></tr>
        <tr><td class="label">Filed on · Difailkan pada</td><td>{{ $notice->filed_on?->toDateString() ?: '—' }}</td></tr>
        <tr><td class="label">Reference · Rujukan</td><td>{{ $notice->reference ?: '—' }}</td></tr>
        <tr><td class="label">LHDN clearance · Pelepasan LHDN</td><td>{{ $notice->cleared_on?->toDateString() ?: '—' }}</td></tr>
    </table>

    <p class="note">Figures cover finalized payroll runs for {{ $year }} only. Check them against the employee's Form EA before submitting to LHDN. · Angka meliputi run gaji yang dimuktamadkan bagi {{ $year }} sahaja. Semak dengan Borang EA pekerja sebelum dihantar kepada LHDN.</p>
</body>
</html>
