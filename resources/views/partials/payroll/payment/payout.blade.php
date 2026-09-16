@php
    $statusColor = ['draft' => 'var(--amber)', 'approved' => 'var(--info)', 'finalized' => 'var(--success)'];
    $statusMs = ['draft' => 'Draf', 'approved' => 'Diluluskan', 'finalized' => 'Difinalize'];
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
@endphp
<form method="get" action="{{ route('app.screen', 'payroll-payment') }}" style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
    <input type="hidden" name="tab" value="payout">
    <label style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Year' : 'Tahun'">Year</label>
    <input name="year" type="number" min="2020" max="2100" value="{{ $payoutYear }}" onchange="this.form.submit()" style="width:90px;height:32px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;">
</form>
<div class="uj-card" style="padding:0;">
    @forelse ($payoutRuns as $r)
        @php $t = $r->totals; @endphp
        <div style="border-top:1px solid var(--hairline-soft);padding:14px 20px;{{ $activeRun && request()->filled('run') && $activeRun->id === $r->id ? 'background:var(--canvas);' : '' }}">
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <div style="min-width:120px;"><div style="font-size:13.5px;color:var(--ink);font-weight:600;">{{ $r->label }}</div><div style="font-size:11px;color:var(--muted);">{{ $r->payslips_count }} <span x-text="$store.ui.lang==='en' ? 'staff' : 'staf'">staff</span></div></div>
                <span class="uj-pill" style="background:#fff;border:1px solid var(--hairline);color:{{ $statusColor[$r->status] ?? 'var(--muted)' }};text-transform:capitalize;font-size:10.5px;" x-text="$store.ui.lang==='en' ? @js($r->status) : @js($statusMs[$r->status] ?? $r->status)">{{ $r->status }}</span>
                <span style="font-family:var(--font-mono);font-size:13px;color:var(--ink);"><span x-text="$store.ui.lang==='en' ? 'Net' : 'Bersih'">Net</span> {{ $money($t['net'] ?? 0) }}</span>
                <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    @if ($r->status === 'draft')
                        <form method="post" action="{{ route('payroll.runs.approve', $r) }}">@csrf<button class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Approve' : 'Luluskan'">Approve</button></form>
                    @endif
                    @if (in_array($r->status, ['draft', 'approved'], true))
                        <form method="post" action="{{ route('payroll.runs.finalize', $r) }}" onsubmit="return confirm(window.Alpine && Alpine.store('ui').lang==='ms' ? @js('Finalize '.$r->label.'? Payslip dikunci, pekerja dimaklumkan, dan tuntutan yang dibayar balik ditanda sebagai paid.') : @js('Finalize '.$r->label.'? Payslips lock, employees are notified, and reimbursed claims are marked paid.'));">@csrf<button class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Finalize & issue' : 'Finalize & keluarkan'">Finalize & issue</button></form>
                        <form method="post" action="{{ route('payroll.runs.delete', $r) }}" onsubmit="return confirm(window.Alpine && Alpine.store('ui').lang==='ms' ? @js('Padam draft run '.$r->label.'? Semua payslip draf dalamnya turut dipadam. Tindakan ini tidak boleh dibatalkan.') : @js('Delete draft run '.$r->label.'? Every draft payslip in it is deleted too. This cannot be undone.'));">@csrf<button class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;color:var(--error);" x-text="$store.ui.lang==='en' ? 'Delete run' : 'Padam run'">Delete run</button></form>
                    @else
                        <span class="uj-pill" style="background:var(--red-tint);color:var(--success);"><span x-text="$store.ui.lang==='en' ? 'Finalized' : 'Difinalize'">Finalized</span> {{ $r->finalized_at?->format('j M') }}</span>
                    @endif
                    <a href="{{ route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'submission', 'run' => $r->id]) }}" style="font-size:12px;color:var(--red);text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Files' : 'Fail'">Files</a>
                </div>
            </div>
            @if ($r->status === 'finalized' && $isManagementTier)
                <div x-data="{ deleting: false }" style="display:flex;align-items:center;gap:6px;margin-top:10px;">
                    <button type="button" @click="deleting = !deleting" class="uj-btn-ghost" style="height:28px;padding:0 10px;font-size:11px;color:var(--error);" x-text="$store.ui.lang==='en' ? (deleting ? 'Cancel' : 'Delete finalized run…') : (deleting ? 'Batal' : 'Padam run difinalize…')">Delete finalized run…</button>
                    <div x-show="deleting" x-cloak><form method="post" action="{{ route('payroll.runs.delete', $r) }}" onsubmit="return confirm(window.Alpine && Alpine.store('ui').lang==='ms' ? @js('Padam run yang telah difinalize? Ini akan menyahtandakan tuntutan yang dibayar dan permintaan OT/cuti tanpa gaji sebagai belum dibayar, supaya ia boleh diambil semula oleh run akan datang. Tidak boleh dibatalkan.') : @js('Delete a FINALIZED run? This un-marks paid claims and OT/unpaid-leave requests as unpaid again so a future run can pick them up. Cannot be undone.'));" style="display:flex;align-items:center;gap:6px;">
                        @csrf
                        <input name="confirm_period" required placeholder="{{ $r->period }}" style="height:28px;width:110px;padding:0 8px;border:1px solid var(--error);border-radius:6px;font-size:11.5px;font-family:var(--font-mono);" />
                        <button type="submit" class="uj-btn-primary" style="height:28px;padding:0 10px;font-size:11px;background:var(--error);border-color:var(--error);" x-text="$store.ui.lang==='en' ? 'Confirm delete' : 'Sahkan padam'">Confirm delete</button>
                    </form></div>
                </div>
                @include('partials.hint', ['tone' => 'warn', 'en' => 'Type the period exactly (e.g. '.$r->period.') to confirm. This reverses what finalizing consumed — claims go back to approved, and pulled overtime/unpaid-leave requests become pullable again.', 'ms' => 'Taip tempoh dengan tepat (cth. '.$r->period.') untuk sahkan. Ini membalikkan apa yang finalize telah guna — tuntutan kembali ke approved, dan permintaan OT/cuti tanpa gaji yang ditarik boleh ditarik semula.'])
            @endif
        </div>
    @empty
        <div style="padding:22px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'No payroll runs in this year.' : 'Tiada run gaji dalam tahun ini.'">No payroll runs in this year.</div>
    @endforelse
</div>
