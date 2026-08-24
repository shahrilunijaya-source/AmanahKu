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
            'On Payroll runs, pick the pay month and "Generate draft run" — a draft payslip is created per employee.',
            'Open each payslip and Edit to enter overtime, bonus, unpaid days and PCB (income tax). PCB is entered by hand.',
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
            'Di Payroll runs, pilih bulan gaji dan "Generate draft run" — satu draft payslip dibuat bagi setiap pekerja.',
            'Buka setiap payslip dan Edit untuk masukkan overtime, bonus, hari tanpa gaji dan PCB (cukai pendapatan). PCB dimasukkan secara manual.',
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
                @php $otSuffix = $p->overtime_hours > 0 ? ' ('.rtrim(rtrim(number_format($p->overtime_hours, 2), '0'), '.').'h)' : ''; @endphp
                @foreach ([
                    ['Basic salary', 'Gaji pokok', $p->basic],
                    ['Allowances', 'Elaun', $p->allowances_total],
                    ['Overtime'.$otSuffix, 'Kerja lebih masa'.$otSuffix, $p->overtime_amount],
                    ['Bonus / one-off', 'Bonus / sekali', $p->bonus],
                ] as $line)
                    <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--body);"><span x-text="$store.ui.lang==='en' ? @js($line[0]) : @js($line[1])">{{ $line[0] }}</span><span style="font-family:var(--font-mono);color:var(--ink);">{{ $money($line[2]) }}</span></div>
                @endforeach
                @foreach (($p->additions ?? []) as $add)
                    <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--body);"><span>{{ $add['name'] }}</span><span style="font-family:var(--font-mono);color:var(--ink);">{{ $money($add['amount']) }}</span></div>
                @endforeach
                @if ($p->unpaid_deduction > 0)
                    @php $unpaidDays = rtrim(rtrim(number_format($p->unpaid_days, 2), '0'), '.'); @endphp
                    <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--error);"><span x-text="$store.ui.lang==='en' ? @js('Unpaid leave ('.$unpaidDays.' days)') : @js('Cuti tanpa gaji ('.$unpaidDays.' hari)')">Unpaid leave ({{ $unpaidDays }} days)</span><span style="font-family:var(--font-mono);">−{{ $money($p->unpaid_deduction) }}</span></div>
                @endif
                <div style="display:flex;justify-content:space-between;font-size:14px;font-weight:700;padding:12px 0 0;margin-top:8px;border-top:1px solid var(--hairline);color:var(--ink);"><span x-text="$store.ui.lang==='en' ? 'Gross' : 'Kasar'">Gross</span><span style="font-family:var(--font-mono);">{{ $money($p->gross) }}</span></div>
            </div>

            {{-- Deductions --}}
            <div style="flex:1;min-width:300px;padding:22px 26px;">
                <div style="font-size:11px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:var(--muted);margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'Deductions' : 'Potongan'">Deductions</div>
                @foreach ([
                    ['EPF (employee)', 'EPF (pekerja)', $p->epf_employee],
                    ['SOCSO (employee)', 'SOCSO (pekerja)', $p->socso_employee],
                    ['EIS (employee)', 'EIS (pekerja)', $p->eis_employee],
                    ['PCB / income tax', 'PCB / cukai pendapatan', $p->pcb],
                ] as $line)
                    <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--body);"><span x-text="$store.ui.lang==='en' ? @js($line[0]) : @js($line[1])">{{ $line[0] }}</span><span style="font-family:var(--font-mono);color:var(--error);">−{{ $money($line[2]) }}</span></div>
                @endforeach
                @foreach (($p->other_deductions ?? []) as $ded)
                    <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;color:var(--body);"><span>{{ $ded['name'] }}</span><span style="font-family:var(--font-mono);color:var(--error);">−{{ $money($ded['amount']) }}</span></div>
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
            @php $tabLabelsMs = ['runs' => 'Payroll run', 'salaries' => 'Struktur gaji', 'rates' => 'Kadar berkanun']; @endphp
            @foreach (['runs' => 'Payroll runs', 'salaries' => 'Salary structures', 'rates' => 'Statutory rates'] as $id => $label)
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
                                    <div style="font-size:11.5px;color:var(--muted);margin-top:3px;"><span x-text="$store.ui.lang==='en' ? 'Employer' : 'Majikan'">Employer</span> — EPF {{ $money($ps->sum('epf_employer')) }} · SOCSO {{ $money($ps->sum('socso_employer')) }} · EIS {{ $money($ps->sum('eis_employer')) }} · <span x-text="$store.ui.lang==='en' ? 'PCB collected' : 'PCB dikutip'">PCB collected</span> {{ $money($ps->sum('pcb')) }}</div>
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
                            <div style="padding:10px 22px;background:#fff7ed;border-bottom:1px solid var(--hairline-soft);font-size:11.5px;color:#9a5b14;" x-text="$store.ui.lang==='en' ? 'Draft figures. PCB (income tax) is entered manually per employee. Verify statutory amounts before finalizing.' : 'Angka draf. PCB (cukai pendapatan) dimasukkan secara manual bagi setiap pekerja. Sahkan jumlah berkanun sebelum finalize.'">Draft figures. PCB (income tax) is entered manually per employee. Verify statutory amounts before finalizing.</div>
                        @endif

                        {{-- Payslip rows --}}
                        @foreach ($activeRun->payslips->sortBy('employee.name') as $p)
                            <div style="border-bottom:1px solid var(--hairline-soft);">
                                <div style="display:flex;align-items:center;gap:12px;padding:12px 22px;">
                                    <div style="width:30px;height:30px;border-radius:50%;background:{{ $p->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:600;flex-shrink:0;">{{ $p->employee?->initials }}</div>
                                    <div style="flex:1;min-width:0;">
                                        <a href="{{ route('app.screen', ['screen' => 'payroll', 'payslip' => $p->id]) }}" style="font-size:13px;color:var(--ink);font-weight:500;text-decoration:none;">{{ $p->employee?->name }}</a>
                                        <div style="font-size:11px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Gross' : 'Kasar'">Gross</span> {{ $money($p->gross) }} · <span x-text="$store.ui.lang==='en' ? 'Deduct' : 'Potong'">Deduct</span> {{ $money($p->total_deductions) }}@if ($p->pcb <= 0) · <span style="color:var(--amber);" x-text="$store.ui.lang==='en' ? 'PCB not set' : 'PCB belum ditetapkan'">PCB not set</span>@endif</div>
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
                                            <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:12px;margin-bottom:12px;">
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Overtime (hrs)' : 'Kerja lebih masa (jam)'">Overtime (hrs)</label><input name="overtime_hours" type="number" step="0.5" min="0" value="{{ rtrim(rtrim(number_format($p->overtime_hours, 2), '0'), '.') }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">Bonus (RM)</label><input name="bonus" type="number" step="0.01" min="0" value="{{ $p->bonus > 0 ? number_format($p->bonus, 2, '.', '') : '' }}" placeholder="0.00" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Unpaid days' : 'Hari tanpa gaji'">Unpaid days</label><input name="unpaid_days" type="number" step="0.5" min="0" max="31" value="{{ $p->unpaid_days > 0 ? rtrim(rtrim(number_format($p->unpaid_days, 2), '0'), '.') : '' }}" placeholder="0" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'PCB / tax (RM)' : 'PCB / cukai (RM)'">PCB / tax (RM)</label><input name="pcb" type="number" step="0.01" min="0" value="{{ $p->pcb > 0 ? number_format($p->pcb, 2, '.', '') : '' }}" placeholder="0.00" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                            </div>
                                            @include('partials.hint', ['tone' => 'warn', 'en' => 'These change take-home pay. PCB (income tax) is not auto-calculated — look it up in the LHDN PCB table and enter it per employee. Unpaid days reduce pay; overtime and bonus add to it.', 'ms' => 'Ini ubah gaji bersih. PCB (cukai pendapatan) tidak dikira automatik — rujuk jadual PCB LHDN dan masukkan bagi setiap pekerja. Hari tanpa gaji kurangkan gaji; overtime dan bonus tambah pada gaji.'])
                                            @php $adds = array_values($p->additions ?? []); $deds = array_values($p->other_deductions ?? []); @endphp
                                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px;">
                                                <div>
                                                    <div style="font-size:11.5px;font-weight:600;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Additions' : 'Tambahan'">Additions</div>
                                                    @for ($i = 0; $i < 2; $i++)
                                                        <div style="display:flex;gap:6px;margin-bottom:6px;"><input name="add_name[]" value="{{ $adds[$i]['name'] ?? '' }}" placeholder="e.g. Travel allowance" :placeholder="$store.ui.lang==='en' ? 'e.g. Travel allowance' : 'cth. Elaun perjalanan'" style="flex:2;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;outline:none;" /><input name="add_amount[]" type="number" step="0.01" min="0" value="{{ isset($adds[$i]) ? number_format($adds[$i]['amount'], 2, '.', '') : '' }}" placeholder="0.00" style="flex:1;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" /></div>
                                                    @endfor
                                                </div>
                                                <div>
                                                    <div style="font-size:11.5px;font-weight:600;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Other deductions' : 'Potongan lain'">Other deductions</div>
                                                    @for ($i = 0; $i < 2; $i++)
                                                        <div style="display:flex;gap:6px;margin-bottom:6px;"><input name="ded_name[]" value="{{ $deds[$i]['name'] ?? '' }}" placeholder="e.g. Salary advance" :placeholder="$store.ui.lang==='en' ? 'e.g. Salary advance' : 'cth. Pendahuluan gaji'" style="flex:2;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;outline:none;" /><input name="ded_amount[]" type="number" step="0.01" min="0" value="{{ isset($deds[$i]) ? number_format($deds[$i]['amount'], 2, '.', '') : '' }}" placeholder="0.00" style="flex:1;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" /></div>
                                                    @endfor
                                                </div>
                                            </div>
                                            <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Recalculate & save' : 'Kira semula & simpan'">Recalculate & save</button>
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
                    @php $s = $e->salaryStructure; @endphp
                    <div style="border-bottom:1px solid var(--hairline-soft);">
                        <div style="display:flex;align-items:center;gap:12px;padding:12px 22px;">
                            <div style="width:30px;height:30px;border-radius:50%;background:{{ $e->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:600;flex-shrink:0;">{{ $e->initials }}</div>
                            <div style="flex:1;min-width:0;"><div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $e->name }}</div><div style="font-size:11px;color:var(--muted);">{{ $e->position }}</div></div>
                            <div style="text-align:right;">
                                @if ($s)<div style="font-size:13px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ $money($s->basic_salary) }}</div><div style="font-size:10.5px;color:var(--muted);">+ {{ $money($s->allowancesTotal()) }} <span x-text="$store.ui.lang==='en' ? 'allowances' : 'elaun'">allowances</span></div>
                                @else<span class="uj-pill" style="background:var(--red-tint);color:var(--amber);" x-text="$store.ui.lang==='en' ? 'Not set' : 'Belum ditetapkan'">Not set</span>@endif
                            </div>
                            <button @click="salaryFor === {{ $e->id }} ? salaryFor = null : salaryFor = {{ $e->id }}" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? @js($s ? 'Edit' : 'Set') : @js($s ? 'Sunting' : 'Tetapkan')">{{ $s ? 'Edit' : 'Set' }}</button>
                        </div>
                        <div x-show="salaryFor === {{ $e->id }}" x-cloak style="padding:4px 22px 18px 64px;">
                            <form method="post" action="{{ route('payroll.salary') }}" style="background:var(--canvas);border:1px solid var(--hairline);border-radius:10px;padding:16px;">
                                @csrf
                                <input type="hidden" name="employee_id" value="{{ $e->id }}" />
                                @php $alw = array_values($s->allowances ?? []); @endphp
                                <div style="display:flex;gap:12px;align-items:flex-end;margin-bottom:12px;flex-wrap:wrap;">
                                    <div style="flex:1;min-width:160px;"><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Basic salary (RM / month)' : 'Gaji pokok (RM / bulan)'">Basic salary (RM / month)</label><input name="basic_salary" type="number" step="0.01" min="0" required value="{{ $s ? number_format($s->basic_salary, 2, '.', '') : '' }}" placeholder="0.00" style="width:100%;height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" />@include('partials.hint', ['tone' => 'warn', 'en' => 'Gross monthly basic. This drives every payslip and all EPF / SOCSO / EIS amounts — double-check before saving.', 'ms' => 'Gaji pokok bulanan kasar. Ini mempengaruhi setiap payslip dan semua jumlah EPF / SOCSO / EIS — semak dua kali sebelum simpan.'])</div>
                                    <div style="flex:1;min-width:160px;"><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Effective from' : 'Berkuat kuasa dari'">Effective from</label><input name="effective_from" type="date" value="{{ $s?->effective_from?->toDateString() ?? now()->toDateString() }}" style="width:100%;height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;outline:none;" /></div>
                                </div>
                                <div style="font-size:11.5px;font-weight:600;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Fixed allowances' : 'Elaun tetap'">Fixed allowances</div>
                                @for ($i = 0; $i < 3; $i++)
                                    <div style="display:flex;gap:6px;margin-bottom:6px;max-width:420px;"><input name="alw_name[]" value="{{ $alw[$i]['name'] ?? '' }}" placeholder="e.g. Transport" :placeholder="$store.ui.lang==='en' ? 'e.g. Transport' : 'cth. Pengangkutan'" style="flex:2;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;outline:none;" /><input name="alw_amount[]" type="number" step="0.01" min="0" value="{{ isset($alw[$i]) ? number_format($alw[$i]['amount'], 2, '.', '') : '' }}" placeholder="0.00" style="flex:1;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" /></div>
                                @endfor
                                <div style="font-size:11.5px;font-weight:600;color:var(--ink);margin:14px 0 6px;"><span x-text="$store.ui.lang==='en' ? 'Payment & statutory identifiers' : 'Pengenalan bayaran & berkanun'">Payment &amp; statutory identifiers</span> <span style="font-weight:400;color:var(--muted);" x-text="$store.ui.lang==='en' ? '— used for the bank file & EPF/SOCSO/EIS reports' : '— digunakan untuk fail bank & laporan EPF/SOCSO/EIS'">— used for the bank file &amp; EPF/SOCSO/EIS reports</span></div>
                                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;max-width:560px;">
                                    <input name="bank_name" value="{{ $s?->bank_name }}" placeholder="Bank (e.g. Maybank)" :placeholder="$store.ui.lang==='en' ? 'Bank (e.g. Maybank)' : 'Bank (cth. Maybank)'" style="height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;outline:none;" />
                                    <input name="bank_account_no" value="{{ $s?->bank_account_no }}" placeholder="Bank account no" :placeholder="$store.ui.lang==='en' ? 'Bank account no' : 'No akaun bank'" style="height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" />
                                    <input name="epf_no" value="{{ $s?->epf_no }}" placeholder="EPF / KWSP no" :placeholder="$store.ui.lang==='en' ? 'EPF / KWSP no' : 'No EPF / KWSP'" style="height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" />
                                    <input name="socso_no" value="{{ $s?->socso_no }}" placeholder="SOCSO / PERKESO no" :placeholder="$store.ui.lang==='en' ? 'SOCSO / PERKESO no' : 'No SOCSO / PERKESO'" style="height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" />
                                    <input name="nric" value="{{ $s?->nric }}" placeholder="NRIC" style="height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" />
                                </div>
                                <div style="margin-top:12px;"><button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Save structure' : 'Simpan struktur'">Save structure</button><button type="button" @click="salaryFor = null" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button></div>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ════ TAB: Statutory rates ════ --}}
        <div x-show="tab === 'rates'" x-cloak>
            <div class="uj-card" style="max-width:720px;padding:24px;">
                <h3 class="uj-card-title" style="margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Statutory contribution rates' : 'Kadar caruman berkanun'">Statutory contribution rates</h3>
                @php $brackets = \App\Services\Payroll\StatutoryBrackets::class; @endphp
                <div style="display:flex;gap:8px;align-items:flex-start;background:#fff7ed;border:1px solid #f1c98a;border-radius:9px;padding:11px 14px;margin-bottom:18px;font-size:12px;color:#9a5b14;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="flex-shrink:0;margin-top:1px;"><path d="M12 9v4M12 17h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                    <span x-show="$store.ui.lang==='en'">SOCSO &amp; EIS now use the PERKESO <strong>stepped bracket schedule</strong> (effective {{ $brackets::SCHEDULE_EFFECTIVE }}), split by contribution category from each employee's date of birth (≥60 → SOCSO Category 2, no EIS). The percentage fields below are the <strong>fallback</strong> only — used if bracket mode is cleared.
                        @if ($brackets::IS_PLACEHOLDER)<strong style="color:#b91c1c;"> Bracket amounts are PLACEHOLDER (generated, not the official figures) — transcribe the official PERKESO Jadual Caruman before any real statutory filing.</strong>@endif
                        EPF is an exact percentage. <strong>Verify against the official KWSP / PERKESO tables before running real payroll.</strong></span>
                    <span x-show="$store.ui.lang==='ms'" x-cloak>SOCSO &amp; EIS kini guna <strong>jadual bracket berperingkat</strong> PERKESO (berkuat kuasa {{ $brackets::SCHEDULE_EFFECTIVE }}), dibahagi mengikut kategori caruman daripada tarikh lahir setiap pekerja (≥60 → SOCSO Kategori 2, tiada EIS). Medan peratus di bawah ialah <strong>sandaran</strong> sahaja — digunakan jika mod bracket dikosongkan.
                        @if ($brackets::IS_PLACEHOLDER)<strong style="color:#b91c1c;"> Amaun bracket ialah PLACEHOLDER (dijana, bukan angka rasmi) — salin Jadual Caruman PERKESO rasmi sebelum sebarang pemfailan berkanun sebenar.</strong>@endif
                        EPF ialah peratusan tepat. <strong>Sahkan dengan jadual KWSP / PERKESO rasmi sebelum menjalankan payroll sebenar.</strong></span>
                </div>
                <form method="post" action="{{ route('payroll.rates') }}">
                    @csrf
                    <div style="margin-bottom:18px;">
                        <div style="font-size:12px;font-weight:700;color:var(--ink);margin-bottom:8px;">EPF (KWSP)</div>
                        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;">
                            @foreach ([
                                ['epf_employee_pct', 'Employee %', 'Pekerja %', $rates['epf']['employee_pct']],
                                ['epf_employer_pct_below', 'Employer % (≤ threshold)', 'Majikan % (≤ ambang)', $rates['epf']['employer_pct_below']],
                                ['epf_employer_pct_above', 'Employer % (> threshold)', 'Majikan % (> ambang)', $rates['epf']['employer_pct_above']],
                                ['epf_threshold', 'Threshold (RM)', 'Ambang (RM)', $rates['epf']['threshold']],
                            ] as $f)
                                <div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? @js($f[1]) : @js($f[2])">{{ $f[1] }}</label><input name="{{ $f[0] }}" type="number" step="0.01" min="0" required value="{{ $f[3] }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" />@error($f[0])<div style="font-size:10.5px;color:var(--error);margin-top:3px;">{{ $message }}</div>@enderror</div>
                            @endforeach
                        </div>
                    </div>
                    <div style="margin-bottom:18px;">
                        <div style="font-size:12px;font-weight:700;color:var(--ink);margin-bottom:8px;">SOCSO (PERKESO)</div>
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">
                            @foreach ([
                                ['socso_employer_pct', 'Employer %', 'Majikan %', $rates['socso']['employer_pct']],
                                ['socso_employee_pct', 'Employee %', 'Pekerja %', $rates['socso']['employee_pct']],
                                ['socso_ceiling', 'Wage ceiling (RM)', 'Siling gaji (RM)', $rates['socso']['wage_ceiling']],
                            ] as $f)
                                <div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? @js($f[1]) : @js($f[2])">{{ $f[1] }}</label><input name="{{ $f[0] }}" type="number" step="0.01" min="0" required value="{{ $f[3] }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                            @endforeach
                        </div>
                    </div>
                    <div style="margin-bottom:20px;">
                        <div style="font-size:12px;font-weight:700;color:var(--ink);margin-bottom:8px;">EIS (SIP)</div>
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">
                            @foreach ([
                                ['eis_employer_pct', 'Employer %', 'Majikan %', $rates['eis']['employer_pct']],
                                ['eis_employee_pct', 'Employee %', 'Pekerja %', $rates['eis']['employee_pct']],
                                ['eis_ceiling', 'Wage ceiling (RM)', 'Siling gaji (RM)', $rates['eis']['wage_ceiling']],
                            ] as $f)
                                <div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? @js($f[1]) : @js($f[2])">{{ $f[1] }}</label><input name="{{ $f[0] }}" type="number" step="0.01" min="0" required value="{{ $f[3] }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                            @endforeach
                        </div>
                    </div>
                    <div style="margin-bottom:20px;padding-top:6px;border-top:1px solid var(--hairline-soft);">
                        <div style="font-size:12px;font-weight:700;color:var(--ink);margin:12px 0 8px;" x-text="$store.ui.lang==='en' ? 'PCB / income tax (MTD)' : 'PCB / cukai pendapatan (MTD)'">PCB / income tax (MTD)</div>
                        <label style="display:flex;align-items:center;gap:9px;font-size:12.5px;color:var(--ink);margin-bottom:10px;cursor:pointer;">
                            <input type="checkbox" name="pcb_auto" value="1" @checked(! empty($rates['pcb']['auto'])) style="width:16px;height:16px;" />
                            <span x-text="$store.ui.lang==='en' ? 'Auto-calculate PCB on new runs (estimate — overridable per payslip)' : 'Kira PCB automatik pada run baharu (anggaran — boleh ditindih setiap payslip)'">Auto-calculate PCB on new runs (estimate — overridable per payslip)</span>
                        </label>
                        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;max-width:360px;">
                            @foreach ([
                                ['pcb_individual_relief', 'Annual individual relief (RM)', 'Pelepasan individu tahunan (RM)', $rates['pcb']['individual_relief'] ?? 9000],
                                ['pcb_epf_relief_cap', 'Annual EPF relief cap (RM)', 'Had pelepasan EPF tahunan (RM)', $rates['pcb']['epf_relief_cap'] ?? 4000],
                            ] as $f)
                                <div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? @js($f[1]) : @js($f[2])">{{ $f[1] }}</label><input name="{{ $f[0] }}" type="number" step="0.01" min="0" value="{{ $f[3] }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                            @endforeach
                        </div>
                        <div style="font-size:11px;color:#9a5b14;margin-top:8px;"><span x-text="$store.ui.lang==='en' ? 'Estimate only — simplified annualised method, not LHDN\'s full MTD computation. HR must review each PCB before finalizing.' : 'Anggaran sahaja — kaedah tahunan ringkas, bukan pengiraan MTD penuh LHDN. HR mesti semak setiap PCB sebelum finalize.'">Estimate only — simplified annualised method, not LHDN's full MTD computation. HR must review each PCB before finalizing.</span>@if (\App\Services\Payroll\PcbCalculator::IS_PLACEHOLDER) <span x-text="$store.ui.lang==='en' ? 'Verify the tax bands against the current LHDN schedule.' : 'Sahkan jaluran cukai dengan jadual LHDN semasa.'">Verify the tax bands against the current LHDN schedule.</span>@endif</div>
                    </div>
                    <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 20px;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'Save rates' : 'Simpan kadar'">Save rates</button>
                    <span style="font-size:12px;color:var(--muted);margin-left:10px;" x-text="$store.ui.lang==='en' ? 'Applies to the next recalculation, not already-finalized runs.' : 'Terpakai pada pengiraan semula seterusnya, bukan run yang sudah difinalize.'">Applies to the next recalculation, not already-finalized runs.</span>
                </form>
            </div>
        </div>
    </div>
@endif
@endsection
