@extends('layouts.app')

@php
    $statusColor = ['draft' => 'var(--amber)', 'approved' => 'var(--info)', 'finalized' => 'var(--success)'];
    $statusMs = ['draft' => 'Draf', 'approved' => 'Diluluskan', 'finalized' => 'Difinalize'];
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
@endphp

@section('screen')

@include('partials.guide', [
    'key' => 'payroll',
    'en'  => [
        'title' => 'Payroll',
        'body'  => empty($privileged)
            ? 'Your issued payslips live here. Each one shows your earnings, the EPF / SOCSO / EIS / PCB deducted, and your final net pay. Payslips only appear once HR has finalized payroll for that month.'
            : 'Run monthly payroll for the whole company. You create a draft run, check each person\'s figures, then finalize to issue payslips and produce the bank file and statutory reports. Finalizing locks the run — get the numbers right first.',
        'who'   => empty($privileged) ? 'Your own payslips' : 'HR & management only',
        'steps' => empty($privileged) ? [] : [
            'Make sure every active employee has a Salary structure set (Salary structures tab).',
            'If anyone joined partway through the year (or your company switched to AmanahKu mid-year), set their Opening figures first — otherwise their PCB for the rest of the year will be wrong.',
            'On Payroll runs, pick the pay month and "Generate draft run" — a draft payslip is created per employee. PCB (income tax) is computed automatically from each employee\'s statutory profile and year-to-date figures.',
            'Open each payslip and Edit to enter overtime, bonus or unpaid days. PCB can be overridden by hand if needed — the override sticks until cleared.',
            'When every figure is verified, "Finalize & issue". This locks payslips, notifies staff, and marks claims paid — it cannot be undone.',
        ],
    ],
    'ms'  => [
        'title' => 'Payroll',
        'body'  => empty($privileged)
            ? 'Payslip yang dikeluarkan untuk anda ada di sini. Setiap satu tunjuk pendapatan anda, potongan EPF / SOCSO / EIS / PCB, dan net pay akhir anda. Payslip hanya muncul setelah HR finalize payroll bagi bulan tersebut.'
            : 'Jalankan payroll bulanan untuk seluruh syarikat. Anda buat draft run, semak angka setiap orang, kemudian finalize untuk keluarkan payslip serta hasilkan bank file dan laporan berkanun. Finalize akan kunci run itu — pastikan angka betul dahulu.',
        'who'   => empty($privileged) ? 'Payslip anda sendiri' : 'HR & pengurusan sahaja',
        'steps' => empty($privileged) ? [] : [
            'Pastikan setiap pekerja aktif ada Salary structure ditetapkan (tab Salary structures).',
            'Jika ada pekerja yang menyertai di tengah tahun (atau syarikat anda bertukar ke AmanahKu di tengah tahun), tetapkan Opening figures dahulu — jika tidak, PCB mereka untuk baki tahun itu akan salah.',
            'Di Payroll runs, pilih bulan gaji dan "Generate draft run" — satu draft payslip dibuat bagi setiap pekerja. PCB (cukai pendapatan) dikira automatik daripada profil berkanun dan angka tahun-ke-tarikh setiap pekerja.',
            'Buka setiap payslip dan Edit untuk masukkan overtime, bonus atau hari tanpa gaji. PCB boleh ditindih secara manual jika perlu — tindihan itu kekal sehingga dikosongkan.',
            'Apabila setiap angka disahkan, "Finalize & issue". Ini kunci payslip, maklumkan staf, dan tanda claim sebagai paid — ia tidak boleh dibatalkan.',
        ],
    ],
])

{{-- ─── Payslip detail ─────────────────────────────────────────────── --}}
@if (!empty($selectedPayslip))
    @php $p = $selectedPayslip; $run = $p->payrollRun; @endphp
    <a href="{{ route('app.screen', 'payroll') }}" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--muted);text-decoration:none;margin-bottom:16px;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        <span x-text="$store.ui.lang==='en' ? 'Back to payroll' : 'Kembali ke payroll'">Back to payroll</span>
    </a>

    <div class="uj-card" style="padding:0;overflow:hidden;max-width:760px;">
        <div style="display:flex;align-items:center;gap:14px;padding:22px 26px;border-bottom:1px solid var(--hairline);background:var(--canvas);">
            <div style="width:46px;height:46px;border-radius:50%;background:{{ $p->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:600;flex-shrink:0;">{{ $p->employee?->initials }}</div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:17px;font-weight:600;color:var(--ink);">{{ $p->employee?->name }}</div>
                <div style="font-size:12.5px;color:var(--muted);">{{ $p->employee?->position }} · <span x-text="$store.ui.lang==='en' ? 'Payslip for' : 'Payslip untuk'">Payslip for</span> {{ $run?->label }}</div>
            </div>
            <span class="uj-pill" style="background:#fff;border:1px solid var(--hairline);color:{{ $statusColor[$run?->status] ?? 'var(--muted)' }};text-transform:capitalize;" x-text="$store.ui.lang==='en' ? @js(ucfirst((string) $run?->status)) : @js($statusMs[$run?->status] ?? ucfirst((string) $run?->status))">{{ $run?->status }}</span>
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

@elseif (empty($privileged))

    {{-- ─── Employee view: my payslips ─────────────────────────────── --}}
    <div class="uj-card" style="max-width:680px;">
        <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'My payslips' : 'Payslip saya'">My payslips</h3></div>
        @forelse ($myPayslips as $p)
            <a href="{{ route('app.screen', ['screen' => 'payroll', 'payslip' => $p->id]) }}" style="display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 20px;border-bottom:1px solid var(--hairline-soft);text-decoration:none;">
                <div style="min-width:0;">
                    <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $p->payrollRun?->label }}</div>
                    <div style="font-size:11.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Issued payslip · net pay' : 'Payslip dikeluarkan · gaji bersih'">Issued payslip · net pay</div>
                </div>
                <div style="display:flex;align-items:center;gap:12px;">
                    <span style="font-size:14px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ $money($p->net_pay) }}</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted-soft)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                </div>
            </a>
        @empty
            <div style="padding:28px 20px;text-align:center;color:var(--muted);">
                <div style="font-size:14px;color:var(--ink);font-weight:500;margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'No payslips yet' : 'Belum ada payslip'"></span></div>
                <div style="font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Your payslips will appear here once payroll is finalized for a month.' : 'Payslip anda akan muncul di sini setelah payroll difinalize untuk sesuatu bulan.'"></span></div>
            </div>
        @endforelse
    </div>

@else

    {{-- ─── Privileged view: run management ────────────────────────── --}}
    <div x-data="{ tab: 'runs', editing: null, salaryFor: null }">
        @php $latest = $activeRun?->totals ?? []; @endphp

        {{-- Stat row --}}
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:18px;">
            <div class="uj-card uj-stat" style="flex:1;min-width:170px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Latest run' : 'Run terkini'">Latest run</div><div class="uj-stat-value" style="font-size:18px;">{{ $activeRun?->label ?? '—' }}</div></div>
            <div class="uj-card uj-stat" style="flex:1;min-width:170px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Net payout' : 'Bayaran bersih'">Net payout</div><div class="uj-stat-value" style="color:var(--success);">{{ $money($latest['net'] ?? 0) }}</div></div>
            <div class="uj-card uj-stat" style="flex:1;min-width:170px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Employer cost' : 'Kos majikan'">Employer cost</div><div class="uj-stat-value">{{ $money($latest['employer_cost'] ?? 0) }}</div></div>
            <div class="uj-card uj-stat" style="flex:1;min-width:170px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Headcount' : 'Bilangan staf'">Headcount</div><div class="uj-stat-value">{{ $latest['headcount'] ?? 0 }}</div></div>
        </div>

        {{-- Tabs --}}
        <div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--hairline);">
            @php $tabLabelsMs = ['runs' => 'Payroll run', 'salaries' => 'Struktur gaji', 'opening' => 'Pekerjaan sebelum ini (TP3)', 'items' => 'Katalog item gaji']; @endphp
            @foreach (['runs' => 'Payroll runs', 'salaries' => 'Salary structures', 'opening' => 'Previous employment (TP3)', 'items' => 'Payroll items'] as $id => $label)
                <button @click="tab = '{{ $id }}'" :style="tab === '{{ $id }}' ? { color:'var(--red)', borderBottom:'2px solid var(--red)' } : { color:'var(--muted)', borderBottom:'2px solid transparent' }" style="background:none;padding:9px 14px;font-size:13px;font-weight:500;cursor:pointer;margin-bottom:-1px;" x-text="$store.ui.lang==='en' ? @js($label) : @js($tabLabelsMs[$id])">{{ $label }}</button>
            @endforeach
        </div>

        {{-- ════ TAB: Runs ════ --}}
        <div x-show="tab === 'runs'" x-cloak>
            <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
                {{-- New run + run list --}}
                <div style="flex:1;min-width:260px;max-width:320px;">
                    <div class="uj-card" style="padding:20px;margin-bottom:16px;">
                        <h3 class="uj-card-title" style="margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'New payroll run' : 'Payroll run baharu'">New payroll run</h3>
                        <form method="post" action="{{ route('payroll.runs.create') }}">
                            @csrf
                            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Pay month' : 'Bulan gaji'">Pay month</label>
                            <input name="period" type="month" value="{{ old('period', now()->format('Y-m')) }}" required style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;margin-bottom:6px;" />
                            @error('period')<div style="font-size:12px;color:var(--error);margin-bottom:8px;">{{ $message }}</div>@enderror
                            @include('partials.hint', ['en' => 'The month you are paying for. One draft run per month — you can edit it freely until you finalize.', 'ms' => 'Bulan yang anda bayar gaji. Satu draft run setiap bulan — anda boleh sunting dengan bebas sehingga finalize.'])
                            <p style="font-size:11.5px;color:var(--muted);margin:6px 0 14px;" x-text="$store.ui.lang==='en' ? 'Generates a draft payslip for every active employee with a salary structure. Approved claims are pulled in as reimbursements.' : 'Menjana draft payslip untuk setiap pekerja aktif yang ada struktur gaji. Tuntutan yang diluluskan ditarik masuk sebagai bayaran balik.'">Generates a draft payslip for every active employee with a salary structure. Approved claims are pulled in as reimbursements.</p>
                            <button type="submit" class="uj-btn-primary" style="height:40px;width:100%;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'Generate draft run' : 'Jana draft run'">Generate draft run</button>
                        </form>
                    </div>

                    <div class="uj-card">
                        <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Run history' : 'Sejarah run'">Run history</h3></div>
                        @forelse ($runs as $r)
                            <a href="{{ route('app.screen', ['screen' => 'payroll', 'run' => $r->id]) }}" style="display:flex;align-items:center;justify-content:space-between;padding:12px 18px;border-bottom:1px solid var(--hairline-soft);text-decoration:none;{{ $activeRun && $activeRun->id === $r->id ? 'background:var(--canvas);' : '' }}">
                                <div><div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $r->label }}</div><div style="font-size:11px;color:var(--muted);">{{ $r->payslips_count }} <span x-text="$store.ui.lang==='en' ? 'payslips' : 'payslip'">payslips</span></div></div>
                                <span class="uj-pill" style="background:#fff;border:1px solid var(--hairline);color:{{ $statusColor[$r->status] ?? 'var(--muted)' }};text-transform:capitalize;font-size:10.5px;" x-text="$store.ui.lang==='en' ? @js(ucfirst((string) $r->status)) : @js($statusMs[$r->status] ?? ucfirst((string) $r->status))">{{ $r->status }}</span>
                            </a>
                        @empty
                            <div style="padding:20px;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No payroll runs yet. Create one to begin.' : 'Belum ada payroll run. Buat satu untuk mula.'"></span></div>
                        @endforelse
                    </div>
                </div>

                {{-- Active run detail --}}
                <div class="uj-card" style="flex:2;min-width:420px;padding:0;">
                    @if ($activeRun)
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:18px 22px;border-bottom:1px solid var(--hairline);">
                            <div>
                                <h3 class="uj-card-title">{{ $activeRun->label }}</h3>
                                <div style="font-size:12px;color:var(--muted);margin-top:2px;"><span x-text="$store.ui.lang==='en' ? 'Gross' : 'Kasar'">Gross</span> {{ $money($latest['gross'] ?? 0) }} · <span x-text="$store.ui.lang==='en' ? 'Deductions' : 'Potongan'">Deductions</span> {{ $money($latest['deductions'] ?? 0) }} · <span x-text="$store.ui.lang==='en' ? 'Net' : 'Bersih'">Net</span> {{ $money($latest['net'] ?? 0) }}</div>
                                @if ($activeRun->status === 'finalized')
                                    @php $ps = $activeRun->payslips; @endphp
                                    <div style="font-size:11.5px;color:var(--muted);margin-top:3px;"><span x-text="$store.ui.lang==='en' ? 'Employer' : 'Majikan'">Employer</span> — EPF {{ $money($ps->sum('epf_employer')) }} · SOCSO {{ $money($ps->sum('socso_employer')) }} · EIS {{ $money($ps->sum('eis_employer')) }} · <span x-text="$store.ui.lang==='en' ? 'PCB collected' : 'PCB dikutip'">PCB collected</span> {{ $money($ps->sum('pcb') + $ps->sum('pcb_additional')) }}</div>
                                @endif
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;">
                                @if ($activeRun->status === 'draft')
                                    <form method="post" action="{{ route('payroll.runs.approve', $activeRun) }}">@csrf<button class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Approve' : 'Luluskan'">Approve</button></form>
                                @endif
                                @if (in_array($activeRun->status, ['draft', 'approved'], true))
                                    <form method="post" action="{{ route('payroll.runs.finalize', $activeRun) }}" onsubmit="return confirm(window.Alpine && Alpine.store('ui').lang==='ms' ? @js('Finalize '.$activeRun->label.'? Payslip dikunci, pekerja dimaklumkan, dan tuntutan yang dibayar balik ditanda sebagai paid.') : @js('Finalize '.$activeRun->label.'? Payslips lock, employees are notified, and reimbursed claims are marked paid.'));">@csrf<button class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Finalize & issue' : 'Finalize & keluarkan'">Finalize & issue</button></form>
                                @else
                                    <span class="uj-pill" style="background:var(--red-tint);color:var(--success);"><span x-text="$store.ui.lang==='en' ? 'Finalized' : 'Difinalize'">Finalized</span> {{ $activeRun->finalized_at?->format('j M') }}</span>
                                @endif
                                @if ($activeRun->status === 'finalized')
                                    <form method="get" action="{{ route('payroll.export.bank', $activeRun) }}" style="display:inline-flex;align-items:center;gap:6px;">
                                        <select name="format" style="height:36px;padding:0 8px;border:1px solid var(--hairline);border-radius:8px;font-size:12px;background:#fff;color:var(--ink);">
                                            @foreach (\App\Services\Payroll\BankFile\BankFileRegistry::options() as $k => $lbl)<option value="{{ $k }}">{{ $lbl }}</option>@endforeach
                                        </select>
                                        <button type="submit" class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Bank file' : 'Fail bank'">Bank file</button>
                                    </form>
                                    <a href="{{ route('payroll.export.statutory', $activeRun) }}" class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Statutory report' : 'Laporan berkanun'">Statutory report</a>
                                @endif
                            </div>
                        </div>

                        @if ($activeRun->status !== 'finalized')
                            <div style="padding:10px 22px;background:#fff7ed;border-bottom:1px solid var(--hairline-soft);font-size:11.5px;color:#9a5b14;" x-text="$store.ui.lang==='en' ? 'Draft figures. PCB (income tax) is computed automatically and can be overridden per employee if needed. Verify statutory amounts before finalizing.' : 'Angka draf. PCB (cukai pendapatan) dikira automatik dan boleh ditindih bagi setiap pekerja jika perlu. Sahkan jumlah berkanun sebelum finalize.'">Draft figures. PCB (income tax) is computed automatically and can be overridden per employee if needed. Verify statutory amounts before finalizing.</div>
                        @endif

                        {{-- Payslip rows --}}
                        @foreach ($activeRun->payslips->sortBy('employee.name') as $p)
                            <div style="border-bottom:1px solid var(--hairline-soft);">
                                <div style="display:flex;align-items:center;gap:12px;padding:12px 22px;">
                                    <div style="width:30px;height:30px;border-radius:50%;background:{{ $p->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:600;flex-shrink:0;">{{ $p->employee?->initials }}</div>
                                    <div style="flex:1;min-width:0;">
                                        <a href="{{ route('app.screen', ['screen' => 'payroll', 'payslip' => $p->id]) }}" style="font-size:13px;color:var(--ink);font-weight:500;text-decoration:none;">{{ $p->employee?->name }}</a>
                                        <div style="font-size:11px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Gross' : 'Kasar'">Gross</span> {{ $money($p->gross) }} · <span x-text="$store.ui.lang==='en' ? 'Deduct' : 'Potong'">Deduct</span> {{ $money($p->total_deductions) }}@if ($p->pcb_override !== null) · <span style="color:var(--info);" x-text="$store.ui.lang==='en' ? 'PCB overridden' : 'PCB ditindih'">PCB overridden</span>@endif</div>
                                    </div>
                                    <div style="text-align:right;"><div style="font-size:13.5px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ $money($p->net_pay) }}</div><div style="font-size:10.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'net' : 'bersih'">net</div></div>
                                    @if ($activeRun->status !== 'finalized')
                                        <button @click="editing === {{ $p->id }} ? editing = null : editing = {{ $p->id }}" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</button>
                                    @endif
                                </div>

                                {{-- Inline variable-input editor --}}
                                @if ($activeRun->status !== 'finalized')
                                    <div x-show="editing === {{ $p->id }}" x-cloak style="padding:4px 22px 18px 64px;">
                                        <form method="post" action="{{ route('payroll.payslips.update', $p) }}" style="background:var(--canvas);border:1px solid var(--hairline);border-radius:10px;padding:16px;">
                                            @csrf
                                            @php
                                                $otPulled = rtrim(rtrim(number_format($p->pulled_overtime_hours, 2), '0'), '.') ?: '0';
                                                $unpaidPulled = rtrim(rtrim(number_format($p->pulled_unpaid_days, 2), '0'), '.') ?: '0';
                                            @endphp
                                            <div style="display:grid;grid-template-columns:repeat(5, 1fr);gap:12px;margin-bottom:4px;">
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Overtime hours (override)' : 'Jam OT (tindihan)'">Overtime hours (override)</label><input name="overtime_hours" type="number" step="0.5" min="0" value="{{ $p->overtime_overridden ? rtrim(rtrim(number_format($p->overtime_hours, 2), '0'), '.') : '' }}" placeholder="{{ $otPulled }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                                {{-- Same unit as the pulled figure's per-rate lines above — hours here always need a
                                                     multiplier alongside them, never a bare number that could be mistaken for one
                                                     unit or the other. Offered as the three Employment Act minimums (1.5x normal
                                                     day, 2x rest day, 3x public holiday) via the datalist, but not restricted to
                                                     them — the day type isn't known here, and a company may pay above the minimum. --}}
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Multiplier (×)' : 'Gandaan (×)'">Multiplier (×)</label><input name="overtime_multiplier" type="number" step="0.1" min="1" list="ot-mult-{{ $p->id }}" value="{{ $p->overtime_overridden && $p->overtime_multiplier !== null ? rtrim(rtrim(number_format($p->overtime_multiplier, 2), '0'), '.') : '' }}" placeholder="1.5" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /><datalist id="ot-mult-{{ $p->id }}"><option value="1.5"></option><option value="2.0"></option><option value="3.0"></option></datalist></div>
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">Bonus (RM)</label><input name="bonus" type="number" step="0.01" min="0" value="{{ $p->bonus > 0 ? number_format($p->bonus, 2, '.', '') : '' }}" placeholder="0.00" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Unpaid days override' : 'Tindihan hari tanpa gaji'">Unpaid days override</label><input name="unpaid_days" type="number" step="0.5" min="0" max="31" value="{{ $p->unpaid_days_overridden ? rtrim(rtrim(number_format($p->unpaid_days, 2), '0'), '.') : '' }}" placeholder="{{ $unpaidPulled }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'PCB override (RM)' : 'Tindihan PCB (RM)'">PCB override (RM)</label><input name="pcb_override" type="number" step="0.01" min="0" value="{{ $p->pcb_override !== null ? number_format($p->pcb_override, 2, '.', '') : '' }}" placeholder="{{ number_format($p->pcb, 2, '.', '') }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                            </div>
                                            @include('partials.hint', ['en' => 'Overtime and unpaid days are pulled automatically from approved OvertimeRequests/unpaid LeaveRequests for this month (shown as the placeholder) — leave the override blank to use the pulled figure. Overtime is entered as hours plus the rate beside it (1.5× if left blank) — the same units the pulled lines show, so the two can never be confused. PCB (income tax) is computed automatically from the LHDN method — leave that override blank too, to use it. Any override sticks until cleared.', 'ms' => 'Overtime dan hari tanpa gaji ditarik automatik daripada OvertimeRequest/LeaveRequest tanpa gaji yang diluluskan bagi bulan ini (ditunjukkan sebagai placeholder) — biarkan tindihan kosong untuk guna angka yang ditarik. Overtime dimasukkan sebagai jam campur kadar di sebelahnya (1.5× jika kosong) — unit yang sama seperti baris yang ditarik, jadi kedua-duanya tidak boleh dikelirukan. PCB (cukai pendapatan) dikira automatik mengikut kaedah LHDN — biarkan tindihan itu kosong juga untuk guna nilai itu. Sebarang tindihan kekal sehingga dikosongkan.'])
                                            @php $individualTxLines = $p->lines->where('source', 'individual')->values(); @endphp
                                            <div style="margin-top:10px;">
                                                <div style="font-size:11.5px;font-weight:600;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Individual transactions (one-off)' : 'Transaksi individu (sekali sahaja)'">Individual transactions (one-off)</div>
                                                @for ($i = 0; $i < max(2, $individualTxLines->count()); $i++)
                                                    @php $existingTx = $individualTxLines->get($i); @endphp
                                                    <div style="display:flex;gap:6px;margin-bottom:6px;align-items:center;">
                                                        <select name="tx_item_id[]" style="flex:2;height:34px;padding:0 7px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;background:#fff;">
                                                            <option value="" x-text="$store.ui.lang==='en' ? '— none —' : '— tiada —'">— none —</option>
                                                            @foreach ($fixedTransactionItems as $item)
                                                                <option value="{{ $item->id }}" @selected($existingTx?->payroll_item_id === $item->id)>{{ $item->name }} ({{ $item->type }})</option>
                                                            @endforeach
                                                        </select>
                                                        <input name="tx_amount[]" type="number" step="0.01" min="0" value="{{ $existingTx ? number_format($existingTx->amount, 2, '.', '') : '' }}" placeholder="0.00" style="flex:1;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" />
                                                        <input name="tx_remark[]" value="{{ $existingTx?->remark }}" placeholder="Remark" :placeholder="$store.ui.lang==='en' ? 'Remark' : 'Catatan'" style="flex:2;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;outline:none;" />
                                                    </div>
                                                @endfor
                                                @include('partials.hint', ['en' => 'Pick a Payroll Item, an amount, and an optional remark — its own EPF/SOCSO/EIS flags drive the statutory bases, same as a Fixed Transaction. All rows here are re-saved together on Recalculate.', 'ms' => 'Pilih satu Item Payroll, jumlah, dan catatan pilihan — penanda EPF/SOCSO/EIS item itu sendiri menentukan asas berkanun, sama seperti Transaksi Tetap. Semua baris di sini disimpan semula bersama apabila Kira semula.'])
                                            </div>
                                            <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;margin-top:8px;" x-text="$store.ui.lang==='en' ? 'Recalculate & save' : 'Kira semula & simpan'">Recalculate & save</button>
                                            <button type="button" @click="editing = null" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                                        </form>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    @else
                        <div style="padding:40px 24px;text-align:center;color:var(--muted);">
                            <div style="font-size:15px;color:var(--ink);font-weight:500;margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'No payroll run selected' : 'Tiada payroll run dipilih'"></span></div>
                            <div style="font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Create a draft run for a month to generate payslips.' : 'Buat draft run untuk sesuatu bulan bagi menjana payslip.'"></span></div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ════ TAB: Salary structures ════ --}}
        <div x-show="tab === 'salaries'" x-cloak>
            <div class="uj-card" style="padding:0;">
                @php $setCount = $salaryEmployees->whereNotNull('salaryStructure')->count(); $totalCount = $salaryEmployees->count(); @endphp
                <div class="uj-card-head" style="padding:16px 22px;"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Salary structures' : 'Struktur gaji'">Salary structures</h3><span style="font-size:12px;color:var(--muted);" x-text="$store.ui.lang==='en' ? @js($setCount.' of '.$totalCount.' set') : @js($setCount.' daripada '.$totalCount.' ditetapkan')">{{ $setCount }} of {{ $totalCount }} set</span></div>
                @foreach ($salaryEmployees as $e)
                    @php
                        $s = $e->salaryStructure;
                        $empFt = $fixedTransactions->get($e->id, collect());
                        $empFtEarnings = $empFt->filter(fn ($ft) => $ft->payrollItem?->type === 'earning')->sum('amount');
                    @endphp
                    <div style="border-bottom:1px solid var(--hairline-soft);">
                        <div style="display:flex;align-items:center;gap:12px;padding:12px 22px;">
                            <div style="width:30px;height:30px;border-radius:50%;background:{{ $e->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:600;flex-shrink:0;">{{ $e->initials }}</div>
                            <div style="flex:1;min-width:0;"><div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $e->name }}</div><div style="font-size:11px;color:var(--muted);">{{ $e->position }}</div></div>
                            <div style="text-align:right;">
                                @if ($s)<div style="font-size:13px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ $money($s->basic_salary) }}</div><div style="font-size:10.5px;color:var(--muted);">+ {{ $money($empFtEarnings) }} <span x-text="$store.ui.lang==='en' ? 'fixed transactions' : 'transaksi tetap'">fixed transactions</span></div>
                                @else<span class="uj-pill" style="background:var(--red-tint);color:var(--amber);" x-text="$store.ui.lang==='en' ? 'Not set' : 'Belum ditetapkan'">Not set</span>@endif
                            </div>
                            <button @click="salaryFor === {{ $e->id }} ? salaryFor = null : salaryFor = {{ $e->id }}" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? @js($s ? 'Edit' : 'Set') : @js($s ? 'Sunting' : 'Tetapkan')">{{ $s ? 'Edit' : 'Set' }}</button>
                        </div>
                        <div x-show="salaryFor === {{ $e->id }}" x-cloak style="padding:4px 22px 18px 64px;">
                            <form method="post" action="{{ route('payroll.salary') }}" style="background:var(--canvas);border:1px solid var(--hairline);border-radius:10px;padding:16px;">
                                @csrf
                                <input type="hidden" name="employee_id" value="{{ $e->id }}" />
                                <div style="display:flex;gap:12px;align-items:flex-end;margin-bottom:12px;flex-wrap:wrap;">
                                    <div style="flex:1;min-width:160px;"><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Basic salary (RM / month)' : 'Gaji pokok (RM / bulan)'">Basic salary (RM / month)</label><input name="basic_salary" type="number" step="0.01" min="0" required value="{{ $s ? number_format($s->basic_salary, 2, '.', '') : '' }}" placeholder="0.00" style="width:100%;height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" />@include('partials.hint', ['tone' => 'warn', 'en' => 'Gross monthly basic. This drives every payslip and all EPF / SOCSO / EIS amounts — double-check before saving.', 'ms' => 'Gaji pokok bulanan kasar. Ini mempengaruhi setiap payslip dan semua jumlah EPF / SOCSO / EIS — semak dua kali sebelum simpan.'])</div>
                                    <div style="flex:1;min-width:160px;"><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Effective from' : 'Berkuat kuasa dari'">Effective from</label><input name="effective_from" type="date" value="{{ $s?->effective_from?->toDateString() ?? now()->toDateString() }}" style="width:100%;height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;outline:none;" /></div>
                                </div>
                                <div style="font-size:11.5px;font-weight:600;color:var(--ink);margin:14px 0 6px;"><span x-text="$store.ui.lang==='en' ? 'Payment & statutory identifiers' : 'Pengenalan bayaran & berkanun'">Payment &amp; statutory identifiers</span> <span style="font-weight:400;color:var(--muted);" x-text="$store.ui.lang==='en' ? '— used for the bank file & EPF/SOCSO/EIS reports' : '— digunakan untuk fail bank & laporan EPF/SOCSO/EIS'">— used for the bank file &amp; EPF/SOCSO/EIS reports</span></div>
                                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;max-width:560px;">
                                    <input name="bank_name" value="{{ $s?->bank_name }}" placeholder="Bank (e.g. Maybank)" :placeholder="$store.ui.lang==='en' ? 'Bank (e.g. Maybank)' : 'Bank (cth. Maybank)'" style="height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;outline:none;" />
                                    <input name="bank_account_no" value="{{ $s?->bank_account_no }}" placeholder="Bank account no" :placeholder="$store.ui.lang==='en' ? 'Bank account no' : 'No akaun bank'" style="height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" />
                                    <input name="epf_no" value="{{ $s?->epf_no }}" placeholder="EPF / KWSP no" :placeholder="$store.ui.lang==='en' ? 'EPF / KWSP no' : 'No EPF / KWSP'" style="height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" />
                                    <input name="socso_no" value="{{ $s?->socso_no }}" placeholder="SOCSO / PERKESO no" :placeholder="$store.ui.lang==='en' ? 'SOCSO / PERKESO no' : 'No SOCSO / PERKESO'" style="height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" />
                                </div>
                                <div style="margin-top:8px;max-width:560px;">
                                    <div style="font-size:10.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'NRIC' : 'No. K/P (NRIC)'">NRIC</span>: <span style="font-family:var(--font-mono);color:var(--ink);">{{ $e->nric ?: '—' }}</span> <span x-text="$store.ui.lang==='en' ? '— from the employee record, not editable here' : '— daripada rekod pekerja, tidak boleh sunting di sini'">— from the employee record, not editable here</span></div>
                                </div>
                                <div style="font-size:11.5px;font-weight:600;color:var(--ink);margin:14px 0 6px;padding-top:10px;border-top:1px solid var(--hairline-soft);" x-text="$store.ui.lang==='en' ? 'Statutory profile' : 'Profil berkanun'">Statutory profile</div>
                                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;max-width:560px;">
                                    <div>
                                        <label style="display:block;font-size:10.5px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'Nationality' : 'Kewarganegaraan'">Nationality</label>
                                        <select name="nationality" style="width:100%;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;outline:none;">
                                            @foreach (['citizen' => ['Citizen', 'Warganegara'], 'pr' => ['Permanent resident', 'Penduduk tetap'], 'foreign' => ['Foreign worker', 'Pekerja asing']] as $val => $lbl)
                                                <option value="{{ $val }}" @selected(($s?->nationality ?? 'citizen') === $val) x-text="$store.ui.lang==='en' ? @js($lbl[0]) : @js($lbl[1])">{{ $lbl[0] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label style="display:block;font-size:10.5px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'Marital status' : 'Status perkahwinan'">Marital status</label>
                                        <div style="height:34px;display:flex;align-items:center;font-size:12.5px;color:var(--ink);">{{ ucfirst((string) ($e->marital_status ?? 'single')) }}</div>
                                        @include('partials.hint', ['en' => 'From the employee record (set at first login) — edit it on the employee\'s own profile, not here. It drives PCB category.', 'ms' => 'Daripada rekod pekerja (ditetapkan semasa log masuk pertama) — sunting di profil pekerja itu sendiri, bukan di sini. Ia mempengaruhi kategori PCB.'])
                                    </div>
                                    <input name="tax_no" value="{{ $s?->tax_no }}" placeholder="LHDN tax reference no" :placeholder="$store.ui.lang==='en' ? 'LHDN tax reference no' : 'No rujukan cukai LHDN'" style="height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" />
                                    <div><label style="display:block;font-size:10.5px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'EPF employee rate override %' : 'Kadar caruman pekerja EPF %'">EPF employee rate override %</label><input name="epf_employee_rate_override" type="number" step="0.01" min="0" max="100" value="{{ $s?->epf_employee_rate_override }}" placeholder="—" style="width:100%;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" /></div>
                                    <div><label style="display:block;font-size:10.5px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'Child relief UNITS (not headcount)' : 'UNIT pelepasan anak (bukan bilangan anak)'">Child relief UNITS (not headcount)</label><input name="children_relief_count" type="number" step="1" min="0" max="20" value="{{ $s?->children_relief_count ?? 0 }}" style="width:100%;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" />@include('partials.hint', ['en' => 'Each unit is RM2,000 relief. A normal child under 18 is 1 unit. A child 18+ in full-time education is 4 units. A disabled child is 4 units. A disabled child 18+ in full-time education is 8 units. Add up every child\'s units and enter the total.', 'ms' => 'Setiap unit bersamaan pelepasan RM2,000. Anak biasa bawah 18 tahun = 1 unit. Anak 18+ dalam pengajian sepenuh masa = 4 unit. Anak OKU = 4 unit. Anak OKU 18+ dalam pengajian sepenuh masa = 8 unit. Jumlahkan unit semua anak dan masukkan jumlahnya.'])</div>
                                    <div><label style="display:block;font-size:10.5px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'Zakat (RM / month)' : 'Zakat (RM / bulan)'">Zakat (RM / month)</label><input name="zakat_monthly" type="number" step="0.01" min="0" value="{{ $s?->zakat_monthly ?? 0 }}" style="width:100%;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" /></div>
                                    <div><label style="display:block;font-size:10.5px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'CP38 instalment (RM / month)' : 'Ansuran CP38 (RM / bulan)'">CP38 instalment (RM / month)</label><input name="cp38_monthly" type="number" step="0.01" min="0" value="{{ $s?->cp38_monthly ?? 0 }}" style="width:100%;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" /></div>
                                </div>
                                <div style="display:flex;flex-wrap:wrap;gap:14px;margin-top:10px;">
                                    <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink);cursor:pointer;"><input type="checkbox" name="epf_opt_in_60plus" value="1" @checked($s?->epf_opt_in_60plus) style="width:15px;height:15px;" /><span x-text="$store.ui.lang==='en' ? 'EPF opt-in (60+)' : 'Pilih masuk EPF (60+)'">EPF opt-in (60+)</span></label>
                                    <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink);cursor:pointer;"><input type="checkbox" name="spouse_working" value="1" @checked($s?->spouse_working) style="width:15px;height:15px;" /><span x-text="$store.ui.lang==='en' ? 'Spouse working' : 'Pasangan bekerja'">Spouse working</span></label>
                                    <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink);cursor:pointer;"><input type="checkbox" name="disabled_self" value="1" @checked($s?->disabled_self) style="width:15px;height:15px;" /><span x-text="$store.ui.lang==='en' ? 'Disabled (self)' : 'OKU (diri sendiri)'">Disabled (self)</span></label>
                                    <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink);cursor:pointer;"><input type="checkbox" name="disabled_spouse" value="1" @checked($s?->disabled_spouse) style="width:15px;height:15px;" /><span x-text="$store.ui.lang==='en' ? 'Disabled (spouse)' : 'OKU (pasangan)'">Disabled (spouse)</span></label>
                                    <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink);cursor:pointer;"><input type="checkbox" name="skbbk_opt_in" value="1" @checked($s?->skbbk_opt_in) style="width:15px;height:15px;" /><span x-text="$store.ui.lang==='en' ? 'Lindung 24 Jam (SKBBK)' : 'Lindung 24 Jam (SKBBK)'">Lindung 24 Jam (SKBBK)</span></label>
                                </div>
                                @include('partials.hint', [
                                    'en' => 'Voluntary since 8 July 2026, employee-paid: 0.75% of wages, capped at RM45/month.',
                                    'ms' => 'Pilihan (voluntari) sejak 8 Julai 2026, dibayar oleh pekerja: 0.75% gaji, had siling RM45/bulan.',
                                ])
                                <div style="margin-top:12px;"><button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Save structure' : 'Simpan struktur'">Save structure</button><button type="button" @click="salaryFor = null" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button></div>
                            </form>

                            {{-- ── Fixed Transactions: recurring earnings/deductions against the Payroll Item catalogue ── --}}
                            <div x-data="{ ftAdding: false, ftEditing: null, ftEnding: null }" style="margin-top:16px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                    <div style="font-size:11.5px;font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Fixed transactions' : 'Transaksi tetap'">Fixed transactions</div>
                                    <button type="button" @click="ftAdding = !ftAdding" class="uj-btn-ghost" style="height:28px;padding:0 10px;font-size:11.5px;" x-text="$store.ui.lang==='en' ? (ftAdding ? 'Cancel' : '+ Add') : (ftAdding ? 'Batal' : '+ Tambah')">+ Add</button>
                                </div>

                                @forelse ($empFt as $ft)
                                    <div style="border:1px solid var(--hairline);border-radius:8px;padding:8px 10px;margin-bottom:6px;">
                                        <div style="display:flex;align-items:center;gap:10px;">
                                            <div style="flex:1;min-width:0;">
                                                <div style="font-size:12.5px;color:var(--ink);font-weight:500;">{{ $ft->payrollItem?->name }} <span style="font-weight:400;color:var(--muted);">({{ $ft->payrollItem?->type }})</span></div>
                                                <div style="font-size:10.5px;color:var(--muted);">
                                                    {{ $ft->start_period }} →
                                                    @if($ft->end_period)
                                                        {{ $ft->end_period }}
                                                    @else
                                                        <span x-text="$store.ui.lang==='en' ? 'open-ended' : 'tiada had'">open-ended</span>
                                                    @endif
                                                    @if($ft->prorate)
                                                        · <span x-text="$store.ui.lang==='en' ? 'prorated' : 'prorata'">prorated</span>
                                                    @endif
                                                    @if($ft->remarks)
                                                        · {{ $ft->remarks }}
                                                    @endif
                                                </div>
                                            </div>
                                            <div style="font-family:var(--font-mono);font-size:12.5px;color:var(--ink);">{{ $money($ft->amount) }}</div>
                                            <button type="button" @click="ftEditing = ftEditing === {{ $ft->id }} ? null : {{ $ft->id }}" class="uj-btn-ghost" style="height:26px;padding:0 8px;font-size:11px;" x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</button>
                                            <button type="button" @click="ftEnding = ftEnding === {{ $ft->id }} ? null : {{ $ft->id }}" class="uj-btn-ghost" style="height:26px;padding:0 8px;font-size:11px;" x-text="$store.ui.lang==='en' ? 'End' : 'Tamat'">End</button>
                                        </div>
                                        <div x-show="ftEditing === {{ $ft->id }}" x-cloak style="margin-top:8px;padding-top:8px;border-top:1px solid var(--hairline-soft);">
                                            <form method="post" action="{{ route('payroll.fixed-transactions.update', $ft) }}">
                                                @csrf
                                                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end;">
                                                    <div><label style="display:block;font-size:10px;color:var(--muted);">RM</label><input name="amount" type="number" step="0.01" min="0.01" required value="{{ number_format($ft->amount, 2, '.', '') }}" style="width:100px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;font-family:var(--font-mono);" /></div>
                                                    <div><label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Start' : 'Mula'">Start</label><input name="start_period" type="month" required value="{{ $ft->start_period }}" style="width:110px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;" /></div>
                                                    <div><label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'End (blank = open)' : 'Tamat (kosong = tiada had)'">End (blank = open)</label><input name="end_period" type="month" value="{{ $ft->end_period }}" style="width:110px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;" /></div>
                                                    <div><label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Last month RM' : 'RM bulan akhir'">Last month RM</label><input name="last_amount" type="number" step="0.01" min="0" value="{{ $ft->last_amount !== null ? number_format($ft->last_amount, 2, '.', '') : '' }}" placeholder="—" style="width:100px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;font-family:var(--font-mono);" /></div>
                                                    <label style="display:flex;align-items:center;gap:5px;font-size:11.5px;color:var(--ink);height:30px;"><input type="checkbox" name="prorate" value="1" @checked($ft->prorate) /> <span x-text="$store.ui.lang==='en' ? 'Prorate' : 'Prorata'">Prorate</span></label>
                                                    <input name="remarks" value="{{ $ft->remarks }}" placeholder="Remarks" :placeholder="$store.ui.lang==='en' ? 'Remarks' : 'Catatan'" style="flex:1;min-width:120px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;" />
                                                    <button type="submit" class="uj-btn-primary" style="height:30px;padding:0 12px;font-size:11.5px;" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                        <div x-show="ftEnding === {{ $ft->id }}" x-cloak style="margin-top:8px;padding-top:8px;border-top:1px solid var(--hairline-soft);">
                                            <form method="post" action="{{ route('payroll.fixed-transactions.end', $ft) }}" style="display:flex;gap:8px;align-items:flex-end;">
                                                @csrf
                                                <div><label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Last period it still applies' : 'Tempoh terakhir ia masih terpakai'">Last period it still applies</label><input name="end_period" type="month" required value="{{ $currentPeriod }}" style="width:130px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;" /></div>
                                                <button type="submit" class="uj-btn-primary" style="height:30px;padding:0 12px;font-size:11.5px;background:var(--error);border-color:var(--error);" x-text="$store.ui.lang==='en' ? 'Confirm end' : 'Sahkan tamat'">Confirm end</button>
                                            </form>
                                            @include('partials.hint', ['en' => 'This never deletes the row — it sets the last period it still applies, so past payslips stay explainable.', 'ms' => 'Ini tidak memadam rekod — ia menetapkan tempoh terakhir ia masih terpakai, supaya payslip lepas kekal boleh dijelaskan.'])
                                        </div>
                                    </div>
                                @empty
                                    <div style="font-size:11.5px;color:var(--muted);padding:6px 0;" x-text="$store.ui.lang==='en' ? 'No fixed transactions.' : 'Tiada transaksi tetap.'">No fixed transactions.</div>
                                @endforelse

                                <div x-show="ftAdding" x-cloak style="border:1px dashed var(--hairline);border-radius:8px;padding:10px;margin-top:6px;">
                                    <form method="post" action="{{ route('payroll.fixed-transactions.store') }}">
                                        @csrf
                                        <input type="hidden" name="employee_id" value="{{ $e->id }}" />
                                        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end;">
                                            <div style="flex:1;min-width:160px;"><label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Payroll item' : 'Item payroll'">Payroll item</label>
                                                <select name="payroll_item_id" required style="width:100%;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;">
                                                    @foreach ($fixedTransactionItems as $item)
                                                        <option value="{{ $item->id }}">{{ $item->name }} ({{ $item->type }})</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div><label style="display:block;font-size:10px;color:var(--muted);">RM</label><input name="amount" type="number" step="0.01" min="0.01" required placeholder="0.00" style="width:100px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;font-family:var(--font-mono);" /></div>
                                            <div><label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Start' : 'Mula'">Start</label><input name="start_period" type="month" required value="{{ $currentPeriod }}" style="width:110px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;" /></div>
                                            <label style="display:flex;align-items:center;gap:5px;font-size:11.5px;color:var(--ink);height:30px;"><input type="checkbox" name="prorate" value="1" /> <span x-text="$store.ui.lang==='en' ? 'Prorate' : 'Prorata'">Prorate</span></label>
                                            <input name="remarks" placeholder="Remarks" :placeholder="$store.ui.lang==='en' ? 'Remarks' : 'Catatan'" style="flex:1;min-width:120px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;" />
                                            <button type="submit" class="uj-btn-primary" style="height:30px;padding:0 12px;font-size:11.5px;" x-text="$store.ui.lang==='en' ? 'Add' : 'Tambah'">Add</button>
                                        </div>
                                        @include('partials.hint', ['en' => 'Prorate uses calendar days in the month (joiner/leaver), not the 26-day rule used for unpaid leave/overtime.', 'ms' => 'Prorata guna bilangan hari kalendar dalam bulan (pekerja baru/keluar), bukan peraturan 26 hari untuk cuti tanpa gaji/kerja lebih masa.'])
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ════ TAB: Previous employment (TP3) ════ --}}
        <div x-show="tab === 'opening'" x-cloak x-data="{ openFor: null }">
            <div class="uj-card" style="max-width:820px;">
                <div class="uj-card-head" style="padding:16px 22px;">
                    <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Previous employment (TP3)' : 'Pekerjaan sebelum ini (TP3)'">Previous employment (TP3)</h3>
                    <span style="font-size:12px;color:var(--muted);">{{ $openingYear }}</span>
                </div>
                <div style="padding:14px 22px;border-bottom:1px solid var(--hairline-soft);">
                    @include('partials.hint', [
                        'tone' => 'warn',
                        'en' => 'Gross, PCB, EPF, zakat and the optional-deductions figures come from the employee\'s Form TP3 (or the payroll system the company used earlier this year) and must be entered before that person\'s first payroll run — getting them wrong makes both the monthly tax and the year-end EA form wrong. SOCSO and EIS are not part of Form TP3 itself; they come from the previous payroll system\'s own take-on screen and are kept here for the EA form and your own reconciliation. Leave everything at 0 for anyone who has been paid through AmanahKu since January.',
                        'ms' => 'Angka kasar, PCB, EPF, zakat dan potongan pilihan datang daripada Borang TP3 pekerja (atau sistem payroll yang syarikat guna lebih awal tahun ini) dan mesti dimasukkan sebelum payroll run pertama pekerja itu — jika salah, cukai bulanan dan Borang EA akhir tahun turut salah. SOCSO dan EIS bukan sebahagian daripada Borang TP3 itu sendiri; ia datang daripada skrin take-on sistem payroll sebelumnya dan disimpan di sini untuk Borang EA dan rekonsiliasi anda sendiri. Biarkan semua pada 0 bagi sesiapa yang telah dibayar melalui AmanahKu sejak Januari.',
                    ])
                </div>
                @foreach ($openingEmployees as $e)
                    @php $o = $openingFigures->get($e->id); @endphp
                    <div style="border-bottom:1px solid var(--hairline-soft);">
                        <div style="display:flex;align-items:center;gap:12px;padding:12px 22px;">
                            <div style="width:30px;height:30px;border-radius:50%;background:{{ $e->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:600;flex-shrink:0;">{{ $e->initials }}</div>
                            <div style="flex:1;min-width:0;"><div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $e->name }}</div><div style="font-size:11px;color:var(--muted);">{{ $e->position }}</div></div>
                            <div style="text-align:right;">
                                @if ($o)<div style="font-size:12.5px;color:var(--ink);">{{ $money($o->gross) }} <span style="color:var(--muted);" x-text="$store.ui.lang==='en' ? 'gross' : 'kasar'">gross</span></div>
                                @else<span class="uj-pill" style="background:var(--canvas);color:var(--muted);" x-text="$store.ui.lang==='en' ? 'None (0)' : 'Tiada (0)'">None (0)</span>@endif
                            </div>
                            <button @click="openFor === {{ $e->id }} ? openFor = null : openFor = {{ $e->id }}" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</button>
                        </div>
                        <div x-show="openFor === {{ $e->id }}" x-cloak style="padding:4px 22px 18px 64px;">
                            <form method="post" action="{{ route('payroll.opening') }}" style="background:var(--canvas);border:1px solid var(--hairline);border-radius:10px;padding:16px;">
                                @csrf
                                <input type="hidden" name="employee_id" value="{{ $e->id }}" />
                                <input type="hidden" name="year" value="{{ $openingYear }}" />
                                <div style="font-size:11.5px;font-weight:600;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Pay & statutory (feeds PCB)' : 'Gaji & berkanun (mempengaruhi PCB)'">Pay &amp; statutory (feeds PCB)</div>
                                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;max-width:520px;margin-bottom:12px;">
                                    @foreach ([
                                        ['gross', 'Gross paid (RM)', 'Kasar dibayar (RM)', $o?->gross],
                                        ['pcb_paid', 'PCB (income tax) paid (RM)', 'PCB (cukai pendapatan) dibayar (RM)', $o?->pcb_paid],
                                        ['epf', 'EPF paid (RM)', 'EPF dibayar (RM)', $o?->epf],
                                        ['socso', 'SOCSO paid (RM)', 'SOCSO dibayar (RM)', $o?->socso],
                                        ['eis', 'EIS paid (RM)', 'EIS dibayar (RM)', $o?->eis],
                                        ['zakat_paid', 'Zakat paid (RM)', 'Zakat dibayar (RM)', $o?->zakat_paid],
                                    ] as $f)
                                        <div><label style="display:block;font-size:10.5px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? @js($f[1]) : @js($f[2])">{{ $f[1] }}</label><input name="{{ $f[0] }}" type="number" step="0.01" min="0" value="{{ $f[3] !== null ? number_format((float) $f[3], 2, '.', '') : '' }}" placeholder="0.00" style="width:100%;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" /></div>
                                    @endforeach
                                </div>
                                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;max-width:520px;margin-bottom:12px;">
                                    @foreach ([
                                        ['additional_gross', 'Additional (bonus) gross (RM)', 'Kasar tambahan (bonus) (RM)', $o?->additional_gross],
                                        ['additional_epf', 'EPF on additional (RM)', 'EPF atas tambahan (RM)', $o?->additional_epf],
                                        ['optional_deductions', 'Optional deductions claimed (RM)', 'Potongan pilihan dituntut (RM)', $o?->optional_deductions],
                                    ] as $f)
                                        <div><label style="display:block;font-size:10.5px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? @js($f[1]) : @js($f[2])">{{ $f[1] }}</label><input name="{{ $f[0] }}" type="number" step="0.01" min="0" value="{{ $f[3] !== null ? number_format((float) $f[3], 2, '.', '') : '' }}" placeholder="0.00" style="width:100%;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" /></div>
                                    @endforeach
                                </div>
                                <div style="font-size:11.5px;font-weight:600;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Record-keeping only (EA form)' : 'Untuk rekod sahaja (Borang EA)'">Record-keeping only (EA form)</div>
                                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;max-width:520px;margin-bottom:12px;">
                                    <div><label style="display:block;font-size:10.5px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'Exempt allowances (RM)' : 'Elaun dikecualikan cukai (RM)'">Exempt allowances (RM)</label><input name="exempt_allowances" type="number" step="0.01" min="0" value="{{ $o?->exempt_allowances !== null ? number_format((float) $o?->exempt_allowances, 2, '.', '') : '' }}" placeholder="0.00" style="width:100%;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" /></div>
                                    <div style="grid-column:span 2;"><label style="display:block;font-size:10.5px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'Previous employer' : 'Majikan sebelum ini'">Previous employer</label><input name="previous_employer" value="{{ $o?->previous_employer }}" placeholder="Company name" :placeholder="$store.ui.lang==='en' ? 'Company name' : 'Nama syarikat'" style="width:100%;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;outline:none;" /></div>
                                    <div><label style="display:block;font-size:10.5px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'Previous employer TIN' : 'TIN majikan sebelum ini'">Previous employer TIN</label><input name="previous_employer_tin" value="{{ $o?->previous_employer_tin }}" placeholder="Tax ID no." :placeholder="$store.ui.lang==='en' ? 'Tax ID no.' : 'No. rujukan cukai'" style="width:100%;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" /></div>
                                </div>
                                <div style="margin-top:12px;"><button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Save opening figures' : 'Simpan angka permulaan'">Save opening figures</button><button type="button" @click="openFor = null" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button></div>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ════ TAB: Payroll items ════ --}}
        <div x-show="tab === 'items'" x-cloak x-data="{ editItem: null }">
            <div class="uj-card" style="max-width:900px;">
                <div class="uj-card-head" style="padding:16px 22px;">
                    <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Payroll items' : 'Katalog item gaji'">Payroll items</h3>
                </div>
                <div style="padding:14px 22px;border-bottom:1px solid var(--hairline-soft);">
                    @include('partials.hint', [
                        'en' => 'Every amount on a payslip comes from one of these named items. The EPF / SOCSO+EIS / taxable flags decide what an item does to statutory contributions — a company can genuinely treat an allowance differently, so flags are editable on any item. System items (seeded defaults) cannot be deleted, but their flags and names can still be changed.',
                        'ms' => 'Setiap jumlah pada payslip datang daripada salah satu item bernama ini. Penanda EPF / SOCSO+EIS / boleh cukai menentukan kesan item itu terhadap caruman berkanun — sesebuah syarikat mungkin benar-benar melayan sesuatu elaun secara berbeza, jadi penanda boleh disunting pada mana-mana item. Item sistem (lalai yang disediakan) tidak boleh dipadam, tetapi nama dan penandanya masih boleh diubah.',
                    ])
                </div>
                @forelse ($payrollItems as $item)
                    <div style="border-bottom:1px solid var(--hairline-soft);">
                        <div style="display:flex;align-items:center;gap:12px;padding:12px 22px;">
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:13px;color:var(--ink);font-weight:500;">
                                    {{ $item->name }}
                                    @if ($item->is_system)<span class="uj-pill" style="background:var(--canvas);color:var(--muted);font-size:9.5px;margin-left:6px;" x-text="$store.ui.lang==='en' ? 'System' : 'Sistem'">System</span>@endif
                                    @if (! $item->active)<span class="uj-pill" style="background:var(--red-tint);color:var(--muted);font-size:9.5px;margin-left:6px;" x-text="$store.ui.lang==='en' ? 'Inactive' : 'Tidak aktif'">Inactive</span>@endif
                                </div>
                                <div style="font-size:11px;color:var(--muted);text-transform:capitalize;">{{ $item->type }} · {{ $item->code }}</div>
                            </div>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                <span class="uj-pill" style="background:{{ $item->epf_liable ? 'var(--red-tint)' : 'var(--canvas)' }};color:{{ $item->epf_liable ? 'var(--success)' : 'var(--muted)' }};font-size:10.5px;">EPF {{ $item->epf_liable ? '✓' : '—' }}</span>
                                <span class="uj-pill" style="background:{{ $item->perkeso_liable ? 'var(--red-tint)' : 'var(--canvas)' }};color:{{ $item->perkeso_liable ? 'var(--success)' : 'var(--muted)' }};font-size:10.5px;">SOCSO/EIS {{ $item->perkeso_liable ? '✓' : '—' }}</span>
                                <span class="uj-pill" style="background:{{ $item->pcb_taxable ? 'var(--red-tint)' : 'var(--canvas)' }};color:{{ $item->pcb_taxable ? 'var(--success)' : 'var(--muted)' }};font-size:10.5px;" x-text="($store.ui.lang==='en' ? 'PCB ' : 'PCB ') + ('{{ $item->pcb_taxable ? '✓' : '—' }}')">PCB {{ $item->pcb_taxable ? '✓' : '—' }}</span>
                            </div>
                            <button @click="editItem === {{ $item->id }} ? editItem = null : editItem = {{ $item->id }}" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</button>
                        </div>
                        <div x-show="editItem === {{ $item->id }}" x-cloak style="padding:4px 22px 18px 22px;">
                            <form method="post" action="{{ route('payroll.items.update', $item) }}" style="background:var(--canvas);border:1px solid var(--hairline);border-radius:10px;padding:16px;">
                                @csrf
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                                    <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Name (EN)' : 'Nama (EN)'">Name (EN)</label><input name="name" value="{{ $item->name }}" required style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;outline:none;" /></div>
                                    <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">Nama (BM)</label><input name="name_ms" value="{{ $item->name_ms }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;outline:none;" /></div>
                                </div>
                                <div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:12px;">
                                    <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink);cursor:pointer;"><input type="checkbox" name="epf_liable" value="1" @checked($item->epf_liable) style="width:15px;height:15px;" /><span>EPF liable</span></label>
                                    <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink);cursor:pointer;"><input type="checkbox" name="perkeso_liable" value="1" @checked($item->perkeso_liable) style="width:15px;height:15px;" /><span>SOCSO/EIS liable</span></label>
                                    <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink);cursor:pointer;"><input type="checkbox" name="pcb_taxable" value="1" @checked($item->pcb_taxable) style="width:15px;height:15px;" /><span x-text="$store.ui.lang==='en' ? 'Taxable (PCB)' : 'Boleh cukai (PCB)'">Taxable (PCB)</span></label>
                                    <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink);cursor:pointer;"><input type="checkbox" name="active" value="1" @checked($item->active) style="width:15px;height:15px;" /><span x-text="$store.ui.lang==='en' ? 'Active' : 'Aktif'">Active</span></label>
                                </div>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                                    <button type="button" @click="editItem = null" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                                    @unless ($item->is_system)
                                        <span style="flex:1;"></span>
                                        <button type="submit" formaction="{{ route('payroll.items.delete', $item) }}" onclick="return confirm(window.Alpine && Alpine.store('ui').lang==='ms' ? 'Padam item ini?' : 'Delete this item?');" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;color:var(--error);" x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</button>
                                    @endunless
                                </div>
                            </form>
                        </div>
                    </div>
                @empty
                    <div style="padding:28px 20px;text-align:center;color:var(--muted);">
                        <div style="font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'No payroll items yet — they seed automatically the first time this tenant is set up.' : 'Belum ada item payroll — ia disediakan secara automatik apabila tenant ini disediakan.'"></span></div>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
@endif
@endsection
