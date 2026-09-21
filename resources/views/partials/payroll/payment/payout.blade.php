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
                <span style="font-size:12px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Paid on' : 'Dibayar pada'">Paid on</span> <span style="color:var(--ink);">{{ $r->payment_date?->format('j M Y') ?? '—' }}</span></span>
                <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    @if ($r->status === 'draft')
                        <form method="post" action="{{ route('payroll.runs.approve', $r) }}">@csrf<button class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Approve' : 'Luluskan'">Approve</button></form>
                    @endif
                    @if (in_array($r->status, ['draft', 'approved'], true))
                        @php $payBy = $r->payByDate()->toDateString(); $payDefault = old('payment_date', $r->payment_date?->toDateString() ?? $r->payByDate()->subDays(7)->toDateString()); @endphp
                        <form method="post" action="{{ route('payroll.runs.finalize', $r) }}" x-data="{ late: @js($payDefault > $payBy) }" style="display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;" onsubmit="return confirm(window.Alpine && Alpine.store('ui').lang==='ms' ? @js('Finalize '.$r->label.'? Payslip dikunci dan tuntutan yang dibayar balik ditanda sebagai paid. Staf hanya nampak payslip selepas diterbitkan.') : @js('Finalize '.$r->label.'? Payslips lock and reimbursed claims are marked paid. Staff see nothing until the run is published.'));">@csrf
                            <div>
                                <label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Pay date' : 'Tarikh bayaran'">Pay date</label>
                                <input name="payment_date" type="date" required value="{{ $payDefault }}" @change="late = $event.target.value > @js($payBy)" style="width:150px;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;" />
                            </div>
                            <div x-show="late" x-cloak>
                                <label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Reason for paying after the seventh day (EA s.19)' : 'Sebab bayar selepas hari ketujuh (AK s.19)'">Reason for paying after the seventh day (EA s.19)</label>
                                <input name="pay_date_override_reason" maxlength="240" value="{{ old('pay_date_override_reason') }}" style="width:260px;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;" />
                            </div>
                            <label style="display:flex;align-items:center;gap:6px;height:36px;font-size:12px;color:var(--muted);">
                                <input type="checkbox" name="publish_now" value="1">
                                <span x-text="$store.ui.lang==='en' ? 'Publish to staff now' : 'Terbit kepada staf sekarang'">Publish to staff now</span>
                            </label>
                            <button class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Finalize & issue' : 'Finalize & keluarkan'">Finalize & issue</button>
                        </form>
                        @error('payment_date')<div style="flex-basis:100%;font-size:12px;color:var(--error);">{{ $message }}</div>@enderror
                        <form method="post" action="{{ route('payroll.runs.delete', $r) }}" onsubmit="return confirm(window.Alpine && Alpine.store('ui').lang==='ms' ? @js('Padam draft run '.$r->label.'? Semua payslip draf dalamnya turut dipadam. Tindakan ini tidak boleh dibatalkan.') : @js('Delete draft run '.$r->label.'? Every draft payslip in it is deleted too. This cannot be undone.'));">@csrf<button class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;color:var(--error);" x-text="$store.ui.lang==='en' ? 'Delete run' : 'Padam run'">Delete run</button></form>
                    @else
                        <span class="uj-pill" style="background:var(--red-tint);color:var(--success);"><span x-text="$store.ui.lang==='en' ? 'Finalized' : 'Difinalize'">Finalized</span> {{ $r->finalized_at?->format('j M') }}</span>
                        @if ($r->isPublished())
                            <span class="uj-pill" style="background:var(--red-tint);color:var(--success);"><span x-text="$store.ui.lang==='en' ? 'Published' : 'Diterbitkan'">Published</span> {{ $r->published_at?->format('j M') }}</span>
                        @else
                            <form method="post" action="{{ route('payroll.runs.publish', $r) }}" onsubmit="return confirm(window.Alpine && Alpine.store('ui').lang==='ms' ? @js('Terbitkan payslip '.$r->label.' kepada staf? Setiap pekerja akan dimaklumkan melalui aplikasi dan e-mel.') : @js('Publish '.$r->label.' payslips to staff? Every employee is notified in the app and by email.'));">@csrf<button class="uj-btn-primary" style="height:36px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Publish to staff' : 'Terbit kepada staf'">Publish to staff</button></form>
                        @endif
                        <span style="font-size:11.5px;color:{{ $r->paid_at === null && now()->gt($r->payByDate()) ? 'var(--error)' : 'var(--muted)' }};"><span x-text="$store.ui.lang==='en' ? 'pay by' : 'bayar sebelum'">pay by</span> {{ $r->payByDate()->format('j M Y') }}</span>
                        @if ($r->paid_at)
                            <span class="uj-pill" style="background:var(--red-tint);color:var(--success);"><span x-text="$store.ui.lang==='en' ? 'Paid' : 'Dibayar'">Paid</span> {{ $r->paid_at->format('j M Y') }}</span>
                        @else
                            <form method="post" action="{{ route('payroll.runs.mark-paid', $r) }}">@csrf<button class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Mark paid' : 'Tanda dibayar'">Mark paid</button></form>
                        @endif
                    @endif
                    <a href="{{ route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'submission', 'run' => $r->id]) }}" style="font-size:12px;color:var(--red);text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Files' : 'Fail'">Files</a>
                </div>
            </div>
            @php $ackPending = $payslipAckOutstanding[$r->id] ?? []; @endphp
            @if (($payslipAckOn ?? false) && $r->isPublished() && $ackPending !== [])
                <div style="margin-top:8px;font-size:12px;color:var(--muted);">
                    <span x-text="$store.ui.lang==='en' ? 'Not acknowledged yet:' : 'Belum diakui:'">Not acknowledged yet:</span>
                    <span style="color:var(--ink);">{{ implode(', ', $ackPending) }}</span>
                </div>
            @endif
            {{-- Spec F10/F11: a leaver's final pay waits here while the CP22A is unsettled. --}}
            @foreach ($r->payslips->where('held_for_cp22a', true) as $held)
                <div x-data="{ releasing: false }" style="margin-top:10px;padding:10px 12px;background:#fff7ed;border:1px solid var(--hairline);border-radius:8px;">
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <span class="uj-pill" style="background:#fff;border:1px solid var(--amber);color:var(--amber);font-size:10.5px;" x-text="$store.ui.lang==='en' ? 'Held · CP22A' : 'Ditahan · CP22A'">Held · CP22A</span>
                        <span style="font-size:12.5px;color:var(--ink);">{{ $held->employee?->name }}</span>
                        <span style="font-size:12px;color:var(--muted);font-family:var(--font-mono);">RM {{ number_format((float) $held->net_pay, 2) }}</span>
                        <button type="button" @click="releasing = !releasing" class="uj-btn-ghost" style="margin-left:auto;height:30px;padding:0 12px;font-size:11.5px;" x-text="$store.ui.lang==='en' ? (releasing ? 'Cancel' : 'Release final pay…') : (releasing ? 'Batal' : 'Lepaskan gaji akhir…')">Release final pay…</button>
                    </div>
                    <div x-show="releasing" x-cloak style="margin-top:8px;">
                        <form method="post" action="{{ route('payroll.payslips.release-hold', $held) }}" style="display:flex;gap:8px;flex-wrap:wrap;">@csrf
                            <input name="reason" required maxlength="240" :placeholder="$store.ui.lang==='en' ? 'Why it can be paid now (LHDN clearance, 90 days passed…)' : 'Kenapa boleh dibayar sekarang (pelepasan LHDN, 90 hari berlalu…)'" style="flex:1;min-width:240px;height:32px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12px;" />
                            <button class="uj-btn-primary" style="height:32px;padding:0 14px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Release' : 'Lepaskan'">Release</button>
                        </form>
                        @include('partials.hint', ['en' => 'Until it is released this payslip stays out of the bank file. The reason is recorded in the audit trail.', 'ms' => 'Sehingga dilepaskan, payslip ini tidak masuk fail bank. Sebab direkod dalam jejak audit.'])
                    </div>
                </div>
            @endforeach
            @foreach ($r->payslips->whereNotNull('hold_released_at') as $released)
                <div style="margin-top:8px;font-size:11.5px;color:var(--muted);">
                    <span x-text="$store.ui.lang==='en' ? 'Final pay released on' : 'Gaji akhir dilepaskan pada'">Final pay released on</span>
                    <span style="color:var(--ink);">{{ $released->hold_released_at?->format('j M Y') }}</span> · {{ $released->employee?->name }}
                </div>
            @endforeach

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
