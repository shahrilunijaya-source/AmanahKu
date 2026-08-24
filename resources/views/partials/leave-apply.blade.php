{{-- Apply tab — one application, asked as three questions.

     Replaces the single long form that used to open this screen. Every rule the
     server enforces is now stated at the step it applies to, against the balance
     it spends, so a request is either sendable or it says why it is not — instead
     of four permanently-open hint paragraphs and a POST that comes back red.

     Params: $leaveTypes, $balances, $leaveMeta (see screens/leave.blade.php),
     $teamLeave, $approvalChain. --}}
@php
    $balByType = $balances->keyBy('leave_type_id');

    /**
     * Per-type facts the form reasons about client-side: the notice rule, whether a
     * document is required, and which balance the days actually come off (Emergency
     * spends Annual, so it must show Annual's numbers, not its own).
     *
     * @var array<int, array{notice:int, unplanned:bool, doc:bool, name:string, balName:string, balLeft:float|null, balQuota:float|null}>
     */
    $applyMeta = $leaveTypes->mapWithKeys(function ($t) use ($balByType, $leaveTypes) {
        $balTypeId = $t->deducts_from_leave_type_id ?: $t->id;
        $balType = $t->deducts_from_leave_type_id ? $leaveTypes->firstWhere('id', $balTypeId) : $t;
        $bal = $balByType->get($balTypeId);

        return [$t->id => [
            'notice' => (int) $t->min_notice_days,
            'unplanned' => (bool) $t->is_unplanned,
            'doc' => (bool) $t->requires_attachment,
            'name' => $t->name,
            'deducts' => $t->deducts_from_leave_type_id ? ($balType?->name) : null,
            'balName' => $balType?->name ?? $t->name,
            'balLeft' => $bal ? (float) $bal->balance : null,
            'balQuota' => $balType && $balType->entitlement > 0 ? (float) $balType->entitlement : null,
        ]];
    })->toArray();

    // Approved leave already on the books, so overlapping dates are flagged while the
    // dates can still be changed rather than in a panel read after submitting.
    $teamRanges = $teamLeave->map(fn ($l) => [
        'name' => $l->employee?->display_name,
        'from' => $l->date_from->toDateString(),
        'to' => $l->date_to->toDateString(),
    ])->filter(fn ($r) => $r['name'])->values()->all();

    // The four types an employee actually reaches for. The other six are a click away
    // rather than in a ten-row dropdown that buries the common case.
    $primarySlugs = ['annual', 'medical', 'emergency', 'replacement'];
    $primary = $leaveTypes->filter(fn ($t) => in_array(strtolower($t->name), $primarySlugs, true));
    $primary = $primary->isEmpty() ? $leaveTypes->take(4) : $primary;
    $rest = $leaveTypes->reject(fn ($t) => $primary->contains('id', $t->id));

    $selInit = old('leave_type_id', '');
    $verifierName = collect($approvalChain['verifiers'] ?? [])->first()?->name;

    // Where the request actually goes. HR skips the verify step entirely, so promising
    // them a manager review would be a lie the timeline then contradicts.
    $sendsTo = ($leaveSkipsVerification ?? false)
        ? ['en' => 'Goes straight to management for approval — your role skips the verify step.',
            'ms' => 'Dihantar terus kepada pengurusan untuk kelulusan — peranan anda melangkau langkah pengesahan.']
        : ($verifierName
            ? ['en' => "Goes to {$verifierName} to verify, then management to approve.",
                'ms' => "Dihantar kepada {$verifierName} untuk sahkan, kemudian pengurusan untuk lulus."]
            : ['en' => 'Goes to your manager to verify, then management to approve.',
                'ms' => 'Dihantar kepada pengurus anda untuk sahkan, kemudian pengurusan untuk lulus.']);
@endphp

<form method="post" action="{{ route('leave.store') }}" enctype="multipart/form-data" x-data="{
        meta: @js($applyMeta),
        team: @js($teamRanges),
        sel: @js($selInit ? (int) $selInit : null),
        step: @js($selInit ? 2 : 1),
        showAll: @js($rest->isNotEmpty() && $selInit && $rest->contains('id', (int) $selInit)),
        dateFrom: @js(old('date_from', '')),
        dateTo: @js(old('date_to', '')),
        half: @js(old('half_day_period', '')),
        fileName: '',

        t() { return this.sel ? this.meta[this.sel] : null; },
        open(n) { if (this.reachable(n)) this.step = n; },
        reachable(n) { return n === 1 || (n === 2 && this.sel) || (n === 3 && this.sel && this.days() > 0); },

        /**
         * Local YYYY-MM-DD. toISOString() converts to UTC first, which in UTC+8 hands
         * back yesterday — the client would then offer a start date the server's notice
         * rule rejects, which is exactly the round trip this form exists to avoid.
         */
        ymd(d) {
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        },
        /** Earliest legal start date for the chosen type — mirrors the server's notice rule. */
        minFrom() {
            const m = this.t();
            if (!m || m.unplanned || !m.notice) return '';
            const d = new Date(); d.setHours(0, 0, 0, 0);
            d.setDate(d.getDate() + m.notice);
            return this.ymd(d);
        },
        pick(id) {
            this.sel = id;
            const mf = this.minFrom();
            if (!this.dateFrom || (mf && this.dateFrom < mf)) { this.dateFrom = mf || this.ymd(new Date()); }
            if (!this.dateTo || this.dateTo < this.dateFrom) { this.dateTo = this.dateFrom; }
            this.clamp();
            this.step = 2;
        },
        clamp() {
            const mf = this.minFrom();
            if (mf && this.dateFrom && this.dateFrom < mf) this.dateFrom = mf;
            if (this.dateTo && this.dateFrom && this.dateTo < this.dateFrom) this.dateTo = this.dateFrom;
            // A half day cannot span a range; drop the marker so a multi-day request
            // always posts whole days, the same combination the server rejects.
            if (!this.single()) this.half = '';
        },
        single() { return !!this.dateFrom && this.dateFrom === this.dateTo; },

        /** Whole inclusive days, or 0.5 for a half day — the same arithmetic as LeaveController. */
        days() {
            if (!this.dateFrom || !this.dateTo) return 0;
            if (this.half) return 0.5;
            return Math.round((new Date(this.dateTo) - new Date(this.dateFrom)) / 864e5) + 1;
        },
        left() { const m = this.t(); return m && m.balLeft !== null ? m.balLeft : null; },
        overBy() { const l = this.left(); return l === null ? 0 : Math.max(0, this.days() - l); },
        remains() { const l = this.left(); return l === null ? null : Math.max(0, l - this.days()); },
        pct(v) { const m = this.t(); return m && m.balQuota ? Math.min(100, (v / m.balQuota) * 100) : 0; },
        used() { const m = this.t(); return m && m.balQuota !== null && m.balLeft !== null ? m.balQuota - m.balLeft : 0; },

        clashes() {
            if (!this.dateFrom || !this.dateTo) return [];
            return this.team.filter(r => r.from <= this.dateTo && r.to >= this.dateFrom).map(r => r.name);
        },
        clashText(en) {
            const n = this.clashes();
            if (!n.length) return '';
            const list = n.length < 2 ? n[0] : n.slice(0, -1).join(', ') + (en ? ' and ' : ' dan ') + n[n.length - 1];
            return en
                ? list + (n.length > 1 ? ' are' : ' is') + ' already away on those days. Your manager sees this too.'
                : list + ' sudah bercuti pada tarikh itu. Pengurus anda juga nampak ini.';
        },

        fmt(d) {
            if (!d) return '—';
            return new Date(d + 'T00:00').toLocaleDateString($store.ui.lang === 'ms' ? 'ms-MY' : 'en-GB', { day: 'numeric', month: 'short' });
        },
        span() {
            if (!this.dateFrom) return '—';
            if (this.single()) {
                const en = $store.ui.lang === 'en';
                const h = this.half === 'am' ? (en ? ', morning' : ', pagi') : this.half === 'pm' ? (en ? ', afternoon' : ', petang') : '';
                return this.fmt(this.dateFrom) + h;
            }
            return this.fmt(this.dateFrom) + ' – ' + this.fmt(this.dateTo);
        },
        dayWord() {
            const en = $store.ui.lang === 'en';
            if (!en) return 'hari';
            return this.days() === 1 ? 'day' : 'days';
        },

        /** Why Send is refused, or '' when the request is sendable. */
        blocker() {
            const m = this.t();
            if (!m) return 'type';
            if (!this.days()) return 'dates';
            if (this.overBy() > 0) return 'over';
            if (m.doc && !this.fileName) return 'doc';
            return '';
        },
    }" x-init="clamp()">
    @csrf
    <input type="hidden" name="leave_type_id" :value="sel">

    @foreach (['date_from', 'date_to', 'half_day_period', 'attachment', 'leave_type_id'] as $field)
        @error($field)
            <div class="uj-lv-note" data-tone="bad" style="margin:0 0 14px;">{{ $message }}</div>
        @enderror
    @endforeach

    <div class="uj-lv-steps">
        {{-- 1 · which type. Balance rides on the row you are choosing. --}}
        <div class="uj-lv-step" :data-open="step === 1 ? '' : null" :data-done="sel ? '' : null">
            <button type="button" class="uj-lv-step-head" @click="open(1)">
                <span class="uj-lv-step-n">1</span>
                <span style="flex:1;min-width:0;">
                    <span class="uj-lv-step-q" x-text="$store.ui.lang==='en' ? 'What kind of leave?' : 'Cuti jenis apa?'">What kind of leave?</span>
                    <span class="uj-lv-step-a" x-show="step !== 1" x-cloak
                          x-text="sel ? (t().name + ($store.ui.lang==='en' ? ' leave' : ' cuti')) : ($store.ui.lang==='en' ? 'Not chosen yet' : 'Belum dipilih')"></span>
                </span>
                <span class="uj-lv-step-edit" x-show="step !== 1 && sel" x-cloak
                      x-text="$store.ui.lang==='en' ? 'Change' : 'Tukar'">Change</span>
            </button>
            <div class="uj-lv-fold"><div><div class="uj-lv-fold-in">
                <div class="uj-lv-types">
                    @foreach ($primary->concat($rest) as $t)
                        @php
                            $slug = strtolower($t->name);
                            $m = $leaveMeta[$slug] ?? [null, '#8a8f98', '#eef0f2', 'Company leave type.', 'Jenis cuti syarikat.'];
                            $isRest = $rest->contains('id', $t->id);
                            $balTypeId = $t->deducts_from_leave_type_id ?: $t->id;
                            $bal = $balByType->get($balTypeId);
                            // Resolved from the loaded collection, not $t->deductsFrom: that
                            // relation is lazy and would fire a query per row.
                            $deductsName = $t->deducts_from_leave_type_id
                                ? ($leaveTypes->firstWhere('id', $t->deducts_from_leave_type_id)?->name ?? 'Annual')
                                : null;
                        @endphp
                        <button type="button" class="uj-lv-type" :aria-pressed="sel === {{ $t->id }}"
                                @click="pick({{ $t->id }})"
                                @if ($isRest) x-show="showAll" x-cloak @endif>
                            <span class="uj-lv-type-ico" style="background:{{ $m[2] }};">
                                @include('partials.leave-type-icon', ['slug' => $slug, 'accent' => $m[1]])
                            </span>
                            <span style="flex:1;min-width:0;">
                                <span class="uj-lv-type-n">{{ $t->name }} <span x-text="$store.ui.lang==='en' ? 'leave' : 'cuti'">leave</span></span>
                                <span class="uj-lv-type-d" x-text="$store.ui.lang==='en' ? @js($m[3]) : @js($m[4])">{{ $m[3] }}</span>
                            </span>
                            <span class="uj-lv-type-bal"
                                  @if ($t->is_unplanned && $t->deducts_from_leave_type_id) data-none
                                  @elseif ($bal && $bal->balance <= 3) data-low @endif>
                                @if ($t->is_unplanned && $t->deducts_from_leave_type_id)
                                    <b x-text="$store.ui.lang==='en' ? 'As needed' : 'Ikut perlu'">As needed</b>
                                    <span x-text="$store.ui.lang==='en' ? 'off {{ $deductsName }}' : 'dari {{ $deductsName }}'"></span>
                                @elseif ($bal)
                                    <b>{{ rtrim(rtrim(number_format((float) $bal->balance, 1), '0'), '.') }}</b>
                                    <span x-text="$store.ui.lang==='en' ? 'of {{ (int) $t->entitlement }} left' : 'dari {{ (int) $t->entitlement }} baki'"></span>
                                @elseif ($t->entitlement > 0)
                                    {{-- The type carries an entitlement but this person has no balance row
                                         against it. Say that, rather than "no quota", which is a different
                                         and untrue thing. --}}
                                    <b x-text="$store.ui.lang==='en' ? 'Not set up' : 'Belum ditetapkan'">Not set up</b>
                                    <span x-text="$store.ui.lang==='en' ? 'ask HR' : 'tanya HR'"></span>
                                @else
                                    <b x-text="$store.ui.lang==='en' ? 'No quota' : 'Tiada kuota'">No quota</b>
                                @endif
                            </span>
                            <svg class="uj-lv-tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                        </button>
                    @endforeach
                </div>
                @if ($rest->isNotEmpty())
                    <button type="button" class="uj-lv-more" x-show="!showAll" x-cloak @click="showAll = true"
                            x-text="$store.ui.lang==='en' ? 'Show the other {{ $rest->count() }} types' : 'Tunjuk {{ $rest->count() }} jenis lain'"></button>
                @endif

                {{-- Only the rule that applies to the chosen type is rendered. --}}
                <div class="uj-lv-note" data-tone="warn" x-show="t() && t().unplanned && t().deducts" x-cloak
                     x-text="$store.ui.lang==='en'
                        ? 'Emergency leave is not extra entitlement. Every day comes off your ' + (t() ? t().deducts : '') + ' balance, and frequent use is reported to management. If you knew about this in advance, use Annual leave.'
                        : 'Cuti kecemasan bukan kelayakan tambahan. Setiap hari ditolak daripada baki ' + (t() ? t().deducts : '') + ' anda, dan penggunaan kerap dilaporkan kepada pengurusan. Jika anda sudah tahu lebih awal, guna cuti tahunan.'"></div>
                <div class="uj-lv-note" data-tone="info" x-show="t() && !t().unplanned && t().notice > 0" x-cloak
                     x-text="$store.ui.lang==='en'
                        ? 'Apply at least ' + (t() ? t().notice : 0) + ' days ahead — the earliest date you can pick is ' + fmt(minFrom()) + '. Need time off sooner than that? Use Emergency leave.'
                        : 'Mohon sekurang-kurangnya ' + (t() ? t().notice : 0) + ' hari lebih awal — tarikh terawal yang boleh dipilih ialah ' + fmt(minFrom()) + '. Perlu cuti lebih cepat? Guna cuti kecemasan.'"></div>
            </div></div></div>
        </div>

        {{-- 2 · which days, and what it costs. --}}
        <div class="uj-lv-step" :data-open="step === 2 ? '' : null" :data-locked="reachable(2) ? null : ''"
             :data-done="days() > 0 ? '' : null">
            <button type="button" class="uj-lv-step-head" @click="open(2)">
                <span class="uj-lv-step-n">2</span>
                <span style="flex:1;min-width:0;">
                    <span class="uj-lv-step-q" x-text="$store.ui.lang==='en' ? 'Which days?' : 'Hari yang mana?'">Which days?</span>
                    <span class="uj-lv-step-a" x-show="step !== 2" x-cloak
                          x-text="days() ? (days() + ' ' + dayWord() + ' · ' + span()) : '—'"></span>
                </span>
                <span class="uj-lv-step-edit" x-show="step !== 2 && days() > 0" x-cloak
                      x-text="$store.ui.lang==='en' ? 'Change' : 'Tukar'">Change</span>
            </button>
            <div class="uj-lv-fold"><div><div class="uj-lv-fold-in">
                <div class="uj-lv-row2">
                    <div>
                        <label class="uj-lv-field" for="lv-from" x-text="$store.ui.lang==='en' ? 'First day' : 'Hari pertama'">First day</label>
                        <input class="uj-lv-in" id="lv-from" name="date_from" type="date" required
                               x-model="dateFrom" :min="minFrom()" @change="clamp()">
                    </div>
                    <div>
                        <label class="uj-lv-field" for="lv-to" x-text="$store.ui.lang==='en' ? 'Last day' : 'Hari terakhir'">Last day</label>
                        <input class="uj-lv-in" id="lv-to" name="date_to" type="date" required
                               x-model="dateTo" :min="dateFrom" @change="clamp()">
                    </div>
                </div>

                <div x-show="single()" x-cloak style="margin-top:14px;">
                    <span class="uj-lv-field" x-text="$store.ui.lang==='en' ? 'How much of that day?' : 'Berapa banyak hari itu?'">How much of that day?</span>
                    <div class="uj-lv-half">
                        <button type="button" :data-on="half === '' ? '' : null" @click="half = ''"
                                x-text="$store.ui.lang==='en' ? 'Whole day' : 'Sepanjang hari'">Whole day</button>
                        <button type="button" :data-on="half === 'am' ? '' : null" @click="half = 'am'"
                                x-text="$store.ui.lang==='en' ? 'Morning only' : 'Pagi sahaja'">Morning only</button>
                        <button type="button" :data-on="half === 'pm' ? '' : null" @click="half = 'pm'"
                                x-text="$store.ui.lang==='en' ? 'Afternoon only' : 'Petang sahaja'">Afternoon only</button>
                    </div>
                    <input type="hidden" name="half_day_period" :value="half">
                </div>

                {{-- What it costs, against what is left. --}}
                <div class="uj-lv-reckon" x-show="days() > 0" x-cloak>
                    <div class="uj-lv-reckon-top">
                        <span class="uj-lv-reckon-n" x-text="days()">0</span>
                        <span class="uj-lv-reckon-w"
                              x-text="dayWord() + ($store.ui.lang==='en' ? ' of ' + (t() ? t().name.toLowerCase() : '') + ' leave' : ' cuti ' + (t() ? t().name.toLowerCase() : ''))"></span>
                        <span class="uj-lv-reckon-d" x-text="span()"></span>
                    </div>
                    <template x-if="t() && t().balQuota">
                        <div>
                            <div class="uj-lv-bar">
                                <i data-k="used" :style="'width:' + pct(used()) + '%'"></i>
                                <i data-k="want" :style="'width:' + pct(Math.min(days(), left() ?? 0)) + '%'"></i>
                            </div>
                            <div class="uj-lv-legend">
                                <span><s style="background:#c6c2b6;"></s><b x-text="used()"></b> <span x-text="$store.ui.lang==='en' ? 'taken' : 'diambil'">taken</span></span>
                                <span><s style="background:var(--red);"></s><b x-text="days()"></b> <span x-text="$store.ui.lang==='en' ? 'requested' : 'dimohon'">requested</span></span>
                                <span><s style="background:var(--shelf);"></s><b x-text="remains()"></b>
                                    <span x-text="$store.ui.lang==='en'
                                        ? 'would remain' + (t() && t().deducts ? ' of ' + t().balName.toLowerCase() : '')
                                        : 'akan berbaki' + (t() && t().deducts ? ' bagi ' + t().balName.toLowerCase() : '')"></span></span>
                            </div>
                        </div>
                    </template>
                    <div class="uj-lv-clash" x-show="clashes().length" x-cloak
                         x-text="clashText($store.ui.lang === 'en')"></div>
                </div>

                <div class="uj-lv-note" data-tone="bad" x-show="overBy() > 0" x-cloak
                     x-text="$store.ui.lang==='en'
                        ? 'That is ' + overBy() + (overBy() === 1 ? ' day' : ' days') + ' more than your ' + (t() ? t().balName.toLowerCase() : '') + ' balance. Shorten the dates, or apply for Unpaid leave instead.'
                        : 'Itu ' + overBy() + ' hari lebih daripada baki ' + (t() ? t().balName.toLowerCase() : '') + ' anda. Pendekkan tarikh, atau mohon cuti tanpa gaji.'"></div>

                <div style="margin-top:14px;">
                    <button type="button" class="uj-btn-ghost" style="height:36px;padding:0 15px;font-size:var(--t-sm);"
                            :disabled="!days()" @click="open(3)"
                            x-text="$store.ui.lang==='en' ? 'Next' : 'Seterusnya'">Next</button>
                </div>
            </div></div></div>
        </div>

        {{-- 3 · reason and document. --}}
        <div class="uj-lv-step" :data-open="step === 3 ? '' : null" :data-locked="reachable(3) ? null : ''">
            <button type="button" class="uj-lv-step-head" @click="open(3)">
                <span class="uj-lv-step-n">3</span>
                <span style="flex:1;min-width:0;">
                    <span class="uj-lv-step-q" x-text="$store.ui.lang==='en' ? 'Anything to add?' : 'Ada apa-apa lagi?'">Anything to add?</span>
                    <span class="uj-lv-step-a" x-show="step !== 3" x-cloak
                          x-text="fileName ? fileName : (t() && t().doc ? ($store.ui.lang==='en' ? 'Document still needed' : 'Dokumen masih diperlukan') : ($store.ui.lang==='en' ? 'Optional' : 'Pilihan'))"></span>
                </span>
            </button>
            <div class="uj-lv-fold"><div><div class="uj-lv-fold-in">
                <label class="uj-lv-field" for="lv-reason">
                    <span x-text="$store.ui.lang==='en' ? 'Reason' : 'Sebab'">Reason</span>
                    <span class="uj-lv-opt" x-text="$store.ui.lang==='en' ? '— optional' : '— pilihan'"></span>
                </label>
                <textarea class="uj-lv-in" id="lv-reason" name="reason" rows="2" maxlength="500"
                          :placeholder="$store.ui.lang==='en' ? 'Your manager reads this. One line is enough.' : 'Pengurus anda akan baca ini. Satu baris sudah cukup.'">{{ old('reason') }}</textarea>

                <div style="margin-top:16px;">
                    <label class="uj-lv-field" for="lv-doc">
                        <span x-text="$store.ui.lang==='en' ? 'Supporting document' : 'Dokumen sokongan'">Supporting document</span>
                        <span class="uj-lv-req" x-show="t() && t().doc" x-cloak x-text="$store.ui.lang==='en' ? 'Required' : 'Wajib'"></span>
                        <span class="uj-lv-opt" x-show="t() && !t().doc" x-cloak x-text="$store.ui.lang==='en' ? '— optional' : '— pilihan'"></span>
                    </label>
                    <div class="uj-lv-file">
                        <input type="file" id="lv-doc" name="attachment" accept=".pdf,.jpg,.jpeg,.png"
                               :required="t() && t().doc"
                               @change="fileName = $event.target.files.length ? $event.target.files[0].name : ''">
                    </div>
                    <div class="uj-lv-note" data-tone="info" x-show="t() && t().doc" x-cloak
                         x-text="$store.ui.lang==='en'
                            ? (t() ? t().name : '') + ' leave needs a document by law — a medical certificate, hospital letter or equivalent. Only you, your manager and HR can open it.'
                            : 'Cuti ' + (t() ? t().name.toLowerCase() : '') + ' perlu dokumen mengikut undang-undang — sijil sakit, surat hospital atau setara. Hanya anda, pengurus anda dan HR boleh membukanya.'"></div>
                    <p style="font-size:var(--t-sm);color:var(--muted);margin:8px 0 0;line-height:1.5;"
                       x-show="!t() || !t().doc" x-cloak
                       x-text="$store.ui.lang==='en' ? 'PDF or photo, up to 8 MB.' : 'PDF atau gambar, sehingga 8 MB.'"></p>
                </div>
            </div></div></div>
        </div>
    </div>

    {{-- The partial carries its own heading, so this must not add a second one. --}}
    @include('partials.approval-chain')

    {{-- The whole request as one sentence, and the only place it can be sent from. --}}
    <div class="uj-lv-commit">
        <span class="uj-lv-commit-t">
            <b x-text="days() && t()
                ? (days() + ' ' + dayWord() + ($store.ui.lang==='en' ? ' of ' + t().name.toLowerCase() + ' leave, ' : ' cuti ' + t().name.toLowerCase() + ', ') + span())
                : ($store.ui.lang==='en' ? 'Choose a leave type to start' : 'Pilih jenis cuti untuk mula')"></b>
            <span x-text="{
                    type: $store.ui.lang==='en' ? 'Nothing is sent until you press Send.' : 'Tiada apa dihantar sehingga anda tekan Hantar.',
                    dates: $store.ui.lang==='en' ? 'Choose the days first.' : 'Pilih hari dahulu.',
                    over: $store.ui.lang==='en' ? 'Over your balance — shorten the dates.' : 'Melebihi baki — pendekkan tarikh.',
                    doc: $store.ui.lang==='en' ? 'Attach the required document to send this.' : 'Lampirkan dokumen wajib untuk hantar.',
                    '': $store.ui.lang==='en' ? @js($sendsTo['en']) : @js($sendsTo['ms']),
                }[blocker()]"></span>
        </span>
        <button type="submit" class="uj-btn-primary" style="height:44px;padding:0 22px;" :disabled="!!blocker()">
            <span x-text="$store.ui.lang==='en' ? 'Send application' : 'Hantar permohonan'">Send application</span>
        </button>
    </div>
</form>
