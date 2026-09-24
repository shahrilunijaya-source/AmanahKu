@php
    $statusColor = ['draft' => 'var(--amber)', 'approved' => 'var(--info)', 'finalized' => 'var(--success)'];
    $statusMs = ['draft' => 'Draf', 'approved' => 'Diluluskan', 'finalized' => 'Difinalize'];
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
    $cycleLabels = ['monthly' => ['Month End', 'Akhir Bulan'], 'mid_month' => ['Mid Month', 'Pertengahan Bulan'], 'bonus' => ['Bonus', 'Bonus'], 'final' => ['Final pay', 'Gaji akhir']];
@endphp
@include('partials.payroll.process.wizard')
<div class="uj-card" style="padding:0;overflow-x:auto;">
    <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Payroll runs' : 'Run gaji'">Payroll runs</h3></div>
    <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
        <thead><tr style="color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.5px;">
            <th style="text-align:left;padding:10px 18px;" x-text="$store.ui.lang==='en' ? 'Period' : 'Tempoh'">Period</th>
            <th style="text-align:left;padding:10px 8px;" x-text="$store.ui.lang==='en' ? 'Cycle' : 'Kitaran'">Cycle</th>
            <th style="text-align:left;padding:10px 8px;">Status</th>
            <th style="text-align:right;padding:10px 8px;" x-text="$store.ui.lang==='en' ? 'Staff' : 'Staf'">Staff</th>
            <th style="text-align:right;padding:10px 8px;" x-text="$store.ui.lang==='en' ? 'Net' : 'Bersih'">Net</th>
            <th style="padding:10px 18px;"></th>
        </tr></thead>
        <tbody>
        @forelse ($runs as $r)
            <tr style="border-top:1px solid var(--hairline-soft);">
                <td style="padding:12px 18px;font-weight:500;color:var(--ink);">{{ $r->label }}</td>
                @php [$cycleEn, $cycleMs] = $cycleLabels[$r->kind] ?? [$r->kind, $r->kind]; @endphp
                <td style="padding:12px 8px;" x-text="$store.ui.lang==='en' ? @js($cycleEn) : @js($cycleMs)">{{ $cycleEn }}</td>
                <td style="padding:12px 8px;"><span class="uj-pill" style="background:#fff;border:1px solid var(--hairline);color:{{ $statusColor[$r->status] ?? 'var(--muted)' }};text-transform:capitalize;font-size:10.5px;" x-text="$store.ui.lang==='en' ? @js($r->status) : @js($statusMs[$r->status] ?? $r->status)">{{ $r->status }}</span></td>
                <td style="padding:12px 8px;text-align:right;">{{ $r->payslips_count }}</td>
                <td style="padding:12px 8px;text-align:right;font-family:var(--font-mono);">{{ $money($r->totals['net'] ?? 0) }}</td>
                <td style="padding:12px 18px;text-align:right;white-space:nowrap;">
                    <a href="{{ route('app.screen', ['screen' => 'payroll-review', 'tab' => 'individual', 'run' => $r->id]) }}" style="color:var(--red);font-size:12px;text-decoration:none;margin-right:10px;" x-text="$store.ui.lang==='en' ? 'Review' : 'Semak'">Review</a>
                    <a href="{{ route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'payout', 'run' => $r->id]) }}" style="color:var(--red);font-size:12px;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Payment' : 'Bayaran'">Payment</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" style="padding:20px 18px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No payroll runs yet. Create one to begin.' : 'Belum ada run gaji. Buat satu untuk mula.'">No payroll runs yet. Create one to begin.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
