{{-- Claim back tab — one claim, asked as three questions.

     The Leave screen's stepped form, ported to claims. Replaces the slide-over
     drawer: every rule the server enforces is stated at the step it applies to
     (mileage rate at the amount, medical cap against what is left, which types
     need a receipt), so a claim is either sendable or it says why it is not.

     Field names (type, amount, title, date, reason, receipt) are unchanged, so
     ClaimController@store is untouched.

     Params: $typeMeta, $medicalCap, $medicalRemaining, $approvalChain. --}}
@php
    // Per-type facts the form reasons about client-side. `doc` mirrors the
    // server's receipt requirement; `capped` is medical, which spends the cap.
    $applyMeta = [
        'expense' => ['doc' => true,  'capped' => false, 'mileage' => false],
        'mileage' => ['doc' => false, 'capped' => false, 'mileage' => true],
        'medical' => ['doc' => true,  'capped' => true,  'mileage' => false],
        'travel'  => ['doc' => true,  'capped' => false, 'mileage' => false],
        'other'   => ['doc' => false, 'capped' => false, 'mileage' => false],
    ];
    $typeLabels = [
        'expense' => ['Expense', 'Perbelanjaan', 'A receipted work expense — supplies, meals, parking.', 'Perbelanjaan kerja yang ada resit — bekalan, makan, letak kereta.'],
        'mileage' => ['Mileage', 'Mileage', 'Driving your own car for work. Enter the distance.', 'Memandu kereta sendiri untuk kerja. Masukkan jarak.'],
        'medical' => ['Medical', 'Perubatan', 'Clinic or pharmacy, against your yearly cap.', 'Klinik atau farmasi, ditolak daripada had tahunan.'],
        'travel'  => ['Travel', 'Perjalanan', 'Flights, hotels and fares for a work trip.', 'Tiket, hotel dan tambang untuk perjalanan kerja.'],
        'other'   => ['Other', 'Lain-lain', 'Anything else. Add a note so finance can code it.', 'Apa-apa lagi. Tambah nota supaya finance boleh kod.'],
    ];
    $verifierName = collect($approvalChain['verifiers'] ?? [])->first()?->name;
    $sendsTo = $verifierName
        ? ['en' => "Goes to {$verifierName} to verify, then management to approve.",
            'ms' => "Dihantar kepada {$verifierName} untuk sahkan, kemudian pengurusan untuk lulus."]
        : ['en' => 'Goes to your manager to verify, then management to approve.',
            'ms' => 'Dihantar kepada pengurus anda untuk sahkan, kemudian pengurusan untuk lulus.'];
@endphp

<form method="post" action="{{ route('claims.store') }}" enctype="multipart/form-data" x-data="{
        meta: @js($applyMeta),
        cap: @js((float) $medicalCap),
        capLeft: @js((float) $medicalRemaining),
        rate: 0.60,
        sel: @js(old('type', null)),
        step: @js(old('type') ? 2 : 1),
        amt: @js(old('amount') ? (float) old('amount') : 0),
        km: '',
        title: @js(old('title', '')),
        fileName: '',

        t() { return this.sel ? this.meta[this.sel] : null; },
        open(n) { if (this.reachable(n)) this.step = n; },
        reachable(n) { return n === 1 || (n === 2 && this.sel) || (n === 3 && this.sel && this.amt > 0); },

        pick(id) { this.sel = id; this.amt = 0; this.km = ''; this.step = 2; },
        setKm(v) { this.km = v; const n = parseFloat(v) || 0; this.amt = n ? +(n * this.rate).toFixed(2) : 0; },
        setAmt(v) { this.amt = parseFloat(v) || 0; },

        money(v) { return 'RM ' + Number(v || 0).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        overCap() { const m = this.t(); return m && m.capped ? Math.max(0, this.amt - this.capLeft) : 0; },
        capRemains() { return Math.max(0, this.capLeft - this.amt); },
        pct(v) { return this.cap ? Math.min(100, (v / this.cap) * 100) : 0; },
        capUsed() { return this.cap - this.capLeft; },

        /** Why Submit is refused, or '' when the claim is sendable. */
        blocker() {
            const m = this.t();
            if (!m) return 'type';
            if (!(this.amt > 0)) return 'amount';
            if (this.overCap() > 0) return 'over';
            if (!this.title.trim()) return 'title';
            if (m.doc && !this.fileName) return 'doc';
            return '';
        },
    }">
    @csrf
    <input type="hidden" name="type" :value="sel">

    @if ($errors->any())
        <div class="uj-lv-note" data-tone="bad" style="margin:0 0 14px;">{{ $errors->first() }}</div>
    @endif

    <div class="uj-lv-steps">
        {{-- 1 · which type. Medical carries its cap on the row. --}}
        <div class="uj-lv-step" :data-open="step === 1 ? '' : null" :data-done="sel ? '' : null">
            <button type="button" class="uj-lv-step-head" @click="open(1)">
                <span class="uj-lv-step-n">1</span>
                <span style="flex:1;min-width:0;">
                    <span class="uj-lv-step-q" x-text="$store.ui.lang==='en' ? 'What are you claiming for?' : 'Anda tuntut untuk apa?'">What are you claiming for?</span>
                    <span class="uj-lv-step-a" x-show="step !== 1" x-cloak
                          x-text="sel ? ({@foreach ($typeLabels as $k => $l) '{{ $k }}': ($store.ui.lang==='en' ? @js($l[0]) : @js($l[1])), @endforeach }[sel]) : ($store.ui.lang==='en' ? 'Not chosen yet' : 'Belum dipilih')"></span>
                </span>
                <span class="uj-lv-step-edit" x-show="step !== 1 && sel" x-cloak
                      x-text="$store.ui.lang==='en' ? 'Change' : 'Tukar'">Change</span>
            </button>
            <div class="uj-lv-fold"><div><div class="uj-lv-fold-in">
                <div class="uj-lv-types">
                    @foreach ($typeLabels as $slug => $l)
                        @php $tm = $typeMeta[$slug]; @endphp
                        <button type="button" class="uj-lv-type" :aria-pressed="sel === '{{ $slug }}'" @click="pick('{{ $slug }}')">
                            <span class="uj-lv-type-ico" style="background:{{ $tm['bg'] }};color:{{ $tm['tint'] }};">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $tm['icon'] }}"/></svg>
                            </span>
                            <span style="flex:1;min-width:0;">
                                <span class="uj-lv-type-n">{{ $l[0] }}</span>
                                <span class="uj-lv-type-d" x-text="$store.ui.lang==='en' ? @js($l[2]) : @js($l[3])">{{ $l[2] }}</span>
                            </span>
                            @if ($slug === 'medical')
                                <span class="uj-lv-type-bal" @if ($medicalRemaining <= $medicalCap * 0.2) data-low @endif>
                                    <b>{{ number_format($medicalRemaining, 0) }}</b>
                                    <span x-text="$store.ui.lang==='en' ? 'RM left of cap' : 'RM baki had'">RM left of cap</span>
                                </span>
                            @endif
                            <svg class="uj-lv-tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                        </button>
                    @endforeach
                </div>
            </div></div></div>
        </div>

        {{-- 2 · how much. Mileage is worked out; medical shows the cap. --}}
        <div class="uj-lv-step" :data-open="step === 2 ? '' : null" :data-locked="reachable(2) ? null : ''"
             :data-done="amt > 0 ? '' : null">
            <button type="button" class="uj-lv-step-head" @click="open(2)">
                <span class="uj-lv-step-n">2</span>
                <span style="flex:1;min-width:0;">
                    <span class="uj-lv-step-q" x-text="$store.ui.lang==='en' ? 'How much?' : 'Berapa?'">How much?</span>
                    <span class="uj-lv-step-a" x-show="step !== 2" x-cloak
                          x-text="amt > 0 ? (money(amt) + (t() && t().mileage && km ? ' · ' + (parseFloat(km)||0) + ' km' : '')) : '—'"></span>
                </span>
                <span class="uj-lv-step-edit" x-show="step !== 2 && amt > 0" x-cloak
                      x-text="$store.ui.lang==='en' ? 'Change' : 'Tukar'">Change</span>
            </button>
            <div class="uj-lv-fold"><div><div class="uj-lv-fold-in">
                {{-- Mileage helper — enter distance, the amount is worked out. --}}
                <div x-show="t() && t().mileage" x-cloak style="margin-bottom:14px;">
                    <label class="uj-lv-field">
                        <span x-text="$store.ui.lang==='en' ? 'Distance' : 'Jarak'">Distance</span>
                        <span class="uj-lv-opt" x-text="$store.ui.lang==='en' ? '— we work out the amount' : '— kami kira jumlahnya'"></span>
                    </label>
                    <div class="uj-cl-km">
                        <div class="uj-cl-km-in">
                            <input type="text" inputmode="decimal" x-model="km" @input="setKm($event.target.value)" placeholder="0">
                            <span class="u">km</span>
                        </div>
                        <span class="uj-cl-km-rate">× RM&nbsp;0.60 / km</span>
                    </div>
                </div>

                <label class="uj-lv-field">
                    <span x-show="!t() || !t().mileage" x-text="$store.ui.lang==='en' ? 'Amount on the receipt' : 'Jumlah pada resit'">Amount on the receipt</span>
                    <span x-show="t() && t().mileage" x-cloak x-text="$store.ui.lang==='en' ? 'Amount — worked out for you' : 'Jumlah — dikira untuk anda'"></span>
                </label>
                <div class="uj-cl-amount">
                    <span class="cur">RM</span>
                    <input name="amount" type="number" step="0.01" min="0.01" x-model="amt" @input="setAmt($event.target.value)"
                           :readonly="t() && t().mileage" required placeholder="0.00">
                </div>

                {{-- Medical cap reckoning, in the same idiom as the leave balance bar. --}}
                <div class="uj-lv-reckon" x-show="t() && t().capped" x-cloak>
                    <div class="uj-lv-reckon-top">
                        <span class="uj-lv-reckon-n" x-text="money(amt)">RM 0.00</span>
                        <span class="uj-lv-reckon-w" x-text="$store.ui.lang==='en' ? 'of your medical cap' : 'daripada had perubatan'"></span>
                    </div>
                    <div class="uj-lv-bar">
                        <i data-k="used" :style="'width:' + pct(capUsed()) + '%'"></i>
                        <i data-k="want" :style="'width:' + pct(Math.min(amt, capLeft)) + '%'"></i>
                    </div>
                    <div class="uj-lv-legend">
                        <span><s style="background:#c6c2b6;"></s><b x-text="money(capUsed())"></b> <span x-text="$store.ui.lang==='en' ? 'used' : 'guna'">used</span></span>
                        <span><s style="background:var(--red);"></s><b x-text="money(amt)"></b> <span x-text="$store.ui.lang==='en' ? 'this claim' : 'tuntutan ini'">this claim</span></span>
                        <span><s style="background:var(--shelf);"></s><b x-text="money(capRemains())"></b> <span x-text="$store.ui.lang==='en' ? 'would remain' : 'akan berbaki'">would remain</span></span>
                    </div>
                </div>

                {{-- Mileage breakdown, and the one hard error we can call — over cap. --}}
                <div class="uj-lv-note" data-tone="info" x-show="t() && t().mileage && amt > 0" x-cloak
                     x-text="(parseFloat(km)||0) + ($store.ui.lang==='en' ? ' km × RM 0.60 = ' : ' km × RM 0.60 = ') + money(amt) + ($store.ui.lang==='en' ? '. Keep any toll or parking receipts.' : '. Simpan resit tol atau letak kereta.')"></div>
                <div class="uj-lv-note" data-tone="bad" x-show="overCap() > 0" x-cloak
                     x-text="$store.ui.lang==='en'
                        ? 'That is ' + money(overCap()) + ' over your medical cap for the year. The excess is not reimbursed.'
                        : 'Itu ' + money(overCap()) + ' melebihi had perubatan tahun ini. Lebihan tidak dibayar balik.'"></div>

                <div style="margin-top:14px;">
                    <button type="button" class="uj-btn-ghost" style="height:36px;padding:0 15px;font-size:var(--t-sm);"
                            :disabled="!(amt > 0) || overCap() > 0" @click="open(3)"
                            x-text="$store.ui.lang==='en' ? 'Next' : 'Seterusnya'">Next</button>
                </div>
            </div></div></div>
        </div>

        {{-- 3 · the details and receipt. --}}
        <div class="uj-lv-step" :data-open="step === 3 ? '' : null" :data-locked="reachable(3) ? null : ''">
            <button type="button" class="uj-lv-step-head" @click="open(3)">
                <span class="uj-lv-step-n">3</span>
                <span style="flex:1;min-width:0;">
                    <span class="uj-lv-step-q" x-text="$store.ui.lang==='en' ? 'The details' : 'Butiran'">The details</span>
                    <span class="uj-lv-step-a" x-show="step !== 3" x-cloak
                          x-text="title.trim() ? title : (t() && t().doc ? ($store.ui.lang==='en' ? 'Receipt still needed' : 'Resit masih diperlukan') : '—')"></span>
                </span>
            </button>
            <div class="uj-lv-fold"><div><div class="uj-lv-fold-in">
                <label class="uj-lv-field" for="cl-title" x-text="$store.ui.lang==='en' ? 'What was it for?' : 'Untuk apa?'">What was it for?</label>
                <input class="uj-lv-in" id="cl-title" name="title" x-model="title" required maxlength="255"
                       :placeholder="$store.ui.lang==='en' ? 'e.g. Client visit — mileage to Klang' : 'cth. Lawatan client — mileage ke Klang'">

                <div class="uj-lv-row2" style="margin-top:14px;">
                    <div>
                        <label class="uj-lv-field" for="cl-date" x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</label>
                        <input class="uj-lv-in" id="cl-date" name="date" type="date" required value="{{ old('date', now()->toDateString()) }}">
                    </div>
                    <div>
                        <label class="uj-lv-field" for="cl-reason">
                            <span x-text="$store.ui.lang==='en' ? 'Notes' : 'Nota'">Notes</span>
                            <span class="uj-lv-opt" x-text="$store.ui.lang==='en' ? '— optional' : '— pilihan'"></span>
                        </label>
                        <input class="uj-lv-in" id="cl-reason" name="reason" value="{{ old('reason') }}"
                               :placeholder="$store.ui.lang==='en' ? 'Anything finance should know' : 'Apa-apa untuk finance'">
                    </div>
                </div>

                <div style="margin-top:16px;">
                    <label class="uj-lv-field">
                        <span x-text="$store.ui.lang==='en' ? 'Receipt' : 'Resit'">Receipt</span>
                        <span class="uj-lv-req" x-show="t() && t().doc" x-cloak x-text="$store.ui.lang==='en' ? 'Required' : 'Wajib'"></span>
                        <span class="uj-lv-opt" x-show="t() && !t().doc" x-cloak x-text="$store.ui.lang==='en' ? '— optional' : '— pilihan'"></span>
                    </label>
                    <div class="uj-lv-file">
                        <input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png" :required="t() && t().doc"
                               @change="fileName = $event.target.files.length ? $event.target.files[0].name : ''">
                    </div>
                    <div class="uj-lv-note" data-tone="info" x-show="t() && t().doc" x-cloak
                         x-text="$store.ui.lang==='en'
                            ? 'This type needs the receipt attached. Only you, your manager and finance can open it.'
                            : 'Jenis ini perlu resit dilampirkan. Hanya anda, pengurus anda dan finance boleh membukanya.'"></div>
                    <p style="font-size:var(--t-sm);color:var(--muted);margin:8px 0 0;line-height:1.5;"
                       x-show="!t() || !t().doc" x-cloak
                       x-text="$store.ui.lang==='en' ? 'PDF or photo, up to 8 MB.' : 'PDF atau gambar, sehingga 8 MB.'"></p>
                </div>
            </div></div></div>
        </div>
    </div>

    {{-- Who signs this off — the two-step chain, shown up front so the claimant knows
         who verifies and who approves before submitting. Compact strip, no tall heading. --}}
    <div style="margin-top:22px;padding:14px 16px;border:1px solid var(--hairline-soft);border-radius:12px;background:var(--canvas);">
        <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;margin-bottom:11px;">
            <span x-text="$store.ui.lang==='en' ? 'Who approves this claim' : 'Siapa luluskan tuntutan ini'">Who approves this claim</span>
        </div>
        @include('partials.approval-chain', ['compact' => true])
    </div>

    {{-- The whole claim as one sentence, and the only place it can be sent from. --}}
    <div class="uj-lv-commit">
        <span class="uj-lv-commit-t">
            <b x-text="amt > 0 && t()
                ? (money(amt) + ($store.ui.lang==='en' ? ' claim' : ' tuntutan'))
                : ($store.ui.lang==='en' ? 'Pick what you are claiming for' : 'Pilih apa yang anda tuntut')"></b>
            <span x-text="{
                    type: $store.ui.lang==='en' ? 'Nothing is sent until you press Submit.' : 'Tiada apa dihantar sehingga anda tekan Hantar.',
                    amount: $store.ui.lang==='en' ? 'Enter how much you are claiming.' : 'Masukkan jumlah tuntutan anda.',
                    over: $store.ui.lang==='en' ? 'Over your medical cap.' : 'Melebihi had perubatan.',
                    title: $store.ui.lang==='en' ? 'Add what it was for in step 3.' : 'Nyatakan untuk apa di langkah 3.',
                    doc: $store.ui.lang==='en' ? 'Attach the receipt to send this.' : 'Lampirkan resit untuk hantar.',
                    '': $store.ui.lang==='en' ? @js($sendsTo['en']) : @js($sendsTo['ms']),
                }[blocker()]"></span>
        </span>
        <button type="submit" class="uj-btn-primary" style="height:44px;padding:0 22px;" :disabled="!!blocker()">
            <span x-text="$store.ui.lang==='en' ? 'Submit claim' : 'Hantar tuntutan'">Submit claim</span>
        </button>
    </div>
</form>
