<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 20px 26px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1a1a1a; }
    /* No CSS page-break rule here — dompdf's :last-child support is unreliable, and a
       break left on the final payslip prints a trailing blank page. The break is added
       inline per-iteration below, only between payslips, via Blade's $loop->last. */
    table { width: 100%; border-collapse: collapse; }
    .header { display: table; width: 100%; margin-bottom: 10px; }
    .header .left { display: table-cell; vertical-align: middle; }
    .header .right { display: table-cell; vertical-align: middle; text-align: right; }
    .logo { max-height: 44px; max-width: 160px; }
    .company-name { font-size: 15px; font-weight: bold; }
    .doc-title { font-size: 12px; font-weight: bold; letter-spacing: 0.5px; text-transform: uppercase; margin-top: 2px; }
    .meta { font-size: 9px; color: #555; margin-top: 2px; }
    hr { border: none; border-top: 1px solid #ccc; margin: 8px 0; }
    .emp-table td { padding: 2px 6px 2px 0; font-size: 9.5px; vertical-align: top; }
    .emp-table .label { color: #666; width: 110px; }
    .cols { display: table; width: 100%; margin-top: 8px; }
    .col { display: table-cell; width: 50%; vertical-align: top; }
    .col.left-col { padding-right: 8px; }
    .col.right-col { padding-left: 8px; }
    .section-title { font-size: 10px; font-weight: bold; text-transform: uppercase; background: #eee; padding: 4px 6px; margin-bottom: 4px; }
    .lines-table th, .lines-table td { border-bottom: 1px solid #ddd; padding: 3px 4px; font-size: 9px; text-align: left; }
    .lines-table th { background: #f5f5f5; font-size: 8.5px; text-transform: uppercase; }
    .lines-table td.num, .lines-table th.num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
    .foot-row td { font-weight: bold; border-top: 2px solid #999; }
    .statutory-table th, .statutory-table td { border: 1px solid #ccc; padding: 4px 6px; font-size: 8.5px; text-align: right; }
    .statutory-table th { background: #f5f5f5; text-align: center; }
    .statutory-table td:first-child, .statutory-table th:first-child { text-align: left; }
    .nett { background: #1a1a1a; color: #fff; padding: 10px 14px; margin-top: 10px; }
    .nett .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; }
    .nett .amount { font-size: 20px; font-weight: bold; font-family: DejaVu Sans Mono, monospace; }
    .bottom { display: table; width: 100%; margin-top: 10px; }
    .bottom .col { display: table-cell; width: 50%; vertical-align: top; }
    .remark-box { border: 1px solid #ccc; padding: 6px 8px; min-height: 34px; font-size: 9px; }
</style>
</head>
<body>
@foreach ($payslips as $d)
    @php
        $p = $d['payslip'];
        $emp = $d['employee'];
        $run = $d['run'];
        $s = $d['structure'];
        $ytd = $d['ytd'];
        $money = fn ($v) => 'MYR '.number_format((float) $v, 2, '.', ',');
        $tenant = $emp?->tenant;
        $logoPath = $tenant?->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->path($tenant->logo_path) : null;
        $payDate = $run?->finalized_at ? $run->finalized_at->format('d/m/Y') : now()->format('d/m/Y');
        $periodLabel = $run?->label ?? $run?->period;
        $balances = $emp?->leaveBalances->take(2) ?? collect();
    @endphp
    <div class="page" @if (! $loop->last) style="page-break-after: always;" @endif>
        <div class="header">
            <div class="left">
                <div class="company-name">{{ $tenant?->name ?? 'Company' }}</div>
                <div class="doc-title">Official Payslip</div>
                <div class="meta">Pay period: {{ $periodLabel }} &nbsp;·&nbsp; Payment date: {{ $payDate }}</div>
            </div>
            <div class="right">
                @if ($logoPath && file_exists($logoPath))
                    <img class="logo" src="{{ $logoPath }}" alt="logo">
                @endif
            </div>
        </div>
        <hr>

        <table class="emp-table">
            <tr>
                <td class="label">Name</td><td>{{ $emp?->name }}</td>
                <td class="label">Staff ID</td><td>{{ $emp?->staff_id }}</td>
            </tr>
            <tr>
                <td class="label">Position</td><td>{{ $emp?->position }}</td>
                <td class="label">NRIC</td><td>{{ $emp?->nric }}</td>
            </tr>
            <tr>
                <td class="label">Department</td><td>{{ $emp?->department?->name }}</td>
                <td class="label">EPF No.</td><td>{{ $s?->epf_no }}</td>
            </tr>
            <tr>
                <td class="label">Employment Type</td><td>{{ $emp?->employmentType?->name }}</td>
                <td class="label">SOCSO / EIS No.</td><td>{{ $s?->socso_no }}</td>
            </tr>
            <tr>
                <td class="label"></td><td></td>
                <td class="label">Income Tax No.</td><td>{{ $s?->tax_no }}</td>
            </tr>
        </table>

        <div class="cols">
            <div class="col left-col">
                <div class="section-title">Earnings</div>
                <table class="lines-table">
                    <tr><th>Description</th><th>Period</th><th class="num">Rate</th><th class="num">Total</th></tr>
                    @forelse ($d['earnings'] as $row)
                        <tr>
                            <td>{{ $row['description'] }}</td>
                            <td>{{ $row['period'] }}</td>
                            <td class="num">{{ $row['rate'] }}</td>
                            <td class="num">{{ number_format($row['total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">-</td></tr>
                    @endforelse
                    <tr class="foot-row"><td colspan="3">TOTAL EARNINGS</td><td class="num">{{ number_format($d['totalEarnings'], 2) }}</td></tr>
                </table>
            </div>
            <div class="col right-col">
                <div class="section-title">Deductions</div>
                <table class="lines-table">
                    <tr><th>Description</th><th>Period</th><th class="num">Rate</th><th class="num">Total</th></tr>
                    @forelse ($d['deductions'] as $row)
                        <tr>
                            <td>{{ $row['description'] }}</td>
                            <td>{{ $row['period'] }}</td>
                            <td class="num">{{ $row['rate'] }}</td>
                            <td class="num">{{ number_format($row['total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">-</td></tr>
                    @endforelse
                    <tr class="foot-row"><td colspan="3">TOTAL DEDUCTIONS</td><td class="num">{{ number_format($d['totalDeductions'], 2) }}</td></tr>
                </table>
            </div>
        </div>

        <div class="section-title" style="margin-top:10px;">Statutory Summary</div>
        <table class="statutory-table">
            <tr>
                <th rowspan="2">Item</th>
                <th colspan="2">Employee</th>
                <th colspan="2">Employer</th>
            </tr>
            <tr>
                <th>Current</th><th>Year-to-date</th><th>Current</th><th>Year-to-date</th>
            </tr>
            @foreach ([['EPF', 'epf'], ['SOCSO', 'socso'], ['EIS', 'eis'], ['PCB', 'pcb']] as [$label, $key])
                <tr>
                    <td>{{ $label }}</td>
                    <td>{{ number_format($ytd[$key]['employee']['month'], 2) }}</td>
                    <td>{{ number_format($ytd[$key]['employee']['ytd'], 2) }}</td>
                    <td>{{ isset($ytd[$key]['employer']) ? number_format($ytd[$key]['employer']['month'], 2) : '-' }}</td>
                    <td>{{ isset($ytd[$key]['employer']) ? number_format($ytd[$key]['employer']['ytd'], 2) : '-' }}</td>
                </tr>
            @endforeach
            @if ($s?->skbbk_opt_in)
                <tr>
                    <td>SKBBK</td>
                    <td>{{ number_format($ytd['skbbk']['employee']['month'], 2) }}</td>
                    <td>{{ number_format($ytd['skbbk']['employee']['ytd'], 2) }}</td>
                    <td>-</td>
                    <td>-</td>
                </tr>
            @endif
        </table>

        <div class="nett">
            <div class="label">Nett Wage</div>
            <div class="amount">{{ $money($p->net_pay) }}</div>
            @if ($d['reimbursement'] > 0)
                <div class="meta" style="color:#ccc;margin-top:3px;">Includes claim reimbursement of {{ $money($d['reimbursement']) }} (added after deductions)</div>
            @endif
        </div>

        <div class="bottom">
            <div class="col">
                <div class="section-title" style="margin-top:10px;">Payment Details</div>
                <table class="emp-table">
                    <tr><td class="label">Method</td><td>Bank Transfer</td></tr>
                    <tr><td class="label">Bank</td><td>{{ $s?->bank_name }}</td></tr>
                    <tr><td class="label">Account No.</td><td>{{ $s?->bank_account_no }}</td></tr>
                </table>

                <div class="section-title" style="margin-top:10px;">Leave Balance</div>
                <table class="emp-table">
                    @forelse ($balances as $b)
                        <tr><td class="label">{{ $b->leaveType?->name }}</td><td>{{ number_format($b->balance, 1) }} days</td></tr>
                    @empty
                        <tr><td>-</td></tr>
                    @endforelse
                </table>
            </div>
            <div class="col">
                <div class="section-title" style="margin-top:10px;">Remarks</div>
                <div class="remark-box">{{ $p->notes }}</div>
            </div>
        </div>
    </div>
@endforeach
</body>
</html>
