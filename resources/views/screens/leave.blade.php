@extends('layouts.app')

{{--
    Leave — three tabs, ported from public/_leave-revamp.html.

    This screen used to stack eleven blocks in one scroll and mixed audiences doing
    it: the superior's "To verify" and management's "To approve" queues sat between
    a staff member's balance cards and their application form. Now:

      Apply      staff — one application, asked as three questions
      My leave   staff — balances, own requests, who else is away
      Approvals  superior/management — the two review queues

    Approvals is rendered only when a queue actually has rows, which is the same
    condition that used to decide whether each queue appeared at all. No dead tab
    for the people who never approve anything.

    Tabs are client-side: partial-nav only intercepts sidebar links, so a real href
    here would full-reload the screen. `?tab=` still seeds the opening tab so a
    notification can deep-link straight into Approvals.
--}}

@php
    /**
     * Per-type presentation: icon accent, tile tint, and the plain-English sentence
     * for what the type covers. Category colour carries meaning — planned green,
     * health blue, family purple, life event slate, in-lieu teal, unplanned red,
     * unpaid grey. Quotas and the doc/notice flags are never hardcoded here; they
     * are read live from leave_types so HR changing a rule changes this screen.
     *
     * @var array<string, array{0:null, 1:string, 2:string, 3:string, 4:string}>
     */
    $leaveMeta = [
        'annual'          => [null, '#2f8f5b', '#e7f4ec', 'Planned paid time off from your yearly entitlement.', 'Cuti bergaji yang dirancang daripada kelayakan tahunan anda.'],
        'medical'         => [null, '#2d6fb3', '#e7f0f9', 'Sick leave certified by a doctor. Attach the MC.', 'Cuti sakit yang disahkan doktor. Lampirkan MC.'],
        'hospitalization' => [null, '#2d6fb3', '#e7f0f9', 'Extended sick leave while you are warded. Attach the hospital letter.', 'Cuti sakit lanjutan semasa dimasukkan ke hospital. Lampirkan surat hospital.'],
        'maternity'       => [null, '#8e5bd6', '#f1eafb', 'Paid leave for the mother around childbirth.', 'Cuti bergaji untuk ibu semasa bersalin.'],
        'paternity'       => [null, '#8e5bd6', '#f1eafb', 'Paid leave for the father around the birth of his child.', 'Cuti bergaji untuk bapa semasa kelahiran anak.'],
        'replacement'     => [null, '#1f8a8a', '#e3f4f4', 'Time off for working a rest day or public holiday.', 'Cuti ganti kerana bekerja pada hari rehat atau cuti umum.'],
        'emergency'       => [null, '#c0392b', '#fbeae8', 'A genuine unplanned absence. Comes off your Annual balance.', 'Ketiadaan mendadak yang sebenar. Ditolak daripada baki tahunan anda.'],
        'compassionate'   => [null, '#5b708a', '#eaeef3', 'Bereavement leave for an immediate family member.', 'Cuti ihsan atas kematian ahli keluarga terdekat.'],
        'marriage'        => [null, '#5b708a', '#eaeef3', 'Leave for your own marriage.', 'Cuti untuk perkahwinan anda sendiri.'],
        'unpaid'          => [null, '#8a8f98', '#eef0f2', 'Approved leave without pay, once your paid balance is spent.', 'Cuti tanpa gaji yang diluluskan, setelah baki bergaji anda habis.'],
    ];

    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');

    $pending = $myRequests->whereIn('status', ['submitted', 'verified']);
    $reviewCount = $leaveToVerify->count() + $leaveToApprove->count();

    // Ledger: every type the person carries a real entitlement in, with the days
    // already spent and the days still awaiting a decision.
    $pendingByType = $pending->groupBy('leave_type_id')->map(fn ($g) => (float) $g->sum('days'));
    // A granted type (Replacement) carries no yearly entitlement: its quota is the sum of
    // what HR has granted, so that sum is the denominator its line counts against.
    $myGrants = $myLeaveGrants ?? collect();
    $grantedByType = $myGrants->groupBy('leave_type_id')->map(fn ($g) => (float) $g->sum('days'));
    $quotaFor = fn ($b) => $b->leaveType?->is_hr_granted_only
        ? $grantedByType->get($b->leave_type_id, 0.0)
        : (float) ($b->leaveType?->entitlement ?? 0);
    $ledger = $balances->filter(fn ($b) => $quotaFor($b) > 0)
        ->sortByDesc($quotaFor);

    $statusTone = ['cancelled' => 'muted', 'approved' => 'success', 'verified' => 'amber', 'submitted' => 'amber', 'rejected' => 'error', 'draft' => 'muted'];
    $statusEn = ['cancelled' => 'Cancelled', 'approved' => 'Approved', 'verified' => 'With management', 'submitted' => 'With your manager', 'rejected' => 'Declined', 'draft' => 'Draft'];
    $statusMs = ['cancelled' => 'Dibatalkan', 'approved' => 'Diluluskan', 'verified' => 'Dengan pengurusan', 'submitted' => 'Dengan pengurus', 'rejected' => 'Ditolak', 'draft' => 'Draf'];

    // Approved keeps the withdrawn ones: a leave the viewer approved and the applicant
    // later pulled is still something they decided, and worth seeing marked.
    $decApproved = $leaveApprovedByMe ?? collect();
    $decRejected = $leaveRejectedByMe ?? collect();
    // A plain manager only recommends — scopeToApprove() closes for them — so the tab is
    // named for what they can actually do.
    $givesFinalApproval = $givesFinalApproval ?? false;
    $tabWordEn = $givesFinalApproval ? 'Approvals' : 'To verify';
    $tabWordMs = $givesFinalApproval ? 'Kelulusan' : 'Untuk disahkan';

    $showApprovals = ($leaveCanReview ?? false) || $reviewCount > 0;

    $tabs = $showApprovals ? ['apply', 'mine', 'approvals'] : ['apply', 'mine'];
    $initialTab = in_array(request()->query('tab'), $tabs, true) ? request()->query('tab') : 'apply';
    // A failed submit must land back on the form holding the rejected input, not on
    // whichever tab the URL still says.
    $initialTab = $errors->any() ? 'apply' : $initialTab;
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'leave',
    'en'  => [
        'title' => 'Leave',
        'body'  => 'Apply for leave in three steps: the type, the days, then anything you need to attach. Each application is checked against the balance you have left and sent to your manager to verify, then to management for final approval. Approved days come off the balance shown at the top.',
        'who'   => 'Staff apply · Managers verify · Management approves',
        'steps' => [
            'Apply — pick the leave type. Your remaining balance is shown on each one.',
            'Choose the first and last day. The day count, and what it leaves you with, is worked out as you type.',
            'Attach a document if the type needs one. Sick and hospitalisation leave always do.',
            'Send it. Follow it under "My leave", where each request shows exactly who is holding it.',
        ],
    ],
    'ms'  => [
        'title' => 'Cuti',
        'body'  => 'Mohon cuti dalam tiga langkah: jenis, hari, kemudian apa-apa yang perlu dilampirkan. Setiap permohonan disemak dengan baki yang berbaki dan dihantar kepada pengurus untuk disahkan, kemudian kepada pengurusan untuk kelulusan akhir. Hari yang diluluskan ditolak daripada baki di bahagian atas.',
        'who'   => 'Staf mohon · Pengurus sahkan · Pengurusan luluskan',
        'steps' => [
            'Mohon — pilih jenis cuti. Baki anda ditunjukkan pada setiap satu.',
            'Pilih hari pertama dan terakhir. Bilangan hari, dan baki selepasnya, dikira sambil anda menaip.',
            'Lampirkan dokumen jika jenis itu memerlukannya. Cuti sakit dan hospital sentiasa perlu.',
            'Hantar. Ikuti di "Cuti saya", di mana setiap permohonan menunjukkan siapa yang memegangnya.',
        ],
    ],
])

<div class="uj-lv" x-data="{
        tab: @js($initialTab),
        go(t) {
            this.tab = t;
            // Keep the URL honest without navigating: a reload or a shared link lands
            // on the same tab, and partial-nav is never triggered.
            const u = new URL(window.location);
            t === 'apply' ? u.searchParams.delete('tab') : u.searchParams.set('tab', t);
            history.replaceState(null, '', u);
        },
    }">

    <div class="uj-lv-tabs" role="tablist">
        <button type="button" class="uj-lv-tab" role="tab" :data-on="tab === 'apply' ? '' : null"
                :aria-selected="tab === 'apply'" @click="go('apply')"
                x-text="$store.ui.lang==='en' ? 'Apply' : 'Mohon'">Apply</button>
        <button type="button" class="uj-lv-tab" role="tab" :data-on="tab === 'mine' ? '' : null"
                :aria-selected="tab === 'mine'" @click="go('mine')"
                x-text="$store.ui.lang==='en' ? 'My leave' : 'Cuti saya'">My leave</button>
        @if ($showApprovals)
            <button type="button" class="uj-lv-tab" role="tab" :data-on="tab === 'approvals' ? '' : null"
                    :aria-selected="tab === 'approvals'" @click="go('approvals')">
                {{-- A manager recommends, they don't approve — so the tab says what they do. --}}
                <span x-text="$store.ui.lang==='en' ? {{ Js::from($tabWordEn) }} : {{ Js::from($tabWordMs) }}">{{ $tabWordEn }}</span>
                @if ($reviewCount > 0)<span class="uj-lv-tab-n">{{ $reviewCount }}</span>@endif
            </button>
        @endif
    </div>

    {{-- ── Apply ── --}}
    <div role="tabpanel" x-show="tab === 'apply'" x-cloak>
        @include('partials.leave-apply', ['balances' => $applyBalances ?? $balances])
    </div>

    {{-- ── My leave ── --}}
    <div role="tabpanel" x-show="tab === 'mine'" x-cloak class="uj-lv-panel">
        @if ($ledger->isNotEmpty())
            <div>
                <h3 class="uj-card-title" style="margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'Your year' : 'Tahun anda'">Your year</span></h3>
                <p style="font-size:var(--t-sm);color:var(--muted);margin:0 0 12px;">
                    <span x-text="$store.ui.lang==='en'
                        ? 'Balances count approved days only. Days still awaiting a decision show in amber.'
                        : 'Baki mengira hari yang diluluskan sahaja. Hari yang masih menunggu keputusan ditunjukkan dalam warna amber.'"></span>
                </p>
                <div class="uj-card">
                    @foreach ($ledger as $b)
                        @php
                            $slug = strtolower($b->leaveType?->name ?? '');
                            $m = $leaveMeta[$slug] ?? [null, '#8a8f98', '#eef0f2', '', ''];
                            $quota = $quotaFor($b);
                            $usedDays = max(0, $quota - (float) $b->balance);
                            $pendDays = $pendingByType->get($b->leave_type_id, 0.0);
                            $w = fn ($v) => $quota > 0 ? min(100, $v / $quota * 100) : 0;
                        @endphp
                        <div class="uj-lv-led">
                            <span class="uj-lv-led-ico" style="background:{{ $m[2] }};">
                                @include('partials.leave-type-icon', ['slug' => $slug, 'accent' => $m[1]])
                            </span>
                            <span class="uj-lv-led-n">{{ $b->leaveType?->name }}</span>
                            <span class="uj-lv-led-bar">
                                <i data-k="used" style="width:{{ $w($usedDays) }}%;"></i>
                                @if ($pendDays > 0)<i data-k="pend" style="width:{{ $w($pendDays) }}%;"></i>@endif
                            </span>
                            <span class="uj-lv-led-v">
                                <b>{{ $num($b->balance) }}</b>
                                <span x-text="$store.ui.lang==='en' ? 'of {{ $num($quota) }}' : 'dari {{ $num($quota) }}'">of {{ $num($quota) }}</span>
                                @if ($pendDays > 0)
                                    <em x-text="$store.ui.lang==='en' ? '{{ $num($pendDays) }} pending' : '{{ $num($pendDays) }} menunggu'"></em>
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($myGrants->isNotEmpty())
            <div>
                <h3 class="uj-card-title" style="margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'Replacement quota granted to you' : 'Kuota cuti ganti yang diberi'">Replacement quota granted to you</span></h3>
                <p style="font-size:var(--t-sm);color:var(--muted);margin:0 0 12px;">
                    <span x-text="$store.ui.lang==='en'
                        ? 'HR grants these days for rest days you worked. Apply for them whenever you like, up to the balance above.'
                        : 'HR memberi hari ini kerana anda bekerja pada hari rehat. Mohon bila-bila masa, sehingga baki di atas.'"></span>
                </p>
                <div class="uj-card" style="padding:0;overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;">
                        <tbody>
                            @foreach ($myGrants as $g)
                                <tr style="{{ $loop->first ? '' : 'border-top:1px solid var(--hairline);' }}">
                                    <td style="padding:10px 16px;font-variant-numeric:tabular-nums;white-space:nowrap;width:1%;font-weight:600;color:{{ $g->days < 0 ? 'var(--danger, #c0392b)' : 'var(--ink)' }};">{{ $g->days > 0 ? '+' : '' }}{{ $num($g->days) }}</td>
                                    <td style="padding:10px 16px;">{{ $g->remark }}</td>
                                    <td style="padding:10px 16px;color:var(--muted);white-space:nowrap;text-align:right;">{{ $g->created_at?->format('j M Y') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div>
            <h3 class="uj-card-title" style="margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Your requests' : 'Permohonan anda'">Your requests</span></h3>
            <div class="uj-card">
                @forelse ($myRequests as $r)
                    @php
                        $slug = strtolower($r->leaveType?->name ?? '');
                        $m = $leaveMeta[$slug] ?? [null, '#8a8f98', '#eef0f2', '', ''];
                        $halfEn = $r->isHalfDay() ? ($r->half_day_period === 'am' ? 'morning' : 'afternoon') : null;
                        $halfMs = $r->isHalfDay() ? ($r->half_day_period === 'am' ? 'pagi' : 'petang') : null;
                    @endphp
                    <div class="uj-lv-rw" x-data="{ open: false }" :data-open="open ? '' : null">
                        <button type="button" class="uj-lv-rw-head" @click="open = !open">
                            <span class="uj-lv-rw-ico" style="background:{{ $m[2] }};">
                                @include('partials.leave-type-icon', ['slug' => $slug, 'accent' => $m[1]])
                            </span>
                            <span class="uj-lv-rw-t">
                                <span class="uj-lv-rw-1">
                                    {{ $r->leaveType?->name }} <span x-text="$store.ui.lang==='en' ? 'leave' : 'cuti'">leave</span> ·
                                    @if ($r->isHalfDay())
                                        {{ $r->date_from->format('j M') }}, <span x-text="$store.ui.lang==='en' ? @js($halfEn) : @js($halfMs)">{{ $halfEn }}</span>
                                    @else
                                        {{ $r->date_from->format('j M') }}@if (! $r->date_from->isSameDay($r->date_to)) – {{ $r->date_to->format('j M') }}@endif
                                    @endif
                                </span>
                                <span class="uj-lv-rw-2">
                                    <span x-text="$store.ui.lang==='en' ? '{{ $num($r->days) }} {{ (float) $r->days == 1 ? 'day' : 'days' }}' : '{{ $num($r->days) }} hari'">{{ $num($r->days) }}</span>
                                    @if ($r->attachment_path)
                                        · <a href="{{ route('leave.attachment', $r) }}" target="_blank" rel="noopener" @click.stop style="color:var(--red);text-decoration:none;">{{ $r->attachment_name }}</a>
                                    @endif
                                </span>
                            </span>
                            <span class="uj-stamp" data-tone="{{ $statusTone[$r->status] ?? 'muted' }}"
                                  x-text="$store.ui.lang==='en' ? @js($statusEn[$r->status] ?? ucfirst($r->status)) : @js($statusMs[$r->status] ?? ucfirst($r->status))">{{ $statusEn[$r->status] ?? ucfirst($r->status) }}</span>
                            <svg class="uj-lv-chev" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                        </button>
                        <div class="uj-lv-fold" :style="open ? 'grid-template-rows:1fr' : 'grid-template-rows:0fr'">
                            <div><div class="uj-lv-rw-in">
                                @if ($r->reason)<div class="uj-lv-quote">“{{ $r->reason }}”</div>@endif
                                @if ($r->verify_note)
                                    <div class="uj-lv-quote" style="margin-bottom:11px;">
                                        “{{ $r->verify_note }}”
                                        <span style="display:block;color:var(--muted);font-size:var(--t-sm);">— {{ $r->verifiedBy?->name }}</span>
                                    </div>
                                @endif
                                @include('partials.leave-timeline', ['r' => $r, 'assignedVerifiers' => $leaveVerifiers])
                                @if (in_array($r->status, ['submitted', 'verified'], true) || ($r->status === 'approved' && ! $r->paid_at && $r->date_from->isFuture()))
                                    <form method="post" action="{{ route('leave.cancel', $r) }}" style="margin-top:11px;">
                                        @csrf
                                        <button type="submit" class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:var(--t-sm);">
                                            <span x-text="$store.ui.lang==='en' ? 'Cancel this application' : 'Batalkan permohonan ini'">Cancel this application</span>
                                        </button>
                                    </form>
                                @endif
                            </div></div>
                        </div>
                    </div>
                @empty
                    <div class="uj-lv-empty">
                        <b x-text="$store.ui.lang==='en' ? 'No applications yet' : 'Belum ada permohonan'">No applications yet</b>
                        <span x-text="$store.ui.lang==='en'
                            ? 'Use the Apply tab to send your first one. It will appear here with the name of whoever is holding it.'
                            : 'Guna tab Mohon untuk menghantar yang pertama. Ia akan muncul di sini dengan nama sesiapa yang memegangnya.'"></span>
                    </div>
                @endforelse
            </div>
        </div>

        <div>
            <h3 class="uj-card-title" style="margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Who else is away' : 'Siapa lagi bercuti'">Who else is away</span></h3>
            <div class="uj-card" style="padding:8px 0;">
                @forelse ($teamLeave as $l)
                    <div class="uj-lv-mini">
                        <span style="flex:0 0 30px;height:30px;border-radius:50%;background:{{ $l->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:grid;place-items:center;font-size:var(--t-micro);font-weight:600;">{{ $l->employee?->initials }}</span>
                        <span class="uj-lv-mini-n">{{ $l->employee?->display_name }}@if ($l->employee?->position)<em>{{ $l->employee->position }}</em>@endif</span>
                        <span class="uj-lv-mini-d">{{ $l->date_from->format('j M') }}@if (! $l->date_from->isSameDay($l->date_to)) – {{ $l->date_to->format('j M') }}@endif</span>
                    </div>
                @empty
                    <div class="uj-lv-mini">
                        <span class="uj-lv-mini-n" style="color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Nobody is on approved leave right now.' : 'Tiada sesiapa bercuti yang diluluskan sekarang.'"></span>
                    </div>
                @endforelse
            </div>
        </div>

        <div x-data="{ open: false }">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                <div>
                    <h3 class="uj-card-title" style="margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'What each type covers' : 'Apa yang setiap jenis merangkumi'">What each type covers</span></h3>
                    <p style="font-size:var(--t-sm);color:var(--muted);margin:0;">
                        <span x-text="$store.ui.lang==='en'
                            ? 'Quotas and rules come from HR\'s leave settings, so this stays current.'
                            : 'Kuota dan peraturan diambil daripada tetapan cuti HR, jadi ini kekal terkini.'"></span>
                    </p>
                </div>
                <button type="button" class="uj-lv-more" style="margin:0;flex-shrink:0;" @click="open = !open"
                        x-text="open
                            ? ($store.ui.lang==='en' ? 'Hide' : 'Sembunyi')
                            : ($store.ui.lang==='en' ? 'Show all ({{ $leaveTypes->count() }})' : 'Tunjuk semua ({{ $leaveTypes->count() }})')">Show all</button>
            </div>
            <div class="uj-lv-fold" :style="open ? 'grid-template-rows:1fr' : 'grid-template-rows:0fr'">
                <div><div style="padding-top:14px;">
                    <div class="uj-lv-ref">
                        @foreach ($leaveTypes as $t)
                            @php
                                $slug = strtolower($t->name);
                                $m = $leaveMeta[$slug] ?? [null, '#8a8f98', '#eef0f2', 'Company leave type.', 'Jenis cuti syarikat.'];
                                $deductsName = $t->deducts_from_leave_type_id
                                    ? ($leaveTypes->firstWhere('id', $t->deducts_from_leave_type_id)?->name ?? 'Annual')
                                    : null;
                            @endphp
                            <div class="uj-card uj-lv-ref-i">
                                <span class="uj-lv-ref-ico" style="background:{{ $m[2] }};">
                                    @include('partials.leave-type-icon', ['slug' => $slug, 'accent' => $m[1]])
                                </span>
                                <span style="min-width:0;flex:1;">
                                    <span class="uj-lv-ref-h">
                                        <span class="uj-lv-ref-n">{{ $t->name }}</span>
                                        <span class="uj-lv-ref-q" style="color:{{ $m[1] }};">
                                            @if ($t->is_unplanned && $t->deducts_from_leave_type_id)
                                                <span x-text="$store.ui.lang==='en' ? 'AS NEEDED' : 'IKUT PERLU'">AS NEEDED</span>
                                            @elseif ($t->is_hr_granted_only)
                                                {{-- Paid days, but no yearly figure to quote: the quota is whatever
                                                     HR granted for the rest days actually worked. --}}
                                                <span x-text="$store.ui.lang==='en' ? 'AS GRANTED' : 'IKUT DIBERI'">AS GRANTED</span>
                                            @elseif ($t->entitlement > 0)
                                                {{ (int) $t->entitlement }} <span x-text="$store.ui.lang==='en' ? 'DAYS/YR' : 'HARI/THN'">DAYS/YR</span>
                                            @else
                                                <span x-text="$store.ui.lang==='en' ? 'NO PAY' : 'TANPA GAJI'">NO PAY</span>
                                            @endif
                                        </span>
                                    </span>
                                    <p class="uj-lv-ref-d" x-text="$store.ui.lang==='en' ? @js($m[3]) : @js($m[4])">{{ $m[3] }}</p>
                                    <span class="uj-lv-tags">
                                        @if ($t->requires_attachment)
                                            <span class="uj-lv-tag" x-text="$store.ui.lang==='en' ? 'Document required' : 'Perlu dokumen'">Document required</span>
                                        @endif
                                        @if ($t->min_notice_days > 0)
                                            <span class="uj-lv-tag" x-text="$store.ui.lang==='en' ? '{{ $t->min_notice_days }} days notice' : 'notis {{ $t->min_notice_days }} hari'">{{ $t->min_notice_days }} days notice</span>
                                        @endif
                                        @if ($deductsName)
                                            <span class="uj-lv-tag" data-warn x-text="$store.ui.lang==='en' ? 'Comes off {{ $deductsName }}' : 'Ditolak dari {{ $deductsName }}'">Comes off {{ $deductsName }}</span>
                                        @endif
                                    </span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div></div>
            </div>
        </div>
    </div>

    {{-- ── Approvals ── --}}
    @if ($showApprovals)
        {{-- `st` is the status filter (pending | approved | rejected), `queue` the
             verify/approve split inside pending. Opens on pending when anything waits,
             otherwise on the approved history — an idle approver lands on something
             rather than an empty panel. --}}
        <div role="tabpanel" x-show="tab === 'approvals'" x-cloak
             x-data="{ st: @js($reviewCount > 0 ? 'pending' : 'approved'),
                       queue: @js($leaveToVerify->isNotEmpty() ? 'verify' : 'approve') }"
             class="uj-lv-panel">
            {{-- Status counts. Approved covers the whole year, so it keeps its number even
                 on a day when nothing is pending — the reason this tab no longer hides. --}}
            <div class="uj-lv-stbar">
                <button type="button" class="uj-lv-stchip" :data-on="st === 'pending' ? '' : null" @click="st = 'pending'">
                    <span x-text="$store.ui.lang==='en' ? 'Pending' : 'Menunggu'">Pending</span>
                    <b>{{ $reviewCount }}</b>
                </button>
                <button type="button" class="uj-lv-stchip" data-tone="ok" :data-on="st === 'approved' ? '' : null" @click="st = 'approved'">
                    <span x-text="$store.ui.lang==='en' ? 'Approved' : 'Diluluskan'">Approved</span>
                    <b>{{ $decApproved->count() }}</b>
                </button>
                <button type="button" class="uj-lv-stchip" data-tone="no" :data-on="st === 'rejected' ? '' : null" @click="st = 'rejected'">
                    <span x-text="$store.ui.lang==='en' ? 'Rejected' : 'Ditolak'">Rejected</span>
                    <b>{{ $decRejected->count() }}</b>
                </button>
            </div>

            {{-- ── Pending ── --}}
            <div x-show="st === 'pending'" class="uj-tab-stack">
                @if ($leaveToVerify->isNotEmpty() && $leaveToApprove->isNotEmpty())
                    <div class="uj-lv-qbar">
                        <button type="button" class="uj-lv-qchip" :data-on="queue === 'verify' ? '' : null" @click="queue = 'verify'">
                            <span x-text="$store.ui.lang==='en' ? 'Yours to verify' : 'Untuk anda sahkan'">Yours to verify</span>
                            <b>{{ $leaveToVerify->count() }}</b>
                        </button>
                        <button type="button" class="uj-lv-qchip" :data-on="queue === 'approve' ? '' : null" @click="queue = 'approve'">
                            <span x-text="$store.ui.lang==='en' ? 'Final approval' : 'Kelulusan akhir'">Final approval</span>
                            <b>{{ $leaveToApprove->count() }}</b>
                        </button>
                    </div>
                @endif

                @if ($leaveToVerify->isNotEmpty())
                    <div x-show="queue === 'verify'">
                        @include('partials.leave-review-queue', ['items' => $leaveToVerify, 'mode' => 'verify'])
                    </div>
                @endif
                @if ($leaveToApprove->isNotEmpty())
                    <div x-show="queue === 'approve'">
                        @include('partials.leave-review-queue', ['items' => $leaveToApprove, 'mode' => 'approve'])
                    </div>
                @endif

                @if ($reviewCount === 0)
                    <div class="uj-card uj-lv-empty">
                        <span x-text="$store.ui.lang==='en' ? 'Nothing is waiting on you.' : 'Tiada apa-apa menunggu anda.'">Nothing is waiting on you.</span>
                    </div>
                @endif
            </div>

            {{-- ── Decided this year ── --}}
            <div x-show="st === 'approved'" class="uj-tab-stack">
                @include('partials.leave-decided-list', ['items' => $decApproved, 'kind' => 'approved'])
            </div>
            <div x-show="st === 'rejected'" class="uj-tab-stack">
                @include('partials.leave-decided-list', ['items' => $decRejected, 'kind' => 'rejected'])
            </div>
        </div>
    @endif
</div>
@endsection
