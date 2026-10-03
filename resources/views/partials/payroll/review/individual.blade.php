@php
    $statusColor = ['draft' => 'var(--amber)', 'approved' => 'var(--info)', 'finalized' => 'var(--success)'];
    $statusMs = ['draft' => 'Draf', 'approved' => 'Diluluskan', 'finalized' => 'Difinalize'];
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
    $latest = $activeRun?->totals ?? [];
    $payslipRows = $activeRun ? $activeRun->payslips->sortBy('employee.name')->values() : collect();
    $openSlip = (int) request('payslip', $payslipRows->first()?->id ?? 0);
@endphp
<form method="get" action="{{ route('app.screen', 'payroll-review') }}" style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
    <input type="hidden" name="tab" value="individual">
    <label style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Run' : 'Run'">Run</label>
    <select name="run" onchange="this.form.submit()" style="height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;">
        @foreach ($runs as $r)
            <option value="{{ $r->id }}" @selected($activeRun?->id === $r->id)>{{ $r->label }} · {{ $r->status }}</option>
        @endforeach
    </select>
</form>

@if (!$activeRun)
    <div class="uj-card" style="padding:22px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'No payroll run yet. Create one under Process.' : 'Belum ada run gaji. Buat satu di bawah Proses.'">No payroll run yet. Create one under Process.</div>
@else
<div x-data="{
        q: '',
        editing: null,
        pick: {{ $openSlip }},
        rows: @js($payslipRows->map(fn ($p) => mb_strtolower(trim($p->employee?->display_name.' '.$p->employee?->name.' '.$p->employee?->position)))->values()),
        hit(h) { return this.q.trim() === '' || h.includes(this.q.trim().toLowerCase()); },
     }" style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- Staff picker --}}
    <div class="uj-card" style="flex:1;min-width:240px;max-width:300px;padding:0;">
        <div style="padding:12px;border-bottom:1px solid var(--hairline);">
            <input type="search" x-model="q" @keydown.escape="q = ''" :placeholder="$store.ui.lang==='en' ? 'Search name or nickname' : 'Cari nama atau gelaran'" style="width:100%;height:32px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;">
        </div>
        <div style="max-height:560px;overflow:auto;">
            @foreach ($payslipRows as $p)
                <div x-show="hit(rows[{{ $loop->index }}])"><button type="button" @click="pick = {{ $p->id }}" :style="{ background: pick === {{ $p->id }} ? 'var(--canvas)' : 'none' }" style="display:flex;width:100%;text-align:left;align-items:center;gap:10px;padding:10px 14px;border:0;border-bottom:1px solid var(--hairline-soft);background:none;cursor:pointer;">
                    <div style="min-width:0;flex:1;"><div style="font-size:12.5px;color:var(--ink);font-weight:500;">{{ $p->employee?->name }}</div><div style="font-size:11px;color:var(--muted);">{{ $p->employee?->position }}</div></div>
                    <span style="font-size:12px;font-family:var(--font-mono);color:var(--ink);">{{ $money($p->net_pay) }}</span>
                </button></div>
            @endforeach
        </div>
    </div>

    {{-- Chosen payslip --}}
    <div class="uj-card" style="flex:2;min-width:min(420px,100%);padding:0;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:18px 22px;border-bottom:1px solid var(--hairline);">
                    <div>
                        <h3 class="uj-card-title">{{ $activeRun->label }}</h3>
                        <div style="font-size:12px;color:var(--muted);margin-top:2px;"><span x-text="$store.ui.lang==='en' ? 'Gross' : 'Kasar'">Gross</span> {{ $money($latest['gross'] ?? 0) }} · <span x-text="$store.ui.lang==='en' ? 'Deductions' : 'Potongan'">Deductions</span> {{ $money($latest['deductions'] ?? 0) }} · <span x-text="$store.ui.lang==='en' ? 'Net' : 'Bersih'">Net</span> {{ $money($latest['net'] ?? 0) }}</div>
                        @if ($activeRun->status === 'finalized')
                            @php $ps = $activeRun->payslips; @endphp
                            <div style="font-size:11.5px;color:var(--muted);margin-top:3px;"><span x-text="$store.ui.lang==='en' ? 'Employer' : 'Majikan'">Employer</span> — EPF {{ $money($ps->sum('epf_employer')) }} · SOCSO {{ $money($ps->sum('socso_employer')) }} · EIS {{ $money($ps->sum('eis_employer')) }} · <span x-text="$store.ui.lang==='en' ? 'PCB collected' : 'PCB dikutip'">PCB collected</span> {{ $money($ps->sum('pcb') + $ps->sum('pcb_additional')) }}</div>
                        @endif
                    </div>
            </div>

                @if ($activeRun->status !== 'finalized')
                    <div style="padding:10px 22px;background:#fff7ed;border-bottom:1px solid var(--hairline-soft);font-size:11.5px;color:#9a5b14;" x-text="$store.ui.lang==='en' ? 'Draft figures. PCB (income tax) is computed automatically and can be overridden per employee if needed. Verify statutory amounts before finalizing.' : 'Angka draf. PCB (cukai pendapatan) dikira automatik dan boleh ditindih bagi setiap pekerja jika perlu. Sahkan jumlah berkanun sebelum finalize.'">Draft figures. PCB (income tax) is computed automatically and can be overridden per employee if needed. Verify statutory amounts before finalizing.</div>
                @endif
        @php
            $payslipRows->load('employee.salaryStructure');
            $itemsById = \App\Models\PayrollItem::get()->keyBy('id');
            $t = fn (string $en, string $ms) => new \Illuminate\Support\HtmlString('<span x-text="$store.ui.lang===\'en\' ? '.e(json_encode($en)).' : '.e(json_encode($ms)).'">'.e($en).'</span>');
            $ytdService = app(\App\Services\Payroll\PayslipYearToDate::class);
        @endphp
        @foreach ($payslipRows as $p)
            @php
                $p->setRelation('payrollRun', $activeRun);
                $editable = $activeRun->status !== 'finalized' && ! $activeRun->isBonus() && ! $activeRun->isMidMonth();
                $ov = fn (string $col) => $p->{$col} !== null ? number_format((float) $p->{$col}, 2, '.', '') : '';
                $ytd = $ytdService->forPayslip($p);
                $fmt = fn ($v) => number_format((float) $v, 2);
                $d = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.') ?: '0';
                // Wage bases: what the calculator summed over each earning line's own EPF / PERKESO flag, less unpaid leave.
                $epfBase = $socsoBase = 0.0;
                if ($p->lines->isEmpty()) {
                    $epfBase = max(0.0, $p->gross - $p->overtime_amount);
                    $socsoBase = max(0.0, $p->gross - $p->bonus);
                } else {
                    foreach ($p->lines->where('type', 'earning')->where('source', '!=', 'claim') as $ln) {
                        $item = $ln->payroll_item_id ? $itemsById->get($ln->payroll_item_id) : null;
                        $amt = $ln->source === 'salary' ? (float) $p->basic : (float) $ln->amount;
                        $epfBase += ($item?->epf_liable ?? true) ? $amt : 0;
                        $socsoBase += ($item?->perkeso_liable ?? true) ? $amt : 0;
                    }
                    $epfBase = max(0.0, $epfBase - $p->unpaid_deduction);
                    $socsoBase = max(0.0, $socsoBase - $p->unpaid_deduction);
                }
                $fixedEarningLines = $p->lines->where('type', 'earning')->where('source', 'fixed-transaction');
                $overtimeLines = $p->lines->where('source', 'overtime');
                $individualEarningLines = $p->lines->where('type', 'earning')->where('source', 'individual');
                $individualDeductionLines = $p->lines->where('type', 'deduction')->where('source', 'individual');
                $fixedDeductionLines = $p->lines->where('type', 'deduction')->where('source', 'fixed-transaction');
                $manualDeductionLines = $p->lines->where('type', 'deduction')->where('source', 'manual');
                $ss = $p->employee?->salaryStructure;
                // Client-side preview only: the server recompute on save is the truth.
                $state = [
                    'f' => [
                        'basic' => $p->basic_overridden ? $ov('basic') : '',
                        'claims' => $ov('claims_reimbursement_override'),
                        'unpaid' => $ov('unpaid_deduction_override'),
                        'epfE' => $ov('epf_employee_override'), 'socsoE' => $ov('socso_employee_override'), 'eisE' => $ov('eis_employee_override'),
                        'pcb' => $ov('pcb_override'),
                        'epfR' => $ov('epf_employer_override'), 'socsoR' => $ov('socso_employer_override'), 'eisR' => $ov('eis_employer_override'),
                    ],
                    'base' => [
                        'basic' => (float) $p->basic, 'claims' => (float) $p->claims_reimbursement, 'unpaid' => (float) $p->unpaid_deduction,
                        'epfE' => (float) $p->epf_employee, 'socsoE' => (float) $p->socso_employee, 'eisE' => (float) $p->eis_employee,
                        'pcb' => (float) $p->pcb,
                    ],
                    'otherEarn' => round($p->gross + $p->unpaid_deduction - $p->basic, 2),
                    'otherDed' => round($p->total_deductions - $p->epf_employee - $p->socso_employee - $p->eis_employee - $p->pcb, 2),
                ];
            @endphp
            <div x-show="pick === {{ $p->id }}" x-cloak
                 x-data="{
                    ...@js($state),
                    num(k) { const v = parseFloat(this.f[k]); return isNaN(v) ? this.base[k] : Math.max(0, v); },
                    get totalEarn() { return this.num('basic') + this.num('claims') + this.otherEarn; },
                    get totalDed() { return this.num('unpaid') + this.num('epfE') + this.num('socsoE') + this.num('eisE') + this.num('pcb') + this.otherDed; },
                    get net() { return this.totalEarn - this.totalDed; },
                    fmt(v) { return v.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                 }">
                {{-- Employee header --}}
                <div style="display:flex;align-items:center;gap:12px;padding:14px 22px;border-bottom:1px solid var(--hairline-soft);flex-wrap:wrap;">
                    <div style="width:38px;height:38px;border-radius:50%;background:{{ $p->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;flex-shrink:0;">{{ $p->employee?->initials }}</div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:14.5px;color:var(--ink);font-weight:600;">{{ $p->employee?->name }}</div>
                        <div style="font-size:12px;color:var(--muted);">{{ $p->employee?->position }}@if ($p->employee?->staff_id) · {{ $p->employee->staff_id }}@endif</div>
                    </div>
                    @if ($activeRun->status === 'finalized')
                        <a href="{{ route('payroll.payslips.pdf', $p) }}" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Download PDF' : 'Muat turun PDF'">Download PDF</a>
                    @endif
                </div>

                                {{-- Spec F4 flags: s.24 deduction cap, negative net and the 104-hour overtime limit. --}}
                                @if ($p->deduction_cap_exceeded || $p->net_pay < 0 || $p->carried_forward_amount > 0 || $p->pulled_overtime_hours > \App\Services\Payroll\PayrollCalculator::OVERTIME_HOURS_CAP)
                                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:0 22px 12px 64px;">
                                        @if ($p->deduction_cap_exceeded)
                                            <form method="post" action="{{ route('payroll.payslips.consent', $p) }}" style="display:flex;align-items:center;gap:6px;">@csrf
                                                <span class="uj-pill" style="background:{{ $p->deduction_consent_confirmed ? 'var(--red-tint)' : '#fff7e6' }};color:{{ $p->deduction_consent_confirmed ? 'var(--success)' : 'var(--amber)' }};font-size:10.5px;" x-text="$store.ui.lang==='en' ? 'Deductions over 50% (s.24)' : 'Potongan melebihi 50% (s.24)'">Deductions over 50% (s.24)</span>
                                                @if ($activeRun->status !== 'finalized')
                                                    @if ($p->deduction_consent_confirmed)
                                                        <button type="submit" class="uj-btn-ghost" style="height:26px;padding:0 8px;font-size:11px;" x-text="$store.ui.lang==='en' ? 'Consent recorded · withdraw' : 'Kebenaran direkod · tarik balik'">Consent recorded · withdraw</button>
                                                    @else
                                                        <button type="submit" class="uj-btn-ghost" style="height:26px;padding:0 8px;font-size:11px;" x-text="$store.ui.lang==='en' ? 'Employee consented in writing' : 'Pekerja beri kebenaran bertulis'">Employee consented in writing</button>
                                                    @endif
                                                @endif
                                            </form>
                                        @endif
                                        @if ($p->net_pay < 0)
                                            <form method="post" action="{{ route('payroll.payslips.carry-forward', $p) }}" style="display:flex;align-items:center;gap:6px;">@csrf
                                                <span class="uj-pill" style="background:var(--red-tint);color:var(--error);font-size:10.5px;" x-text="$store.ui.lang==='en' ? 'Net pay negative' : 'Gaji bersih negatif'">Net pay negative</span>
                                                @if ($activeRun->status !== 'finalized')
                                                    <button type="submit" class="uj-btn-ghost" style="height:26px;padding:0 8px;font-size:11px;" x-text="$store.ui.lang==='en' ? 'Carry to next month' : 'Bawa ke bulan depan'">Carry to next month</button>
                                                @endif
                                            </form>
                                        @endif
                                        @if ($p->carried_forward_amount > 0)
                                            <span class="uj-pill" style="background:#fff7e6;color:var(--amber);font-size:10.5px;">{{ $money($p->carried_forward_amount) }} <span x-text="$store.ui.lang==='en' ? 'carried to next month' : 'dibawa ke bulan depan'">carried to next month</span></span>
                                        @endif
                                        @if ($p->pulled_overtime_hours > \App\Services\Payroll\PayrollCalculator::OVERTIME_HOURS_CAP)
                                            <span class="uj-pill" style="background:#fff7e6;color:var(--amber);font-size:10.5px;" x-text="$store.ui.lang==='en' ? 'Overtime above 104h' : 'Kerja lebih masa melebihi 104j'">Overtime above 104h</span>
                                        @endif
                                    </div>
                                @endif

                <form method="post" action="{{ route('payroll.payslips.update', $p) }}">
                    @csrf
                    {{-- Earnings (left) and Deductions (right); stacks on a phone. --}}
                    <div class="grid md:grid-cols-2" style="padding:6px 22px 0;">
                        <section class="md:pr-6 md:border-r" style="border-color:var(--hairline-soft);" aria-labelledby="earn-{{ $p->id }}">
                            <h4 id="earn-{{ $p->id }}" style="font-size:15px;font-weight:600;color:var(--ink);margin:14px 0 6px;">{!! $t('Earnings', 'Pendapatan') !!}</h4>
                            <div class="payrow"><span>{!! $t('Salary', 'Gaji') !!}@if ($p->days_employed !== null && $p->days_in_month !== null && $p->days_employed < $p->days_in_month) <small style="color:var(--muted);">({{ $p->days_employed }}/{{ $p->days_in_month }} D)</small>@endif</span><span class="cur">MYR</span>
                                @include('partials.payroll.review.money-input', ['key' => 'basic', 'name' => 'basic', 'effective' => $p->basic, 'editable' => $editable, 'resettable' => false, 'en' => 'Salary', 'ms' => 'Gaji'])</div>
                            @forelse ($fixedEarningLines as $line)
                                <div class="payrow"><span>{{ $line->name }}</span><span class="cur">MYR</span><span class="amt">{{ $fmt($line->amount) }}</span></div>
                            @empty
                                @if ($p->allowances_total > 0)
                                    <div class="payrow"><span>{!! $t('Allowances', 'Elaun') !!}</span><span class="cur">MYR</span><span class="amt">{{ $fmt($p->allowances_total) }}</span></div>
                                @endif
                            @endforelse
                            @forelse ($overtimeLines as $line)
                                <div class="payrow"><span>{{ $line->name }}@if ($line->quantity) <small style="color:var(--muted);">({{ $d($line->quantity) }}h)</small>@endif</span><span class="cur">MYR</span><span class="amt">{{ $fmt($line->amount) }}</span></div>
                            @empty
                                @if ($p->overtime_amount > 0)
                                    <div class="payrow"><span>{!! $t('Overtime', 'Kerja lebih masa') !!}</span><span class="cur">MYR</span><span class="amt">{{ $fmt($p->overtime_amount) }}</span></div>
                                @endif
                            @endforelse
                            @if ($p->bonus > 0)
                                <div class="payrow"><span>{!! $t('Bonus / one-off', 'Bonus / sekali') !!}</span><span class="cur">MYR</span><span class="amt">{{ $fmt($p->bonus) }}</span></div>
                            @endif
                            @forelse ($individualEarningLines as $line)
                                <div class="payrow"><span>{{ $line->name }}@if ($line->remark) <small style="color:var(--muted);">— {{ $line->remark }}</small>@endif</span><span class="cur">MYR</span><span class="amt">{{ $fmt($line->amount) }}</span></div>
                            @empty
                                @foreach (($p->additions ?? []) as $add)
                                    <div class="payrow"><span>{{ $add['name'] }}</span><span class="cur">MYR</span><span class="amt">{{ $fmt($add['amount']) }}</span></div>
                                @endforeach
                            @endforelse
                            @if ($p->claims_reimbursement > 0 || $p->claims_reimbursement_override !== null || ($p->claim_ids ?? null))
                                <div class="payrow"><span>{!! $t('Claims (from Claim)', 'Tuntutan (daripada Tuntutan)') !!}</span><span class="cur">MYR</span>
                                    @include('partials.payroll.review.money-input', ['key' => 'claims', 'name' => 'claims_reimbursement_override', 'effective' => $p->claims_reimbursement, 'editable' => $editable, 'en' => 'Claims reimbursement', 'ms' => 'Bayaran balik tuntutan'])</div>
                            @endif
                        </section>

                        <section class="md:pl-6" aria-labelledby="ded-{{ $p->id }}">
                            <h4 id="ded-{{ $p->id }}" style="font-size:15px;font-weight:600;color:var(--ink);margin:14px 0 6px;">{!! $t('Deductions', 'Potongan') !!}</h4>
                            <div class="payrow"><span>{!! $t('Unpaid Leave', 'Cuti Tanpa Gaji') !!} <small style="color:var(--muted);">({{ $d($p->unpaid_days) }} D)</small></span><span class="cur">MYR</span>
                                @include('partials.payroll.review.money-input', ['key' => 'unpaid', 'name' => 'unpaid_deduction_override', 'effective' => $p->unpaid_deduction, 'editable' => $editable, 'en' => 'Unpaid leave amount', 'ms' => 'Jumlah cuti tanpa gaji'])</div>
                            <div class="payrow"><span>{!! $t('EPF Employee Contribution', 'Caruman EPF Pekerja') !!}</span><span class="cur">MYR</span>
                                @include('partials.payroll.review.money-input', ['key' => 'epfE', 'name' => 'epf_employee_override', 'effective' => $p->epf_employee, 'editable' => $editable, 'en' => 'EPF employee contribution', 'ms' => 'Caruman EPF pekerja'])</div>
                            <div class="payrow"><span>{!! $t('SOCSO Employee Contribution', 'Caruman PERKESO Pekerja') !!}</span><span class="cur">MYR</span>
                                @include('partials.payroll.review.money-input', ['key' => 'socsoE', 'name' => 'socso_employee_override', 'effective' => $p->socso_employee, 'editable' => $editable, 'en' => 'SOCSO employee contribution', 'ms' => 'Caruman PERKESO pekerja'])</div>
                            <div class="payrow"><span>{!! $t('EIS Employee Contribution', 'Caruman SIP Pekerja') !!}</span><span class="cur">MYR</span>
                                @include('partials.payroll.review.money-input', ['key' => 'eisE', 'name' => 'eis_employee_override', 'effective' => $p->eis_employee, 'editable' => $editable, 'en' => 'EIS employee contribution', 'ms' => 'Caruman SIP pekerja'])</div>
                            @if ($p->skbbk_employee > 0)
                                <div class="payrow"><span>SKBBK</span><span class="cur">MYR</span><span class="amt">{{ $fmt($p->skbbk_employee) }}</span></div>
                            @endif
                            <div class="payrow"><span>PCB</span><span class="cur">MYR</span>
                                @include('partials.payroll.review.money-input', ['key' => 'pcb', 'name' => 'pcb_override', 'effective' => $p->pcb, 'editable' => $editable, 'en' => 'PCB (income tax)', 'ms' => 'PCB (cukai pendapatan)'])</div>
                            @if ($p->pcb_additional > 0)
                                <div class="payrow"><span>{!! $t('PCB (bonus / additional)', 'PCB (bonus / tambahan)') !!}</span><span class="cur">MYR</span><span class="amt">{{ $fmt($p->pcb_additional) }}</span></div>
                            @endif
                            @if ($p->zakat > 0)
                                <div class="payrow"><span>Zakat</span><span class="cur">MYR</span><span class="amt">{{ $fmt($p->zakat) }}</span></div>
                            @endif
                            @if ($p->cp38 > 0)
                                <div class="payrow"><span>{!! $t('CP38 instalment', 'Ansuran CP38') !!}</span><span class="cur">MYR</span><span class="amt">{{ $fmt($p->cp38) }}</span></div>
                            @endif
                            @foreach ($fixedDeductionLines->concat($manualDeductionLines)->concat($individualDeductionLines) as $line)
                                <div class="payrow"><span>{{ $line->name }}@if ($line->remark) <small style="color:var(--muted);">— {{ $line->remark }}</small>@endif</span><span class="cur">MYR</span><span class="amt">{{ $fmt($line->amount) }}</span></div>
                            @endforeach
                            @if ($p->lines->where('type', 'deduction')->whereIn('source', ['fixed-transaction', 'manual', 'individual'])->isEmpty())
                                @foreach (($p->other_deductions ?? []) as $ded)
                                    <div class="payrow"><span>{{ $ded['name'] }}</span><span class="cur">MYR</span><span class="amt">{{ $fmt($ded['amount']) }}</span></div>
                                @endforeach
                            @endif
                            @if ($p->mid_month_advance > 0)
                                <div class="payrow"><span>{!! $t('Mid-month advance', 'Pendahuluan pertengahan bulan') !!}</span><span class="cur">MYR</span><span class="amt">{{ $fmt($p->mid_month_advance) }}</span></div>
                            @endif
                        </section>
                    </div>

                    {{-- Totals and the net pay bar, recomputed live while typing. --}}
                    <div class="grid md:grid-cols-2" style="padding:0 22px;">
                        <div class="payrow md:pr-6" style="border-top:1px solid var(--hairline);font-weight:600;color:var(--ink);"><span>{!! $t('Total Earnings', 'Jumlah Pendapatan') !!}</span><span class="cur">MYR</span><span class="amt" x-text="fmt(totalEarn)">{{ $fmt($p->gross + $p->unpaid_deduction + $p->claims_reimbursement) }}</span></div>
                        <div class="payrow md:pl-6" style="border-top:1px solid var(--hairline);font-weight:600;color:var(--ink);"><span>{!! $t('Total Deductions', 'Jumlah Potongan') !!}</span><span class="cur">MYR</span><span class="amt" x-text="fmt(totalDed)">{{ $fmt($p->total_deductions + $p->unpaid_deduction) }}</span></div>
                    </div>
                    <div style="margin:12px 22px 0;padding:14px 18px;border-radius:10px;background:var(--ink);color:#fff;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;" role="status" aria-live="polite">
                        <span style="font-size:13px;letter-spacing:0.4px;text-transform:uppercase;opacity:0.8;">{!! $t('Net Pay', 'Gaji Bersih') !!}</span>
                        <span style="font-size:24px;font-weight:700;font-family:var(--font-mono);" :style="{ color: net < 0 ? '#ff9ab0' : '#fff' }">MYR <span x-text="fmt(net)">{{ $fmt($p->net_pay) }}</span></span>
                    </div>
                    @if ($editable)
                        <div style="padding:6px 22px 0;font-size:11px;color:var(--muted);">{!! $t('Live preview. Save to recalculate EPF, SOCSO, EIS and PCB from the new figures. A typed figure stays until you clear it.', 'Pratonton langsung. Simpan untuk kira semula EPF, PERKESO, SIP dan PCB daripada angka baharu. Angka yang ditaip kekal sehingga anda kosongkannya.') !!}</div>
                    @endif

                    {{-- Payroll info and statutory info --}}
                    <div class="grid gap-4" style="padding:16px 22px 0;">
                        <section aria-labelledby="pinfo-{{ $p->id }}" style="border:1px solid var(--hairline);border-radius:10px;overflow:hidden;">
                            <h4 id="pinfo-{{ $p->id }}" style="margin:0;padding:8px 14px;background:var(--canvas);font-size:12px;font-weight:700;color:var(--ink);">{!! $t('Payroll Information', 'Maklumat Gaji') !!}</h4>
                            <dl style="margin:0;padding:8px 14px;font-size:12.5px;display:grid;grid-template-columns:auto 1fr;gap:6px 14px;">
                                <dt style="color:var(--muted);">{!! $t('Payment Method', 'Kaedah Bayaran') !!}</dt><dd style="margin:0;text-align:right;color:var(--ink);">@if ($ss?->bank_name){!! $t('Bank Transfer', 'Pindahan Bank') !!} · {{ $ss->bank_name }}@if ($ss->bank_account_no) (A/C {{ $ss->bank_account_no }})@endif @else — @endif</dd>
                                <dt style="color:var(--muted);">{!! $t('EPF Base', 'Asas EPF') !!}</dt><dd class="mono-dd">MYR {{ $fmt($epfBase) }}</dd>
                                <dt style="color:var(--muted);">{!! $t('SOCSO Base', 'Asas PERKESO') !!}</dt><dd class="mono-dd">MYR {{ $fmt($socsoBase) }}</dd>
                                <dt style="color:var(--muted);">{!! $t('EIS Base', 'Asas SIP') !!}</dt><dd class="mono-dd">MYR {{ $fmt($socsoBase) }}</dd>
                                <dt style="color:var(--muted);">{!! $t('Working Day(s)', 'Hari Bekerja') !!}</dt><dd class="mono-dd">{{ $p->days_employed ?? $p->days_in_month ?? '—' }} {!! $t('Days', 'Hari') !!}</dd>
                            </dl>
                        </section>
                        <section aria-labelledby="sinfo-{{ $p->id }}" style="border:1px solid var(--hairline);border-radius:10px;overflow:hidden;">
                            <h4 id="sinfo-{{ $p->id }}" style="margin:0;padding:8px 14px;background:var(--canvas);font-size:12px;font-weight:700;color:var(--ink);">{!! $t('Statutory Information', 'Maklumat Berkanun') !!}</h4>
                            <div style="overflow-x:auto;padding:4px 10px 8px;">
                                <table style="width:100%;border-collapse:collapse;font-size:12px;min-width:500px;">
                                    <thead><tr style="color:var(--muted);text-align:right;">
                                        <th scope="col" style="text-align:left;font-weight:500;padding:6px 4px;">{!! $t('Description', 'Perihal') !!}</th>
                                        <th scope="col" style="font-weight:500;padding:6px 4px;">{!! $t('Employee', 'Pekerja') !!}</th><th scope="col" style="font-weight:500;padding:6px 4px;">YTD</th>
                                        <th scope="col" style="font-weight:500;padding:6px 4px;">{!! $t('Employer', 'Majikan') !!}</th><th scope="col" style="font-weight:500;padding:6px 4px;">YTD</th>
                                    </tr></thead>
                                    <tbody>
                                    @foreach ([
                                        ['EPF', 'epf', 'epfE', 'epfR', 'epf_employer', 'epf_employer_override'],
                                        ['SOCSO', 'socso', 'socsoE', 'socsoR', 'socso_employer', 'socso_employer_override'],
                                        ['EIS', 'eis', 'eisE', 'eisR', 'eis_employer', 'eis_employer_override'],
                                        ['PCB', 'pcb', 'pcb', null, null, null],
                                    ] as [$label, $yk, $empKey, $erKey, $erCol, $erName])
                                        <tr style="border-top:1px solid var(--hairline-soft);text-align:right;">
                                            <th scope="row" style="text-align:left;font-weight:500;color:var(--ink);padding:6px 4px;">{{ $label }}</th>
                                            <td style="padding:6px 4px;">@include('partials.payroll.review.money-input', ['key' => $empKey, 'name' => null, 'effective' => $p->{$yk === 'pcb' ? 'pcb' : $yk.'_employee'}, 'editable' => $editable, 'resettable' => false, 'en' => $label.' employee', 'ms' => $label.' pekerja'])</td>
                                            <td style="padding:6px 4px;font-family:var(--font-mono);color:var(--muted);">{{ $fmt($ytd[$yk]['employee']['ytd'] ?? 0) }}</td>
                                            @if ($erKey)
                                                <td style="padding:6px 4px;">@include('partials.payroll.review.money-input', ['key' => $erKey, 'name' => $erName, 'effective' => $p->{$erCol}, 'editable' => $editable, 'resettable' => false, 'en' => $label.' employer', 'ms' => $label.' majikan'])</td>
                                                <td style="padding:6px 4px;font-family:var(--font-mono);color:var(--muted);">{{ $fmt($ytd[$yk]['employer']['ytd'] ?? 0) }}</td>
                                            @else
                                                <td style="padding:6px 4px;color:var(--muted);">—</td><td style="padding:6px 4px;color:var(--muted);">—</td>
                                            @endif
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                                <div style="font-size:11px;color:var(--muted);padding:6px 4px 0;">* {!! $t('Employer contributions are borne by the employer and are not deducted from pay.', 'Caruman majikan ditanggung majikan dan tidak ditolak daripada gaji.') !!}</div>
                            </div>
                        </section>
                    </div>

                    @if ($editable)
                        {{-- Spec: a bonus payslip is edited through its individual transaction and a mid-month one is regenerated, so those stay read-only above. --}}
                        <details style="margin:16px 22px 0;border:1px solid var(--hairline);border-radius:10px;background:var(--canvas);">
                            <summary style="cursor:pointer;padding:10px 14px;font-size:12.5px;font-weight:600;color:var(--ink);">{!! $t('More adjustments', 'Pelarasan lain') !!} <small style="color:var(--muted);font-weight:400;">{!! $t('overtime, bonus, unpaid days, one-off transactions', 'OT, bonus, hari tanpa gaji, transaksi sekali') !!}</small></summary>
                            <div style="padding:4px 14px 14px;">
                                            @php
                                                $otPulled = rtrim(rtrim(number_format($p->pulled_overtime_hours, 2), '0'), '.') ?: '0';
                                                $unpaidPulled = rtrim(rtrim(number_format($p->pulled_unpaid_days, 2), '0'), '.') ?: '0';
                                            @endphp
<div class="grid sm:grid-cols-2 lg:grid-cols-4" style="gap:12px;margin-bottom:4px;">
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Overtime hours (override)' : 'Jam OT (tindihan)'">Overtime hours (override)</label><input name="overtime_hours" type="number" step="0.5" min="0" value="{{ $p->overtime_overridden ? rtrim(rtrim(number_format($p->overtime_hours, 2), '0'), '.') : '' }}" placeholder="{{ $otPulled }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                                {{-- Same unit as the pulled figure's per-rate lines above — hours here always need a
                                                     multiplier alongside them, never a bare number that could be mistaken for one
                                                     unit or the other. Offered as the three Employment Act minimums (1.5x normal
                                                     day, 2x rest day, 3x public holiday) via the datalist, but not restricted to
                                                     them — the day type isn't known here, and a company may pay above the minimum. --}}
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Multiplier (×)' : 'Gandaan (×)'">Multiplier (×)</label><input name="overtime_multiplier" type="number" step="0.1" min="1" list="ot-mult-{{ $p->id }}" value="{{ $p->overtime_overridden && $p->overtime_multiplier !== null ? rtrim(rtrim(number_format($p->overtime_multiplier, 2), '0'), '.') : '' }}" placeholder="1.5" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /><datalist id="ot-mult-{{ $p->id }}"><option value="1.5"></option><option value="2.0"></option><option value="3.0"></option></datalist></div>
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">Bonus (RM)</label><input name="bonus" type="number" step="0.01" min="0" value="{{ $p->bonus > 0 ? number_format($p->bonus, 2, '.', '') : '' }}" placeholder="0.00" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Unpaid days override' : 'Tindihan hari tanpa gaji'">Unpaid days override</label><input name="unpaid_days" type="number" step="0.5" min="0" max="31" value="{{ $p->unpaid_days_overridden ? rtrim(rtrim(number_format($p->unpaid_days, 2), '0'), '.') : '' }}" placeholder="{{ $unpaidPulled }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
</div>
                                            @include('partials.hint', ['en' => 'Basic pay is split by calendar days for anyone who joined or left mid-month (Employment Act s.18A) — leave the basic override blank to keep that figure. Overtime and unpaid days are pulled automatically from approved OvertimeRequests/unpaid LeaveRequests for this month (shown as the placeholder) — leave the override blank to use the pulled figure. Overtime is entered as hours plus the rate beside it (1.5× if left blank) — the same units the pulled lines show, so the two can never be confused. PCB (income tax) is computed automatically from the LHDN method — leave that override blank too, to use it. Any override sticks until cleared.', 'ms' => 'Gaji pokok dibahagi ikut hari kalendar untuk sesiapa yang masuk atau berhenti pertengahan bulan (Akta Kerja s.18A) — biarkan tindihan gaji pokok kosong untuk kekalkan angka itu. Overtime dan hari tanpa gaji ditarik automatik daripada OvertimeRequest/LeaveRequest tanpa gaji yang diluluskan bagi bulan ini (ditunjukkan sebagai placeholder) — biarkan tindihan kosong untuk guna angka yang ditarik. Overtime dimasukkan sebagai jam campur kadar di sebelahnya (1.5× jika kosong) — unit yang sama seperti baris yang ditarik, jadi kedua-duanya tidak boleh dikelirukan. PCB (cukai pendapatan) dikira automatik mengikut kaedah LHDN — biarkan tindihan itu kosong juga untuk guna nilai itu. Sebarang tindihan kekal sehingga dikosongkan.'])
                                            {{-- Pre-filled from the live individual_transactions table for this employee+period
                                                 (not this payslip's own last-generated lines) — so a one-off added or removed via
                                                 the standalone "Individual transactions" tab is never silently reverted by
                                                 submitting this form for an unrelated reason (e.g. changing the bonus). See
                                                 PayrollController::syncIndividualTransactions. --}}
                                            @php $individualTxLines = ($individualTransactionsForActiveRun->get($p->employee_id) ?? collect())->values(); @endphp
                                            <div style="margin-top:10px;">
                                                <div style="font-size:11.5px;font-weight:600;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Individual transactions (one-off)' : 'Transaksi individu (sekali sahaja)'">Individual transactions (one-off)</div>
                                                {{-- tx_known_ids: every row id this form was rendered with — the save only ever
                                                     updates/deletes an id in this list; a row created elsewhere after the page
                                                     loaded is never touched (see PayrollController::syncIndividualTransactions).
                                                     Rendered even with zero rows so the key itself is always present — its
                                                     absence on the request is what tells the controller to skip the sync
                                                     entirely rather than treat "no rows" as "delete everything". --}}
                                                @forelse ($individualTxLines as $knownTx)
                                                    <input type="hidden" name="tx_known_ids[]" value="{{ $knownTx->id }}" />
                                                @empty
                                                    <input type="hidden" name="tx_known_ids[]" value="" />
                                                @endforelse
                                                @for ($i = 0; $i < max(2, $individualTxLines->count()); $i++)
                                                    @php $existingTx = $individualTxLines->get($i); @endphp
                                                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px;align-items:center;">
                                                        <input type="hidden" name="tx_id[]" value="{{ $existingTx?->id }}" />
                                                        <select name="tx_item_id[]" style="flex:2 1 200px;min-width:0;max-width:100%;height:34px;padding:0 7px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;background:#fff;">
                                                            <option value="" x-text="$store.ui.lang==='en' ? '— none —' : '— tiada —'">— none —</option>
                                                            @foreach ($fixedTransactionItems as $item)
                                                                <option value="{{ $item->id }}" @selected($existingTx?->payroll_item_id === $item->id)>{{ $item->name }} ({{ $item->type }})</option>
                                                            @endforeach
                                                        </select>
                                                        <input name="tx_amount[]" type="number" step="0.01" min="0" value="{{ $existingTx ? number_format($existingTx->amount, 2, '.', '') : '' }}" placeholder="0.00" style="flex:1 1 90px;min-width:0;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" />
                                                        <input name="tx_remark[]" value="{{ $existingTx?->remarks }}" placeholder="Remark" :placeholder="$store.ui.lang==='en' ? 'Remark' : 'Catatan'" style="flex:2 1 140px;min-width:0;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;outline:none;" />
                                                    </div>
                                                @endfor
                                                @include('partials.hint', ['en' => 'Pick a Payroll Item, an amount, and an optional remark — its own EPF/SOCSO/EIS flags drive the statutory bases, same as a Fixed Transaction. All rows here are re-saved together on Recalculate. A one-off added elsewhere (another tab, or the Individual transactions screen) since this page loaded is untouched by this save.', 'ms' => 'Pilih satu Item Payroll, jumlah, dan catatan pilihan — penanda EPF/SOCSO/EIS item itu sendiri menentukan asas berkanun, sama seperti Transaksi Tetap. Semua baris di sini disimpan semula bersama apabila Kira semula. Transaksi individu yang ditambah di tempat lain (tab lain, atau skrin Transaksi individu) sejak halaman ini dimuatkan tidak akan disentuh oleh simpanan ini.'])
                                            </div>
                            </div>
                        </details>
                        <div style="padding:14px 22px 22px;display:flex;gap:10px;align-items:center;">
                            <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;" x-text="$store.ui.lang==='en' ? 'Save & recalculate' : 'Simpan & kira semula'">Save & recalculate</button>
                        </div>
                    @else
                        <div style="height:22px;"></div>
                    @endif
                </form>
            </div>
        @endforeach
    </div>
</div>
@endif
