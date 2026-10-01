@php
    $money = fn ($v) => ((float) $v < 0 ? '−' : '').'RM '.number_format(abs((float) $v), 2);
    // One bilingual tooltip per action/status, so the row stays short and the meaning is one hover away.
    $tip = fn (string $en, string $ms) => '$store.ui.lang===\'en\' ? '.json_encode($en, JSON_UNESCAPED_UNICODE).' : '.json_encode($ms, JSON_UNESCAPED_UNICODE);
    $fourEyes = $payoutFourEyes ?? false;
@endphp
<form method="get" action="{{ route('app.screen', 'payroll-payment') }}" style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
    <input type="hidden" name="tab" value="payout">
    <label for="payout-year" style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Year' : 'Tahun'">Year</label>
    <input id="payout-year" name="year" type="number" min="2020" max="2100" value="{{ $payoutYear }}" onchange="this.form.submit()" style="width:90px;height:32px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;font-family:var(--font-mono);">
</form>
<div class="uj-card" style="padding:0;">
    @forelse ($payoutRuns as $r)
        @php
            $t = $r->totals;
            $net = (float) ($t['net'] ?? 0);
            $open = in_array($r->status, ['draft', 'approved'], true);
            $payBy = $r->payByDate();
            $overdue = $r->paid_at === null && now()->gt($payBy);
        @endphp
        <div class="uj-payout-row" @if ($activeRun && request()->filled('run') && $activeRun->id === $r->id) data-active @endif>
            <div class="uj-payout-head">
                <div class="uj-payout-title">
                    <div style="font-size:14px;color:var(--ink);font-weight:600;">{{ $r->label }}</div>
                    <div style="font-size:11px;color:var(--muted);">{{ $r->payslips_count }} <span x-text="$store.ui.lang==='en' ? 'staff' : 'staf'">staff</span></div>
                </div>
                <div class="uj-payout-stamps">
                    @if ($r->status === 'draft')
                        <span class="uj-stamp" data-tone="amber" tabindex="0" data-tip-wrap :data-tip="{{ $tip('Payslips can still change. Edit or recalculate them in Review.', 'Payslip masih boleh berubah. Sunting atau kira semula di Semakan.') }}" x-text="$store.ui.lang==='en' ? 'Draft' : 'Draf'">Draft</span>
                    @elseif ($r->status === 'approved')
                        <span class="uj-stamp" tabindex="0" data-tip-wrap :data-tip="{{ $tip('Checked and signed off. Finalize to lock the payslips.', 'Telah disemak dan diluluskan. Finalize untuk kunci payslip.') }}" x-text="$store.ui.lang==='en' ? 'Approved' : 'Diluluskan'">Approved</span>
                    @else
                        <span class="uj-stamp" data-tone="success" tabindex="0" data-tip-wrap :data-tip="{{ $tip('Locked. Payslips can no longer change.', 'Dikunci. Payslip tidak boleh berubah lagi.') }}"><span x-text="$store.ui.lang==='en' ? 'Finalized' : 'Difinalize'">Finalized</span>&nbsp;{{ $r->finalized_at?->format('j M') }}</span>
                        @if ($r->isPublished())
                            <span class="uj-stamp" data-tone="success" tabindex="0" data-tip-wrap :data-tip="{{ $tip('Staff can see their payslips.', 'Staf boleh lihat payslip mereka.') }}"><span x-text="$store.ui.lang==='en' ? 'Published' : 'Diterbitkan'">Published</span>&nbsp;{{ $r->published_at?->format('j M') }}</span>
                        @else
                            <span class="uj-stamp" tabindex="0" data-tip-wrap :data-tip="{{ $tip('Staff can\'t see these payslips yet.', 'Staf belum boleh lihat payslip ini.') }}" x-text="$store.ui.lang==='en' ? 'Not published' : 'Belum diterbit'">Not published</span>
                        @endif
                        @if ($r->paid_at)
                            <span class="uj-stamp" data-tone="success" tabindex="0" data-tip-wrap :data-tip="{{ $tip('Marked as paid out to staff.', 'Ditanda sebagai sudah dibayar kepada staf.') }}"><span x-text="$store.ui.lang==='en' ? 'Paid' : 'Dibayar'">Paid</span>&nbsp;{{ $r->paid_at->format('j M Y') }}</span>
                        @endif
                    @endif
                    @if ($r->paid_at === null && $r->status !== 'draft' && $r->status !== 'approved')
                        <span class="uj-stamp" @if ($overdue) data-tone="error" @endif tabindex="0" data-tip-wrap :data-tip="{{ $tip('Employment Act s.19: wages are due within 7 days after the pay period ends.', 'Akta Kerja s.19: gaji perlu dibayar dalam 7 hari selepas tempoh gaji tamat.') }}"><span x-text="$store.ui.lang==='en' ? @js($overdue ? 'Overdue · pay by' : 'Pay by') : @js($overdue ? 'Lewat · bayar sebelum' : 'Bayar sebelum')">{{ $overdue ? 'Overdue · pay by' : 'Pay by' }}</span>&nbsp;{{ $payBy->format('j M Y') }}</span>
                    @endif
                </div>
                <div class="uj-payout-facts">
                    @unless ($r->paid_at)
                        <span style="font-size:12px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Pay date' : 'Tarikh bayaran'">Pay date</span>
                            @if ($r->payment_date)
                                <span style="color:var(--ink);">{{ $r->payment_date->format('j M Y') }}</span>
                            @else
                                <span x-text="$store.ui.lang==='en' ? 'not set' : 'belum ditetapkan'">not set</span>
                            @endif
                        </span>
                    @endunless
                    <span class="uj-payout-net" tabindex="0" data-tip-end data-tip-wrap
                          :data-tip="{{ $net < 0
                              ? $tip('Below zero: deductions in this run (such as a mid-month advance taken back) are bigger than the pay.', 'Bawah sifar: potongan dalam run ini (seperti pendahuluan pertengahan bulan yang ditarik balik) lebih besar daripada gaji.')
                              : $tip('Total take-home pay across every payslip in this run.', 'Jumlah gaji bersih semua payslip dalam run ini.') }}">
                        <span style="font-family:var(--font-sans);font-size:11px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Net' : 'Bersih'">Net</span>
                        <span @if ($net < 0) style="color:var(--error-ink);" @endif>{{ $money($net) }}</span>
                    </span>
                    <a class="uj-payout-files" href="{{ route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'submission', 'run' => $r->id]) }}" data-tip-end data-tip-wrap :data-tip="{{ $tip('Bank file, EPF, SOCSO, EIS and PCB submission files for this run.', 'Fail bank, KWSP, PERKESO, SIP dan PCB untuk run ini.') }}">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                        <span x-text="$store.ui.lang==='en' ? 'Files' : 'Fail'">Files</span>
                    </a>
                </div>
            </div>

            @if ($open)
                @php $payDefault = old('payment_date', $r->payment_date?->toDateString() ?? $payBy->subDays(7)->toDateString()); $blocked = $fourEyes && $r->status === 'draft'; @endphp
                <div class="uj-payout-actions">
                    <form method="post" action="{{ route('payroll.runs.delete', $r) }}" class="uj-payout-delete-form" onsubmit="return confirm(window.Alpine && Alpine.store('ui').lang==='ms' ? @js('Padam draft run '.$r->label.'? Semua payslip draf dalamnya turut dipadam. Tindakan ini tidak boleh dibatalkan.') : @js('Delete draft run '.$r->label.'? Every draft payslip in it is deleted too. This cannot be undone.'));">@csrf
                        <button class="uj-payout-delete" data-tip-start data-tip-wrap :data-tip="{{ $tip('Removes this draft and its payslips. Nothing has been paid or issued yet.', 'Buang draf ini dan payslipnya. Belum ada apa-apa dibayar atau dikeluarkan.') }}" x-text="$store.ui.lang==='en' ? 'Delete run' : 'Padam run'">Delete run</button>
                    </form>
                    @if ($r->status === 'draft')
                        <form method="post" action="{{ route('payroll.runs.approve', $r) }}">@csrf
                            <button class="uj-btn-ghost uj-payout-btn" data-tip-wrap :data-tip="{{ $fourEyes
                                ? $tip('Sign off the figures. Four-eyes control is on, so finalizing needs this first.', 'Luluskan angka. Kawalan empat mata aktif, jadi finalize perlukan ini dahulu.')
                                : $tip('Optional sign-off that the figures were checked. You can also finalize straight from draft.', 'Pengesahan pilihan bahawa angka telah disemak. Boleh juga finalize terus dari draf.') }}" x-text="$store.ui.lang==='en' ? 'Approve' : 'Luluskan'">Approve</button>
                        </form>
                    @endif
                    <form method="post" action="{{ route('payroll.runs.finalize', $r) }}" class="uj-payout-finalize" x-data="{ late: @js($payDefault > $payBy->toDateString()) }" onsubmit="return confirm(window.Alpine && Alpine.store('ui').lang==='ms' ? @js('Finalize '.$r->label.'? Payslip dikunci dan tuntutan yang dibayar balik ditanda sebagai paid. Staf hanya nampak payslip selepas diterbitkan.') : @js('Finalize '.$r->label.'? Payslips lock and reimbursed claims are marked paid. Staff see nothing until the run is published.'));">@csrf
                        <div class="uj-payout-field">
                            <label for="pay-date-{{ $r->id }}"><span x-text="$store.ui.lang==='en' ? 'Pay date' : 'Tarikh bayaran'">Pay date</span><span class="uj-tip-i" tabindex="0" data-tip-wrap :data-tip="{{ $tip('The day the money reaches staff. Employment Act s.19: within 7 days after the period ends, by '.$payBy->format('j M Y').'.', 'Hari wang sampai kepada staf. Akta Kerja s.19: dalam 7 hari selepas tempoh tamat, sebelum '.$payBy->format('j M Y').'.') }}" aria-hidden="true">i</span></label>
                            <input id="pay-date-{{ $r->id }}" name="payment_date" type="date" required value="{{ $payDefault }}" @change="late = $event.target.value > @js($payBy->toDateString())" />
                        </div>
                        <div class="uj-payout-field uj-payout-field--wide" x-show="late" x-cloak>
                            <label for="late-{{ $r->id }}" x-text="$store.ui.lang==='en' ? 'Reason for paying after the seventh day (EA s.19)' : 'Sebab bayar selepas hari ketujuh (AK s.19)'">Reason for paying after the seventh day (EA s.19)</label>
                            <input id="late-{{ $r->id }}" name="pay_date_override_reason" maxlength="240" value="{{ old('pay_date_override_reason') }}" />
                        </div>
                        <label class="uj-payout-check" data-tip-wrap :data-tip="{{ $tip('Staff see their payslips and get a notification as soon as you finalize. Leave it off to publish later.', 'Staf nampak payslip dan dapat notifikasi sebaik sahaja anda finalize. Biarkan kosong untuk terbit kemudian.') }}">
                            <input type="checkbox" name="publish_now" value="1">
                            <span x-text="$store.ui.lang==='en' ? 'Publish to staff now' : 'Terbit kepada staf sekarang'">Publish to staff now</span>
                        </label>
                        <button class="uj-btn-primary uj-payout-btn" @disabled($blocked) data-tip-end data-tip-wrap :data-tip="{{ $blocked
                            ? $tip('Needs approval first: four-eyes control is on.', 'Perlu kelulusan dahulu: kawalan empat mata aktif.')
                            : $tip('Locks the payslips, marks reimbursed claims paid, and opens the bank and statutory files.', 'Kunci payslip, tanda tuntutan sebagai dibayar, dan buka fail bank serta statutori.') }}" x-text="$store.ui.lang==='en' ? 'Finalize & issue' : 'Finalize & keluarkan'">Finalize & issue</button>
                    </form>
                    @error('payment_date')<div style="flex-basis:100%;font-size:12px;color:var(--error-ink);">{{ $message }}</div>@enderror
                </div>
            @elseif (! $r->isPublished() || ! $r->paid_at)
                <div class="uj-payout-actions">
                    @unless ($r->isPublished())
                        <form method="post" action="{{ route('payroll.runs.publish', $r) }}" onsubmit="return confirm(window.Alpine && Alpine.store('ui').lang==='ms' ? @js('Terbitkan payslip '.$r->label.' kepada staf? Setiap pekerja akan dimaklumkan melalui aplikasi dan e-mel.') : @js('Publish '.$r->label.' payslips to staff? Every employee is notified in the app and by email.'));">@csrf
                            <button class="uj-btn-primary uj-payout-btn" data-tip-wrap :data-tip="{{ $tip('Staff can see their payslips and are notified in the app and by email.', 'Staf boleh lihat payslip dan dimaklumkan melalui aplikasi dan e-mel.') }}" x-text="$store.ui.lang==='en' ? 'Publish to staff' : 'Terbit kepada staf'">Publish to staff</button>
                        </form>
                    @endunless
                    @unless ($r->paid_at)
                        <form method="post" action="{{ route('payroll.runs.mark-paid', $r) }}">@csrf
                            <button class="uj-btn-ghost uj-payout-btn" data-tip-end data-tip-wrap :data-tip="{{ $tip('Record that the bank transfer went out. Clears the pay-by reminder.', 'Rekod bahawa pindahan bank telah dibuat. Buang peringatan tarikh bayar.') }}" x-text="$store.ui.lang==='en' ? 'Mark paid' : 'Tanda dibayar'">Mark paid</button>
                        </form>
                    @endunless
                </div>
            @endif
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
