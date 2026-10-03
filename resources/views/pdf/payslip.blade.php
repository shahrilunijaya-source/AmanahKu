<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    /* Poppins for text, JetBrains Mono for figures (the app's pairing). dompdf reads TTF
       only, so the files live in resources/fonts; it copies them into storage/fonts. DejaVu
       stays as the fallback for any glyph Poppins lacks. */
    @font-face { font-family: 'Poppins'; font-weight: normal; font-style: normal; src: url('{{ resource_path('fonts/Poppins-Regular.ttf') }}') format('truetype'); }
    @font-face { font-family: 'Poppins'; font-weight: bold; font-style: normal; src: url('{{ resource_path('fonts/Poppins-Bold.ttf') }}') format('truetype'); }
    @font-face { font-family: 'JetBrains Mono'; font-weight: normal; font-style: normal; src: url('{{ resource_path('fonts/JetBrainsMono-Regular.ttf') }}') format('truetype'); }
    @font-face { font-family: 'JetBrains Mono'; font-weight: bold; font-style: normal; src: url('{{ resource_path('fonts/JetBrainsMono-Bold.ttf') }}') format('truetype'); }

    @page { margin: 26px 30px 22px; }
    body { font-family: 'Poppins', 'DejaVu Sans', sans-serif; font-size: 8px; color: #5a5852; line-height: 1.35; }
    /* No CSS page-break rule here: dompdf's :last-child support is unreliable, and a
       break left on the final payslip prints a trailing blank page. The break is added
       inline per-iteration below, only between payslips, via Blade's $loop->last. */
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }
    .mono { font-family: 'JetBrains Mono', 'DejaVu Sans Mono', monospace; }
    .num { text-align: right; white-space: nowrap; font-family: 'JetBrains Mono', 'DejaVu Sans Mono', monospace; }
    .muted { color: #6f6c61; }
    .ink { color: #26251e; }
    .b { font-weight: bold; }
    .gap { width: 12px; }
    .sp { height: 9px; }

    .header { border-bottom: 1px solid #26251e; padding-bottom: 9px; }
    .header td { vertical-align: middle; }
    .logo { max-height: 40px; max-width: 120px; }
    .company { font-size: 16px; font-weight: bold; color: #26251e; line-height: 1.2; }
    .doc-title { font-size: 9px; color: #6f6c61; margin-top: 2px; }
    .period { font-size: 14px; font-weight: bold; color: #26251e; text-align: right; }
    .paydate { text-align: right; color: #6f6c61; margin-top: 2px; font-size: 8.5px; }

    .emp-name { font-size: 13px; font-weight: bold; color: #26251e; letter-spacing: 0.3px; text-transform: uppercase; }
    .emp-pos { color: #6f6c61; margin: 1px 0 6px; font-size: 8.5px; }
    .ids td { padding: 0.5px 0; font-size: 8.5px; }
    .ids td.k { color: #6f6c61; width: 60px; }
    .ids td.v { color: #26251e; }

    .card { border: 1px solid #e6e5e0; border-radius: 8px; }
    .bar { background: #26251e; color: #ffffff; font-weight: bold; font-size: 7.5px; letter-spacing: 0.8px; text-transform: uppercase; padding: 6px 12px; border-radius: 7px 7px 0 0; }
    .info td { padding: 2.5px 12px; font-size: 8.5px; }
    .info td.k { color: #6f6c61; width: 90px; }
    .info td.v { color: #26251e; }

    .sec { font-size: 9px; font-weight: bold; color: #26251e; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 5px; }
    .sw { display: inline-block; width: 8px; height: 8px; border-radius: 2px; margin-right: 5px; }
    .split { border-collapse: separate; border-spacing: 0; }
    .box { border: 1px solid #e6e5e0; border-radius: 8px; }
    .lines th { padding: 5px 7px; font-size: 7.5px; font-weight: bold; text-align: center; color: #26251e; border-bottom: 1px solid #e6e5e0; }
    .lines td { padding: 4px 7px; font-size: 8.5px; color: #26251e; }
    .lines tr.alt td { background: #f6f6f3; }
    .lines td.cur { color: #8b887e; font-size: 7px; text-align: right; padding-right: 0; width: 18px; }
    .total { border-top: 1px solid #e6e5e0; }
    .total td { padding: 6px 8px; word-spacing: 1.5px; font-weight: bold; color: #26251e; font-size: 8.5px; vertical-align: middle; }
    .total .cur { font-size: 7.5px; }
    .total .big { font-size: 12px; }

    .stat th { padding: 4px 7px; font-size: 7.5px; font-weight: bold; color: #26251e; text-align: center; border-bottom: 1px solid #e6e5e0; border-left: 1px solid #efeee8; }
    .stat td { padding: 3px 7px; font-size: 8.5px; text-align: right; border-bottom: 1px solid #efeee8; border-left: 1px solid #efeee8; color: #26251e; font-family: 'JetBrains Mono', 'DejaVu Sans Mono', monospace; }
    .stat th:first-child, .stat td:first-child { border-left: 0; }
    .stat td.lab { text-align: left; font-weight: bold; font-family: 'Poppins', 'DejaVu Sans', sans-serif; }
    .stat tr.last td { border-bottom: 0; }
    .stat tr.alt td { background: #f6f6f3; }
    .foot { font-size: 7px; color: #6f6c61; margin-top: 3px; }

    .nett { background: #26251e; border-radius: 8px; padding: 11px 14px; }
    .nett td { vertical-align: middle; color: #ffffff; }
    .nett .lab { font-size: 9px; font-weight: bold; letter-spacing: 0.8px; text-transform: uppercase; }
    .nett .amt { text-align: right; font-size: 16px; font-weight: bold; white-space: nowrap; font-family: 'JetBrains Mono', 'DejaVu Sans Mono', monospace; }
    .nett .cur { font-size: 8px; font-weight: normal; opacity: 0.8; }
    .pay td { padding: 4px 12px; font-size: 8.5px; }
    .pay td.k { color: #6f6c61; width: 80px; }
    .pay td.v { color: #26251e; text-align: right; }

    .remark-t { padding: 6px 12px; font-size: 7.5px; font-weight: bold; color: #26251e; text-transform: uppercase; letter-spacing: 0.8px; border-bottom: 1px solid #e6e5e0; }
    .remark { padding: 7px 12px; min-height: 26px; font-size: 8.5px; color: #26251e; }
    .particulars { padding: 7px 12px; background: #f6f6f3; border: 1px solid #e6e5e0; border-radius: 8px; font-size: 7.5px; color: #5a5852; line-height: 1.6; }
    .particulars b { color: #26251e; }
    .footer { margin-top: 12px; padding-top: 7px; border-top: 1px solid #e6e5e0; text-align: center; font-size: 7.5px; color: #8b887e; }
</style>
</head>
<body>
@if (! empty($draft))
    {{-- HR preview of an unissued payslip: fixed, so it repeats on every page. --}}
    <div style="position:fixed;top:40%;left:0;right:0;text-align:center;transform:rotate(-28deg);color:#d6232b;opacity:0.10;font-weight:bold;font-size:96px;letter-spacing:8px;">DRAFT</div>
    <div style="position:fixed;top:-12px;left:0;right:0;text-align:center;font-size:8px;color:#d6232b;letter-spacing:1px;">DRAFT PREVIEW · NOT ISSUED · FIGURES MAY CHANGE</div>
@endif
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
        // entitlement a payslip reports. Annual then Medical first, whatever the row order.
        $balances = $emp?->leaveBalances->reject(fn ($b) => $b->leaveType?->is_hr_granted_only)
            ->sortBy(fn ($b) => match ($b->leaveType?->name) { 'Annual' => 0, 'Medical' => 1, default => 2 })->take(2) ?? collect();
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
        <table class="header"><tr>
            @if ($logoPath && file_exists($logoPath))
                <td style="width: 60px;"><img class="logo" src="{{ $logoPath }}" alt="logo"></td>
            @endif
            <td>
                <div class="company">{{ $tenant?->name ?? 'Company' }}</div>
                <div class="doc-title">Official Payslip</div>
            </td>
            <td style="width: 190px;">
                <div class="period">{{ $periodLabel }}</div>
                <div class="paydate">Payment Date: {{ $payDate }}</div>
            </td>
        </tr></table>

        <div class="sp"></div>
        <table><tr>
            <td style="width: 52%;">
                <div class="emp-name">{{ $emp?->name }}</div>
                <div class="emp-pos">{{ $emp?->position }}</div>
                <table class="ids">
                    <tr><td class="k">IC</td><td class="v">{{ $dash($emp?->nric) }}</td></tr>
                    <tr><td class="k">ID</td><td class="v">{{ $dash($emp?->staff_id) }}</td></tr>
                    <tr><td class="k">EPF</td><td class="v">{{ $dash($s?->epf_no) }}</td></tr>
                    <tr><td class="k">SOCSO/EIS</td><td class="v">{{ $dash($s?->socso_no) }}</td></tr>
                    <tr><td class="k">TAX</td><td class="v">{{ $dash($s?->tax_no) }}</td></tr>
                </table>
            </td>
            <td class="gap"></td>
            <td>
                <div class="card">
                    <div class="bar">Employment Info</div>
                    <table class="info" style="margin: 3px 0;">
                        <tr><td class="k">Department</td><td class="v">{{ $dash($emp?->department?->name) }}</td></tr>
                        <tr><td class="k">Employment Type</td><td class="v">{{ $dash($emp?->employmentType?->name) }}</td></tr>
                        @if ($partial)
                            <tr><td class="k">Days Employed</td><td class="v">{{ $p->days_employed }} / {{ $p->days_in_month }}</td></tr>
                        @endif
                    </table>
                </div>
            </td>
        </tr></table>

        <div class="sp"></div>
        @php
            $panes = [['Earnings', '#1f8a65', $d['earnings'], 'TOTAL EARNINGS', $d['totalEarnings']], ['Deductions', '#d6232b', $d['deductions'], 'TOTAL DEDUCTIONS', $d['totalDeductions']]];
            $maxRows = max(count($d['earnings']), count($d['deductions']), 1);
        @endphp
        {{-- Two rows (titles, then boxes) so both boxes are table cells and stretch to the same height. --}}
        <table class="split">
            <tr>
                @foreach ($panes as [$title, $color])
                    <td style="width: 49.5%;"><div class="sec"><span class="sw" style="background: {{ $color }};"></span>{{ $title }}</div></td>
                    @if ($loop->first)<td class="gap"></td>@endif
                @endforeach
            </tr>
            <tr>
                @foreach ($panes as [$title, $color, $rows, $totalLabel, $total])
                    <td class="box">
                        <table class="lines">
                            <tr><th style="width: 45%;">Description</th><th style="width: 13%;">Period</th><th style="width: 13%;">Rate</th><th colspan="2">Total</th></tr>
                            @forelse ($rows as $row)
                                <tr class="{{ $loop->even ? 'alt' : '' }}">
                                    <td>{{ $row['description'] }}</td>
                                    <td class="muted" style="text-align: center;">{{ $row['period'] !== '' ? $row['period'] : '-' }}</td>
                                    <td class="num muted">{{ $row['rate'] !== '' ? $row['rate'] : '-' }}</td>
                                    <td class="cur">MYR</td>
                                    <td class="num">{{ $amt($row['total']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="muted">-</td></tr>
                            @endforelse
                            {{-- Blank filler rows so both TOTAL rows land on the same line (dompdf cannot bottom-align). --}}
                            @for ($i = max(count($rows), 1); $i < $maxRows; $i++)
                                <tr><td colspan="5">&nbsp;</td></tr>
                            @endfor
                        </table>
                        <table class="total"><tr>
                            <td>{{ $totalLabel }}</td>
                            <td class="num" style="color: {{ $color }};"><span class="cur">MYR</span> <span class="big">{{ $amt($total) }}</span></td>
                        </tr></table>
                    </td>
                    @if ($loop->first)<td class="gap"></td>@endif
                @endforeach
            </tr>
        </table>

        <div class="sp"></div>
        <table><tr>
            <td style="width: 55%;">
                <div class="sec">Statutory Summary</div>
                <div class="card">
                    <table class="stat">
                        <tr><th rowspan="2" style="vertical-align: middle;"></th><th colspan="2">Employee</th><th colspan="2">Employer</th></tr>
                        <tr><th>CURRENT</th><th>YTD</th><th>CURRENT</th><th>YTD</th></tr>
                        @foreach ($statRows as [$label, $key])
                            @php
                                [$eeM, $eeY] = $cellOf($ytd[$key], 'employee');
                                [$erM, $erY] = $cellOf($ytd[$key], 'employer');
                            @endphp
                            <tr class="{{ $loop->even ? 'alt' : '' }} {{ $loop->last ? 'last' : '' }}">
                                <td class="lab">{{ $label }}</td>
                                <td>{{ $eeM }}</td><td>{{ $eeY }}</td><td>{{ $erM }}</td><td>{{ $erY }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
                @if ($borne)
                    <div class="foot">* Contribution is borne by the Employer</div>
                @endif
            </td>
            <td class="gap"></td>
            <td>
                <table class="nett"><tr>
                    <td class="lab">Nett Wage</td>
                    <td class="amt"><span class="cur">MYR</span> {{ $amt($p->net_pay) }}</td>
                </tr></table>
                <div class="sp"></div>
                <div class="card">
                    <table class="pay">
                        <tr><td class="k">Payment Method</td><td class="v">Bank Transfer</td></tr>
                        <tr><td class="k">Bank Name</td><td class="v">{{ $dash($s?->bank_name) }}</td></tr>
                        <tr><td class="k">Account No.</td><td class="v mono">{{ $dash($s?->bank_account_no) }}</td></tr>
                    </table>
                </div>
            </td>
        </tr></table>

        <div class="sp"></div>
        <table><tr>
            <td style="width: 55%;">
                <div class="card">
                    <table class="stat">
                        <tr><th style="text-align: left;">Leave Type</th><th>Balance</th><th>YTD</th></tr>
                        @forelse ($balances as $b)
                            <tr class="{{ $loop->even ? 'alt' : '' }} {{ $loop->last ? 'last' : '' }}">
                                <td class="lab">{{ $b->leaveType?->name }}</td>
                                <td>{{ number_format($b->balance, 1) }}</td>
                                <td>{{ number_format($d['leaveYtd'][$b->leaveType?->name] ?? 0, 1) }}</td>
                            </tr>
                        @empty
                            <tr class="last"><td colspan="3" class="muted" style="text-align: left;">-</td></tr>
                        @endforelse
                    </table>
                </div>
            </td>
            <td class="gap"></td>
            <td>
                <div class="card">
                    <div class="remark-t">Remark</div>
                    <div class="remark">{{ $p->notes }}</div>
                </div>
            </td>
        </tr></table>

        <div class="sp"></div>
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
