<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28px 32px 24px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #26251e; line-height: 1.35; }
    /* No CSS page-break rule here — dompdf's :last-child support is unreliable, and a
       break left on the final payslip prints a trailing blank page. The break is added
       inline per-iteration below, only between payslips, via Blade's $loop->last. */
    table { width: 100%; border-collapse: collapse; }
    .row { width: 100%; }
    .row td { vertical-align: top; }
    .muted { color: #6f6c61; }
    .num { text-align: right; white-space: nowrap; }
    .caps { text-transform: uppercase; letter-spacing: 0.6px; }

    .header { border-bottom: 1.5px solid #26251e; padding-bottom: 8px; }
    .logo { max-height: 38px; max-width: 120px; }
    .company { font-size: 14px; font-weight: bold; }
    .doc-title { font-size: 8px; color: #d6232b; font-weight: bold; margin-top: 2px; }
    .period { font-size: 13px; font-weight: bold; text-align: right; }
    .paydate { text-align: right; color: #6f6c61; margin-top: 2px; }

    .emp { margin-top: 12px; }
    .emp-name { font-size: 12px; font-weight: bold; letter-spacing: 0.4px; }
    .emp-pos { color: #6f6c61; margin-bottom: 5px; }
    .ids td { padding: 1.5px 0; vertical-align: top; }
    .ids td.k { color: #6f6c61; width: 62px; }
    .panel { background: #f6f6f3; border: 1px solid #e6e5e0; padding: 8px 10px; }
    .panel-title { font-size: 7.5px; font-weight: bold; color: #6f6c61; margin-bottom: 4px; }
    .panel td { padding: 2px 0; }
    .panel td.k { color: #6f6c61; width: 80px; }

    .gap { width: 14px; }
    .box { border: 1px solid #e6e5e0; }
    .box-title { padding: 5px 8px; font-size: 8px; font-weight: bold; border-bottom: 1px solid #e6e5e0; }
    .box-title.earn { border-top: 2px solid #1f8a65; }
    .box-title.ded { border-top: 2px solid #d6232b; }
    .lines th { padding: 4px 6px; font-size: 7px; text-align: left; color: #6f6c61; font-weight: bold; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: 1px solid #e6e5e0; }
    .lines td { padding: 4px 6px; border-bottom: 1px solid #efeee8; vertical-align: top; }
    .lines th.num { text-align: right; }
    .lines td.cur { color: #8b887e; padding-right: 0; text-align: right; }
    .total-row td { background: #f6f6f3; font-weight: bold; padding: 5px 6px; border-top: 1px solid #ddd9cf; }

    .sec-title { font-size: 8px; font-weight: bold; margin-bottom: 4px; }
    .stat th { padding: 3px 6px; font-size: 7px; text-transform: uppercase; color: #6f6c61; border-bottom: 1px solid #ddd9cf; text-align: right; }
    .stat td { padding: 3.5px 6px; border-bottom: 1px solid #efeee8; text-align: right; }
    .stat th:first-child, .stat td:first-child { text-align: left; }
    .stat td.lab { font-weight: bold; }
    .stat .grp { text-align: center; border-bottom: 0; padding-bottom: 0; }
    .nett { background: #d6232b; color: #fff; padding: 9px 12px; }
    .nett .lab { font-size: 7.5px; font-weight: bold; opacity: 0.9; }
    .nett .amt { font-size: 19px; font-weight: bold; margin-top: 2px; }
    .pay td { padding: 2.5px 0; vertical-align: top; }
    .pay td.k { color: #6f6c61; width: 88px; }

    .remark { min-height: 36px; padding: 6px 8px; border: 1px solid #e6e5e0; }
    .particulars { margin-top: 10px; padding: 6px 8px; background: #f6f6f3; border: 1px solid #e6e5e0; font-size: 7.5px; color: #5a5852; }
    .particulars b { color: #26251e; font-weight: bold; }
    .footer { margin-top: 12px; padding-top: 6px; border-top: 1px solid #e6e5e0; text-align: center; font-size: 7.5px; color: #8b887e; }
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
        $pt = $d['particulars'];
        $amt = fn ($v) => number_format((float) $v, 2, '.', ',');
        $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        $tenant = $emp?->tenant;
        $logoPath = $tenant?->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->path($tenant->logo_path) : null;
        $payDate = ($run?->payment_date ?? $run?->finalized_at)?->format('d/m/Y') ?? now()->format('d/m/Y');
        $periodLabel = $run?->label ?? $run?->period;
        $partial = $p->days_employed !== null && $p->days_in_month !== null && $p->days_employed < $p->days_in_month;
        $dash = fn ($v) => ($v === null || $v === '') ? 'N/A' : $v;
        // Only the two headline entitlements. A granted type (Replacement) does carry a
        // balance now, but it is quota earned by working rest days, not part of the yearly
        // entitlement a payslip reports.
        $balances = $emp?->leaveBalances->reject(fn ($b) => $b->leaveType?->is_hr_granted_only)->take(2) ?? collect();
        $statRows = [['EPF', 'epf'], ['SOCSO', 'socso'], ['EIS', 'eis'], ['PCB', 'pcb'], ['HRDF', 'hrdf']];
        if ($s?->skbbk_opt_in) {
            $statRows[] = ['SKBBK', 'skbbk'];
        }
        $borne = false;
        $cellOf = function (array $f, string $side) use ($amt, &$borne) {
            if (! isset($f[$side])) {
                return ['-', '-', false];
            }
            $star = $side === 'employer' && isset($f['employee']) && $f['employee']['month'] == 0 && $f[$side]['month'] > 0;
            $borne = $borne || $star;

            return [$amt($f[$side]['month']).($star ? ' *' : ''), $amt($f[$side]['ytd']), $star];
        };
    @endphp
    <div class="page" @if (! $loop->last) style="page-break-after: always;" @endif>
        <table class="row header"><tr>
            @if ($logoPath && file_exists($logoPath))
                <td class="cell" style="width: 60px; vertical-align: middle;"><img class="logo" src="{{ $logoPath }}" alt="logo"></td>
            @endif
            <td class="cell" style="vertical-align: middle;">
                <div class="company">{{ $tenant?->name ?? 'Company' }}</div>
                <div class="doc-title caps">Official Payslip</div>
            </td>
            <td class="cell" style="vertical-align: middle; width: 200px;">
                <div class="period">{{ $periodLabel }}</div>
                <div class="paydate">Payment Date: {{ $payDate }}</div>
            </td>
        </tr></table>

        <div style="height: 12px;"></div><table class="row"><tr>
            <td class="cell" style="width: 55%;">
                <div class="emp-name caps">{{ $emp?->name }}</div>
                <div class="emp-pos">{{ $emp?->position }}</div>
                <table class="ids">
                    <tr><td class="k">IC</td><td>{{ $dash($emp?->nric) }}</td></tr>
                    <tr><td class="k">ID</td><td>{{ $dash($emp?->staff_id) }}</td></tr>
                    <tr><td class="k">EPF</td><td>{{ $dash($s?->epf_no) }}</td></tr>
                    <tr><td class="k">SOCSO/EIS</td><td>{{ $dash($s?->socso_no) }}</td></tr>
                    <tr><td class="k">TAX</td><td>{{ $dash($s?->tax_no) }}</td></tr>
                </table>
            </td>
            <td class="gap"></td>
            <td class="cell">
                <div class="panel">
                    <div class="panel-title caps">Employment Info</div>
                    <table>
                        <tr><td class="k">Department</td><td>{{ $dash($emp?->department?->name) }}</td></tr>
                        <tr><td class="k">Employment Type</td><td>{{ $dash($emp?->employmentType?->name) }}</td></tr>
                        @if ($partial)
                            <tr><td class="k">Days Employed</td><td>{{ $p->days_employed }} / {{ $p->days_in_month }}</td></tr>
                        @endif
                    </table>
                </div>
            </td>
        </tr></table>

        <div style="height: 12px;"></div><table class="row"><tr>
            @foreach ([['Earnings', 'earn', $d['earnings'], 'TOTAL EARNINGS', $d['totalEarnings']], ['Deductions', 'ded', $d['deductions'], 'TOTAL DEDUCTIONS', $d['totalDeductions']]] as [$title, $cls, $rows, $totalLabel, $total])
                <td class="cell box" style="width: 49%;">
                    <div class="box-title caps {{ $cls }}">{{ $title }}</div>
                    <table class="lines">
                        <tr><th style="width: 46%;">Description</th><th style="width: 13%;">Period</th><th class="num" style="width: 13%;">Rate</th><th class="num" colspan="2">Total</th></tr>
                        @forelse ($rows as $row)
                            <tr>
                                <td>{{ $row['description'] }}</td>
                                <td class="muted">{{ $row['period'] !== '' ? $row['period'] : '-' }}</td>
                                <td class="num muted">{{ $row['rate'] !== '' ? $row['rate'] : '-' }}</td>
                                <td class="cur">MYR</td>
                                <td class="num">{{ $amt($row['total']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="muted">-</td></tr>
                        @endforelse
                        <tr class="total-row"><td colspan="3">{{ $totalLabel }}</td><td class="num" colspan="2">MYR {{ $amt($total) }}</td></tr>
                    </table>
                </td>
                @if ($loop->first)
                    <td class="gap"></td>
                @endif
            @endforeach
        </tr></table>

        <div style="height: 12px;"></div><table class="row"><tr>
            <td class="cell" style="width: 55%;">
                <div class="sec-title caps">Statutory Summary</div>
                <table class="stat">
                    <tr><th rowspan="2" style="vertical-align: bottom;"></th><th colspan="2" class="grp">Employee</th><th colspan="2" class="grp">Employer</th></tr>
                    <tr><th>Current</th><th>YTD</th><th>Current</th><th>YTD</th></tr>
                    @foreach ($statRows as [$label, $key])
                        @php
                            [$eeM, $eeY] = $cellOf($ytd[$key], 'employee');
                            [$erM, $erY] = $cellOf($ytd[$key], 'employer');
                        @endphp
                        <tr>
                            <td class="lab">{{ $label }}</td>
                            <td>{{ $eeM }}</td><td>{{ $eeY }}</td><td>{{ $erM }}</td><td>{{ $erY }}</td>
                        </tr>
                    @endforeach
                </table>
                @if ($borne)
                    <div class="muted" style="margin-top: 3px; font-size: 7px;">* Contribution is borne by the Employer</div>
                @endif
            </td>
            <td class="gap"></td>
            <td class="cell">
                <div class="nett">
                    <div class="lab caps">Nett Wage</div>
                    <div class="amt">MYR {{ $amt($p->net_pay) }}</div>
                </div>
                <div class="panel" style="margin-top: 8px; border-top: 0;">
                    <table class="pay">
                        <tr><td class="k">Payment Method</td><td>Bank Transfer</td></tr>
                        <tr><td class="k">Bank Name</td><td>{{ $dash($s?->bank_name) }}</td></tr>
                        <tr><td class="k">Account No.</td><td>{{ $dash($s?->bank_account_no) }}</td></tr>
                    </table>
                </div>
            </td>
        </tr></table>

        <div style="height: 12px;"></div><table class="row"><tr>
            <td class="cell" style="width: 55%;">
                <table class="stat">
                    <tr><th>Leave Type</th><th>Balance</th><th>YTD</th></tr>
                    @forelse ($balances as $b)
                        <tr>
                            <td class="lab">{{ $b->leaveType?->name }}</td>
                            <td>{{ number_format($b->balance, 1) }}</td>
                            <td>{{ number_format($d['leaveYtd'][$b->leaveType?->name] ?? 0, 1) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="muted" style="text-align: left;">-</td></tr>
                    @endforelse
                </table>
            </td>
            <td class="gap"></td>
            <td class="cell">
                <div class="sec-title caps">Remark</div>
                <div class="remark">{{ $p->notes }}</div>
            </td>
        </tr></table>

        <div class="particulars">
            <b>Pay particulars</b> &nbsp; Monthly rate <b>MYR {{ $amt($pt['monthlyRate']) }}</b>
            @if ($pt['daysEmployed'] !== null && $pt['daysInMonth'] !== null) &middot; Days employed <b>{{ $pt['daysEmployed'] }} / {{ $pt['daysInMonth'] }}</b>@endif
            @if ($pt['unpaidDailyRate'] !== null) &middot; Unpaid leave rate <b>MYR {{ $amt($pt['unpaidDailyRate']) }}</b> / day (basic &divide; {{ $pt['daysInMonth'] }} calendar days)@endif
            @if ($pt['dailyRate'] !== null) &middot; Ordinary daily rate <b>MYR {{ $amt($pt['dailyRate']) }}</b> (monthly &divide; 26) &middot; Hourly rate <b>MYR {{ $amt($pt['hourlyRate']) }}</b> (daily &divide; 8)@endif
            @foreach ($pt['overtimeGroups'] as $g)
                &middot; Overtime @if ($g['multiplier'] !== '') {{ $g['multiplier'] }}&times;@endif <b>{{ $trim($g['hours']) }} hrs</b>
            @endforeach
            @foreach ($pt['leaveTaken'] as $l)
                &middot; Leave taken: {{ $l['type'] }} <b>{{ $trim($l['days']) }} d</b>
            @endforeach
            @if ($pt['employerEpfNo'] || $pt['employerSocsoNo'])
                <br>Employer EPF No. <b>{{ $pt['employerEpfNo'] ?? '-' }}</b> &middot; Employer SOCSO Code <b>{{ $pt['employerSocsoNo'] ?? '-' }}</b>
            @endif
        </div>

        <div class="footer">This is a computer generated document. No signature is required.</div>
    </div>
@endforeach
</body>
</html>
