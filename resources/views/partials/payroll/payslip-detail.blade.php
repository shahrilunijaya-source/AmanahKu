{{-- One payslip in full: earnings, deductions, net, employer cost. $p is the payslip; $ackable shows the Acknowledge control (My Payroll only). --}}
@php
    $statusColor = ['draft' => 'var(--amber)', 'approved' => 'var(--info)', 'finalized' => 'var(--success)'];
    $statusMs = ['draft' => 'Draf', 'approved' => 'Diluluskan', 'finalized' => 'Difinalize'];
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
    $run = $p->payrollRun;
    $me = request()->attributes->get('employee');
@endphp
<div class="uj-card" style="padding:0;overflow:hidden;max-width:760px;">
    <div style="display:flex;align-items:center;gap:14px;padding:22px 26px;border-bottom:1px solid var(--hairline);background:var(--canvas);">
        <div style="width:46px;height:46px;border-radius:50%;background:{{ $p->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:600;flex-shrink:0;">{{ $p->employee?->initials }}</div>
        <div style="flex:1;min-width:0;">
            <div style="font-size:17px;font-weight:600;color:var(--ink);">{{ $p->employee?->name }}</div>
            <div style="font-size:12.5px;color:var(--muted);">{{ $p->employee?->position }} · <span x-text="$store.ui.lang==='en' ? 'Payslip for' : 'Payslip untuk'">Payslip for</span> {{ $run?->label }}</div>
        </div>
        <span class="uj-pill" style="background:#fff;border:1px solid var(--hairline);color:{{ $statusColor[$run?->status] ?? 'var(--muted)' }};text-transform:capitalize;" x-text="$store.ui.lang==='en' ? @js(ucfirst((string) $run?->status)) : @js($statusMs[$run?->status] ?? ucfirst((string) $run?->status))">{{ $run?->status }}</span>
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

    <div style="display:flex;flex-wrap:wrap;">
        {{-- Earnings --}}
        <div style="flex:1;min-width:300px;padding:22px 26px;border-right:1px solid var(--hairline-soft);">
            <div style="font-size:11px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:var(--muted);margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'Earnings' : 'Pendapatan'">Earnings</div>
            @foreach ([
                ['Basic salary', 'Gaji pokok', $p->basic],
            ] as $line)
                <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--body);"><span x-text="$store.ui.lang==='en' ? @js($line[0]) : @js($line[1])">{{ $line[0] }}</span><span style="font-family:var(--font-mono);color:var(--ink);">{{ $money($line[2]) }}</span></div>
            @endforeach
            {{-- Fixed Transactions (earning side): itemised by Payroll Item name when
                 this payslip has them, else the lumped legacy total for payslips issued
                 before Fixed Transactions existed. --}}
            @php $fixedEarningLines = $p->lines->where('type', 'earning')->where('source', 'fixed-transaction'); @endphp
            @forelse ($fixedEarningLines as $line)
                <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--body);"><span>{{ $line->name }}</span><span style="font-family:var(--font-mono);color:var(--ink);">{{ $money($line->amount) }}</span></div>
            @empty
                @if ($p->allowances_total > 0)
                    <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--body);"><span x-text="$store.ui.lang==='en' ? 'Allowances' : 'Elaun'">Allowances</span><span style="font-family:var(--font-mono);color:var(--ink);">{{ $money($p->allowances_total) }}</span></div>
                @endif
            @endforelse
            {{-- Overtime: one line per rate multiplier (e.g. "Overtime 1.5×" and
                 "Overtime 3×" as separate lines) so a pull mixing an ordinary and a
                 public-holiday request is never flattened into one ambiguous figure —
                 the total below is just their sum. Legacy payslips predating this
                 breakdown fall back to the single lumped overtime_amount column. --}}
            @php $overtimeLines = $p->lines->where('source', 'overtime'); @endphp
            @forelse ($overtimeLines as $line)
                <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--body);"><span>{{ $line->name }}@if($line->quantity) <span style="color:var(--muted);font-weight:400;"> ({{ rtrim(rtrim(number_format($line->quantity, 2), '0'), '.') }}h)</span>@endif</span><span style="font-family:var(--font-mono);color:var(--ink);">{{ $money($line->amount) }}</span></div>
            @empty
                @if ($p->overtime_amount > 0)
                    <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--body);"><span x-text="$store.ui.lang==='en' ? 'Overtime' : 'Kerja lebih masa'">Overtime</span><span style="font-family:var(--font-mono);color:var(--ink);">{{ $money($p->overtime_amount) }}</span></div>
                @endif
            @endforelse
            <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--body);"><span x-text="$store.ui.lang==='en' ? 'Bonus / one-off' : 'Bonus / sekali'">Bonus / one-off</span><span style="font-family:var(--font-mono);color:var(--ink);">{{ $money($p->bonus) }}</span></div>
            {{-- Where the overtime figure came from: pulled from approved OvertimeRequests
                 (count + hours, per rate — see the lines above), or typed by HR. --}}
            @if ($p->overtime_amount > 0 || ($p->overtime_request_ids ?? null))
                @php $otCount = count($p->overtime_request_ids ?? []); @endphp
                <div style="font-size:11px;color:var(--muted);padding:0 0 4px;">
                    @if ($p->overtime_overridden)
                        <span x-text="$store.ui.lang==='en' ? 'Entered by hand — overrides the pulled figure' : 'Dimasukkan secara manual — menindih angka yang ditarik'">Entered by hand — overrides the pulled figure</span>
                        @if ($otCount > 0)
                            <span x-text="$store.ui.lang==='en' ? @js(' ('.$otCount.' approved OT request(s), '.number_format($p->pulled_overtime_hours, 2).' hrs pulled but not used)') : @js(' ('.$otCount.' permintaan OT diluluskan, '.number_format($p->pulled_overtime_hours, 2).' jam ditarik tetapi tidak digunakan)')"></span>
                        @endif
                    @elseif ($otCount > 0)
                        <span x-text="$store.ui.lang==='en' ? @js($otCount.' approved OT request(s) · '.number_format($p->pulled_overtime_hours, 2).' hours pulled automatically') : @js($otCount.' permintaan OT diluluskan · '.number_format($p->pulled_overtime_hours, 2).' jam ditarik automatik')"></span>
                    @endif
                </div>
            @endif
            {{-- Individual Transactions (earning side) and, for a payslip predating this
                 feature, the legacy free-form additions JSON. --}}
            @php $individualEarningLines = $p->lines->where('type', 'earning')->where('source', 'individual'); @endphp
            @forelse ($individualEarningLines as $line)
                <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--body);"><span>{{ $line->name }}@if($line->remark) <span style="color:var(--muted);font-weight:400;">— {{ $line->remark }}</span>@endif</span><span style="font-family:var(--font-mono);color:var(--ink);">{{ $money($line->amount) }}</span></div>
            @empty
                @foreach (($p->additions ?? []) as $add)
                    <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--body);"><span>{{ $add['name'] }}</span><span style="font-family:var(--font-mono);color:var(--ink);">{{ $money($add['amount']) }}</span></div>
                @endforeach
            @endforelse
            @if ($p->unpaid_deduction > 0)
                @php $unpaidDays = rtrim(rtrim(number_format($p->unpaid_days, 2), '0'), '.'); @endphp
                <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--error);"><span x-text="$store.ui.lang==='en' ? @js('Unpaid leave ('.$unpaidDays.' days)') : @js('Cuti tanpa gaji ('.$unpaidDays.' hari)')">Unpaid leave ({{ $unpaidDays }} days)</span><span style="font-family:var(--font-mono);">−{{ $money($p->unpaid_deduction) }}</span></div>
            @endif
            @if ($p->unpaid_deduction > 0 || ($p->unpaid_leave_request_ids ?? null))
                @php $leaveCount = count($p->unpaid_leave_request_ids ?? []); @endphp
                <div style="font-size:11px;color:var(--muted);padding:0 0 4px;">
                    @if ($p->unpaid_days_overridden)
                        <span x-text="$store.ui.lang==='en' ? 'Entered by hand — overrides the pulled figure' : 'Dimasukkan secara manual — menindih angka yang ditarik'">Entered by hand — overrides the pulled figure</span>
                    @elseif ($leaveCount > 0)
                        <span x-text="$store.ui.lang==='en' ? @js($leaveCount.' approved unpaid-leave request(s) pulled automatically') : @js($leaveCount.' permintaan cuti tanpa gaji diluluskan ditarik automatik')"></span>
                    @endif
                </div>
            @endif
            <div style="display:flex;justify-content:space-between;font-size:14px;font-weight:700;padding:12px 0 0;margin-top:8px;border-top:1px solid var(--hairline);color:var(--ink);"><span x-text="$store.ui.lang==='en' ? 'Gross' : 'Kasar'">Gross</span><span style="font-family:var(--font-mono);">{{ $money($p->gross) }}</span></div>
        </div>

        {{-- Deductions --}}
        <div style="flex:1;min-width:300px;padding:22px 26px;">
            <div style="font-size:11px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:var(--muted);margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'Deductions' : 'Potongan'">Deductions</div>
            @foreach (array_filter([
                ['EPF (employee)', 'EPF (pekerja)', $p->epf_employee],
                ['SOCSO (employee)', 'SOCSO (pekerja)', $p->socso_employee],
                ['EIS (employee)', 'EIS (pekerja)', $p->eis_employee],
                $p->skbbk_employee > 0 ? ['SKBBK (Lindung 24 Jam)', 'SKBBK (Lindung 24 Jam)', $p->skbbk_employee] : null,
                ['PCB / income tax', 'PCB / cukai pendapatan', $p->pcb],
                $p->pcb_additional > 0 ? ['PCB — bonus / additional', 'PCB — bonus / tambahan', $p->pcb_additional] : null,
                $p->zakat > 0 ? ['Zakat', 'Zakat', $p->zakat] : null,
                $p->cp38 > 0 ? ['CP38 instalment', 'Ansuran CP38', $p->cp38] : null,
            ]) as $line)
                <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--body);"><span x-text="$store.ui.lang==='en' ? @js($line[0]) : @js($line[1])">{{ $line[0] }}</span><span style="font-family:var(--font-mono);color:var(--error);">−{{ $money($line[2]) }}</span></div>
            @endforeach
            @if ($p->pcb_override !== null)
                <div style="font-size:11px;color:var(--muted);padding:2px 0 0;"><span x-text="$store.ui.lang==='en' ? 'PCB figure was overridden by HR, not computed' : 'Angka PCB ditindih oleh HR, bukan dikira'">PCB figure was overridden by HR, not computed</span></div>
            @endif
            {{-- Fixed Transactions (deduction side), itemised by Payroll Item name. --}}
            @foreach ($p->lines->where('type', 'deduction')->where('source', 'fixed-transaction') as $line)
                <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--body);"><span>{{ $line->name }}</span><span style="font-family:var(--font-mono);color:var(--error);">−{{ $money($line->amount) }}</span></div>
            @endforeach
            @php $deductionLines = $p->lines->where('type', 'deduction')->where('source', 'manual'); @endphp
            @forelse ($deductionLines as $line)
                <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--body);"><span>{{ $line->name }}</span><span style="font-family:var(--font-mono);color:var(--error);">−{{ $money($line->amount) }}</span></div>
            @empty
                @foreach (($p->other_deductions ?? []) as $ded)
                    <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--body);"><span>{{ $ded['name'] }}</span><span style="font-family:var(--font-mono);color:var(--error);">−{{ $money($ded['amount']) }}</span></div>
                @endforeach
            @endforelse
            {{-- Individual Transactions (deduction side). --}}
            @foreach ($p->lines->where('type', 'deduction')->where('source', 'individual') as $line)
                <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--body);"><span>{{ $line->name }}@if($line->remark) <span style="color:var(--muted);font-weight:400;">— {{ $line->remark }}</span>@endif</span><span style="font-family:var(--font-mono);color:var(--error);">−{{ $money($line->amount) }}</span></div>
            @endforeach
            <div style="display:flex;justify-content:space-between;font-size:14px;font-weight:700;padding:12px 0 0;margin-top:8px;border-top:1px solid var(--hairline);color:var(--ink);"><span x-text="$store.ui.lang==='en' ? 'Total deductions' : 'Jumlah potongan'">Total deductions</span><span style="font-family:var(--font-mono);color:var(--error);">−{{ $money($p->total_deductions) }}</span></div>
            @if ($p->claims_reimbursement > 0)
                <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:10px 0 0;color:var(--success);"><span x-text="$store.ui.lang==='en' ? 'Claims reimbursement' : 'Bayaran balik tuntutan'">Claims reimbursement</span><span style="font-family:var(--font-mono);">+{{ $money($p->claims_reimbursement) }}</span></div>
            @endif
        </div>
    </div>

    {{-- Net + employer cost --}}
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:20px 26px;background:var(--ink);color:#fff;">
        <div>
            <div style="font-size:11.5px;opacity:0.7;letter-spacing:0.4px;text-transform:uppercase;" x-text="$store.ui.lang==='en' ? 'Net pay' : 'Gaji bersih'">Net pay</div>
            <div style="font-size:26px;font-weight:700;font-family:var(--font-mono);">{{ $money($p->net_pay) }}</div>
        </div>
        <div style="text-align:right;font-size:12px;opacity:0.8;">
            <div><span x-text="$store.ui.lang==='en' ? 'Employer EPF' : 'EPF majikan'">Employer EPF</span> {{ $money($p->epf_employer) }} · SOCSO {{ $money($p->socso_employer) }} · EIS {{ $money($p->eis_employer) }}</div>
            <div style="margin-top:3px;"><span x-text="$store.ui.lang==='en' ? 'Total employer cost' : 'Jumlah kos majikan'">Total employer cost</span> <strong style="font-family:var(--font-mono);">{{ $money($p->employer_cost) }}</strong></div>
        </div>
    </div>
</div>
@if ($run?->status !== 'finalized')
    @php $runStatusMs = $statusMs[$run?->status] ?? $run?->status; @endphp
    <p style="font-size:12px;color:var(--muted);margin-top:12px;max-width:760px;" x-text="$store.ui.lang==='en' ? @js('This payslip belongs to a '.$run?->status.' run and is not yet issued. Figures may change until the run is finalized.') : @js('Payslip ini milik run '.$runStatusMs.' dan belum dikeluarkan. Angka mungkin berubah sehingga run difinalize.')">This payslip belongs to a {{ $run?->status }} run and is not yet issued. Figures may change until the run is finalized.</p>
@endif
