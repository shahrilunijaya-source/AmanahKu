@php
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
@endphp
<form method="get" action="{{ route('app.screen', 'payroll-payment') }}" style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
    <input type="hidden" name="tab" value="submission">
    <label style="font-size:12.5px;color:var(--muted);">Run</label>
    <select name="run" onchange="this.form.submit()" style="height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;">
        @foreach ($runs as $r)<option value="{{ $r->id }}" @selected($activeRun?->id === $r->id)>{{ $r->label }} · {{ $r->status }}</option>@endforeach
    </select>
</form>
@if (! $activeRun)
    <div class="uj-card" style="padding:22px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'No payroll run yet. Create one under Process.' : 'Belum ada run gaji. Buat satu di bawah Proses.'">No payroll run yet. Create one under Process.</div>
@else
@php $ps = $activeRun->payslips; @endphp
<div class="uj-card" style="max-width:820px;padding:20px;">
    @if ($activeRun->status !== 'finalized')
        <div style="font-size:12px;color:var(--muted);margin-bottom:10px;" x-text="$store.ui.lang==='en' ? 'Files are available once this run is finalized.' : 'Fail tersedia setelah run ini dimuktamadkan.'">Files are available once this run is finalized.</div>
    @endif
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:16px;">
        @foreach (['Net' => $ps->sum('net_pay'), 'EPF' => $ps->sum('epf_employee') + $ps->sum('epf_employer'), 'SOCSO' => $ps->sum('socso_employee') + $ps->sum('socso_employer'), 'EIS' => $ps->sum('eis_employee') + $ps->sum('eis_employer'), 'PCB' => $ps->sum('pcb') + $ps->sum('pcb_additional')] as $k => $v)
            <div><div style="font-size:11px;color:var(--muted);text-transform:uppercase;">{{ $k }}</div><div style="font-family:var(--font-mono);font-size:14px;color:var(--ink);">{{ $money($v) }}</div></div>
        @endforeach
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        @if ($activeRun->status === 'finalized')
            <form method="get" action="{{ route('payroll.export.bank', $activeRun) }}" style="display:inline-flex;align-items:center;gap:6px;">
                <select name="format" style="height:36px;padding:0 8px;border:1px solid var(--hairline);border-radius:8px;font-size:12px;background:#fff;color:var(--ink);">
                    @foreach (\App\Services\Payroll\BankFile\BankFileRegistry::options() as $k => $lbl)<option value="{{ $k }}">{{ $lbl }}</option>@endforeach
                </select>
                <button type="submit" class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Bank file' : 'Fail bank'">Bank file</button>
            </form>
            <a href="{{ route('payroll.export.statutory', $activeRun) }}" class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Statutory report' : 'Laporan berkanun'">Statutory report</a>
        @endif
        @foreach (['KWSP Form A', 'PERKESO 8A', 'LHDN CP39'] as $f)
            <button type="button" disabled class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;opacity:.55;cursor:not-allowed;">{{ $f }} <span class="uj-pill" style="margin-left:6px;font-size:10px;">Spec F6</span></button>
        @endforeach
    </div>
</div>
@endif
