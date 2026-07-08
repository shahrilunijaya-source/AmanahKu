@extends('layouts.app')

@php
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';
    $milestoneColor = [
        '30-day' => 'var(--info)',
        '60-day' => 'var(--amber)',
        '90-day' => 'var(--success)',
        'Ad-hoc' => 'var(--muted)',
    ];
@endphp

@section('screen')

@include('partials.guide', [
    'key' => 'probation',
    'en'  => [
        'title' => 'Probation tracking',
        'body'  => 'Track new hires through their probation period, log check-ins along the way, and record the final confirm, extend or terminate decision. Probation is typically 1 to 6 months — the goal is to decide before the end date, not after.',
        'who'   => 'HR & managers only',
        'steps' => [
            'Click "+ New review", pick the new hire, set the start date and probation length in days.',
            'Add check-ins at the 30, 60 and 90-day milestones — note progress, concerns and a rating.',
            'Watch the countdown: amber means the decision is due soon, red means it is overdue.',
            'Before the end date, click "Record decision" — Confirm activates the employee, Extend adds more days, Terminate ends it.',
        ],
    ],
    'ms'  => [
        'title' => 'Penjejakan probation',
        'body'  => 'Jejak pekerja baharu sepanjang tempoh probation mereka, log check-in di sepanjang jalan, dan rekod keputusan akhir untuk sahkan, lanjutkan atau tamatkan. Probation biasanya 1 hingga 6 bulan — matlamatnya adalah membuat keputusan sebelum tarikh tamat, bukan selepasnya.',
        'who'   => 'HR & pengurus sahaja',
        'steps' => [
            'Klik "+ New review", pilih pekerja baharu, tetapkan tarikh mula dan tempoh probation dalam hari.',
            'Tambah check-in pada milestone 30, 60 dan 90 hari — catat kemajuan, kebimbangan dan penarafan.',
            'Perhati kiraan detik: kuning bermakna keputusan perlu dibuat tidak lama lagi, merah bermakna sudah lewat.',
            'Sebelum tarikh tamat, klik "Record decision" — Confirm mengaktifkan pekerja, Extend menambah hari, Terminate menamatkannya.',
        ],
    ],
])

@if (! ($privileged ?? false))
{{-- ───────────────────────── No access: restricted HR area ───────────────────────── --}}
<div class="uj-card" style="padding:48px 24px;text-align:center;">
    <div style="width:52px;height:52px;border-radius:14px;background:var(--hairline-soft);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4z"></path><path d="M9 12l2 2 4-4"></path></svg>
    </div>
    <h3 style="font-size:16px;font-weight:600;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Restricted — HR only' : 'Terhad — HR sahaja'"></span></h3>
    <p style="font-size:13.5px;color:var(--muted);max-width:420px;margin:0 auto;"><span x-text="$store.ui.lang==='en' ? 'Probation reviews and check-in notes are confidential and restricted to HR and management.' : 'Semakan probation dan catatan check-in adalah sulit dan terhad kepada HR dan pengurusan.'"></span></p>
</div>

@else
{{-- ───────────────────────── Privileged view: track probations ───────────────────────── --}}
<div x-data="{
    start: {{ $errors->any() && old('_form') === 'start' ? 'true' : 'false' }},
    addci: {{ $errors->any() && old('_form') === 'checkin' ? (int) old('_review') : 'null' }},
    open: {{ $errors->any() && old('_form') === 'decide' ? (int) old('_review') : 'null' }}
}">

    {{-- Summary counts --}}
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
        <div class="uj-card uj-stat" style="flex:1;min-width:140px;">
            <div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'On probation' : 'Dalam probation'">On probation</span></div>
            <div class="uj-stat-value" style="color:var(--info);">{{ $counts['on_probation'] }}</div>
        </div>
        <div class="uj-card uj-stat" style="flex:1;min-width:140px;">
            <div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Due for decision' : 'Perlu keputusan'">Due for decision</span></div>
            <div class="uj-stat-value" style="color:var(--amber);">{{ $counts['due_for_decision'] }}</div>
        </div>
        <div class="uj-card uj-stat" style="flex:1;min-width:140px;">
            <div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Confirmed' : 'Disahkan'">Confirmed</span></div>
            <div class="uj-stat-value" style="color:var(--success);">{{ $counts['confirmed'] }}</div>
        </div>
    </div>

    {{-- Start a probation review --}}
    <div class="uj-card" style="padding:20px;margin-bottom:16px;">
        <div class="uj-card-head" style="padding:0;margin-bottom:14px;">
            <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Start a probation review' : 'Mulakan semakan probation'">Start a probation review</span></h3>
            <button @click="start = ! start" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;"><span x-text="start ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ New review' : '+ Semakan baharu')"></span></button>
        </div>
        <form x-show="start" x-cloak method="post" action="{{ route('probation.store') }}">
            @csrf
            <input type="hidden" name="_form" value="start" />
            @if ($errors->any() && old('_form') === 'start')
                <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>
            @endif
            @if ($eligible->isEmpty())
                <p style="font-size:13px;color:var(--muted);margin:0;"><span x-text="$store.ui.lang==='en' ? 'No employees are currently awaiting a probation review.' : 'Tiada pekerja sedang menunggu semakan probation buat masa ini.'"></span></p>
            @else
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:start;">
                    <div>
                        <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Employee' : 'Pekerja'">Employee</span> *</label>
                        <select name="employee_id" required style="{{ $fs }}width:100%;">
                            <option value="" x-text="$store.ui.lang==='en' ? 'Select employee…' : 'Pilih pekerja…'">Select employee…</option>
                            @foreach ($eligible as $e)
                                <option value="{{ $e->id }}" @selected((string) old('employee_id') === (string) $e->id)>{{ $e->name }}@if ($e->position) · {{ $e->position }}@endif</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Start date' : 'Tarikh mula'">Start date</span> *</label>
                        <input name="start_date" type="date" value="{{ old('start_date', now()->toDateString()) }}" required style="{{ $fs }}width:100%;" />
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Length (days)' : 'Tempoh (hari)'">Length (days)</span> *</label>
                        <input name="length_days" type="number" min="1" max="365" value="{{ old('length_days', $defaultLength) }}" required style="{{ $fs }}margin-bottom:6px;width:100%;" />
                        @include('partials.hint', ['en' => 'The probation period from the contract, in days. 90 days (3 months) is common; 180 (6 months) for senior roles.', 'ms' => 'Tempoh probation daripada kontrak, dalam hari. 90 hari (3 bulan) adalah biasa; 180 (6 bulan) untuk jawatan kanan.'])
                    </div>
                </div>
                <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;"><span x-text="$store.ui.lang==='en' ? 'Start review' : 'Mulakan semakan'">Start review</span></button>
            @endif
        </form>
    </div>

    {{-- Active reviews --}}
    @forelse ($reviews as $r)
        @php
            $today = now()->startOfDay();
            $daysRemaining = (int) round($today->diffInDays($r->end_date->copy()->startOfDay(), false));
            $remainLabel = $daysRemaining > 0 ? $daysRemaining.' days remaining' : ($daysRemaining === 0 ? 'Decision due today' : abs($daysRemaining).' days overdue');
            $remainLabelMs = $daysRemaining > 0 ? $daysRemaining.' hari lagi' : ($daysRemaining === 0 ? 'Keputusan perlu dibuat hari ini' : abs($daysRemaining).' hari lewat');
            $remainColor = $daysRemaining < 0 ? 'var(--red)' : ($daysRemaining <= 14 ? 'var(--amber)' : 'var(--ink)');
            $latestRating = optional($r->checkins->whereNotNull('rating')->last())->rating;
            // Elapsed share of the probation window, for the progress bar (clamped 0–100).
            $elapsed = max(0, $r->length_days - max($daysRemaining, 0));
            $pct = $r->length_days > 0 ? min(100, max(0, (int) round($elapsed / $r->length_days * 100))) : 0;
            $checkinCount = $r->checkins->count();
        @endphp
        <div class="uj-card" style="margin-bottom:16px;">
            <div style="padding:18px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div style="display:flex;align-items:center;gap:14px;">
                    <div style="width:44px;height:44px;border-radius:50%;background:{{ $r->employee?->avatar_color ?? '#c08532' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:600;flex-shrink:0;">{{ $r->employee?->initials }}</div>
                    <div>
                        <h3 style="font-size:16px;font-weight:600;color:var(--ink);margin:0;">{{ $r->employee?->name }}</h3>
                        <p style="font-size:12.5px;color:var(--muted);margin:3px 0 0;">{{ $r->employee?->position }} · {{ $r->start_date->format('j M Y') }} → {{ $r->end_date->format('j M Y') }} · {{ $r->length_days }} <span x-text="$store.ui.lang==='en' ? 'days' : 'hari'">days</span></p>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:13.5px;font-weight:700;color:{{ $remainColor }};"><span x-text="$store.ui.lang==='en' ? @json($remainLabel) : @json($remainLabelMs)">{{ $remainLabel }}</span></div>
                    @if ($latestRating)
                        <div style="display:flex;align-items:center;gap:6px;justify-content:flex-end;margin-top:5px;">
                            <span style="font-size:11px;color:var(--muted-soft);"><span x-text="$store.ui.lang==='en' ? 'Latest' : 'Terkini'">Latest</span></span>
                            @include('partials.rating-dots', ['rating' => $latestRating])
                        </div>
                    @endif
                </div>
            </div>

            {{-- Probation progress: how far through the window we are; colour tracks urgency. --}}
            <div style="padding:0 20px 16px;">
                <div style="height:6px;border-radius:9999px;background:var(--hairline-soft);overflow:hidden;">
                    <div style="height:100%;width:{{ $pct }}%;background:{{ $remainColor === 'var(--ink)' ? 'var(--info)' : $remainColor }};border-radius:9999px;transition:width .3s;"></div>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:10.5px;color:var(--muted-soft);margin-top:5px;font-family:var(--font-mono);">
                    <span>{{ $r->start_date->format('j M') }}</span>
                    <span>{{ $pct }}%</span>
                    <span>{{ $r->end_date->format('j M') }}</span>
                </div>
            </div>
            <div style="border-top:1px solid var(--hairline-soft);"></div>

            {{-- Check-in timeline --}}
            <div style="padding:16px 20px;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;">
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;"><span x-text="$store.ui.lang==='en' ? 'Check-ins' : 'Check-in'">Check-ins</span>@if ($checkinCount) <span style="color:var(--muted-soft);">· {{ $checkinCount }}</span>@endif</div>
                    <button type="button" @click="addci = (addci === {{ $r->id }} ? null : {{ $r->id }})" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;">
                        <span x-text="addci === {{ $r->id }} ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Add check-in' : '+ Tambah check-in')">+ Add check-in</span>
                    </button>
                </div>

                @if ($checkinCount)
                    {{-- Vertical timeline: a milestone dot + connector down the left rail. --}}
                    <div style="position:relative;padding-left:20px;">
                        <div style="position:absolute;left:4px;top:6px;bottom:6px;width:2px;background:var(--hairline-soft);"></div>
                        @foreach ($r->checkins as $c)
                            @php $mc = $milestoneColor[$c->milestone] ?? 'var(--muted)'; @endphp
                            <div style="position:relative;padding:0 0 14px;">
                                <span style="position:absolute;left:-20px;top:3px;width:10px;height:10px;border-radius:50%;background:{{ $mc }};box-shadow:0 0 0 3px var(--surface, #fff);"></span>
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:3px;">
                                    <span style="font-size:10.5px;font-weight:700;color:#fff;background:{{ $mc }};padding:2px 8px;border-radius:9999px;">{{ $c->milestone }}</span>
                                    <span style="font-size:11.5px;color:var(--muted-soft);">{{ $c->checkin_date->format('j M Y') }}</span>
                                    @if ($c->rating)<span style="margin-left:auto;">@include('partials.rating-dots', ['rating' => $c->rating, 'size' => 6])</span>@endif
                                </div>
                                <div style="font-size:13px;color:var(--body);line-height:1.55;white-space:pre-line;">{{ $c->note }}</div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div style="font-size:13px;color:var(--muted);padding:2px 0 8px;"><span x-text="$store.ui.lang==='en' ? 'No check-ins yet — add the first one to start the timeline.' : 'Tiada check-in lagi — tambah yang pertama untuk mula garis masa.'"></span></div>
                @endif

                {{-- Add check-in (collapsed by default) --}}
                <form x-show="addci === {{ $r->id }}" x-cloak x-data="{ rating: {{ (int) old('rating', 0) }} }" method="post" action="{{ route('probation.checkin', $r) }}" style="margin-top:6px;padding:14px;background:var(--canvas);border-radius:10px;">
                    @csrf
                    <input type="hidden" name="_form" value="checkin" />
                    <input type="hidden" name="_review" value="{{ $r->id }}" />
                    @if ($errors->any() && old('_form') === 'checkin' && (int) old('_review') === $r->id)
                        <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:12px;">{{ $errors->first() }}</div>
                    @endif
                    {{-- align-items:start + equal control heights keep the three fields on one
                         baseline; the milestone hint moved out of the grid (below) so it no
                         longer stretches its column and skews the row. --}}
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;align-items:start;">
                        <div>
                            <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Milestone' : 'Peringkat'">Milestone</span> *</label>
                            <select name="milestone" required style="{{ $fs }}width:100%;">
                                @foreach ($milestones as $m)
                                    <option value="{{ $m }}" @selected(old('milestone') === $m)>{{ $m }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Rating' : 'Penarafan'">Rating</span> <span style="color:var(--muted-soft);font-weight:400;"><span x-text="$store.ui.lang==='en' ? '(optional)' : '(pilihan)'">(optional)</span></span></label>
                            {{-- Click a star to set 1–5; click the current one again to clear. --}}
                            <div style="display:flex;align-items:center;gap:2px;height:38px;">
                                @for ($i = 1; $i <= 5; $i++)
                                    <button type="button" @click="rating = (rating === {{ $i }} ? 0 : {{ $i }})"
                                            :style="rating >= {{ $i }} ? { color:'var(--amber)' } : { color:'var(--hairline)' }"
                                            style="background:none;border:none;padding:0 3px;cursor:pointer;font-size:23px;line-height:1;color:var(--hairline);"
                                            :aria-label="$store.ui.lang==='en' ? 'Rate {{ $i }}' : 'Nilai {{ $i }}'">★</button>
                                @endfor
                                <span x-cloak x-show="rating" x-text="rating + '/5'" style="font-size:12px;color:var(--muted);margin-left:6px;font-family:var(--font-mono);"></span>
                            </div>
                            <input type="hidden" name="rating" :value="rating || ''" />
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</span></label>
                            <input name="checkin_date" type="date" value="{{ old('checkin_date', now()->toDateString()) }}" style="{{ $fs }}width:100%;" />
                        </div>
                    </div>
                    <div style="margin-top:8px;">
                        @include('partials.hint', ['en' => 'Milestone: which checkpoint this note is for. Use 30/60/90-day for the scheduled reviews, or Ad-hoc for an unplanned conversation.', 'ms' => 'Peringkat: catatan ini untuk checkpoint yang mana. Guna 30/60/90-day untuk semakan berjadual, atau Ad-hoc untuk perbualan luar jangka.'])
                    </div>
                    <div style="margin-top:10px;">
                        <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Note' : 'Nota'">Note</span> *</label>
                        <textarea name="note" required maxlength="5000" rows="2" placeholder="What did this check-in cover? Progress, concerns, next steps." :placeholder="$store.ui.lang==='en' ? 'What did this check-in cover? Progress, concerns, next steps.' : 'Apa yang check-in ini liputi? Kemajuan, kebimbangan, langkah seterusnya.'" style="width:100%;padding:10px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;resize:vertical;">{{ old('note') }}</textarea>
                    </div>
                    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;margin-top:12px;"><span x-text="$store.ui.lang==='en' ? 'Save check-in' : 'Simpan check-in'">Save check-in</span></button>
                </form>
            </div>

            {{-- Decision --}}
            <div style="padding:16px 20px;border-top:1px solid var(--hairline-soft);background:var(--canvas);">
                <button @click="open = (open === {{ $r->id }} ? null : {{ $r->id }})" class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Record decision' : 'Rekod keputusan'">Record decision</span></button>
                <div x-show="open === {{ $r->id }}" x-cloak style="margin-top:14px;" x-data="{ decision: 'confirm' }">
                    <form method="post" action="{{ route('probation.decide', $r) }}">
                        @csrf
                        <input type="hidden" name="_form" value="decide" />
                        <input type="hidden" name="_review" value="{{ $r->id }}" />
                        <input type="hidden" name="decision" :value="decision" />
                        @if ($errors->any() && old('_form') === 'decide' && (int) old('_review') === $r->id)
                            <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:12px;">{{ $errors->first() }}</div>
                        @endif
                        <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Decision' : 'Keputusan'">Decision</span> *</label>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;">
                            <button type="button" @click="decision='confirm'"
                                    :style="decision==='confirm' ? { borderColor:'var(--success)', background:'#e9f5ee' } : {}"
                                    style="text-align:left;padding:10px 12px;border:1px solid var(--hairline);border-radius:10px;background:#fff;cursor:pointer;">
                                <div style="font-size:13px;font-weight:600;color:var(--ink);"><span x-text="$store.ui.lang==='en' ? '✓ Confirm' : '✓ Sahkan'">✓ Confirm</span></div>
                                <div style="font-size:11px;color:var(--muted);margin-top:2px;"><span x-text="$store.ui.lang==='en' ? 'Activate as permanent' : 'Aktif sebagai tetap'">Activate as permanent</span></div>
                            </button>
                            <button type="button" @click="decision='extend'"
                                    :style="decision==='extend' ? { borderColor:'var(--amber)', background:'#fbf3e6' } : {}"
                                    style="text-align:left;padding:10px 12px;border:1px solid var(--hairline);border-radius:10px;background:#fff;cursor:pointer;">
                                <div style="font-size:13px;font-weight:600;color:var(--ink);"><span x-text="$store.ui.lang==='en' ? '↻ Extend' : '↻ Lanjutkan'">↻ Extend</span></div>
                                <div style="font-size:11px;color:var(--muted);margin-top:2px;"><span x-text="$store.ui.lang==='en' ? 'Needs more time' : 'Perlu masa lagi'">Needs more time</span></div>
                            </button>
                            <button type="button" @click="decision='terminate'"
                                    :style="decision==='terminate' ? { borderColor:'var(--red)', background:'var(--red-tint)' } : {}"
                                    style="text-align:left;padding:10px 12px;border:1px solid var(--hairline);border-radius:10px;background:#fff;cursor:pointer;">
                                <div style="font-size:13px;font-weight:600;color:var(--ink);"><span x-text="$store.ui.lang==='en' ? '✕ Terminate' : '✕ Tamatkan'">✕ Terminate</span></div>
                                <div style="font-size:11px;color:var(--muted);margin-top:2px;"><span x-text="$store.ui.lang==='en' ? 'Ends employment' : 'Menamatkan pekerjaan'">Ends employment</span></div>
                            </button>
                        </div>
                        <div style="margin-top:8px;">
                            @include('partials.hint', ['en' => 'Confirm = passed, becomes a permanent employee. Extend = needs more time, adds days below. Terminate = ends employment. This closes the review — be sure before saving.', 'ms' => 'Confirm = lulus, menjadi pekerja tetap. Extend = perlu masa lagi, tambah hari di bawah. Terminate = menamatkan pekerjaan. Ini menutup semakan — pastikan dahulu sebelum simpan.', 'tone' => 'warn'])
                        </div>
                        <div x-show="decision === 'extend'" x-cloak style="margin-top:12px;max-width:220px;">
                            <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Extend by (days)' : 'Lanjut sebanyak (hari)'">Extend by (days)</span></label>
                            <input name="extend_days" type="number" min="1" max="365" value="{{ old('extend_days', 30) }}" style="{{ $fs }}width:100%;" />
                        </div>
                        <div style="margin-top:12px;">
                            <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Sign-off note' : 'Catatan pengesahan'">Sign-off note</span></label>
                            <textarea name="decision_note" maxlength="5000" rows="3" placeholder="Record the rationale for this decision." :placeholder="$store.ui.lang==='en' ? 'Record the rationale for this decision.' : 'Rekod justifikasi untuk keputusan ini.'" style="width:100%;padding:10px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;resize:vertical;">{{ old('decision_note') }}</textarea>
                        </div>
                        <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;margin-top:14px;"><span x-text="$store.ui.lang==='en' ? 'Save decision' : 'Simpan keputusan'">Save decision</span></button>
                    </form>
                </div>
            </div>
        </div>
    @empty
        <div class="uj-card" style="padding:40px 24px;text-align:center;">
            <p style="font-size:13.5px;color:var(--muted);margin:0;"><span x-text="$store.ui.lang==='en' ? 'No active probation reviews. Start one above for any new hire on probation.' : 'Tiada semakan probation aktif. Mulakan satu di atas untuk mana-mana pekerja baharu yang sedang probation.'"></span></p>
        </div>
    @endforelse
</div>
@endif

@endsection
