{{-- One payslip in full, mirroring the PDF. Both read PayslipPdfData::build so the screen and the
     download can never disagree. $p is the payslip; $ackable shows the Acknowledge control (My Payroll only). --}}
@php
    $p->loadMissing(['employee.salaryStructure', 'employee.department', 'employee.employmentType', 'employee.leaveBalances.leaveType', 'employee.tenant', 'payrollRun', 'lines']);
    $d = app(\App\Services\Payroll\PayslipPdfData::class)->build($p);
    $statusColor = ['draft' => 'var(--amber)', 'approved' => 'var(--info)', 'finalized' => 'var(--success)'];
    $statusMs = ['draft' => 'Draf', 'approved' => 'Diluluskan', 'finalized' => 'Difinalize'];
    $amt = fn ($v) => number_format((float) $v, 2, '.', ',');
    $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $run = $p->payrollRun;
    $emp = $p->employee;
    $s = $emp?->salaryStructure;
    $ytd = $d['ytd'];
    $pt = $d['particulars'];
    $me = request()->attributes->get('employee');
    $payDate = ($run?->payment_date ?? $run?->finalized_at)?->format('d/m/Y');
    $partial = $p->days_employed !== null && $p->days_in_month !== null && $p->days_employed < $p->days_in_month;
    $na = fn ($v) => ($v === null || $v === '') ? 'N/A' : $v;
    $balances = $emp?->leaveBalances->reject(fn ($b) => $b->leaveType?->is_hr_granted_only)
        ->sortBy(fn ($b) => match ($b->leaveType?->name) { 'Annual' => 0, 'Medical' => 1, default => 2 })->take(2) ?? collect();
    $statRows = [['EPF', 'epf'], ['SOCSO', 'socso'], ['EIS', 'eis'], ['PCB', 'pcb'], ['HRDF', 'hrdf']];
    if ($s?->skbbk_opt_in) {
        $statRows[] = ['SKBBK', 'skbbk'];
    }
    $borne = false;
    $statCell = function (array $f, string $side) use ($amt, &$borne) {
        if (! isset($f[$side])) {
            return ['-', '-'];
        }
        $star = $side === 'employer' && isset($f['employee']) && $f['employee']['month'] == 0 && $f[$side]['month'] > 0;
        $borne = $borne || $star;

        return [$amt($f[$side]['month']).($star ? ' *' : ''), $amt($f[$side]['ytd'])];
    };
    $labelMs = ['Salary' => 'Gaji', 'Unpaid Leave' => 'Cuti tanpa gaji', 'EPF Employee Contribution' => 'Caruman EPF pekerja', 'SOCSO Employee Contribution' => 'Caruman SOCSO pekerja', 'EIS Employee Contribution' => 'Caruman EIS pekerja', 'PCB additional' => 'PCB tambahan', 'Mid-month advance' => 'Pendahuluan pertengahan bulan', 'Bonus' => 'Bonus', 'Zakat' => 'Zakat'];
@endphp
<div class="uj-card slip" style="padding:0;overflow:hidden;max-width:860px;">
    {{-- Header --}}
    <div class="slip-head">
        <div style="flex:1;min-width:200px;">
            <div style="font-size:17px;font-weight:600;color:var(--ink);">{{ $emp?->tenant?->name }}</div>
            <div class="slip-kicker" x-text="$store.ui.lang==='en' ? 'Official payslip' : 'Payslip rasmi'">Official payslip</div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:15px;font-weight:600;color:var(--ink);">{{ $run?->label ?? $run?->period }}</div>
            @if ($payDate)<div style="font-size:12px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Payment date' : 'Tarikh bayaran'">Payment date</span>: {{ $payDate }}</div>@endif
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span class="uj-pill" style="background:#fff;border:1px solid var(--hairline);color:{{ $statusColor[$run?->status] ?? 'var(--muted)' }};" x-text="$store.ui.lang==='en' ? @js(ucfirst((string) $run?->status)) : @js($statusMs[$run?->status] ?? ucfirst((string) $run?->status))">{{ $run?->status }}</span>
            @if ($run?->status === 'finalized')
                <a href="{{ route('payroll.payslips.pdf', $p) }}" class="uj-btn-ghost" style="height:34px;padding:0 12px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Download PDF' : 'Muat turun PDF'">Download PDF</a>
                @if (($ackable ?? false) && ($payslipAckOn ?? false) && $run->isPublished())
                    @if ($p->acknowledged_at)
                        <span style="font-size:12px;color:var(--success);"><span x-text="$store.ui.lang==='en' ? 'Acknowledged on' : 'Diakui pada'">Acknowledged on</span> {{ $p->acknowledged_at->format('d M Y') }}</span>
                    @elseif ($me?->id === $p->employee_id)
                        <form method="post" action="{{ route('payroll.payslips.acknowledge', $p) }}" style="margin:0;">@csrf<button class="uj-btn-primary" style="height:34px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Acknowledge' : 'Akui'">Acknowledge</button></form>
                    @endif
                @endif
            @endif
        </div>
    </div>

    {{-- Employee + employment info --}}
    <div class="slip-cols slip-pad" style="border-bottom:1px solid var(--hairline-soft);">
        <div>
            <div style="font-size:15px;font-weight:700;letter-spacing:0.4px;text-transform:uppercase;color:var(--ink);">{{ $emp?->name }}</div>
            <div style="font-size:12.5px;color:var(--muted);margin-bottom:8px;">{{ $emp?->position }}</div>
            @foreach ([['IC', 'IC', $emp?->nric], ['ID', 'ID', $emp?->staff_id], ['EPF', 'EPF', $s?->epf_no], ['SOCSO/EIS', 'SOCSO/EIS', $s?->socso_no], ['TAX', 'CUKAI', $s?->tax_no]] as [$en, $ms, $v])
                <div class="slip-kv"><span x-text="$store.ui.lang==='en' ? @js($en) : @js($ms)">{{ $en }}</span><span class="mono-dd" style="text-align:left;">{{ $na($v) }}</span></div>
            @endforeach
        </div>
        <div class="slip-panel">
            <div class="slip-kicker" x-text="$store.ui.lang==='en' ? 'Employment info' : 'Maklumat pekerjaan'">Employment info</div>
            <div class="slip-kv"><span x-text="$store.ui.lang==='en' ? 'Department' : 'Jabatan'">Department</span><span>{{ $na($emp?->department?->name) }}</span></div>
            <div class="slip-kv"><span x-text="$store.ui.lang==='en' ? 'Employment type' : 'Jenis pekerjaan'">Employment type</span><span>{{ $na($emp?->employmentType?->name) }}</span></div>
            @if ($partial)
                <div class="slip-kv"><span x-text="$store.ui.lang==='en' ? 'Days employed' : 'Hari bekerja'">Days employed</span><span>{{ $p->days_employed }} / {{ $p->days_in_month }}</span></div>
            @endif
        </div>
    </div>

    {{-- Earnings | Deductions --}}
    <div class="slip-cols slip-pad" style="border-bottom:1px solid var(--hairline-soft);">
        @foreach ([['Earnings', 'Pendapatan', $d['earnings'], 'Total earnings', 'Jumlah pendapatan', $d['totalEarnings'], 'var(--success)'], ['Deductions', 'Potongan', $d['deductions'], 'Total deductions', 'Jumlah potongan', $d['totalDeductions'], 'var(--red)']] as [$en, $ms, $rows, $totEn, $totMs, $tot, $accent])
            <div class="slip-box" style="border-top:2px solid {{ $accent }};">
                <div class="slip-kicker" style="padding:10px 12px 4px;" x-text="$store.ui.lang==='en' ? @js($en) : @js($ms)">{{ $en }}</div>
                <div class="slip-lines">
                    <div class="slip-lh"><span x-text="$store.ui.lang==='en' ? 'Description' : 'Butiran'">Description</span><span x-text="$store.ui.lang==='en' ? 'Period' : 'Tempoh'">Period</span><span x-text="$store.ui.lang==='en' ? 'Rate' : 'Kadar'">Rate</span><span>MYR</span></div>
                    @forelse ($rows as $row)
                        <div class="slip-lr">
                            <span>@if (isset($labelMs[$row['description']]))<span x-text="$store.ui.lang==='en' ? @js($row['description']) : @js($labelMs[$row['description']])">{{ $row['description'] }}</span>@else{{ $row['description'] }}@endif</span>
                            <span class="mut">{{ $row['period'] !== '' ? $row['period'] : '-' }}</span>
                            <span class="mut">{{ $row['rate'] !== '' ? $row['rate'] : '-' }}</span>
                            <span class="mono-dd">{{ $amt($row['total']) }}</span>
                        </div>
                    @empty
                        <div class="slip-lr"><span class="mut">-</span></div>
                    @endforelse
                    <div class="slip-lt"><span x-text="$store.ui.lang==='en' ? @js($totEn) : @js($totMs)">{{ $totEn }}</span><span class="mono-dd">MYR {{ $amt($tot) }}</span></div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Net pay --}}
    <div class="slip-net">
        <div class="slip-kicker" style="color:rgba(255,255,255,0.75);" x-text="$store.ui.lang==='en' ? 'Net pay' : 'Gaji bersih'">Net pay</div>
        <div style="font-size:26px;font-weight:700;font-family:var(--font-mono);">MYR {{ $amt($p->net_pay) }}</div>
    </div>

    {{-- Statutory summary | payment --}}
    <div class="slip-cols slip-pad" style="border-bottom:1px solid var(--hairline-soft);">
        <div style="min-width:0;">
            <div class="slip-kicker" style="margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Statutory summary' : 'Ringkasan berkanun'">Statutory summary</div>
            <div class="slip-scroll">
                <table class="slip-tbl">
                    <thead>
                        <tr><th></th><th colspan="2" style="text-align:center;" x-text="$store.ui.lang==='en' ? 'Employee' : 'Pekerja'">Employee</th><th colspan="2" style="text-align:center;" x-text="$store.ui.lang==='en' ? 'Employer' : 'Majikan'">Employer</th></tr>
                        <tr><th></th><th x-text="$store.ui.lang==='en' ? 'Current' : 'Semasa'">Current</th><th>YTD</th><th x-text="$store.ui.lang==='en' ? 'Current' : 'Semasa'">Current</th><th>YTD</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($statRows as [$label, $key])
                            @php [$eeM, $eeY] = $statCell($ytd[$key], 'employee'); [$erM, $erY] = $statCell($ytd[$key], 'employer'); @endphp
                            <tr><td style="font-weight:600;color:var(--ink);">{{ $label }}</td><td>{{ $eeM }}</td><td>{{ $eeY }}</td><td>{{ $erM }}</td><td>{{ $erY }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($borne)<div style="font-size:11px;color:var(--muted);margin-top:4px;" x-text="$store.ui.lang==='en' ? '* Contribution is borne by the Employer' : '* Caruman ditanggung oleh Majikan'">* Contribution is borne by the Employer</div>@endif
        </div>
        <div class="slip-panel" style="align-self:start;">
            <div class="slip-kv"><span x-text="$store.ui.lang==='en' ? 'Payment method' : 'Kaedah bayaran'">Payment method</span><span x-text="$store.ui.lang==='en' ? 'Bank transfer' : 'Pindahan bank'">Bank transfer</span></div>
            <div class="slip-kv"><span x-text="$store.ui.lang==='en' ? 'Bank name' : 'Nama bank'">Bank name</span><span>{{ $na($s?->bank_name) }}</span></div>
            <div class="slip-kv"><span x-text="$store.ui.lang==='en' ? 'Account no.' : 'No. akaun'">Account no.</span><span class="mono-dd" style="text-align:left;">{{ $na($s?->bank_account_no) }}</span></div>
        </div>
    </div>

    {{-- Leave | Remark --}}
    <div class="slip-cols slip-pad" style="border-bottom:1px solid var(--hairline-soft);">
        <div>
            <table class="slip-tbl">
                <thead><tr><th style="text-align:left;" x-text="$store.ui.lang==='en' ? 'Leave type' : 'Jenis cuti'">Leave type</th><th x-text="$store.ui.lang==='en' ? 'Balance' : 'Baki'">Balance</th><th>YTD</th></tr></thead>
                <tbody>
                    @forelse ($balances as $b)
                        <tr><td style="text-align:left;font-weight:600;color:var(--ink);">{{ $b->leaveType?->name }}</td><td>{{ number_format($b->balance, 1) }}</td><td>{{ number_format($d['leaveYtd'][$b->leaveType?->name] ?? 0, 1) }}</td></tr>
                    @empty
                        <tr><td colspan="3" style="text-align:left;color:var(--muted);">-</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>
            <div class="slip-kicker" style="margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Remark' : 'Catatan'">Remark</div>
            <div class="slip-panel" style="min-height:44px;font-size:13px;color:var(--body);">{{ $p->notes }}</div>
        </div>
    </div>

    {{-- Pay particulars --}}
    <div class="slip-pad" style="font-size:12px;color:var(--body);line-height:1.6;">
        <strong style="color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Pay particulars' : 'Butiran gaji'">Pay particulars</strong>
        <span x-text="$store.ui.lang==='en' ? 'Monthly rate' : 'Kadar bulanan'">Monthly rate</span> <strong class="mono-dd" style="display:inline;">MYR {{ $amt($pt['monthlyRate']) }}</strong>
        @if ($pt['daysEmployed'] !== null && $pt['daysInMonth'] !== null) · <span x-text="$store.ui.lang==='en' ? 'Days employed' : 'Hari bekerja'">Days employed</span> <strong>{{ $pt['daysEmployed'] }} / {{ $pt['daysInMonth'] }}</strong>@endif
        @if ($pt['unpaidDailyRate'] !== null) · <span x-text="$store.ui.lang==='en' ? 'Unpaid leave rate' : 'Kadar cuti tanpa gaji'">Unpaid leave rate</span> <strong>MYR {{ $amt($pt['unpaidDailyRate']) }}</strong> / <span x-text="$store.ui.lang==='en' ? 'day (basic ÷ calendar days)' : 'hari (gaji pokok ÷ hari kalendar)'">day</span>@endif
        @if ($pt['dailyRate'] !== null) · <span x-text="$store.ui.lang==='en' ? 'Ordinary daily rate' : 'Kadar harian biasa'">Ordinary daily rate</span> <strong>MYR {{ $amt($pt['dailyRate']) }}</strong> (÷26) · <span x-text="$store.ui.lang==='en' ? 'Hourly rate' : 'Kadar sejam'">Hourly rate</span> <strong>MYR {{ $amt($pt['hourlyRate']) }}</strong> (÷8)@endif
        @foreach ($pt['overtimeGroups'] as $g) · OT @if ($g['multiplier'] !== ''){{ $g['multiplier'] }}×@endif <strong>{{ $trim($g['hours']) }} hrs</strong>@endforeach
        @foreach ($pt['leaveTaken'] as $l) · <span x-text="$store.ui.lang==='en' ? 'Leave taken' : 'Cuti diambil'">Leave taken</span>: {{ $l['type'] }} <strong>{{ $trim($l['days']) }} d</strong>@endforeach
        @if ($pt['employerEpfNo'] || $pt['employerSocsoNo'])<div style="color:var(--muted);">EPF <span x-text="$store.ui.lang==='en' ? 'Employer No.' : 'No. Majikan'">Employer No.</span> {{ $pt['employerEpfNo'] ?? '-' }} · SOCSO {{ $pt['employerSocsoNo'] ?? '-' }}</div>@endif
    </div>
</div>
@if ($run?->status !== 'finalized')
    @php $runStatusMs = $statusMs[$run?->status] ?? $run?->status; @endphp
    <p style="font-size:12px;color:var(--muted);margin-top:12px;max-width:860px;" x-text="$store.ui.lang==='en' ? @js('This payslip belongs to a '.$run?->status.' run and is not yet issued. Figures may change until the run is finalized.') : @js('Payslip ini milik run '.$runStatusMs.' dan belum dikeluarkan. Angka mungkin berubah sehingga run difinalize.')">This payslip belongs to a {{ $run?->status }} run and is not yet issued. Figures may change until the run is finalized.</p>
@endif
