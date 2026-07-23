@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'leave',
    'en'  => [
        'title' => 'Leave applications',
        'body'  => 'Staff apply for annual, sick and other leave here. Each application is checked against the person\'s remaining balance and sent to their manager to approve or reject. Approved days are deducted from the balance shown at the top.',
        'who'   => 'Staff apply · Managers & HR approve',
        'steps' => [
            'Check the balance cards above — you can only apply for days you still have left (amber means running low).',
            'Pick the leave type, then the From and To dates. The number of days is worked out for you.',
            'Add a reason if needed and submit. The request shows as "Submitted" until a manager decides.',
            'Watch the "Team on leave" panel so you don\'t leave the team short-handed on the same dates.',
        ],
    ],
    'ms'  => [
        'title' => 'Permohonan cuti',
        'body'  => 'Staf mohon cuti tahunan, sakit dan lain-lain di sini. Setiap permohonan disemak dengan baki cuti yang berbaki, kemudian dihantar kepada pengurus untuk lulus atau tolak. Hari yang diluluskan ditolak daripada baki yang dipaparkan di bahagian atas.',
        'who'   => 'Staf mohon · Pengurus & HR luluskan',
        'steps' => [
            'Semak kad baki di atas — anda hanya boleh mohon hari yang masih berbaki (amber bermaksud baki dah rendah).',
            'Pilih jenis cuti, kemudian tarikh From dan To. Bilangan hari dikira untuk anda.',
            'Tambah sebab jika perlu dan hantar. Permohonan akan kekal "Submitted" sehingga pengurus buat keputusan.',
            'Perhatikan panel "Team on leave" supaya anda tak tinggalkan pasukan kekurangan orang pada tarikh sama.',
        ],
    ],
])
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    {{-- Medical balance is hidden from the top cards by request. It still exists as a
         leave type (dropdown + "Leave types explained") — only the summary card is dropped. --}}
    @foreach ($balances->reject(fn ($b) => strtolower($b->leaveType?->name ?? '') === 'medical') as $b)
        @php $low = $b->balance <= 3; @endphp
        <div class="uj-card uj-stat" style="flex:1;min-width:150px;">
            <div class="uj-stat-label">{{ $b->leaveType?->name }} <span x-text="$store.ui.lang==='en' ? 'leave' : 'cuti'">leave</span></div>
            <div class="uj-stat-value" style="color:{{ $low ? 'var(--amber)' : 'var(--ink)' }};">{{ $b->balance }} <span style="font-size:12px;color:var(--muted-soft);">/ {{ $b->leaveType?->entitlement }}</span></div>
        </div>
    @endforeach
</div>

{{-- Two-step gate, both queues server-scoped to the org chart. Step 1: the immediate
     superior verifies their reports' requests. Step 2: management approves verified ones. --}}
@if ($leaveToVerify->isNotEmpty())
    @include('partials.leave-review-queue', ['items' => $leaveToVerify, 'mode' => 'verify'])
@endif

@if ($leaveToApprove->isNotEmpty())
    @include('partials.leave-review-queue', ['items' => $leaveToApprove, 'mode' => 'approve'])
@endif

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    <div class="uj-card" style="flex:1.4;min-width:340px;padding:24px;">
        <h3 class="uj-card-title" style="margin-bottom:16px;"><span x-text="$store.ui.lang==='en' ? 'New application' : 'Permohonan baharu'">New application</span></h3>
        @php
            $requiresDocMap = $leaveTypes->mapWithKeys(fn ($t) => [$t->id => (bool) $t->requires_attachment])->toJson();
            $typeMeta = $leaveTypes->mapWithKeys(fn ($t) => [$t->id => [
                'notice' => (int) $t->min_notice_days,
                'unplanned' => (bool) $t->is_unplanned,
                'deductsFrom' => $t->deducts_from_leave_type_id ? ($leaveTypes->firstWhere('id', $t->deducts_from_leave_type_id)?->name) : null,
            ]])->toJson();
        @endphp
        <form method="post" action="{{ route('leave.store') }}" enctype="multipart/form-data" x-data="{
            requiresDoc: {{ $requiresDocMap }},
            meta: {{ $typeMeta }},
            sel: '{{ old('leave_type_id', $leaveTypes->first()?->id) }}',
            dateFrom: '{{ old('date_from', now()->addDays(3)->toDateString()) }}',
            dateTo: '{{ old('date_to', now()->addDays(4)->toDateString()) }}',
            // '' = full day; 'am'/'pm' = half day. Only offered for a single-day range.
            halfDay: '{{ old('half_day_period', '') }}',
            isSingleDay() { return this.dateFrom === this.dateTo; },
            // Earliest allowed start date for the selected type — mirrors the server rule
            // (planned types need min_notice_days advance). Empty = no client restriction
            // (unplanned/emergency; the server is the only gate for those).
            minFrom() {
                const m = this.meta[this.sel];
                if (!m || m.unplanned || !m.notice) return '';
                const d = new Date(); d.setHours(0, 0, 0, 0);
                d.setDate(d.getDate() + m.notice);
                return d.toISOString().slice(0, 10);
            },
            clampDates() {
                const mf = this.minFrom();
                if (mf && this.dateFrom < mf) this.dateFrom = mf;
                if (this.dateTo < this.dateFrom) this.dateTo = this.dateFrom;
                // A half day is meaningless across a range — drop the marker once the
                // dates span more than one day, so a multi-day request always posts full days.
                if (!this.isSingleDay()) this.halfDay = '';
            },
        }" x-init="clampDates()">
            @csrf
            @error('date_from')<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12.5px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $message }}</div>@enderror
            @error('date_to')<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12.5px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $message }}</div>@enderror
            @error('half_day_period')<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12.5px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $message }}</div>@enderror
            @error('attachment')<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12.5px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $message }}</div>@enderror
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Leave type' : 'Jenis cuti'">Leave type</span></label>
            <select name="leave_type_id" x-model="sel" @change="clampDates()" required style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;color:var(--ink);background:#fff;margin-bottom:10px;">
                @foreach ($leaveTypes as $t)<option value="{{ $t->id }}" x-text="$store.ui.lang==='en' ? '{{ $t->name }} leave' : '{{ $t->name }} cuti'">{{ $t->name }} leave</option>@endforeach
            </select>
            {{-- Emergency: unplanned, spends the Annual balance. Planned types: advance notice. --}}
            <div x-show="meta[sel] && meta[sel].unplanned" style="background:var(--amber-tint,#fbf3e6);border:1px solid var(--amber);color:#7a5418;font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:16px;line-height:1.5;">
                <span x-text="$store.ui.lang==='en' ? 'Emergency leave is for genuine unplanned absences only. It is not extra entitlement — it is deducted from your ' + (meta[sel] ? meta[sel].deductsFrom : '') + ' balance, and frequent use is flagged to management.' : 'Cuti kecemasan hanya untuk ketiadaan tidak dirancang yang sebenar. Ia bukan kelayakan tambahan — ia ditolak daripada baki ' + (meta[sel] ? meta[sel].deductsFrom : '') + ' anda, dan penggunaan kerap dibangkitkan kepada pengurusan.'"></span>
            </div>
            <div x-show="meta[sel] && !meta[sel].unplanned && meta[sel].notice > 0" style="background:var(--info-tint,#e8f0f8);border:1px solid var(--info);color:#1b4a72;font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:16px;line-height:1.5;">
                <span x-text="$store.ui.lang==='en' ? 'Planned leave — apply at least ' + (meta[sel] ? meta[sel].notice : 0) + ' days before the start date. Need time off sooner? Use Emergency leave.' : 'Cuti dirancang — mohon sekurang-kurangnya ' + (meta[sel] ? meta[sel].notice : 0) + ' hari sebelum tarikh mula. Perlu cuti lebih awal? Guna Cuti Kecemasan.'"></span>
            </div>
            @include('partials.hint', ['en' => 'Pick the type that matches the reason — sick leave usually needs an MC, annual leave comes off your yearly entitlement.', 'ms' => 'Pilih jenis yang sepadan dengan sebab — cuti sakit biasanya perlu MC, cuti tahunan ditolak daripada kelayakan tahunan anda.'])
            <div style="display:flex;gap:16px;margin-bottom:16px;">
                <div style="flex:1;"><label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'From' : 'Dari'">From</span></label><input name="date_from" type="date" x-model="dateFrom" :min="minFrom()" @change="clampDates()" value="{{ old('date_from', now()->addDays(3)->toDateString()) }}" style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" /></div>
                <div style="flex:1;"><label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'To' : 'Hingga'">To</span></label><input name="date_to" type="date" x-model="dateTo" :min="dateFrom" value="{{ old('date_to', now()->addDays(4)->toDateString()) }}" style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" /></div>
            </div>
            @include('partials.hint', ['en' => 'First and last day of leave, inclusive. The "To" date must be on or after the "From" date.', 'ms' => 'Hari pertama dan terakhir cuti, termasuk kedua-duanya. Tarikh "To" mesti pada atau selepas tarikh "From".'])
            {{-- Half day: only meaningful on a single date, so it appears only when From = To.
                 'am' = morning off, 'pm' = afternoon off; the other half stays a working half. --}}
            <div x-show="isSingleDay()" x-cloak style="margin-bottom:16px;">
                <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Duration' : 'Tempoh'">Duration</span></label>
                <select name="half_day_period" x-model="halfDay" style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;color:var(--ink);background:#fff;">
                    <option value="" x-text="$store.ui.lang==='en' ? 'Full day' : 'Sepanjang hari'">Full day</option>
                    <option value="am" x-text="$store.ui.lang==='en' ? 'Half day — morning' : 'Setengah hari — pagi'">Half day — morning</option>
                    <option value="pm" x-text="$store.ui.lang==='en' ? 'Half day — afternoon' : 'Setengah hari — petang'">Half day — afternoon</option>
                </select>
                @include('partials.hint', ['en' => 'A half day counts as 0.5 against your balance. You still fill the other half of that day on your timesheet.', 'ms' => 'Setengah hari dikira sebagai 0.5 daripada baki anda. Anda tetap perlu isi separuh hari yang lagi satu pada timesheet.'])
            </div>
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Reason (optional)' : 'Sebab (pilihan)'">Reason (optional)</span></label>
            <textarea name="reason" rows="2" style="width:100%;padding:12px 14px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:16px;outline:none;resize:vertical;">{{ old('reason') }}</textarea>
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;">
                <span x-text="$store.ui.lang==='en' ? 'Supporting document' : 'Dokumen sokongan'">Supporting document</span>
                <span x-show="requiresDoc[sel]" style="color:var(--red);font-weight:600;" x-text="$store.ui.lang==='en' ? '(required)' : '(wajib)'"></span>
                <span x-show="!requiresDoc[sel]" style="color:var(--muted-soft);font-weight:400;" x-text="$store.ui.lang==='en' ? '(optional)' : '(pilihan)'"></span>
            </label>
            <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png" :required="requiresDoc[sel]" style="width:100%;font-size:13px;color:var(--ink);margin-bottom:8px;" />
            @include('partials.hint', ['en' => 'Sick and hospitalisation leave need a medical certificate (MC); maternity and paternity need a supporting letter. PDF or image, up to 8 MB.', 'ms' => 'Cuti sakit dan kemasukan hospital perlu sijil sakit (MC); cuti bersalin dan paterniti perlu surat sokongan. PDF atau imej, sehingga 8 MB.'])
            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? 'Submit application' : 'Hantar permohonan'">Submit application</span></button>
        </form>
    </div>

    <div style="flex:1;min-width:280px;display:flex;flex-direction:column;gap:16px;">
        <div class="uj-card" style="padding:20px;">
            <h3 class="uj-card-title" style="margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'My requests' : 'Permohonan saya'">My requests</span></h3>
            {{-- Process legend so every applicant understands the two-step gate up front. --}}
            <div style="font-size:11px;color:var(--muted);margin-bottom:12px;line-height:1.5;">
                <span x-text="$store.ui.lang==='en' ? 'How it works: you apply → your manager verifies → management approves. Tap a request to see its progress.' : 'Cara ia berfungsi: anda mohon → pengurus sahkan → pengurusan luluskan. Ketik permohonan untuk lihat perkembangannya.'"></span>
            </div>
            @include('partials.approval-chain')
            @forelse ($myRequests as $r)
                @php
                    $sc = ['approved' => 'var(--success)', 'verified' => 'var(--info)', 'submitted' => 'var(--amber)', 'rejected' => 'var(--error)', 'draft' => 'var(--muted)'][$r->status] ?? 'var(--muted)';
                    $leaveStatusMs = ['approved' => 'Diluluskan', 'verified' => 'Disahkan', 'submitted' => 'Dihantar', 'rejected' => 'Ditolak', 'draft' => 'Draf'];
                    $halfEn = $r->isHalfDay() ? ($r->half_day_period === 'am' ? '½ day, morning' : '½ day, afternoon') : null;
                    $halfMs = $r->isHalfDay() ? ($r->half_day_period === 'am' ? '½ hari, pagi' : '½ hari, petang') : null;
                @endphp
                <div x-data="{ open: false }" style="border-bottom:1px solid var(--hairline-soft);">
                    <div @click="open = !open" style="display:flex;align-items:center;justify-content:space-between;padding:9px 0;cursor:pointer;">
                        <span style="font-size:13px;color:var(--ink);">
                            <span x-text="open ? '▾' : '▸'" style="color:var(--muted-soft);font-size:11px;">▸</span>
                            {{ $r->leaveType?->name }} · @if ($r->isHalfDay()){{ $r->date_from->format('j M') }} <span style="font-size:11px;color:var(--muted);" x-text="$store.ui.lang==='en' ? '{{ $halfEn }}' : '{{ $halfMs }}'">{{ $halfEn }}</span>@else{{ $r->date_from->format('j') }}–{{ $r->date_to->format('j M') }}@endif
                            @if ($r->attachment_path)<a href="{{ route('leave.attachment', $r) }}" @click.stop style="text-decoration:none;" title="{{ $r->attachment_name }}">📎</a>@endif
                        </span>
                        <span style="font-size:11px;font-weight:600;color:{{ $sc }};" x-text="$store.ui.lang==='en' ? '{{ ucfirst($r->status) }}' : '{{ $leaveStatusMs[$r->status] ?? ucfirst($r->status) }}'">{{ ucfirst($r->status) }}</span>
                    </div>
                    <div x-show="open" x-collapse style="padding:4px 0 12px 6px;">
                        @if ($r->reason)<div style="font-size:12px;color:var(--muted);margin-bottom:10px;font-style:italic;">“{{ $r->reason }}”</div>@endif
                        @include('partials.leave-timeline', ['r' => $r, 'assignedVerifiers' => $leaveVerifiers])
                    </div>
                </div>
            @empty
                <div style="padding:6px 0;">
                    <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No requests yet' : 'Belum ada permohonan'"></span></div>
                    <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Fill the form on the left to apply for leave. Your applications and their approval status will appear here.' : 'Isi borang di sebelah kiri untuk mohon cuti. Permohonan anda dan status kelulusannya akan muncul di sini.'"></span></div>
                </div>
            @endforelse
        </div>
        <div class="uj-card" style="padding:20px;">
            <h3 class="uj-card-title" style="margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Team on leave' : 'Pasukan bercuti'">Team on leave</span></h3>
            @forelse ($teamLeave as $l)
                <div style="display:flex;align-items:center;gap:11px;padding:8px 0;border-bottom:1px solid var(--hairline-soft);">
                    <div style="width:30px;height:30px;border-radius:50%;background:{{ $l->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;">{{ $l->employee?->initials }}</div>
                    <span style="flex:1;font-size:13px;color:var(--ink);">{{ $l->employee?->name }}</span>
                    <span style="font-size:11.5px;color:var(--muted);">{{ $l->date_from->format('j') }}–{{ $l->date_to->format('j M') }}</span>
                </div>
            @empty
                <div style="font-size:13px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Nobody is on leave right now. Check here before approving overlapping dates so the team is not left short.' : 'Tiada sesiapa bercuti sekarang. Semak di sini sebelum lulus tarikh bertindih supaya pasukan tak kekurangan orang.'"></span></div>
            @endforelse
        </div>
        <div class="uj-card" style="padding:20px;">
            <h3 class="uj-card-title" style="margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Upcoming holidays' : 'Cuti umum akan datang'">Upcoming holidays</span></h3>
            @foreach ($holidays as $h)
                <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--hairline-soft);">
                    <div><div style="font-size:13px;color:var(--ink);">{{ $h->name }}</div><div style="font-size:11.5px;color:var(--muted-soft);">{{ $h->state }}</div></div>
                    <span style="font-size:12px;color:var(--muted);font-family:var(--font-mono);">{{ $h->date->format('j M') }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>

{{-- Reference: what every leave type means. Curated copy per type; the quota and
     the doc/notice/emergency flags are read live from the leave_types config so this
     stays accurate if HR changes them. Each type carries a category colour + icon so
     the list scans at a glance instead of reading as a wall of text. --}}
@php
    // [icon, accent, tint] per type. Category colour encodes meaning: planned=green,
    // health=blue, family=purple, life-event=slate, in-lieu=teal, unplanned=red, unpaid=grey.
    $leaveMeta = [
        'annual'         => ['🌴', '#2f8f5b', '#e7f4ec', 'Planned paid leave taken from your yearly entitlement. Apply ahead — 3 days’ notice.', 'Cuti bergaji dirancang daripada kelayakan tahunan anda. Mohon awal — notis 3 hari.'],
        'medical'        => ['🩺', '#2d6fb3', '#e7f0f9', 'Sick leave certified by a doctor. Attach your MC (medical certificate).', 'Cuti sakit disahkan doktor. Lampirkan MC (sijil sakit).'],
        'hospitalization'=> ['🏥', '#2d6fb3', '#e7f0f9', 'Extended sick leave when you are warded in hospital. Attach the hospital letter.', 'Cuti sakit lanjutan semasa dimasukkan ke hospital. Lampirkan surat hospital.'],
        'maternity'      => ['🤰', '#8e5bd6', '#f1eafb', 'Paid leave for the mother around childbirth (up to 98 days). Attach supporting document.', 'Cuti bergaji untuk ibu semasa bersalin (sehingga 98 hari). Lampirkan dokumen sokongan.'],
        'paternity'      => ['👶', '#8e5bd6', '#f1eafb', 'Paid leave for the father around the birth of his child. Attach supporting document.', 'Cuti bergaji untuk bapa semasa kelahiran anak. Lampirkan dokumen sokongan.'],
        'replacement'    => ['🔄', '#1f8a8a', '#e3f4f4', 'Time off in lieu for working on a rest day or public holiday.', 'Cuti ganti kerana bekerja pada hari rehat atau cuti umum.'],
        'emergency'      => ['⚡', '#c0392b', '#fbeae8', 'For genuine unplanned absences only. Not extra leave — it comes off your Annual balance, and frequent use is flagged to management.', 'Untuk ketiadaan mendadak yang sebenar sahaja. Bukan cuti tambahan — ia ditolak daripada baki Tahunan anda, dan penggunaan kerap dibangkitkan kepada pengurusan.'],
        'compassionate'  => ['🕊️', '#5b708a', '#eaeef3', 'Bereavement leave for the death of an immediate family member.', 'Cuti ihsan atas kematian ahli keluarga terdekat.'],
        'marriage'       => ['💍', '#5b708a', '#eaeef3', 'Leave for your own marriage.', 'Cuti untuk perkahwinan anda sendiri.'],
        'unpaid'         => ['⏸️', '#8a8f98', '#eef0f2', 'Approved leave without pay, usually when your paid balance is used up.', 'Cuti tanpa gaji yang diluluskan, biasanya apabila baki bergaji anda habis.'],
    ];
    $typeNameById = $leaveTypes->pluck('name', 'id');
@endphp
<style>
    .lt-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:12px;margin-top:16px;}
    .lt-tile{display:flex;gap:13px;border:1px solid var(--hairline-soft);border-radius:13px;padding:15px;background:var(--surface,#fff);transition:border-color .15s ease,box-shadow .15s ease,transform .15s ease;}
    .lt-tile:hover{border-color:var(--hairline);box-shadow:0 4px 16px rgba(15,23,42,.06);transform:translateY(-1px);}
    .lt-ico{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0;line-height:1;}
    .lt-head{display:flex;align-items:baseline;justify-content:space-between;gap:8px;}
    .lt-name{font-size:13.5px;font-weight:650;color:var(--ink);}
    .lt-quota{font-size:10.5px;font-weight:700;color:var(--muted);white-space:nowrap;letter-spacing:.01em;text-transform:uppercase;}
    .lt-desc{font-size:12px;color:var(--muted);line-height:1.55;margin:5px 0 10px;}
    .lt-badges{display:flex;flex-wrap:wrap;gap:6px;}
    .lt-badge{font-size:10.5px;border-radius:999px;padding:3px 9px;display:inline-flex;align-items:center;gap:4px;background:var(--canvas);border:1px solid var(--hairline);color:var(--muted);}
    .lt-badge--warn{background:#fbeae8;border-color:#f1c8c2;color:#b23325;}
    .lt-toggle{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--info);cursor:pointer;user-select:none;}
</style>
<div class="uj-card" style="margin-top:16px;padding:20px;" x-data="{ open: false }">
    <div @click="open = !open" style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;cursor:pointer;">
        <div>
            <h3 class="uj-card-title" style="margin-bottom:2px;"><span x-text="$store.ui.lang==='en' ? 'Leave types explained' : 'Jenis cuti diterangkan'">Leave types explained</span></h3>
            <p style="font-size:12px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'What each type covers, and when to use it.' : 'Apa yang setiap jenis merangkumi, dan bila menggunakannya.'">What each type covers, and when to use it.</span></p>
        </div>
        <span class="lt-toggle">
            <span x-text="open ? ($store.ui.lang==='en' ? 'Hide' : 'Sembunyi') : ($store.ui.lang==='en' ? 'Show all ('+{{ $leaveTypes->count() }}+')' : 'Tunjuk semua ('+{{ $leaveTypes->count() }}+')')">Show all</span>
            <span x-text="open ? '▴' : '▾'" style="font-size:10px;">▾</span>
        </span>
    </div>
    <div x-show="open" x-collapse>
        <div class="lt-grid">
            @foreach ($leaveTypes as $t)
                @php $m = $leaveMeta[strtolower($t->name)] ?? ['📄', '#8a8f98', '#eef0f2', 'Company leave type.', 'Jenis cuti syarikat.']; @endphp
                <div class="lt-tile">
                    <div class="lt-ico" style="background:{{ $m[2] }};">{{ $m[0] }}</div>
                    <div style="min-width:0;flex:1;">
                        <div class="lt-head">
                            <span class="lt-name">{{ $t->name }} <span x-text="$store.ui.lang==='en' ? 'leave' : 'cuti'">leave</span></span>
                            <span class="lt-quota" style="color:{{ $m[1] }};">
                                @if ($t->is_unplanned)<span x-text="$store.ui.lang==='en' ? 'As needed' : 'Ikut perlu'">As needed</span>@elseif ($t->entitlement > 0){{ (int) $t->entitlement }} <span x-text="$store.ui.lang==='en' ? 'days/yr' : 'hari/thn'">days/yr</span>@else<span x-text="$store.ui.lang==='en' ? 'Unpaid' : 'Tanpa gaji'">Unpaid</span>@endif
                            </span>
                        </div>
                        <div class="lt-desc"><span x-text="$store.ui.lang==='en' ? '{{ addslashes($m[3]) }}' : '{{ addslashes($m[4]) }}'">{{ $m[3] }}</span></div>
                        <div class="lt-badges">
                            @if ($t->requires_attachment)<span class="lt-badge">📎 <span x-text="$store.ui.lang==='en' ? 'Document required' : 'Perlu dokumen'">Document required</span></span>@endif
                            @if ($t->min_notice_days > 0)<span class="lt-badge">⏳ <span x-text="$store.ui.lang==='en' ? '{{ $t->min_notice_days }} days notice' : 'notis {{ $t->min_notice_days }} hari'">{{ $t->min_notice_days }} days notice</span></span>@endif
                            @if ($t->is_unplanned && $t->deducts_from_leave_type_id)<span class="lt-badge lt-badge--warn">⚠ <span x-text="$store.ui.lang==='en' ? 'Deducts from {{ $typeNameById[$t->deducts_from_leave_type_id] ?? 'Annual' }}' : 'Ditolak dari {{ $typeNameById[$t->deducts_from_leave_type_id] ?? 'Tahunan' }}'">Deducts from {{ $typeNameById[$t->deducts_from_leave_type_id] ?? 'Annual' }}</span></span>@endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection
