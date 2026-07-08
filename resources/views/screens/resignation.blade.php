@extends('layouts.app')

@php
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';
    $statusColors = ['submitted' => 'var(--amber)', 'acknowledged' => 'var(--info)', 'withdrawn' => 'var(--muted)', 'completed' => 'var(--success)'];
    $statusTints = ['submitted' => '#f7efe1', 'acknowledged' => '#eaf1f8', 'withdrawn' => 'var(--hairline-soft)', 'completed' => '#e6f3ee'];
    $ratingLabels = ['management' => 'Management', 'culture' => 'Culture', 'growth' => 'Growth', 'compensation' => 'Compensation'];
    $ratingLabelsMs = ['management' => 'Pengurusan', 'culture' => 'Budaya', 'growth' => 'Pertumbuhan', 'compensation' => 'Pampasan'];
    $statusLabelsMs = ['submitted' => 'Dihantar', 'acknowledged' => 'Disahkan terima', 'withdrawn' => 'Ditarik balik', 'completed' => 'Selesai'];
@endphp

@section('screen')

@include('partials.guide', [
    'key' => 'resignation',
    'en'  => [
        'title' => 'Resignation',
        'body'  => 'Where staff submit their resignation and HR tracks the notice period through to the exit interview. Notice periods follow the contract and the Employment Act — usually 24 hours to 4 weeks depending on length of service.',
        'who'   => 'Staff submit · HR acknowledges & runs exit interview',
        'steps' => [
            'A staff member submits their last working date, notice period and reason.',
            'While still "Submitted" they can withdraw it themselves. Once HR clicks "Acknowledge", it is locked in.',
            'After acknowledging, HR records a confidential exit interview to capture why they left.',
            'The notice period counts the working days between submission and the last working date.',
        ],
    ],
    'ms'  => [
        'title' => 'Peletakan jawatan',
        'body'  => 'Tempat staf menghantar peletakan jawatan dan HR menjejak notice period sehingga ke exit interview. Notice period mengikut kontrak dan Akta Kerja — biasanya 24 jam hingga 4 minggu bergantung pada tempoh perkhidmatan.',
        'who'   => 'Staf hantar · HR mengesahkan & jalankan exit interview',
        'steps' => [
            'Seorang staf menghantar tarikh hari kerja terakhir, notice period dan sebab.',
            'Selagi masih "Submitted" mereka boleh tarik balik sendiri. Setelah HR klik "Acknowledge", ia dikunci.',
            'Selepas mengesahkan, HR merekod exit interview sulit untuk menangkap sebab mereka berhenti.',
            'Notice period mengira hari bekerja antara penghantaran dan hari kerja terakhir.',
        ],
    ],
])

{{-- Employee's own resignation: submit form (none active) or status card (has one). --}}
@if (! $privileged)
    @if ($myResignation && in_array($myResignation->status, ['submitted', 'acknowledged'], true))
        @php $r = $myResignation; @endphp
        <div class="uj-card" style="padding:24px;max-width:560px;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Your resignation' : 'Peletakan jawatan anda'">Your resignation</span></h3>
                <span class="uj-pill" style="background:{{ $statusTints[$r->status] }};color:{{ $statusColors[$r->status] }};"><span x-text="$store.ui.lang==='en' ? @json(ucfirst($r->status)) : @json($statusLabelsMs[$r->status] ?? ucfirst($r->status))">{{ ucfirst($r->status) }}</span></span>
            </div>
            <div style="margin-top:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;">
                <div><div style="font-size:11.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Last working date' : 'Tarikh hari kerja terakhir'">Last working date</span></div><div style="font-size:14px;color:var(--ink);font-weight:500;margin-top:3px;">{{ $r->last_working_date->format('j M Y') }}</div></div>
                <div><div style="font-size:11.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Notice period' : 'Tempoh notis'">Notice period</span></div><div style="font-size:14px;color:var(--ink);font-weight:500;margin-top:3px;">{{ $r->notice_days }} <span x-text="$store.ui.lang==='en' ? 'days' : 'hari'">days</span></div></div>
                <div><div style="font-size:11.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Submitted' : 'Dihantar'">Submitted</span></div><div style="font-size:14px;color:var(--ink);font-weight:500;margin-top:3px;">{{ $r->submitted_at?->format('j M Y') ?? '—' }}</div></div>
            </div>
            <div style="margin-top:14px;"><div style="font-size:11.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Reason' : 'Sebab'">Reason</span></div><p style="font-size:13px;color:var(--body);line-height:1.5;margin:4px 0 0;">{{ $r->reason }}</p></div>
            @if ($r->status === 'submitted')
                <form method="post" action="{{ route('resignation.withdraw', $r) }}" style="margin-top:18px;">
                    @csrf
                    <button type="submit" class="uj-btn-ghost" style="height:40px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Withdraw resignation' : 'Tarik balik peletakan jawatan'">Withdraw resignation</span></button>
                </form>
            @else
                <p style="font-size:12px;color:var(--muted);margin-top:18px;"><span x-text="$store.ui.lang==='en' ? 'Acknowledged by HR — this resignation can no longer be withdrawn.' : 'Telah disahkan terima oleh HR — peletakan jawatan ini tidak boleh ditarik balik lagi.'">Acknowledged by HR — this resignation can no longer be withdrawn.</span></p>
            @endif
        </div>
    @else
        <div class="uj-card" style="padding:24px;max-width:560px;">
            <h3 class="uj-card-title" style="margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Submit your resignation' : 'Hantar peletakan jawatan anda'">Submit your resignation</span></h3>
            <p style="font-size:12.5px;color:var(--muted);margin-bottom:18px;"><span x-text="$store.ui.lang==='en' ? 'Once submitted, HR will acknowledge it. You may withdraw while it is still pending.' : 'Setelah dihantar, HR akan mengesahkan terima. Anda boleh tarik balik selagi ia masih menunggu.'">Once submitted, HR will acknowledge it. You may withdraw while it is still pending.</span></p>
            <form method="post" action="{{ route('resignation.store') }}">
                @csrf
                @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;">
                    <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Last working date' : 'Tarikh hari kerja terakhir'">Last working date</span> *</label><input name="last_working_date" type="date" value="{{ old('last_working_date') }}" required style="{{ $fs }}margin-bottom:6px;width:100%;" />@include('partials.hint', ['en' => 'Your final day on the job — the end of your notice period, not the day you are submitting.', 'ms' => 'Hari terakhir anda bekerja — penghujung notice period anda, bukan hari anda menghantar.'])</div>
                    <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Notice period (days)' : 'Tempoh notis (hari)'">Notice period (days)</span> *</label><input name="notice_days" type="number" min="0" max="365" value="{{ old('notice_days', 30) }}" required style="{{ $fs }}margin-bottom:6px;width:100%;font-family:var(--font-mono);" />@include('partials.hint', ['en' => 'How many days notice your contract requires (often 30). Leaving earlier than your notice may mean paying in lieu — check your contract.', 'ms' => 'Berapa hari notice yang kontrak anda perlukan (selalunya 30). Berhenti lebih awal daripada notice mungkin bermakna bayar ganti — semak kontrak anda.', 'tone' => 'warn'])</div>
                </div>
                <div style="margin-top:12px;"><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Reason' : 'Sebab'">Reason</span> *</label><textarea name="reason" rows="3" maxlength="2000" required style="{{ $fs }}width:100%;height:auto;padding:9px 11px;resize:vertical;">{{ old('reason') }}</textarea></div>
                <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;"><span x-text="$store.ui.lang==='en' ? 'Submit resignation' : 'Hantar peletakan jawatan'">Submit resignation</span></button>
            </form>
        </div>
    @endif
@endif

{{-- Privileged (management/HR): all resignations + acknowledge + confidential exit interview. --}}
@if ($privileged)
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
        <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Resignations' : 'Peletakan jawatan'">Resignations</span></h3>
        <span class="uj-pill" style="background:var(--hairline-soft);color:var(--muted);">{{ $allResignations->count() }}</span>
    </div>

    @forelse ($allResignations as $r)
        @php $ei = $r->exitInterview; @endphp
        <div class="uj-card" style="padding:20px;margin-bottom:16px;">
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <div style="width:40px;height:40px;border-radius:50%;background:{{ $r->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;flex-shrink:0;">{{ $r->employee?->initials }}</div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:14.5px;color:var(--ink);font-weight:600;">{{ $r->employee?->name }}</div>
                    <div style="font-size:12px;color:var(--muted);">{{ $r->employee?->position }} · <span x-text="$store.ui.lang==='en' ? 'Last day' : 'Hari terakhir'">Last day</span> {{ $r->last_working_date->format('j M Y') }} · {{ $r->notice_days }}<span x-text="$store.ui.lang==='en' ? 'd notice' : 'h notis'">d notice</span></div>
                    @if ($r->offboardingCase)
                        @php $oc = $r->offboardingCase; $ocCleared = $oc->clearanceItems->where('done', true)->count(); $ocTotal = $oc->clearanceItems->count(); @endphp
                        <div style="font-size:11.5px;color:var(--muted);margin-top:2px;"><span x-text="$store.ui.lang==='en' ? 'Clearance' : 'Pelepasan'">Clearance</span> <span style="font-family:var(--font-mono);color:var(--ink);">{{ $ocCleared }}/{{ $ocTotal }}</span></div>
                    @endif
                </div>
                <span class="uj-pill" style="background:{{ $statusTints[$r->status] }};color:{{ $statusColors[$r->status] }};"><span x-text="$store.ui.lang==='en' ? @json(ucfirst($r->status)) : @json($statusLabelsMs[$r->status] ?? ucfirst($r->status))">{{ ucfirst($r->status) }}</span></span>
                @if ($r->status === 'submitted')
                    <form method="post" action="{{ route('resignation.acknowledge', $r) }}" style="line-height:0;">
                        @csrf
                        <button type="submit" class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Acknowledge' : 'Sahkan terima'">Acknowledge</span></button>
                    </form>
                @endif
            </div>
            <p style="font-size:13px;color:var(--body);line-height:1.5;margin:14px 0 0;">{{ $r->reason }}</p>

            {{-- Confidential exit-interview panel — privileged only (this whole block already gated). --}}
            @if (in_array($r->status, ['acknowledged', 'completed'], true))
                <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--hairline);">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                        <span style="font-size:12.5px;font-weight:600;color:var(--ink);"><span x-text="$store.ui.lang==='en' ? 'Exit interview' : 'Temu bual keluar'">Exit interview</span></span>
                        <span class="uj-pill" style="background:var(--hairline-soft);color:var(--muted);font-size:10px;"><span x-text="$store.ui.lang==='en' ? 'Confidential · HR only' : 'Sulit · HR sahaja'">Confidential · HR only</span></span>
                    </div>

                    @if ($ei)
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:12px;">
                            <div><div style="font-size:11px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Reason category' : 'Kategori sebab'">Reason category</span></div><div style="font-size:13px;color:var(--ink);font-weight:500;margin-top:3px;">{{ $ei->reason_category }}</div></div>
                            <div><div style="font-size:11px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Would recommend' : 'Akan cadangkan'">Would recommend</span></div><div style="font-size:13px;color:{{ $ei->would_recommend ? 'var(--success)' : 'var(--muted)' }};font-weight:500;margin-top:3px;"><span x-text="$store.ui.lang==='en' ? @json($ei->would_recommend ? 'Yes' : 'No') : @json($ei->would_recommend ? 'Ya' : 'Tidak')">{{ $ei->would_recommend ? 'Yes' : 'No' }}</span></div></div>
                        </div>
                        <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:12px;">
                            @foreach ($ratingLabels as $key => $label)
                                <div style="font-size:12px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? @json($label) : @json($ratingLabelsMs[$key] ?? $label)">{{ $label }}</span>: <span style="color:var(--ink);font-weight:600;font-family:var(--font-mono);">{{ $ei->ratings[$key] ?? '—' }}</span>/5</div>
                            @endforeach
                        </div>
                        @if ($ei->feedback)<p style="font-size:12.5px;color:var(--body);line-height:1.5;margin:0 0 4px;">{{ $ei->feedback }}</p>@endif
                    @endif

                    {{-- Create / update form (updateOrCreate on the server). --}}
                    <details style="margin-top:8px;">
                        <summary style="font-size:12px;color:var(--info);cursor:pointer;"><span x-text="$store.ui.lang==='en' ? @json($ei ? 'Edit exit interview' : 'Record exit interview') : @json($ei ? 'Edit exit interview' : 'Rekod exit interview')">{{ $ei ? 'Edit exit interview' : 'Record exit interview' }}</span></summary>
                        <form method="post" action="{{ route('resignation.interview', $r) }}" style="margin-top:12px;">
                            @csrf
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
                                <div>
                                    <label style="display:block;font-size:11px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Reason category' : 'Kategori sebab'">Reason category</span> *</label>
                                    <select name="reason_category" required style="{{ $fs }}margin-bottom:6px;width:100%;">
                                        @foreach ($reasonCategories as $cat)
                                            <option value="{{ $cat }}" @selected($ei && $ei->reason_category === $cat)>{{ $cat }}</option>
                                        @endforeach
                                    </select>
                                    @include('partials.hint', ['en' => 'Pick the main reason they are leaving. Categorising it lets HR spot patterns across resignations over time.', 'ms' => 'Pilih sebab utama mereka berhenti. Mengkategorikannya membolehkan HR mengesan corak peletakan jawatan dari masa ke masa.'])
                                </div>
                                <div>
                                    <label style="display:block;font-size:11px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Would recommend' : 'Akan cadangkan'">Would recommend</span></label>
                                    <label style="display:flex;align-items:center;gap:8px;height:38px;font-size:13px;color:var(--ink);">
                                        <input type="checkbox" name="would_recommend" value="1" @checked($ei && $ei->would_recommend) /> <span x-text="$store.ui.lang==='en' ? 'Yes' : 'Ya'">Yes</span>
                                    </label>
                                </div>
                            </div>
                            <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:12px;">
                                @foreach ($ratingKeys as $key)
                                    <div>
                                        <label style="display:block;font-size:11px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? @json($ratingLabels[$key] ?? ucfirst($key)) : @json($ratingLabelsMs[$key] ?? ucfirst($key))">{{ $ratingLabels[$key] ?? ucfirst($key) }}</span> (1–5)</label>
                                        <input name="ratings[{{ $key }}]" type="number" min="1" max="5" value="{{ $ei->ratings[$key] ?? '' }}" style="{{ $fs }}width:80px;font-family:var(--font-mono);" />
                                    </div>
                                @endforeach
                            </div>
                            <div style="margin-top:12px;"><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Feedback' : 'Maklum balas'">Feedback</span></label><textarea name="feedback" rows="2" maxlength="5000" style="{{ $fs }}width:100%;height:auto;padding:9px 11px;resize:vertical;">{{ $ei->feedback ?? '' }}</textarea></div>
                            <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:12.5px;margin-top:14px;"><span x-text="$store.ui.lang==='en' ? 'Save exit interview' : 'Simpan exit interview'">Save exit interview</span></button>
                        </form>
                    </details>
                </div>
            @endif
        </div>
    @empty
        <div class="uj-card" style="padding:28px 20px;text-align:center;">
            <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No resignations on record' : 'Tiada peletakan jawatan dalam rekod'"></span></div>
            <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Nobody has resigned. When a staff member submits a resignation, it appears here for you to acknowledge and follow up with an exit interview.' : 'Tiada sesiapa yang meletak jawatan. Apabila seorang staf menghantar peletakan jawatan, ia akan muncul di sini untuk anda sahkan dan susuli dengan exit interview.'"></span></div>
        </div>
    @endforelse
@endif

@endsection
