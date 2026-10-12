@php
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
@endphp
@include('partials.payroll.run-picker', ['screen' => 'payroll-payment', 'tab' => 'payslip'])
@if (! $activeRun)
    <div class="uj-card" style="padding:22px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'No payroll run yet. Create one under Process.' : 'Belum ada run gaji. Buat satu di bawah Proses.'">No payroll run yet. Create one under Process.</div>
@else
<div class="uj-card" style="max-width:720px;padding:0;">
    @if ($activeRun->status !== 'finalized')
        <div style="font-size:12px;color:var(--muted);padding:12px 20px;" x-text="$store.ui.lang==='en' ? 'Payslip PDFs are available once this run is finalized.' : 'PDF slip gaji tersedia setelah run ini dimuktamadkan.'">Payslip PDFs are available once this run is finalized.</div>
    @endif
    @foreach ($activeRun->payslips->sortBy('employee.name') as $p)
        <div style="display:flex;align-items:center;gap:12px;padding:12px 20px;border-top:1px solid var(--hairline-soft);">
            <div style="flex:1;font-size:13px;color:var(--ink);font-weight:500;">{{ $p->employee?->name }}</div>
            <span style="font-family:var(--font-mono);font-size:13px;">{{ $money($p->net_pay) }}</span>
            @if ($activeRun->status === 'finalized')
                <a href="{{ route('payroll.payslips.pdf', $p) }}" class="uj-btn-ghost" style="height:30px;padding:0 10px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;">PDF</a>
            @endif
        </div>
    @endforeach
</div>
@endif
