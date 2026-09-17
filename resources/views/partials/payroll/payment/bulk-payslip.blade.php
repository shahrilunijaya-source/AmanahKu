<form method="get" action="{{ route('app.screen', 'payroll-payment') }}" style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
    <input type="hidden" name="tab" value="bulk-payslip">
    <label style="font-size:12.5px;color:var(--muted);">Run</label>
    <select name="run" onchange="this.form.submit()" style="height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;">
        @foreach ($runs as $r)<option value="{{ $r->id }}" @selected($activeRun?->id === $r->id)>{{ $r->label }} · {{ $r->status }}</option>@endforeach
    </select>
</form>
@if (! $activeRun)
    <div class="uj-card" style="padding:22px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'No payroll run yet. Create one under Process.' : 'Belum ada run gaji. Buat satu di bawah Proses.'">No payroll run yet. Create one under Process.</div>
@else
<div class="uj-card" style="max-width:520px;padding:22px;">
    <h3 class="uj-card-title" style="margin-bottom:6px;">Bulk Pay Slip</h3>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px;" x-text="$store.ui.lang==='en' ? 'Every payslip in this run as one PDF.' : 'Semua slip gaji dalam run ini sebagai satu PDF.'">Every payslip in this run as one PDF.</p>
    @if ($activeRun->status === 'finalized')
        <a href="{{ route('payroll.export.payslips-pdf', $activeRun) }}" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;display:inline-flex;align-items:center;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Download all' : 'Muat turun semua'">Download all</a>
    @else
        <div style="font-size:12px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Available once this run is finalized.' : 'Tersedia setelah run ini dimuktamadkan.'">Available once this run is finalized.</div>
    @endif
</div>
@endif
