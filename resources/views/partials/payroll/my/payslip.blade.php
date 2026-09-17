{{-- My Payroll, Payslip tab: own issued payslips, one opened with ?payslip=. HR opens other people's slips on Payroll Review. --}}
@php
    $statusColor = ['draft' => 'var(--amber)', 'approved' => 'var(--info)', 'finalized' => 'var(--success)'];
    $statusMs = ['draft' => 'Draf', 'approved' => 'Diluluskan', 'finalized' => 'Difinalize'];
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
    $me = request()->attributes->get('employee');
    $ownSlip = ! empty($selectedPayslip) && $me && $selectedPayslip->employee_id === $me->id && $selectedPayslip->payrollRun?->status === 'finalized';
@endphp
@if ($ownSlip)
    <a href="{{ route('app.screen', 'payroll-my') }}" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--muted);text-decoration:none;margin-bottom:16px;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        <span x-text="$store.ui.lang==='en' ? 'Back to my payslips' : 'Kembali ke slip gaji saya'">Back to my payslips</span>
    </a>

    @include('partials.payroll.payslip-detail', ['p' => $selectedPayslip, 'ackable' => true])

@else

    {{-- ─── Employee view: my payslips ─────────────────────────────── --}}
    <div class="uj-card" style="max-width:680px;">
        <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'My payslips' : 'Payslip saya'">My payslips</h3></div>
        @forelse ($myPayslips as $p)
            <a href="{{ route('app.screen', ['screen' => 'payroll-my', 'payslip' => $p->id]) }}" style="display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 20px;border-bottom:1px solid var(--hairline-soft);text-decoration:none;">
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
@endif
