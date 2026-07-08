@extends('layouts.app')

@php
    $statusMeta = [
        'open' => ['label' => 'Open', 'ms' => 'Dibuka', 'color' => 'var(--info)'],
        'investigating' => ['label' => 'Investigating', 'ms' => 'Disiasat', 'color' => 'var(--amber)'],
        'resolved' => ['label' => 'Resolved', 'ms' => 'Diselesaikan', 'color' => 'var(--success)'],
        'closed' => ['label' => 'Closed', 'ms' => 'Ditutup', 'color' => 'var(--muted-soft)'],
    ];
    $typeMeta = [
        'warning' => 'Warning',
        'grievance' => 'Grievance',
        'investigation' => 'Investigation',
    ];
    $typeMetaMs = [
        'warning' => 'Amaran',
        'grievance' => 'Rungutan',
        'investigation' => 'Siasatan',
    ];
    $severityColor = [
        'low' => 'var(--muted)',
        'medium' => 'var(--amber)',
        'high' => 'var(--red)',
    ];
    $severityMs = ['low' => 'Rendah', 'medium' => 'Sederhana', 'high' => 'Tinggi'];
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';
    $pill = function ($s) use ($statusMeta) {
        $color = $statusMeta[$s]['color'] ?? 'var(--muted)';
        $en = addslashes($statusMeta[$s]['label'] ?? ucfirst($s));
        $ms = addslashes($statusMeta[$s]['ms'] ?? ucfirst($s));
        return '<span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:'.$color.';"><span style="width:8px;height:8px;border-radius:50%;background:'.$color.';"></span><span x-text="$store.ui.lang===\'en\' ? \''.$en.'\' : \''.$ms.'\'">'.$en.'</span></span>';
    };
@endphp

@section('screen')

@if (! $privileged)
{{-- ───────────────────────── No access: confidential HR area ───────────────────────── --}}
<div class="uj-card" style="padding:48px 24px;text-align:center;">
    <div style="width:52px;height:52px;border-radius:14px;background:var(--hairline-soft);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4z"></path><path d="M9 12l2 2 4-4"></path></svg>
    </div>
    <h3 style="font-size:16px;font-weight:600;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'You do not have access to this area.' : 'Anda tidak mempunyai akses ke kawasan ini.'">You do not have access to this area.</span></h3>
    <p style="font-size:13.5px;color:var(--muted);max-width:420px;margin:0 auto;"><span x-text="$store.ui.lang==='en' ? 'Disciplinary and grievance cases are confidential and restricted to HR and management.' : 'Kes tatatertib dan rungutan adalah sulit dan terhad kepada HR dan pengurusan sahaja.'">Disciplinary and grievance cases are confidential and restricted to HR and management.</span></p>
</div>

@else
{{-- ───────────────────────── Privileged view: open + manage cases ───────────────────────── --}}
@include('partials.guide', [
    'key' => 'cases',
    'en'  => [
        'title' => 'Disciplinary & grievance cases',
        'body'  => 'A confidential record of disciplinary actions (warnings, investigations) and staff grievances. Everything here is restricted to HR and management — handle it with care and never discuss a case outside this group.',
        'who'   => 'HR & management only · strictly confidential',
        'steps' => [
            'Open a case: pick the employee, the type (warning, grievance or investigation) and how serious it is.',
            'Write the facts plainly — dates, what happened, who was involved. Stick to facts, not opinions.',
            'As the case progresses, use "Manage" to update its status and record the outcome.',
            'Mark it Resolved or Closed once the matter is fully dealt with and documented.',
        ],
    ],
    'ms'  => [
        'title' => 'Kes tatatertib & rungutan',
        'body'  => 'Rekod sulit bagi tindakan tatatertib (amaran, siasatan) dan rungutan staf. Semua maklumat di sini terhad kepada HR dan pengurusan sahaja — kendalikannya dengan berhati-hati dan jangan sesekali bincangkan sesuatu kes di luar kumpulan ini.',
        'who'   => 'HR & pengurusan sahaja · sulit dan terhad',
        'steps' => [
            'Buka kes: pilih pekerja, jenis (amaran, rungutan atau siasatan) dan tahap keseriusannya.',
            'Catat fakta dengan jelas — tarikh, apa yang berlaku, siapa terlibat. Kekal pada fakta, bukan pendapat.',
            'Apabila kes berkembang, guna "Manage" untuk kemas kini statusnya dan rekod keputusan.',
            'Tanda sebagai Resolved atau Closed setelah perkara selesai sepenuhnya dan didokumenkan.',
        ],
    ],
])
<div x-data="{ create: {{ $errors->any() && ! old('_case') ? 'true' : 'false' }}, open: {{ $errors->any() && old('_case') ? (int) old('_case') : 'null' }} }">

    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
        @foreach ($statuses as $s)
            <div class="uj-card uj-stat" style="flex:1;min-width:140px;">
                <div class="uj-stat-label" x-text="$store.ui.lang==='en' ? '{{ $statusMeta[$s]['label'] }}' : '{{ $statusMeta[$s]['ms'] }}'">{{ $statusMeta[$s]['label'] }}</div>
                <div class="uj-stat-value" style="color:{{ $statusMeta[$s]['color'] }};">{{ $counts[$s] ?? 0 }}</div>
            </div>
        @endforeach
    </div>

    {{-- Open a new case --}}
    <div class="uj-card" style="padding:20px;margin-bottom:16px;">
        <div class="uj-card-head" style="padding:0;margin-bottom:14px;">
            <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Open a case' : 'Buka kes'">Open a case</span></h3>
            <button @click="create = ! create" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;"><span x-text="create ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ New case' : '+ Kes baharu')"></span></button>
        </div>
        <form x-show="create" x-cloak method="post" action="{{ route('cases.store') }}">
            @csrf
            @if ($errors->any() && ! old('_case'))
                <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>
            @endif
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
                <div>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Employee *' : 'Pekerja *'">Employee *</span></label>
                    <select name="employee_id" required style="{{ $fs }}width:100%;">
                        <option value="" x-text="$store.ui.lang==='en' ? 'Select employee…' : 'Pilih pekerja…'">Select employee…</option>
                        @foreach ($employees as $e)
                            <option value="{{ $e->id }}" @selected((string) old('employee_id') === (string) $e->id)>{{ $e->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Type *' : 'Jenis *'">Type *</span></label>
                    <select name="type" required style="{{ $fs }}width:100%;margin-bottom:6px;">
                        @foreach ($types as $t)
                            <option value="{{ $t }}" @selected(old('type') === $t) x-text="$store.ui.lang==='en' ? '{{ $typeMeta[$t] ?? ucfirst($t) }}' : '{{ $typeMetaMs[$t] ?? ucfirst($t) }}'">{{ $typeMeta[$t] ?? ucfirst($t) }}</option>
                        @endforeach
                    </select>
                    @include('partials.hint', ['en' => 'Warning = disciplinary action against staff. Grievance = a complaint raised by staff. Investigation = looking into an allegation before deciding.', 'ms' => 'Amaran = tindakan tatatertib terhadap staf. Rungutan = aduan yang dibangkitkan oleh staf. Siasatan = menyiasat sesuatu dakwaan sebelum membuat keputusan.'])
                </div>
                <div>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Severity *' : 'Keseriusan *'">Severity *</span></label>
                    <select name="severity" required style="{{ $fs }}width:100%;margin-bottom:6px;">
                        @foreach ($severities as $sev)
                            <option value="{{ $sev }}" @selected(old('severity', 'low') === $sev) x-text="$store.ui.lang==='en' ? '{{ ucfirst($sev) }}' : '{{ $severityMs[$sev] ?? ucfirst($sev) }}'">{{ ucfirst($sev) }}</option>
                        @endforeach
                    </select>
                    @include('partials.hint', ['en' => 'How serious is it? High for misconduct or anything that may lead to dismissal; Low for a minor first issue.', 'ms' => 'Seberapa serius? High untuk salah laku atau apa-apa yang boleh membawa kepada pembuangan kerja; Low untuk isu kecil kali pertama.'])
                </div>
            </div>
            <div style="margin-top:12px;">
                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Subject *' : 'Subjek *'">Subject *</span></label>
                <input name="subject" value="{{ old('subject') }}" required maxlength="150" :placeholder="$store.ui.lang==='en' ? 'e.g. Repeated lateness — first written warning' : 'cth. Kerap lewat — amaran bertulis pertama'" style="{{ $fs }}width:100%;" />
            </div>
            <div style="margin-top:12px;">
                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Details *' : 'Butiran *'">Details *</span></label>
                <textarea name="details" required maxlength="5000" rows="4" :placeholder="$store.ui.lang==='en' ? 'Document the facts, dates, and context of this case.' : 'Dokumenkan fakta, tarikh dan konteks kes ini.'" style="width:100%;padding:10px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;resize:vertical;margin-bottom:6px;">{{ old('details') }}</textarea>
                @include('partials.hint', ['en' => 'Record only verified facts — dates, what was said or done, who was present. This is a formal record that may be reviewed later.', 'ms' => 'Rekod fakta yang disahkan sahaja — tarikh, apa yang dikata atau dilakukan, siapa hadir. Ini rekod rasmi yang mungkin disemak kemudian.', 'tone' => 'warn'])
            </div>
            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;"><span x-text="$store.ui.lang==='en' ? 'Open case' : 'Buka kes'">Open case</span></button>
        </form>
    </div>

    {{-- Cases grouped by status --}}
    @foreach ($statuses as $s)
        @php $bucket = $grouped->get($s, collect()); @endphp
        <div class="uj-card" style="margin-bottom:16px;">
            <div class="uj-card-head">
                <h3 class="uj-card-title">{!! $pill($s) !!} <span style="color:var(--muted);font-weight:500;font-size:13px;">· {{ $bucket->count() }}</span></h3>
            </div>
            @forelse ($bucket as $c)
                <div style="padding:14px 20px;border-bottom:1px solid var(--hairline-soft);">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
                        <div style="min-width:0;">
                            <div style="font-size:14px;font-weight:600;color:var(--ink);">{{ $c->subject }}</div>
                            <div style="font-size:12px;color:var(--muted);margin-top:3px;">
                                {{ $c->employee?->name ?? '—' }} ·
                                <span style="color:var(--body);font-weight:600;" x-text="$store.ui.lang==='en' ? '{{ $typeMeta[$c->type] ?? ucfirst($c->type) }}' : '{{ $typeMetaMs[$c->type] ?? ucfirst($c->type) }}'">{{ $typeMeta[$c->type] ?? ucfirst($c->type) }}</span> ·
                                <span style="color:{{ $severityColor[$c->severity] ?? 'var(--muted)' }};font-weight:600;"><span x-text="$store.ui.lang==='en' ? '{{ ucfirst($c->severity) }}' : '{{ $severityMs[$c->severity] ?? ucfirst($c->severity) }}'">{{ ucfirst($c->severity) }}</span> <span x-text="$store.ui.lang==='en' ? 'severity' : 'keseriusan'">severity</span></span> ·
                                {{ $c->created_at?->format('j M Y') }}
                                @if ($c->openedBy) · <span style="color:var(--body);"><span x-text="$store.ui.lang==='en' ? 'opened by' : 'dibuka oleh'">opened by</span> {{ $c->openedBy->name }}</span>@endif
                            </div>
                        </div>
                        <button @click="open = (open === {{ $c->id }} ? null : {{ $c->id }})" class="uj-btn-ghost" style="height:30px;padding:0 11px;font-size:12px;flex-shrink:0;"><span x-text="$store.ui.lang==='en' ? 'Manage' : 'Urus'">Manage</span></button>
                    </div>
                    <div style="font-size:13px;color:var(--body);margin-top:8px;white-space:pre-line;">{{ $c->details }}</div>
                    @if ($c->outcome)
                        <div style="background:var(--canvas);border:1px solid var(--hairline-soft);border-radius:8px;padding:10px 12px;margin-top:10px;">
                            <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Outcome' : 'Keputusan'">Outcome</span></div>
                            <div style="font-size:13px;color:var(--body);white-space:pre-line;">{{ $c->outcome }}</div>
                        </div>
                    @endif

                    <div x-show="open === {{ $c->id }}" x-cloak style="margin-top:12px;border-top:1px solid var(--hairline-soft);padding-top:12px;">
                        <form method="post" action="{{ route('cases.update', $c) }}">
                            @csrf
                            <input type="hidden" name="_case" value="{{ $c->id }}" />
                            @if ($errors->any() && (int) old('_case') === $c->id)
                                <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:12px;">{{ $errors->first() }}</div>
                            @endif
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end;">
                                <div>
                                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Status *' : 'Status *'">Status *</span></label>
                                    <select name="status" required style="{{ $fs }}width:100%;">
                                        @foreach ($statuses as $opt)
                                            <option value="{{ $opt }}" @selected(old('status', $c->status) === $opt) x-text="$store.ui.lang==='en' ? '{{ $statusMeta[$opt]['label'] }}' : '{{ $statusMeta[$opt]['ms'] }}'">{{ $statusMeta[$opt]['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div style="margin-top:12px;">
                                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Outcome note' : 'Nota keputusan'">Outcome note</span></label>
                                <textarea name="outcome" maxlength="5000" rows="3" :placeholder="$store.ui.lang==='en' ? 'Record the resolution or outcome of this case.' : 'Rekod penyelesaian atau keputusan kes ini.'" style="width:100%;padding:10px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;resize:vertical;">{{ old('outcome', $c->outcome) }}</textarea>
                            </div>
                            <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;margin-top:14px;"><span x-text="$store.ui.lang==='en' ? 'Save changes' : 'Simpan perubahan'">Save changes</span></button>
                        </form>
                    </div>
                </div>
            @empty
                <div style="padding:28px 20px;text-align:center;">
                    <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No {{ $statusMeta[$s]['label'] }} cases' : 'Tiada kes {{ $statusMeta[$s]['label'] }}'"></span></div>
                    <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Cases marked \'{{ $statusMeta[$s]['label'] }}\' will appear here. Use \'Open a case\' above to record a new one.' : 'Kes bertanda \'{{ $statusMeta[$s]['label'] }}\' akan muncul di sini. Guna \'Open a case\' di atas untuk rekod kes baharu.'"></span></div>
                </div>
            @endforelse
        </div>
    @endforeach
</div>
@endif

@endsection
