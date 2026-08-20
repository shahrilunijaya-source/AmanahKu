@extends('layouts.app')

{{--
    Claims — four tabs, brought up to the Leave screen's structure and polish
    (public/_claims-revamp.html is the approved mock).

      Claim back   staff — one claim, asked as three questions (partials.claims-apply)
      My claims    staff — spend-by-category ledger + own claims with an approval timeline
      Approvals    superior/management — the verify and approve queues
      All claims   management/hr — the company-wide finance ledger

    Approvals renders only when a queue has rows; All claims only for privileged
    viewers. Non-approvers get neither key from claimsData(), so neither tab.

    Tabs are client-side (partial-nav only intercepts sidebar links). `?tab=` seeds
    the opening tab so a notification can deep-link into Approvals.
--}}

@php
    // Icon path + tint per claim type, shared by the apply form, list rows and queues.
    $typeMeta = [
        'expense' => ['tint' => 'var(--amber)', 'bg' => 'var(--amber-tint,#f7efe0)', 'icon' => 'M3 6h18M3 12h18M3 18h18'],
        'mileage' => ['tint' => 'var(--info)', 'bg' => 'var(--info-tint,#eef4fb)', 'icon' => 'M5 17h14M5 17a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2M9 17v2M15 17v2'],
        'medical' => ['tint' => 'var(--red)', 'bg' => 'var(--red-tint)', 'icon' => 'M12 5v14M5 12h14'],
        'travel' => ['tint' => 'var(--success)', 'bg' => 'var(--success-tint,#e7f4ec)', 'icon' => 'M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20'],
        'other' => ['tint' => 'var(--muted)', 'bg' => 'var(--shelf)', 'icon' => 'M5 12h.01M12 12h.01M19 12h.01'],
    ];

    $medicalCap = (float) ($medicalCap ?? 0);
    $medicalRemaining = max(0, $medicalCap - (float) ($medicalUsedYtd ?? 0));

    // "Your year" ledger: money reimbursed per category (approved + paid), and medical
    // shown against its cap rather than a share of spend.
    $settledByType = $myClaims->whereIn('status', ['approved', 'paid'])
        ->groupBy('type')->map(fn ($g) => (float) $g->sum('amount'));
    $settledTotal = (float) $settledByType->sum();

    // Two money tiles: still moving through the two-step gate (submitted or verified),
    // and approved but not yet paid — kept apart from `paid` since that's the thing an
    // employee actually wants to know: when does the money arrive.
    $waitingClaims = $myClaims->whereIn('status', ['submitted', 'verified']);
    $waitingTotal = (float) $waitingClaims->sum('amount');
    $waitingCount = $waitingClaims->count();
    $approvedNotPaidTotal = (float) $myClaims->where('status', 'approved')->sum('amount');

    $isApprover = $isApprover ?? false;
    $privileged = $privileged ?? false;

    // The count on the Approvals tab, and whether the tab shows at all.
    $verifyCount = $isApprover ? ($claimsToVerify?->count() ?? 0) : 0;
    $approveCount = $isApprover ? ($claimsToApprove?->count() ?? 0) : 0;
    $reviewCount = $verifyCount + $approveCount;

    $sc = ['submitted' => 'amber', 'verified' => 'info', 'approved' => 'success', 'paid' => 'muted', 'rejected' => 'error'];
    $statusEn = ['submitted' => 'With your manager', 'verified' => 'With management', 'approved' => 'Approved · pays next run', 'paid' => 'Paid', 'rejected' => 'Declined'];
    $statusMs = ['submitted' => 'Dengan pengurus', 'verified' => 'Dengan pengurusan', 'approved' => 'Diluluskan · gaji berikutnya', 'paid' => 'Dibayar', 'rejected' => 'Ditolak'];

    $money = fn ($v) => 'RM ' . number_format((float) $v, 2);

    $tabs = array_merge(['apply', 'mine'], $reviewCount > 0 ? ['approvals'] : [], $privileged ? ['all'] : []);
    $requested = request()->query('tab');
    $initialTab = in_array($requested, $tabs, true) ? $requested : 'apply';
    // The claim-approvals slug opens straight on the queue when there is one.
    if (($screen ?? null) === 'claim-approvals' && $reviewCount > 0) {
        $initialTab = 'approvals';
    }
    // A failed submit lands back on the form holding the rejected input.
    $initialTab = $errors->any() ? 'apply' : $initialTab;
@endphp

@section('screen')
<style>
    /* Claims-only widgets. Everything else reuses the Leave (.uj-lv-*) vocabulary. */
    .uj-cl-amount { display:flex; align-items:center; gap:8px; border:1px solid var(--hairline); border-radius:12px; padding:2px 16px; background:var(--canvas); transition:border-color .15s, box-shadow .15s; }
    .uj-cl-amount:focus-within { border-color:var(--info); box-shadow:0 0 0 3px rgba(58,110,165,.13); }
    .uj-cl-amount .cur { font-family:var(--font-mono); font-size:20px; font-weight:600; color:var(--muted-soft); }
    .uj-cl-amount input { flex:1; min-width:0; border:0; background:transparent; padding:12px 0; font-family:var(--font-mono); font-size:28px; font-weight:600; letter-spacing:-.02em; color:var(--ink); outline:none; }
    .uj-cl-amount input::-webkit-outer-spin-button, .uj-cl-amount input::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
    .uj-cl-km { display:flex; align-items:center; gap:12px; }
    .uj-cl-km-in { display:flex; align-items:center; flex:1; min-width:0; border:1px solid var(--hairline); border-radius:11px; padding:0 14px; background:var(--card); transition:border-color .15s, box-shadow .15s; }
    .uj-cl-km-in:focus-within { border-color:var(--info); box-shadow:0 0 0 3px rgba(58,110,165,.13); }
    .uj-cl-km-in input { flex:1; min-width:0; border:0; background:transparent; padding:12px 0; font-family:var(--font-mono); font-size:18px; font-weight:600; color:var(--ink); outline:none; }
    .uj-cl-km-in .u { font-family:var(--font-mono); font-size:13px; color:var(--muted-soft); }
    .uj-cl-km-rate { font-size:var(--t-sm); color:var(--muted); white-space:nowrap; }
    .uj-cl-amt { font-family:var(--font-mono); font-weight:600; color:var(--ink); text-align:right; flex-shrink:0; }
</style>

@include('partials.guide', [
    'key' => 'claims',
    'en'  => [
        'title' => 'Claims',
        'body'  => 'Claim back what you spent for work in three steps: the type, the amount, then the receipt. Mileage is worked out from the distance; medical is checked against your yearly cap. Each claim goes to your manager to verify, then to management to approve, and approved claims are paid in the next payroll run.',
        'who'   => 'Staff claim · Managers verify · Management approves',
        'steps' => [
            'Claim back — pick the type. Medical shows what is left of your cap.',
            'Enter the amount. For mileage, type the distance and the amount is worked out.',
            'Attach the receipt if the type needs one, then submit.',
            'Follow it under "My claims", where each claim shows exactly who is holding it.',
        ],
    ],
    'ms'  => [
        'title' => 'Tuntutan',
        'body'  => 'Tuntut balik wang yang anda belanja untuk kerja dalam tiga langkah: jenis, jumlah, kemudian resit. Mileage dikira daripada jarak; perubatan disemak dengan had tahunan. Setiap tuntutan dihantar kepada pengurus untuk disahkan, kemudian pengurusan untuk diluluskan, dan tuntutan yang diluluskan dibayar dalam gaji berikutnya.',
        'who'   => 'Staf tuntut · Pengurus sahkan · Pengurusan luluskan',
        'steps' => [
            'Tuntut balik — pilih jenis. Perubatan menunjukkan baki had anda.',
            'Masukkan jumlah. Untuk mileage, taip jarak dan jumlahnya dikira.',
            'Lampirkan resit jika jenis itu memerlukannya, kemudian hantar.',
            'Ikuti di "Tuntutan saya", di mana setiap tuntutan menunjukkan siapa memegangnya.',
        ],
    ],
])

<div class="uj-lv" x-data="{
        tab: @js($initialTab),
        go(t) {
            this.tab = t;
            const u = new URL(window.location);
            t === 'apply' ? u.searchParams.delete('tab') : u.searchParams.set('tab', t);
            history.replaceState(null, '', u);
        },
    }">

    <div class="uj-lv-tabs" role="tablist">
        <button type="button" class="uj-lv-tab" role="tab" :data-on="tab === 'apply' ? '' : null"
                :aria-selected="tab === 'apply'" @click="go('apply')"
                x-text="$store.ui.lang==='en' ? 'Claim back' : 'Tuntut balik'">Claim back</button>
        <button type="button" class="uj-lv-tab" role="tab" :data-on="tab === 'mine' ? '' : null"
                :aria-selected="tab === 'mine'" @click="go('mine')"
                x-text="$store.ui.lang==='en' ? 'My claims' : 'Tuntutan saya'">My claims</button>
        @if ($reviewCount > 0)
            <button type="button" class="uj-lv-tab" role="tab" :data-on="tab === 'approvals' ? '' : null"
                    :aria-selected="tab === 'approvals'" @click="go('approvals')">
                <span x-text="$store.ui.lang==='en' ? 'Approvals' : 'Kelulusan'">Approvals</span>
                <span class="uj-lv-tab-n">{{ $reviewCount }}</span>
            </button>
        @endif
        @if ($privileged)
            <button type="button" class="uj-lv-tab" role="tab" :data-on="tab === 'all' ? '' : null"
                    :aria-selected="tab === 'all'" @click="go('all')"
                    x-text="$store.ui.lang==='en' ? 'All claims' : 'Semua tuntutan'">All claims</button>
        @endif
    </div>

    {{-- ── Claim back ── --}}
    <div role="tabpanel" x-show="tab === 'apply'" x-cloak>
        @include('partials.claims-apply')
    </div>

    {{-- ── My claims ── --}}
    <div role="tabpanel" x-show="tab === 'mine'" x-cloak class="uj-lv-panel">
        <div>
            <h3 class="uj-card-title" style="margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'Your year' : 'Tahun anda'">Your year</span></h3>
            <p style="font-size:var(--t-sm);color:var(--muted);margin:0 0 12px;">
                <span x-text="$store.ui.lang==='en'
                    ? 'What you have claimed back this year, by category. Medical counts against its yearly cap.'
                    : 'Apa yang anda tuntut balik tahun ini, mengikut kategori. Perubatan dikira terhadap had tahunannya.'"></span>
            </p>
            <div class="uj-card">
                @foreach (['expense' => ['Expense', 'Perbelanjaan'], 'mileage' => ['Mileage', 'Mileage'], 'medical' => ['Medical', 'Perubatan'], 'travel' => ['Travel', 'Perjalanan'], 'other' => ['Other', 'Lain-lain']] as $slug => $l)
                    @php
                        $tm = $typeMeta[$slug];
                        $isMed = $slug === 'medical';
                        $val = (float) ($settledByType[$slug] ?? 0);
                        if ($isMed) {
                            $barW = $medicalCap > 0 ? min(100, ($medicalCap - $medicalRemaining) / $medicalCap * 100) : 0;
                        } else {
                            $barW = $settledTotal > 0 ? min(100, $val / $settledTotal * 100) : 0;
                        }
                    @endphp
                    <div class="uj-lv-led">
                        <span class="uj-lv-led-ico" style="background:{{ $tm['bg'] }};color:{{ $tm['tint'] }};">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $tm['icon'] }}"/></svg>
                        </span>
                        <span class="uj-lv-led-n">{{ $l[0] }}</span>
                        <span class="uj-lv-led-bar"><i data-k="used" style="width:{{ $barW }}%;{{ $val > 0 || $isMed ? "background:{$tm['tint']}" : '' }}"></i></span>
                        <span class="uj-lv-led-v">
                            <b>{{ $money($isMed ? ($medicalCap - $medicalRemaining) : $val) }}</b>
                            @if ($isMed)<em style="color:var(--muted);" x-text="$store.ui.lang==='en' ? 'of {{ $money($medicalCap) }}' : 'dari {{ $money($medicalCap) }}'">of {{ $money($medicalCap) }}</em>@endif
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        <div>
            <h3 class="uj-card-title" style="margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'Your claims' : 'Tuntutan anda'">Your claims</span></h3>
            <p style="font-size:var(--t-sm);color:var(--muted);margin:0 0 12px;">
                <span x-text="$store.ui.lang==='en'
                    ? 'Tap any claim to see where it is and who is holding it.'
                    : 'Ketik mana-mana tuntutan untuk lihat statusnya dan siapa memegangnya.'"></span>
            </p>
            <div class="uj-card">
                @forelse ($myClaims as $c)
                    @php $tm = $typeMeta[$c->type] ?? $typeMeta['other']; @endphp
                    <div class="uj-lv-rw" x-data="{ open: false }" :data-open="open ? '' : null">
                        <button type="button" class="uj-lv-rw-head" @click="open = !open">
                            <span class="uj-lv-rw-ico" style="background:{{ $tm['bg'] }};color:{{ $tm['tint'] }};">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $tm['icon'] }}"/></svg>
                            </span>
                            <span class="uj-lv-rw-t">
                                <span class="uj-lv-rw-1">{{ $c->title }}</span>
                                <span class="uj-lv-rw-2">
                                    {{ ucfirst($c->type) }} · {{ $c->date->format('j M') }}
                                    @if ($c->receipt_path) · 📎 {{ $c->receipt_name }}@endif
                                </span>
                            </span>
                            <span class="uj-cl-amt">{{ $c->currency }} {{ number_format($c->amount, 2) }}</span>
                            <span class="uj-stamp" data-tone="{{ $sc[$c->status] ?? 'muted' }}"
                                  x-text="$store.ui.lang==='en' ? @js($statusEn[$c->status] ?? ucfirst($c->status)) : @js($statusMs[$c->status] ?? ucfirst($c->status))">{{ $statusEn[$c->status] ?? ucfirst($c->status) }}</span>
                            <svg class="uj-lv-chev" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                        </button>
                        <div class="uj-lv-fold" :style="open ? 'grid-template-rows:1fr' : 'grid-template-rows:0fr'">
                            <div><div class="uj-lv-rw-in">
                                @if ($c->reason)<div class="uj-lv-quote">“{{ $c->reason }}”</div>@endif
                                @if ($c->receipt_path)
                                    <div style="margin-bottom:11px;">
                                        <a href="{{ route('claims.receipt', $c) }}" style="font-size:var(--t-sm);color:var(--red);text-decoration:none;">
                                            <span x-text="$store.ui.lang==='en' ? 'Open receipt' : 'Buka resit'">Open receipt</span>@if ($c->receipt_name) — {{ $c->receipt_name }}@endif
                                        </a>
                                    </div>
                                @endif
                                @include('partials.claims-timeline', ['c' => $c])
                            </div></div>
                        </div>
                    </div>
                @empty
                    <div class="uj-lv-empty">
                        <b x-text="$store.ui.lang==='en' ? 'No claims yet' : 'Belum ada tuntutan'">No claims yet</b>
                        <span x-text="$store.ui.lang==='en'
                            ? 'Use the Claim back tab to submit your first one. It will appear here with the name of whoever is holding it.'
                            : 'Guna tab Tuntut balik untuk menghantar yang pertama. Ia akan muncul di sini dengan nama sesiapa yang memegangnya.'"></span>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ── Approvals ── --}}
    @if ($reviewCount > 0)
        <div role="tabpanel" x-show="tab === 'approvals'" x-cloak
             x-data="{ queue: @js($verifyCount > 0 ? 'verify' : 'approve') }"
             class="uj-lv-panel">
            <div>
                <h3 class="uj-card-title" style="margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'Claims waiting on you' : 'Tuntutan menunggu anda'">Claims waiting on you</span></h3>
                <p style="font-size:var(--t-sm);color:var(--muted);margin:0;">
                    <span x-text="$store.ui.lang==='en'
                        ? 'You verify your own reports first. Management gives the final approval on anything already verified.'
                        : 'Anda sahkan laporan anda sendiri dahulu. Pengurusan beri kelulusan akhir bagi yang telah disahkan.'"></span>
                </p>
                <p style="font-size:var(--t-sm);color:var(--muted);margin:6px 0 0;">
                    <span x-text="$store.ui.lang==='en'
                        ? 'Tap any claim to open its receipt and approval trail before you decide.'
                        : 'Ketik mana-mana tuntutan untuk buka resit dan jejak kelulusan sebelum anda putuskan.'"></span>
                </p>
            </div>

            @if ($verifyCount > 0 && $approveCount > 0)
                <div class="uj-lv-qbar">
                    <button type="button" class="uj-lv-qchip" :data-on="queue === 'verify' ? '' : null" @click="queue = 'verify'">
                        <span x-text="$store.ui.lang==='en' ? 'Yours to verify' : 'Untuk anda sahkan'">Yours to verify</span>
                        <b>{{ $verifyCount }}</b>
                    </button>
                    <button type="button" class="uj-lv-qchip" :data-on="queue === 'approve' ? '' : null" @click="queue = 'approve'">
                        <span x-text="$store.ui.lang==='en' ? 'Final approval' : 'Kelulusan akhir'">Final approval</span>
                        <b>{{ $approveCount }}</b>
                    </button>
                </div>
            @endif

            @if ($verifyCount > 0)
                <div x-show="queue === 'verify'">
                    @include('partials.claims-review-queue', ['items' => $claimsToVerify, 'mode' => 'verify'])
                </div>
            @endif
            @if ($approveCount > 0)
                <div x-show="queue === 'approve'">
                    @include('partials.claims-review-queue', ['items' => $claimsToApprove, 'mode' => 'approve'])
                </div>
            @endif
        </div>
    @endif

    {{-- ── All claims — finance & audit (management / hr) ── --}}
    @if ($privileged)
        @php
            $pendTotal = (float) (($claimTotals['submitted']->total ?? 0) + ($claimTotals['verified']->total ?? 0));
            $pendCount = (int) (($claimTotals['submitted']->count ?? 0) + ($claimTotals['verified']->count ?? 0));
            $payTotal = (float) ($claimTotals['approved']->total ?? 0);
            $payCount = (int) ($claimTotals['approved']->count ?? 0);
        @endphp
        <div role="tabpanel" x-show="tab === 'all'" x-cloak x-data="{
                rows: @js($allClaims->map(fn ($c) => [
                    'name' => $c->employee?->name ?? '—',
                    'type' => $c->type,
                    'status' => $c->status,
                    'amount' => (float) $c->amount,
                    'currency' => $c->currency,
                    'date' => $c->date?->toDateString(),
                    'changed' => optional($c->updated_at)->toIso8601String(),
                ])->values()),
                q: '', fstatus: 'all', ftype: 'all',
                daysAgo(iso) { return iso ? Math.floor((Date.now() - new Date(iso)) / 864e5) : null; },
                isStuck(r) { return ['submitted', 'verified'].includes(r.status) && this.daysAgo(r.changed) >= 5; },
                get list() {
                    return this.rows.filter(r => {
                        const hay = (r.name + ' ' + r.type).toLowerCase();
                        const mq = !this.q || hay.includes(this.q.toLowerCase());
                        const ms = this.fstatus === 'all'
                            || (this.fstatus === 'waiting' ? ['submitted', 'verified'].includes(r.status) : r.status === this.fstatus);
                        const mt = this.ftype === 'all' || r.type === this.ftype;
                        return mq && ms && mt;
                    }).sort((a, b) => (this.isStuck(b) ? 1 : 0) - (this.isStuck(a) ? 1 : 0));
                },
                get stuckCount() { return this.rows.filter(r => this.isStuck(r)).length; },
                money(v) { return 'RM ' + v.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                fmtDate(d) { return d ? new Date(d + 'T00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : ''; },
                cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); },
                exportCsv() {
                    const head = ['Date', 'Employee', 'Type', 'Amount', 'Status'];
                    const Q = String.fromCharCode(34);
                    const esc = v => Q + String(v).split(Q).join(Q + Q) + Q;
                    const body = this.list.map(r => [r.date, esc(r.name), r.type, r.amount.toFixed(2), r.status].join(','));
                    const blob = new Blob([[head.join(','), ...body].join('\n')], { type: 'text/csv;charset=utf-8' });
                    const a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = 'company-claims-' + new Date().toISOString().slice(0, 10) + '.csv';
                    a.click(); URL.revokeObjectURL(a.href);
                },
            }" class="uj-lv-panel">
            {{-- The two numbers finance opens this tab for. --}}
            <div style="display:flex;gap:14px;flex-wrap:wrap;">
                <div class="uj-card" style="flex:1;min-width:210px;padding:15px 18px;">
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;" x-text="$store.ui.lang==='en' ? 'Waiting on a decision' : 'Menunggu keputusan'">Waiting on a decision</div>
                    <div style="font-family:var(--font-mono);font-size:24px;font-weight:600;letter-spacing:-.025em;color:var(--amber-ink,#8a5714);margin-top:5px;">{{ $money($pendTotal) }}</div>
                    <div style="font-size:12px;color:var(--muted);margin-top:3px;">{{ $pendCount }} <span x-text="$store.ui.lang==='en' ? @js(\Illuminate\Support\Str::plural('claim', $pendCount)) : 'tuntutan'">{{ \Illuminate\Support\Str::plural('claim', $pendCount) }}</span> · <span x-text="$store.ui.lang==='en' ? 'not yet a cost' : 'belum jadi kos'">not yet a cost</span></div>
                </div>
                <div class="uj-card" style="flex:1;min-width:210px;padding:15px 18px;">
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;" x-text="$store.ui.lang==='en' ? 'Approved · pays next run' : 'Diluluskan · gaji berikutnya'">Approved · pays next run</div>
                    <div style="font-family:var(--font-mono);font-size:24px;font-weight:600;letter-spacing:-.025em;color:var(--success-ink,#14614a);margin-top:5px;">{{ $money($payTotal) }}</div>
                    <div style="font-size:12px;color:var(--muted);margin-top:3px;">{{ $payCount }} <span x-text="$store.ui.lang==='en' ? @js(\Illuminate\Support\Str::plural('claim', $payCount)) : 'tuntutan'">{{ \Illuminate\Support\Str::plural('claim', $payCount) }}</span> · <span x-text="$store.ui.lang==='en' ? 'include in this payroll' : 'masukkan dalam payroll ini'">include in this payroll</span></div>
                </div>
            </div>

            {{-- Search · filter · export, over the loaded ledger. --}}
            <div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap;">
                <label style="position:relative;flex:1;min-width:200px;">
                    <svg style="position:absolute;left:12px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:var(--muted-soft,#8b887e);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3" stroke-linecap="round"/></svg>
                    <input type="text" x-model="q" :placeholder="$store.ui.lang==='en' ? 'Search employee or type…' : 'Cari pekerja atau jenis…'" style="width:100%;height:38px;padding:0 12px 0 34px;border:1px solid var(--hairline);border-radius:10px;font-size:13px;color:var(--ink);outline:none;background:#fff;" />
                </label>
                <select x-model="fstatus" class="uj-tr-sel" style="height:38px;">
                    <option value="all" x-text="$store.ui.lang==='en' ? 'All status' : 'Semua status'">All status</option>
                    <option value="waiting" x-text="$store.ui.lang==='en' ? 'Waiting' : 'Menunggu'">Waiting</option>
                    <option value="approved" x-text="$store.ui.lang==='en' ? 'Approved' : 'Diluluskan'">Approved</option>
                    <option value="paid" x-text="$store.ui.lang==='en' ? 'Paid' : 'Dibayar'">Paid</option>
                </select>
                <select x-model="ftype" class="uj-tr-sel" style="height:38px;">
                    <option value="all" x-text="$store.ui.lang==='en' ? 'All types' : 'Semua jenis'">All types</option>
                    <option value="expense">Expense</option>
                    <option value="mileage">Mileage</option>
                    <option value="medical">Medical</option>
                    <option value="travel">Travel</option>
                    <option value="other">Other</option>
                </select>
                <button type="button" @click="exportCsv()" class="uj-btn-ghost" style="height:38px;padding:0 14px;font-size:12.5px;display:inline-flex;align-items:center;gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>
                    <span x-text="$store.ui.lang==='en' ? 'Export CSV' : 'Eksport CSV'">Export CSV</span>
                </button>
            </div>

            <p style="font-size:var(--t-sm);color:var(--muted);margin:0 0 -10px;">
                <span x-text="$store.ui.lang==='en'
                    ? 'Every claim across the company, newest first (latest 50). Rows waiting more than five days are flagged.'
                    : 'Setiap tuntutan seluruh syarikat, terbaharu dahulu (50 terkini). Baris yang menunggu lebih lima hari ditandakan.'"></span>
            </p>
            <div class="uj-card">
                <div class="uj-card-head" style="justify-content:space-between;">
                    <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Company claims' : 'Tuntutan syarikat'">Company claims</h3>
                    <span x-show="stuckCount > 0" x-cloak style="font-size:11.5px;font-weight:600;color:var(--red-active,#b01b22);background:var(--red-tint);padding:4px 10px;border-radius:8px;display:inline-flex;align-items:center;gap:6px;">
                        <span style="width:6px;height:6px;border-radius:50%;background:var(--red);"></span>
                        <span x-text="stuckCount + ($store.ui.lang==='en' ? ' stuck > 5 days' : ' tersekat > 5 hari')"></span>
                    </span>
                </div>
                <div style="display:flex;gap:14px;padding:9px 20px;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;border-top:1px solid var(--hairline);">
                    <div style="width:78px;" x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</div>
                    <div style="flex:1;min-width:0;" x-text="$store.ui.lang==='en' ? 'Employee' : 'Pekerja'">Employee</div>
                    <div style="width:84px;" x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</div>
                    <div style="width:96px;text-align:right;" x-text="$store.ui.lang==='en' ? 'Amount' : 'Jumlah'">Amount</div>
                    <div style="width:150px;" x-text="$store.ui.lang==='en' ? 'Where it is' : 'Di mana'">Where it is</div>
                </div>
                <template x-for="(r, i) in list" :key="i">
                    <div style="display:flex;align-items:center;gap:14px;padding:11px 20px;border-bottom:1px solid var(--hairline-soft);"
                         :style="isStuck(r) ? { background: 'linear-gradient(90deg,rgba(214,35,43,.045),transparent 55%)' } : {}">
                        <div style="width:78px;font-size:12px;color:var(--muted);" x-text="fmtDate(r.date)"></div>
                        <div style="flex:1;min-width:0;font-size:13px;color:var(--ink);font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" x-text="r.name"></div>
                        <div style="width:84px;font-size:12px;color:var(--muted);" x-text="cap(r.type)"></div>
                        <div style="width:96px;text-align:right;font-size:13px;font-weight:600;color:var(--ink);font-family:var(--font-mono);" x-text="r.currency + ' ' + r.amount.toFixed(2)"></div>
                        <div style="width:150px;font-size:11.5px;line-height:1.35;display:flex;flex-direction:column;">
                            <template x-if="r.status === 'submitted'"><span><span style="font-weight:500;" :style="isStuck(r) ? 'color:var(--red-active,#b01b22)' : 'color:var(--ink)'" x-text="$store.ui.lang==='en' ? 'With manager' : 'Dengan pengurus'"></span><span :style="isStuck(r) ? 'color:var(--red-active,#b01b22)' : 'color:var(--muted)'" x-text="($store.ui.lang==='en' ? ' · to verify · ' : ' · sahkan · ') + daysAgo(r.changed) + ($store.ui.lang==='en' ? 'd' : 'h') + (isStuck(r) ? ' ⚠' : '')"></span></span></template>
                            <template x-if="r.status === 'verified'"><span><span style="font-weight:500;" :style="isStuck(r) ? 'color:var(--red-active,#b01b22)' : 'color:var(--ink)'" x-text="$store.ui.lang==='en' ? 'With management' : 'Dengan pengurusan'"></span><span :style="isStuck(r) ? 'color:var(--red-active,#b01b22)' : 'color:var(--muted)'" x-text="($store.ui.lang==='en' ? ' · to approve · ' : ' · lulus · ') + daysAgo(r.changed) + ($store.ui.lang==='en' ? 'd' : 'h') + (isStuck(r) ? ' ⚠' : '')"></span></span></template>
                            <template x-if="r.status === 'approved'"><span><span style="font-weight:500;color:var(--success-ink,#14614a);" x-text="$store.ui.lang==='en' ? 'Approved' : 'Diluluskan'"></span><span style="color:var(--muted);" x-text="$store.ui.lang==='en' ? ' · pays next run' : ' · gaji berikutnya'"></span></span></template>
                            <template x-if="r.status === 'paid'"><span style="color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Paid' : 'Dibayar'"></span></template>
                            <template x-if="r.status === 'rejected'"><span style="color:var(--error,#cf2d56);font-weight:500;" x-text="$store.ui.lang==='en' ? 'Rejected' : 'Ditolak'"></span></template>
                        </div>
                    </div>
                </template>
                <div x-show="list.length === 0" x-cloak style="padding:32px 20px;text-align:center;">
                    <div style="font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No claims match those filters.' : 'Tiada tuntutan padan dengan penapis itu.'">No claims match those filters.</div>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
