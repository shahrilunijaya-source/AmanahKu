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
            @php $tabLabelsMs = ['runs' => 'Payroll run', 'salaries' => 'Struktur gaji', 'opening' => 'Pekerjaan sebelum ini (TP3)']; @endphp
            @foreach (['runs' => 'Payroll runs', 'salaries' => 'Salary structures', 'opening' => 'Previous employment (TP3)'] as $id => $label)
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
                                            <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:12px;margin-bottom:12px;">
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Overtime (hrs)' : 'Kerja lebih masa (jam)'">Overtime (hrs)</label><input name="overtime_hours" type="number" step="0.5" min="0" value="{{ rtrim(rtrim(number_format($p->overtime_hours, 2), '0'), '.') }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">Bonus (RM)</label><input name="bonus" type="number" step="0.01" min="0" value="{{ $p->bonus > 0 ? number_format($p->bonus, 2, '.', '') : '' }}" placeholder="0.00" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Unpaid days' : 'Hari tanpa gaji'">Unpaid days</label><input name="unpaid_days" type="number" step="0.5" min="0" max="31" value="{{ $p->unpaid_days > 0 ? rtrim(rtrim(number_format($p->unpaid_days, 2), '0'), '.') : '' }}" placeholder="0" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'PCB override (RM)' : 'Tindihan PCB (RM)'">PCB override (RM)</label><input name="pcb_override" type="number" step="0.01" min="0" value="{{ $p->pcb_override !== null ? number_format($p->pcb_override, 2, '.', '') : '' }}" placeholder="{{ number_format($p->pcb, 2, '.', '') }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                            </div>
                                            @include('partials.hint', ['tone' => 'warn', 'en' => 'PCB (income tax) is computed automatically from the LHDN method and each employee\'s statutory profile — leave the override blank to use it. Filling in a figure here overrides the computed PCB and sticks until cleared. Unpaid days reduce pay; overtime and bonus add to it (bonus gets its own PCB figure).', 'ms' => 'PCB (cukai pendapatan) dikira automatik mengikut kaedah LHDN dan profil berkanun setiap pekerja — biarkan tindihan kosong untuk guna nilai itu. Mengisi angka di sini akan menindih PCB yang dikira dan kekal sehingga dikosongkan. Hari tanpa gaji kurangkan gaji; overtime dan bonus tambah pada gaji (bonus ada angka PCB tersendiri).'])
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
                                        <select name="marital_status" style="width:100%;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;outline:none;">
                                            @foreach (['single' => ['Single', 'Bujang'], 'married' => ['Married', 'Berkahwin'], 'divorced' => ['Divorced', 'Bercerai'], 'widowed' => ['Widowed', 'Balu/Duda']] as $val => $lbl)
                                                <option value="{{ $val }}" @selected(($s?->marital_status ?? 'single') === $val) x-text="$store.ui.lang==='en' ? @js($lbl[0]) : @js($lbl[1])">{{ $lbl[0] }}</option>
                                            @endforeach
                                        </select>
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
    </div>
@endif
@endsection
