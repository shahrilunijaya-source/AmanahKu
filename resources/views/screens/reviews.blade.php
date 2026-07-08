@extends('layouts.app')

@php
    $statusStyle = [
        'scheduled'    => ['var(--muted)', 'var(--hairline-soft)'],
        'in_progress'  => ['var(--amber)', '#fbf3e6'],
        'completed'    => ['var(--info)', '#eaf1f8'],
        'acknowledged' => ['var(--success)', '#e7f4ee'],
    ];
    $isPrivileged = in_array($role, ['manager', 'management', 'hr'], true);
    $blocks = [
        ['Strengths', 'Kekuatan', $latest?->strengths, 'var(--success)'],
        ['Focus areas', 'Bidang fokus', $latest?->improvements, 'var(--amber)'],
        ['Goals', 'Matlamat', $latest?->goals, 'var(--info)'],
    ];
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'reviews',
    'en'  => [
        'title' => 'Performance reviews',
        'body'  => 'A review cycle is a structured check-in on how someone is performing. You write a self-assessment first, your manager then scores your competencies and gives an overall rating out of 5, and finally you acknowledge it so it is on record. Past reviews stay here as history.',
        'who'   => 'You self-assess · Manager scores · You acknowledge',
        'steps' => [
            'When a cycle is open, write your self-assessment and save it (you can keep editing until it closes).',
            'Your manager reviews it, scores each competency, and sets an overall rating.',
            'Once marked complete, read it and click "Acknowledge review" to confirm you have seen it.',
            'Managers: use "Score" on a team member to enter ratings, then tick "complete" to close their cycle.',
        ],
    ],
    'ms'  => [
        'title' => 'Review prestasi',
        'body'  => 'Kitaran review ialah semakan berstruktur tentang prestasi seseorang. Anda tulis self-assessment dahulu, pengurus anda kemudian menilai kompetensi anda dan beri rating keseluruhan daripada 5, dan akhirnya anda mengesahkannya supaya ia direkodkan. Review lepas kekal di sini sebagai sejarah.',
        'who'   => 'Anda self-assess · Pengurus beri skor · Anda sahkan',
        'steps' => [
            'Apabila kitaran dibuka, tulis self-assessment anda dan simpan (anda boleh terus mengedit sehingga ia ditutup).',
            'Pengurus anda review, beri skor setiap kompetensi, dan tetapkan rating keseluruhan.',
            'Sebaik sahaja ditanda selesai, baca dan klik "Acknowledge review" untuk sahkan anda telah melihatnya.',
            'Pengurus: guna "Score" pada ahli pasukan untuk masukkan rating, kemudian tandakan "complete" untuk tutup kitaran mereka.',
        ],
    ],
])

{{-- Open-cycle self-assessment --}}
@if ($current)
    <div class="uj-card" style="padding:0;overflow:hidden;margin-bottom:16px;">
        <div style="background:var(--sidebar);color:#fff;padding:18px 22px;">
            <span style="font-size:10px;font-weight:700;letter-spacing:0.5px;color:#fff;background:var(--red);padding:3px 8px;border-radius:9999px;"><span x-text="$store.ui.lang==='en' ? 'OPEN' : 'DIBUKA'">OPEN</span> · {{ strtoupper(str_replace('_', ' ', $current->status)) }}</span>
            <h3 style="font-size:16px;font-weight:600;color:#fff;margin:11px 0 4px;">{{ $current->cycle }} <span x-text="$store.ui.lang==='en' ? 'self-assessment' : 'penilaian kendiri'">self-assessment</span></h3>
            <p style="font-size:12.5px;color:#b8b6ad;margin:0;line-height:1.5;">{{ $current->period_label }} · <span x-text="$store.ui.lang==='en' ? 'summarise your achievements and goals. Your manager reviews this before scoring.' : 'ringkaskan pencapaian dan matlamat anda. Pengurus anda menyemak ini sebelum memberi skor.'">summarise your achievements and goals. Your manager reviews this before scoring.</span></p>
        </div>
        <form method="post" action="{{ route('reviews.self', $current) }}" style="padding:18px 22px;">
            @csrf
            @error('self_assessment')<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:12px;">{{ $message }}</div>@enderror
            <textarea name="self_assessment" rows="4" maxlength="2000" placeholder="What did you achieve this cycle? What are your goals for the next one?" :placeholder="$store.ui.lang==='en' ? 'What did you achieve this cycle? What are your goals for the next one?' : 'Apa yang anda capai kitaran ini? Apa matlamat anda untuk kitaran seterusnya?'" style="width:100%;padding:12px 13px;border:1px solid var(--hairline);border-radius:9px;font-size:13.5px;font-family:var(--font-sans);resize:vertical;outline:none;color:var(--ink);margin-bottom:6px;">{{ old('self_assessment', $current->self_assessment) }}</textarea>
            @include('partials.hint', ['en' => 'Be specific and honest — name concrete wins, where you grew, and what you want to work on next. This is what your manager reads before scoring you.', 'ms' => 'Jadilah spesifik dan jujur — nyatakan kejayaan yang konkrit, di mana anda berkembang, dan apa yang anda mahu perbaiki seterusnya. Inilah yang pengurus anda baca sebelum memberi skor.'])
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap;">
                @if ($current->self_assessment)
                    <span style="font-size:12px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Draft saved — you can keep editing until the cycle closes.' : 'Draf disimpan — anda boleh terus mengedit sehingga kitaran ditutup.'">Draft saved — you can keep editing until the cycle closes.</span>
                @else
                    <span style="font-size:12px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Not started yet.' : 'Belum dimulakan.'">Not started yet.</span>
                @endif
                <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'Save self-assessment' : 'Simpan penilaian kendiri'">Save self-assessment</button>
            </div>
        </form>
    </div>
@endif

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- Latest scorecard --}}
    <div class="uj-card" style="flex:2;min-width:360px;">
        @if ($latest)
            @php [$sc, $sbg] = $statusStyle[$latest->status] ?? ['var(--muted)', 'var(--hairline-soft)']; @endphp
            <div class="uj-card-head"><h3 class="uj-card-title">{{ $latest->cycle }} <span x-text="$store.ui.lang==='en' ? 'review' : 'penilaian'">review</span></h3><span class="uj-pill" style="background:{{ $sbg }};color:{{ $sc }};">{{ ucfirst(str_replace('_', ' ', $latest->status)) }}</span></div>
            <div style="padding:22px;">
                <div style="display:flex;gap:28px;align-items:flex-start;flex-wrap:wrap;margin-bottom:22px;">
                    <div style="min-width:130px;">
                        <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;" x-text="$store.ui.lang==='en' ? 'Overall rating' : 'Rating keseluruhan'">Overall rating</div>
                        <div style="font-size:42px;font-weight:600;color:var(--ink);font-family:var(--font-mono);line-height:1.1;margin-top:4px;">{{ $latest->overall_rating ? number_format($latest->overall_rating, 1) : '—' }}<span style="font-size:16px;color:var(--muted-soft);">/5</span></div>
                        @if ($latest->rating_label)<div style="font-size:13px;color:var(--success);font-weight:600;margin-top:4px;">{{ $latest->rating_label }}</div>@endif
                    </div>
                    <div style="flex:1;min-width:240px;">
                        @forelse ($latest->competencies ?? [] as $c)
                            <div style="margin-bottom:11px;">
                                <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:4px;"><span style="color:var(--body);">{{ $c['label'] }}</span><span style="color:var(--muted);font-family:var(--font-mono);">{{ number_format($c['score'], 1) }}</span></div>
                                <div class="uj-progress"><span style="width:{{ min($c['score'] / 5 * 100, 100) }}%;background:var(--info);"></span></div>
                            </div>
                        @empty
                            <div style="font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Competency breakdown not recorded.' : 'Pecahan kompetensi tidak direkodkan.'"></span></div>
                        @endforelse
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;">
                    @foreach ($blocks as [$label, $labelMs, $text, $col])
                        @if ($text)
                            <div style="border-left:3px solid {{ $col }};padding:2px 0 2px 13px;">
                                <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;" x-text="$store.ui.lang==='en' ? @js($label) : @js($labelMs)">{{ $label }}</div>
                                <div style="font-size:13px;color:var(--ink);line-height:1.5;">{{ $text }}</div>
                            </div>
                        @endif
                    @endforeach
                </div>

                <div style="margin-top:22px;padding-top:16px;border-top:1px solid var(--hairline-soft);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                    <span style="font-size:12.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Reviewed by' : 'Direview oleh'">Reviewed by</span> {{ $latest->reviewer?->name ?? 'Management' }}@if ($latest->review_date) · {{ $latest->review_date->format('j M Y') }}@endif</span>
                    @if ($latest->status === 'completed')
                        <form method="post" action="{{ route('reviews.acknowledge', $latest) }}">@csrf<button type="submit" class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'Acknowledge review' : 'Sahkan review'">Acknowledge review</button></form>
                    @elseif ($latest->status === 'acknowledged')
                        <span style="display:inline-flex;align-items:center;gap:7px;font-size:13px;color:var(--success);font-weight:600;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg><span x-text="$store.ui.lang==='en' ? 'Acknowledged' : 'Disahkan'">Acknowledged</span> @if ($latest->acknowledged_at)· {{ $latest->acknowledged_at->format('j M Y') }}@endif</span>
                    @endif
                </div>
            </div>
        @else
            <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Latest review' : 'Review terkini'">Latest review</span></h3></div>
            <div style="padding:44px 24px;text-align:center;">
                <div style="font-size:13.5px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No completed review yet' : 'Belum ada review yang selesai'"></span></div>
                <div style="font-size:12.5px;color:var(--muted);line-height:1.5;">@if ($current)<span x-text="$store.ui.lang==='en' ? 'Your {{ $current->cycle }} cycle is in progress — finish your self-assessment above and your scored review will appear here.' : 'Kitaran {{ $current->cycle }} anda sedang berjalan — siapkan self-assessment di atas dan review berskor anda akan muncul di sini.'"></span>@else<span x-text="$store.ui.lang==='en' ? 'Once a review cycle is run for you, your scorecard will show here.' : 'Sebaik sahaja kitaran review dijalankan untuk anda, kad skor anda akan dipaparkan di sini.'"></span>@endif</div>
            </div>
        @endif
    </div>

    {{-- History + team --}}
    <div style="flex:1;min-width:280px;display:flex;flex-direction:column;gap:16px;">
        <div class="uj-card">
            <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Review history' : 'Sejarah review'">Review history</span></h3></div>
            @forelse ($history as $r)
                @php [$hc, $hbg] = $statusStyle[$r->status] ?? ['var(--muted)', 'var(--hairline-soft)']; @endphp
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 20px;border-bottom:1px solid var(--hairline-soft);">
                    <div style="min-width:0;"><div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $r->cycle }}</div><div style="font-size:11.5px;color:var(--muted-soft);">{{ $r->period_label }}</div></div>
                    <div style="text-align:right;flex-shrink:0;"><div style="font-size:13px;color:var(--ink);font-weight:600;font-family:var(--font-mono);">{{ $r->overall_rating ? number_format($r->overall_rating, 1) : '—' }}</div><span class="uj-pill" style="background:{{ $hbg }};color:{{ $hc }};font-size:10px;">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</span></div>
                </div>
            @empty
                <div style="padding:24px 20px;font-size:13px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'No past reviews yet. Completed cycles will be listed here as your review history builds up.' : 'Belum ada review lepas. Kitaran yang selesai akan disenaraikan di sini apabila sejarah review anda bertambah.'"></span></div>
            @endforelse
        </div>

        @if ($isPrivileged)
            <div class="uj-card">
                <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Team reviews' : 'Review pasukan'">Team reviews</span></h3><span style="font-size:12px;color:var(--muted);">{{ $teamReviews->count() }} <span x-text="$store.ui.lang==='en' ? 'in flight' : 'sedang berjalan'">in flight</span></span></div>
                @forelse ($teamReviews as $r)
                    @php
                        [$tc, $tbg] = $statusStyle[$r->status] ?? ['var(--muted)', 'var(--hairline-soft)'];
                        $fsr = 'height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;background:#fff;color:var(--ink);outline:none;width:100%;';
                        $reopen = old('row') == $r->id;
                        // Pre-fill from any saved reviewer ratings so a manager can revise a draft.
                        $rScores = collect($r->reviewer_scores ?? [])->pluck('score', 'label');
                        $rDelivery = $rScores['Delivery & results'] ?? null;
                        $rCollab = $rScores['Collaboration'] ?? null;
                        $rLeadership = $rScores['Leadership'] ?? null;
                        $rField = fn (string $name, $saved, string $default) => $reopen ? old($name, $default) : ($saved !== null ? number_format((float) $saved, 1) : $default);
                    @endphp
                    <div x-data="{ score: {{ $reopen ? 'true' : 'false' }} }" style="border-bottom:1px solid var(--hairline-soft);">
                        <div style="display:flex;align-items:center;gap:11px;padding:11px 20px;">
                            <div style="width:30px;height:30px;border-radius:50%;background:{{ $r->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $r->employee?->initials ?? '—' }}</div>
                            <div style="flex:1;min-width:0;"><div style="font-size:13px;color:var(--ink);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $r->employee?->name }}</div><div style="font-size:11.5px;color:var(--muted-soft);">{{ $r->cycle }}</div></div>
                            @if ($r->status === 'in_progress')
                                @if ($r->reviewer_rated_at)<span class="uj-pill" style="background:#eaf1f8;color:var(--info);font-size:10px;" x-text="$store.ui.lang==='en' ? 'Rated' : 'Dinilai'">Rated</span>@endif
                                <button @click="score = ! score" class="uj-btn-ghost" style="height:28px;padding:0 11px;font-size:11.5px;"><span x-text="score ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : (@js($r->reviewer_rated_at ? true : false) ? ($store.ui.lang==='en' ? 'Edit' : 'Sunting') : ($store.ui.lang==='en' ? 'Score' : 'Beri skor'))"></span></button>
                            @else
                                <span class="uj-pill" style="background:{{ $tbg }};color:{{ $tc }};font-size:10px;">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</span>
                            @endif
                        </div>
                        @if ($r->status === 'in_progress')
                            {{-- Reviewer rating-entry: manager/HR scores competencies; finalise to close the cycle. Server re-enforces role + tenant. --}}
                            <form method="post" action="{{ route('reviews.rate', $r) }}" x-show="score" x-cloak style="padding:0 20px 16px;display:flex;flex-direction:column;gap:9px;">
                                @csrf
                                <input type="hidden" name="row" value="{{ $r->id }}" />
                                <div style="display:flex;gap:9px;">
                                    <div style="width:96px;"><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'Overall /5' : 'Keseluruhan /5'">Overall /5</label><input name="reviewer_overall" type="number" step="0.1" min="0" max="5" value="{{ $rField('reviewer_overall', $r->reviewer_overall, '4.0') }}" required style="{{ $fsr }}font-family:var(--font-mono);" /></div>
                                    <div style="flex:1;"><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'Rating label' : 'Label rating'">Rating label</label><input name="rating_label" value="{{ $reopen ? old('rating_label', 'Meets expectations') : ($r->rating_label ?? 'Meets expectations') }}" required maxlength="60" style="{{ $fsr }}" /></div>
                                </div>
                                @include('partials.hint', ['en' => 'Score each from 0 to 5 (5 = outstanding). The label is the short summary, e.g. "Meets expectations" or "Exceeds".', 'ms' => 'Beri skor setiap satu dari 0 hingga 5 (5 = cemerlang). Label ialah ringkasan pendek, cth. "Meets expectations" atau "Exceeds".'])
                                <div style="display:flex;gap:9px;">
                                    @php
                                        $rDefaults = ['r_delivery' => $rDelivery, 'r_collaboration' => $rCollab, 'r_leadership' => $rLeadership];
                                        $rLabelMs = ['r_delivery' => 'Penyampaian', 'r_collaboration' => 'Kerjasama', 'r_leadership' => 'Kepimpinan'];
                                    @endphp
                                    @foreach (['r_delivery' => 'Delivery', 'r_collaboration' => 'Collab', 'r_leadership' => 'Leadership'] as $cf => $cl)
                                        <div style="flex:1;"><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? @js($cl) : @js($rLabelMs[$cf])">{{ $cl }}</span> /5</label><input name="{{ $cf }}" type="number" step="0.1" min="0" max="5" value="{{ $rField($cf, $rDefaults[$cf], '4.0') }}" required style="{{ $fsr }}font-family:var(--font-mono);" /></div>
                                    @endforeach
                                </div>
                                <textarea name="reviewer_comments" rows="3" maxlength="2000" placeholder="Reviewer comments — strengths, focus areas, goals" :placeholder="$store.ui.lang==='en' ? 'Reviewer comments — strengths, focus areas, goals' : 'Komen penilai — kekuatan, bidang fokus, matlamat'" style="{{ $fsr }}height:auto;padding:8px 9px;resize:vertical;">{{ $reopen ? old('reviewer_comments') : ($r->reviewer_comments ?? '') }}</textarea>
                                <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--body);cursor:pointer;">
                                    <input type="checkbox" name="finalize" value="1" style="width:15px;height:15px;accent-color:var(--info);" />
                                    <span x-text="$store.ui.lang==='en' ? 'Mark review complete (employee can then acknowledge)' : 'Tandakan review selesai (pekerja kemudian boleh sahkan)'">Mark review complete (employee can then acknowledge)</span>
                                </label>
                                @include('partials.hint', ['en' => 'Leave unticked to save as a draft you can revise. Tick it only when scoring is final — it closes the cycle and lets the employee acknowledge.', 'ms' => 'Biarkan tidak bertanda untuk simpan sebagai draf yang anda boleh semak semula. Tandakan hanya apabila pemberian skor muktamad — ia menutup kitaran dan membenarkan pekerja mengesahkan.', 'tone' => 'warn'])
                                <button type="submit" class="uj-btn-primary" style="height:36px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Save reviewer ratings' : 'Simpan rating penilai'">Save reviewer ratings</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <div style="padding:24px 20px;font-size:13px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'No team reviews in progress. When a cycle is opened for your team, each member appears here for you to score.' : 'Tiada review pasukan sedang berjalan. Apabila kitaran dibuka untuk pasukan anda, setiap ahli muncul di sini untuk anda beri skor.'"></span></div>
                @endforelse
            </div>
        @endif
    </div>
</div>
@endsection
