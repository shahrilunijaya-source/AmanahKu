@extends('layouts.app')

@php
    $moodLabel = [1 => '😟 Very low', 2 => '🙁 Low', 3 => '😐 Okay', 4 => '🙂 Good', 5 => '😄 Great'];
    $moodLabelMs = [1 => '😟 Sangat rendah', 2 => '🙁 Rendah', 3 => '😐 Okay', 4 => '🙂 Baik', 5 => '😄 Sangat baik'];
    $stressLabel = [1 => 'Very calm', 2 => 'Calm', 3 => 'Some pressure', 4 => 'Stressed', 5 => 'Overwhelmed'];
    $stressLabelMs = [1 => 'Sangat tenang', 2 => 'Tenang', 3 => 'Sedikit tekanan', 4 => 'Tertekan', 5 => 'Terbeban'];
    $catColor = [
        'Hotline' => 'var(--red)',
        'Mental Health' => 'var(--info)',
        'Financial' => 'var(--success)',
        'Physical' => 'var(--amber)',
        'Legal' => 'var(--muted)',
    ];
    $urgencyColor = ['low' => 'var(--muted)', 'normal' => 'var(--info)', 'high' => 'var(--red)'];
    $reqStatusColor = ['open' => 'var(--amber)', 'acknowledged' => 'var(--info)', 'closed' => 'var(--success)'];
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';

    // Shared EAP resource-card markup. Defined as a closure so the employee view and
    // the HR catalogue view render identical cards without a second view file. All
    // user-supplied fields are escaped with e() since this is emitted via {!! !!}.
    $resourceList = function ($resources, $catColor) {
        if ($resources->isEmpty()) {
            return '<div style="padding:16px 2px;font-size:12.5px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang===\'en\' ? \'No resources have been added yet.\' : \'Belum ada sumber ditambah.\'">No resources have been added yet.</span></div>';
        }
        $html = '';
        foreach ($resources as $r) {
            $color = $catColor[$r->category] ?? 'var(--muted)';
            $html .= '<div style="padding:13px 0;border-bottom:1px solid var(--hairline-soft);">';
            $html .= '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">';
            $html .= '<span style="width:8px;height:8px;border-radius:50%;background:'.$color.';flex-shrink:0;"></span>';
            $html .= '<span style="font-size:13.5px;font-weight:600;color:var(--ink);">'.e($r->title).'</span>';
            $html .= '<span class="uj-pill" style="background:var(--hairline-soft);color:'.$color.';margin-left:auto;">'.e($r->category).'</span>';
            $html .= '</div>';
            $html .= '<p style="font-size:12.5px;color:var(--body);margin:0 0 6px;line-height:1.5;">'.e($r->description).'</p>';
            if ($r->contact) {
                $html .= '<div style="font-size:12.5px;color:var(--ink);font-weight:600;font-family:var(--font-mono);">'.e($r->contact).'</div>';
            }
            if ($r->url) {
                $html .= '<a href="'.e($r->url).'" target="_blank" rel="noopener noreferrer" style="font-size:12px;color:var(--info);text-decoration:underline;"><span x-text="$store.ui.lang===\'en\' ? \'Visit resource\' : \'Lawati sumber\'">Visit resource</span></a>';
            }
            $html .= '</div>';
        }

        return $html;
    };
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'wellness',
    'en'  => [
        'title' => 'Wellbeing & support',
        'body'  => 'A safe, confidential space. Your private mood check-ins are visible only to you — HR sees anonymous, combined trends, never your individual entries. Browse support resources, or quietly ask HR for a confidential 1:1 chat.',
        'who'   => 'Everyone has their own private view · HR sees trends, not names',
        'steps' => [
            'Log how you are feeling — it stays private to you. Only you ever see your own entries.',
            'Browse the EAP library: helplines, counselling, financial and legal support.',
            'Need to talk? Request a confidential 1:1 with HR — only you and HR can see it.',
        ],
    ],
    'ms'  => [
        'title' => 'Kesejahteraan & sokongan',
        'body'  => 'Ruang yang selamat dan sulit. Semakan mood peribadi anda hanya dapat dilihat oleh anda — HR hanya nampak trend gabungan tanpa nama, bukan entri individu anda. Layari sumber sokongan, atau minta sesi 1:1 sulit dengan HR.',
        'who'   => 'Setiap orang ada paparan peribadi sendiri · HR nampak trend, bukan nama',
        'steps' => [
            'Catat perasaan anda — ia kekal peribadi. Hanya anda yang nampak entri sendiri.',
            'Layari pustaka EAP: talian bantuan, kaunseling, sokongan kewangan dan undang-undang.',
            'Perlu berbual? Minta sesi 1:1 sulit dengan HR — hanya anda dan HR boleh melihatnya.',
        ],
    ],
])
@if (session('ok'))
    <div style="background:#e7f4ee;border:1px solid var(--success);color:var(--success);font-size:12.5px;border-radius:8px;padding:10px 14px;margin-bottom:16px;">{{ session('ok') }}</div>
@endif

{{-- Confidentiality reassurance banner --}}
<div class="uj-card" style="padding:13px 16px;margin-bottom:16px;display:flex;gap:11px;align-items:flex-start;border-left:3px solid var(--success);background:#f3faf6;">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
    <div style="font-size:12.5px;color:var(--body);line-height:1.5;">
        @if ($privileged)
            <strong style="color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Confidential by design.' : 'Sulit secara reka bentuk.'">Confidential by design.</strong> <span x-text="$store.ui.lang==='en' ? 'You see anonymous, combined wellbeing trends only — never an individual mood or stress entry. Treat the 1:1 requests below with care.' : 'Anda hanya nampak trend kesejahteraan gabungan tanpa nama — tidak pernah entri mood atau tekanan individu. Layan permintaan 1:1 di bawah dengan berhati-hati.'">You see anonymous, combined wellbeing trends only — never an individual mood or stress entry. Treat the 1:1 requests below with care.</span>
        @else
            <strong style="color:var(--ink);" x-text="$store.ui.lang==='en' ? 'This is private.' : 'Ini peribadi.'">This is private.</strong> <span x-text="$store.ui.lang==='en' ? 'Your check-ins are visible only to you. HR sees combined trends with no names attached. A 1:1 request is seen only by you and HR.' : 'Semakan anda hanya dapat dilihat oleh anda. HR nampak trend gabungan tanpa nama. Permintaan 1:1 hanya dilihat oleh anda dan HR.'">Your check-ins are visible only to you. HR sees combined trends with no names attached. A 1:1 request is seen only by you and HR.</span>
        @endif
    </div>
</div>

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">

    {{-- ───────────────────────── Left column ───────────────────────── --}}
    <div style="flex:2;min-width:340px;display:flex;flex-direction:column;gap:16px;">

        @if ($privileged)
            {{-- HR/management: anonymous aggregate wellbeing panel --}}
            <div class="uj-card" style="padding:20px;">
                <div class="uj-card-head" style="padding:0;margin-bottom:14px;">
                    <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Team wellbeing — anonymous trends' : 'Kesejahteraan pasukan — trend tanpa nama'">Team wellbeing — anonymous trends</span></h3>
                    <span style="font-size:12px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No names · combined only' : 'Tiada nama · gabungan sahaja'">No names · combined only</span>
                </div>
                @if (! $aggregate || $aggregate['count'] === 0)
                    <div style="padding:18px 4px;font-size:12.5px;color:var(--muted);line-height:1.5;">
                        <span x-text="$store.ui.lang==='en' ? 'No check-ins yet. As staff log how they feel, anonymous averages and distribution will appear here — never tied to anyone.' : 'Belum ada semakan. Apabila staf mencatat perasaan mereka, purata dan taburan tanpa nama akan muncul di sini — tidak pernah dikaitkan dengan sesiapa.'"></span>
                    </div>
                @else
                    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:18px;">
                        <div class="uj-stat" style="flex:1;min-width:120px;">
                            <div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Avg mood' : 'Purata mood'">Avg mood</div>
                            <div class="uj-stat-value" style="color:var(--success);">{{ $aggregate['avgMood'] ?? '—' }}<span style="font-size:13px;color:var(--muted);font-weight:500;"> / 5</span></div>
                        </div>
                        <div class="uj-stat" style="flex:1;min-width:120px;">
                            <div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Avg stress' : 'Purata tekanan'">Avg stress</div>
                            <div class="uj-stat-value" style="color:var(--amber);">{{ $aggregate['avgStress'] ?? '—' }}<span style="font-size:13px;color:var(--muted);font-weight:500;"> / 5</span></div>
                        </div>
                        <div class="uj-stat" style="flex:1;min-width:120px;">
                            <div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Check-ins' : 'Semakan'">Check-ins</div>
                            <div class="uj-stat-value">{{ $aggregate['count'] }}</div>
                        </div>
                        <div class="uj-stat" style="flex:1;min-width:120px;">
                            <div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Participants' : 'Peserta'">Participants</div>
                            <div class="uj-stat-value">{{ $aggregate['participants'] }}</div>
                        </div>
                    </div>

                    @php $maxMood = max(1, max($aggregate['moodDist'])); $maxStress = max(1, max($aggregate['stressDist'])); @endphp
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                        <div>
                            <div style="font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;" x-text="$store.ui.lang==='en' ? 'Mood distribution' : 'Taburan mood'">Mood distribution</div>
                            @foreach ($aggregate['moodDist'] as $n => $cnt)
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                    <span style="width:14px;font-size:12px;color:var(--muted);font-family:var(--font-mono);">{{ $n }}</span>
                                    <div style="flex:1;height:9px;border-radius:5px;background:var(--hairline-soft);overflow:hidden;">
                                        <div style="height:100%;width:{{ (int) ($cnt / $maxMood * 100) }}%;background:var(--success);"></div>
                                    </div>
                                    <span style="width:20px;text-align:right;font-size:12px;color:var(--ink);font-family:var(--font-mono);">{{ $cnt }}</span>
                                </div>
                            @endforeach
                        </div>
                        <div>
                            <div style="font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;" x-text="$store.ui.lang==='en' ? 'Stress distribution' : 'Taburan tekanan'">Stress distribution</div>
                            @foreach ($aggregate['stressDist'] as $n => $cnt)
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                    <span style="width:14px;font-size:12px;color:var(--muted);font-family:var(--font-mono);">{{ $n }}</span>
                                    <div style="flex:1;height:9px;border-radius:5px;background:var(--hairline-soft);overflow:hidden;">
                                        <div style="height:100%;width:{{ (int) ($cnt / $maxStress * 100) }}%;background:var(--amber);"></div>
                                    </div>
                                    <span style="width:20px;text-align:right;font-size:12px;color:var(--ink);font-family:var(--font-mono);">{{ $cnt }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- HR/management: confidential 1:1 requests inbox --}}
            <div class="uj-card" style="padding:0;">
                <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Confidential 1:1 requests' : 'Permintaan 1:1 sulit'">Confidential 1:1 requests</span></h3><span style="font-size:12px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Handle with care' : 'Layan dengan berhati-hati'">Handle with care</span></div>
                @forelse ($inbox as $r)
                    <div style="padding:16px 20px;border-bottom:1px solid var(--hairline-soft);">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
                            <div style="min-width:0;">
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:3px;">
                                    <span style="font-size:13.5px;font-weight:600;color:var(--ink);">{{ $r->employee?->name ?? 'Unknown' }}</span>
                                    @if ($r->topic)<span class="uj-pill" style="background:var(--hairline-soft);color:var(--muted);">{{ $r->topic }}</span>@endif
                                    <span class="uj-pill" style="background:var(--hairline-soft);color:{{ $urgencyColor[$r->urgency] ?? 'var(--muted)' }};">{{ ucfirst($r->urgency) }} <span x-text="$store.ui.lang==='en' ? 'urgency' : 'keutamaan'">urgency</span></span>
                                </div>
                                <div style="font-size:12px;color:var(--muted);">{{ $r->created_at?->format('j M Y, g:ia') }}</div>
                            </div>
                            <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:{{ $reqStatusColor[$r->status] ?? 'var(--muted)' }};flex-shrink:0;"><span style="width:8px;height:8px;border-radius:50%;background:{{ $reqStatusColor[$r->status] ?? 'var(--muted)' }};"></span>{{ ucfirst($r->status) }}</span>
                        </div>
                        <div style="font-size:13px;color:var(--body);margin-top:8px;white-space:pre-line;">{{ $r->message }}</div>
                        @if ($r->handledBy)
                            <div style="font-size:11.5px;color:var(--muted);margin-top:6px;"><span x-text="$store.ui.lang==='en' ? 'Handled by' : 'Dikendalikan oleh'">Handled by</span> {{ $r->handledBy->name }} · {{ $r->handled_at?->format('j M Y') }}</div>
                        @endif

                        @if ($r->status !== 'closed')
                            <form method="post" action="{{ route('wellness.resolve', $r) }}" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                                @csrf
                                @if ($r->status === 'open')
                                    <button type="submit" name="status" value="acknowledged" class="uj-btn-ghost" style="height:32px;padding:0 13px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Acknowledge' : 'Akui'">Acknowledge</button>
                                @endif
                                <button type="submit" name="status" value="closed" class="uj-btn-primary" style="height:32px;padding:0 13px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Close request' : 'Tutup permintaan'">Close request</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <div style="padding:28px 20px;text-align:center;">
                        <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No requests right now' : 'Tiada permintaan sekarang'"></span></div>
                        <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'When a staff member asks for a confidential 1:1, it will appear here for you to acknowledge and close.' : 'Apabila staf meminta sesi 1:1 sulit, ia akan muncul di sini untuk anda akui dan tutup.'"></span></div>
                    </div>
                @endforelse
            </div>

        @else
            {{-- Employee: how are you feeling? check-in form --}}
            <div class="uj-card" style="padding:20px;" x-data="{ mood: '{{ old('mood') }}', stress: '{{ old('stress') }}' }">
                <h3 class="uj-card-title" style="margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'How are you feeling today?' : 'Bagaimana perasaan anda hari ini?'">How are you feeling today?</span></h3>
                <p style="font-size:12px;color:var(--muted);margin:0 0 16px;" x-text="$store.ui.lang==='en' ? 'Private to you. This is never shared with anyone by name.' : 'Peribadi untuk anda. Ini tidak pernah dikongsi dengan sesiapa secara nama.'">Private to you. This is never shared with anyone by name.</p>
                <form method="post" action="{{ route('wellness.checkin') }}">
                    @csrf
                    @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:7px;" x-text="$store.ui.lang==='en' ? 'Mood' : 'Suasana hati'">Mood</label>
                    <div style="display:flex;gap:7px;margin-bottom:6px;flex-wrap:wrap;">
                        @for ($n = 1; $n <= 5; $n++)
                            <label style="flex:1;min-width:70px;cursor:pointer;">
                                <input type="radio" name="mood" value="{{ $n }}" x-model="mood" required style="position:absolute;opacity:0;" />
                                <span :style="mood === '{{ $n }}' ? { borderColor:'var(--success)', background:'#f3faf6' } : {}" style="display:flex;flex-direction:column;align-items:center;gap:3px;padding:10px 4px;border:1px solid var(--hairline);border-radius:9px;font-size:18px;transition:.12s;">
                                    {{ ['😟','🙁','😐','🙂','😄'][$n - 1] }}<span style="font-size:10.5px;color:var(--muted);">{{ $n }}</span>
                                </span>
                            </label>
                        @endfor
                    </div>
                    @include('partials.hint', ['en' => '1 = very low · 5 = great. There is no wrong answer — log how you honestly feel.', 'ms' => '1 = sangat rendah · 5 = sangat baik. Tiada jawapan salah — catat perasaan sebenar anda.'])

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin:8px 0 7px;" x-text="$store.ui.lang==='en' ? 'Stress level' : 'Tahap tekanan'">Stress level</label>
                    <div style="display:flex;gap:7px;margin-bottom:6px;flex-wrap:wrap;">
                        @for ($n = 1; $n <= 5; $n++)
                            <label style="flex:1;min-width:55px;cursor:pointer;">
                                <input type="radio" name="stress" value="{{ $n }}" x-model="stress" required style="position:absolute;opacity:0;" />
                                <span :style="stress === '{{ $n }}' ? { borderColor:'var(--amber)', background:'#fdf6ec' } : {}" style="display:block;text-align:center;padding:11px 0;border:1px solid var(--hairline);border-radius:9px;font-size:14px;font-weight:600;font-family:var(--font-mono);color:var(--ink);transition:.12s;">{{ $n }}</span>
                            </label>
                        @endfor
                    </div>
                    @include('partials.hint', ['en' => '1 = very calm · 5 = overwhelmed.', 'ms' => '1 = sangat tenang · 5 = terbeban.'])

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin:8px 0 5px;"><span x-text="$store.ui.lang==='en' ? 'Private note' : 'Nota peribadi'">Private note</span> <span style="color:var(--muted);font-weight:400;" x-text="$store.ui.lang==='en' ? '(optional)' : '(pilihan)'">(optional)</span></label>
                    <textarea name="note" maxlength="1000" rows="2" placeholder="Anything on your mind? Only you will ever read this." :placeholder="$store.ui.lang==='en' ? 'Anything on your mind? Only you will ever read this.' : 'Ada sesuatu di fikiran anda? Hanya anda yang akan membacanya.'" style="width:100%;padding:9px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;resize:vertical;outline:none;margin-bottom:14px;font-family:inherit;">{{ old('note') }}</textarea>

                    <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 22px;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'Log my check-in' : 'Rekod semakan saya'">Log my check-in</button>
                </form>
            </div>

            {{-- Employee: my recent (private) check-ins --}}
            <div class="uj-card" style="padding:0;">
                <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'My recent check-ins' : 'Semakan terkini saya'">My recent check-ins</span></h3><span style="font-size:12px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Private to you' : 'Peribadi untuk anda'">Private to you</span></div>
                @forelse ($myCheckins as $c)
                    <div style="padding:13px 20px;border-bottom:1px solid var(--hairline-soft);display:flex;align-items:center;gap:14px;">
                        <div style="font-size:22px;">{{ ['😟','🙁','😐','🙂','😄'][$c->mood - 1] ?? '🙂' }}</div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:13px;color:var(--ink);font-weight:500;"><span x-text="$store.ui.lang==='en' ? @js($moodLabel[$c->mood] ?? ('Mood '.$c->mood)) : @js($moodLabelMs[$c->mood] ?? ('Mood '.$c->mood))">{{ $moodLabel[$c->mood] ?? ('Mood '.$c->mood) }}</span> · <span style="color:var(--muted);font-weight:400;" x-text="$store.ui.lang==='en' ? @js($stressLabel[$c->stress] ?? ('Stress '.$c->stress)) : @js($stressLabelMs[$c->stress] ?? ('Stress '.$c->stress))">{{ $stressLabel[$c->stress] ?? ('Stress '.$c->stress) }}</span></div>
                            @if ($c->note)<div style="font-size:12px;color:var(--muted);margin-top:2px;white-space:pre-line;">{{ $c->note }}</div>@endif
                        </div>
                        <div style="font-size:12px;color:var(--muted);font-family:var(--font-mono);flex-shrink:0;">{{ $c->checkin_date?->format('j M') }}</div>
                    </div>
                @empty
                    <div style="padding:28px 20px;text-align:center;">
                        <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No check-ins yet' : 'Belum ada semakan'"></span></div>
                        <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Log your first check-in above — your history stays private to you.' : 'Catat semakan pertama anda di atas — sejarah anda kekal peribadi.'"></span></div>
                    </div>
                @endforelse
            </div>
        @endif
    </div>

    {{-- ───────────────────────── Right column ───────────────────────── --}}
    <div style="flex:1;min-width:300px;display:flex;flex-direction:column;gap:16px;">

        {{-- EAP resource library — everyone browses; HR/management can add --}}
        @if ($privileged)
            <div class="uj-card" style="padding:20px;" x-data="{ add: {{ $errors->any() && old('title') ? 'true' : 'false' }} }">
                <div class="uj-card-head" style="padding:0;margin-bottom:14px;">
                    <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'EAP library' : 'Pustaka EAP'">EAP library</span></h3>
                    <button @click="add = ! add" class="uj-btn-primary" style="height:32px;padding:0 12px;font-size:12px;"><span x-text="add ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Add resource' : '+ Tambah sumber')"></span></button>
                </div>
                <form x-show="add" x-cloak method="post" action="{{ route('wellness.resources') }}" style="margin-bottom:16px;">
                    @csrf
                    @if ($errors->any() && old('title'))<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:12px;">{{ $errors->first() }}</div>@endif
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Title *' : 'Tajuk *'">Title *</label>
                    <input name="title" value="{{ old('title') }}" required maxlength="160" placeholder="e.g. Befrienders KL" :placeholder="$store.ui.lang==='en' ? 'e.g. Befrienders KL' : 'cth. Befrienders KL'" style="{{ $fs }}width:100%;margin-bottom:11px;" />
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Category *' : 'Kategori *'">Category *</label>
                    <select name="category" required style="{{ $fs }}width:100%;margin-bottom:11px;">
                        @foreach ($categories as $cat)
                            <option value="{{ $cat }}" @selected(old('category') === $cat)>{{ $cat }}</option>
                        @endforeach
                    </select>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Description *' : 'Penerangan *'">Description *</label>
                    <textarea name="description" required maxlength="2000" rows="3" placeholder="What this resource offers and when to use it." :placeholder="$store.ui.lang==='en' ? 'What this resource offers and when to use it.' : 'Apa yang sumber ini tawarkan dan bila perlu digunakan.'" style="width:100%;padding:9px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;resize:vertical;outline:none;margin-bottom:11px;font-family:inherit;">{{ old('description') }}</textarea>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Contact' : 'Hubungan'">Contact</span> <span style="font-weight:400;" x-text="$store.ui.lang==='en' ? '(phone/email — optional)' : '(telefon/emel — pilihan)'">(phone/email — optional)</span></label>
                    <input name="contact" value="{{ old('contact') }}" maxlength="160" placeholder="e.g. 03-7627 2929" :placeholder="$store.ui.lang==='en' ? 'e.g. 03-7627 2929' : 'cth. 03-7627 2929'" style="{{ $fs }}width:100%;margin-bottom:11px;" />
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;">URL <span style="font-weight:400;" x-text="$store.ui.lang==='en' ? '(optional)' : '(pilihan)'">(optional)</span></label>
                    <input name="url" value="{{ old('url') }}" maxlength="300" placeholder="https://…" style="{{ $fs }}width:100%;margin-bottom:6px;" />
                    @include('partials.hint', ['en' => 'For hotlines, add the phone number in Contact and mark the category as Hotline so it surfaces at the top.', 'ms' => 'Untuk talian bantuan, masukkan nombor telefon dalam Contact dan tetapkan kategori sebagai Hotline supaya ia muncul di atas.'])
                    <button type="submit" class="uj-btn-primary" style="width:100%;height:40px;font-size:13px;margin-top:10px;" x-text="$store.ui.lang==='en' ? 'Add to library' : 'Tambah ke pustaka'">Add to library</button>
                </form>
                {{-- Resource list (shared markup via $resourceList closure, defined at top) --}}
                {!! $resourceList($resources, $catColor) !!}
            </div>
        @else
            <div class="uj-card" style="padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'Support resources' : 'Sumber sokongan'">Support resources</span></h3>
                <p style="font-size:12px;color:var(--muted);margin:0 0 14px;" x-text="$store.ui.lang==='en' ? 'Confidential help, whenever you need it.' : 'Bantuan sulit, bila-bila anda perlukan.'">Confidential help, whenever you need it.</p>
                {!! $resourceList($resources, $catColor) !!}
            </div>

            {{-- Employee: request a confidential 1:1 + my requests --}}
            <div class="uj-card" style="padding:20px;" x-data="{ open: {{ $errors->any() && old('message') ? 'true' : 'false' }} }">
                <div class="uj-card-head" style="padding:0;margin-bottom:12px;">
                    <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Request a confidential chat' : 'Minta perbualan sulit'">Request a confidential chat</span></h3>
                    <button @click="open = ! open" class="uj-btn-primary" style="height:32px;padding:0 12px;font-size:12px;"><span x-text="open ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? 'Request' : 'Minta')"></span></button>
                </div>
                <p style="font-size:12px;color:var(--muted);margin:0 0 12px;line-height:1.5;" x-text="$store.ui.lang==='en' ? 'Only you and HR will see this. Use it whenever you would like a private 1:1.' : 'Hanya anda dan HR akan melihat ini. Gunakannya bila-bila anda mahukan sesi 1:1 peribadi.'">Only you and HR will see this. Use it whenever you would like a private 1:1.</p>
                <form x-show="open" x-cloak method="post" action="{{ route('wellness.request') }}" style="margin-bottom:8px;">
                    @csrf
                    @if ($errors->any() && old('message'))<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:12px;">{{ $errors->first() }}</div>@endif
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Topic' : 'Topik'">Topic</span> <span style="font-weight:400;" x-text="$store.ui.lang==='en' ? '(optional)' : '(pilihan)'">(optional)</span></label>
                    <select name="topic" style="{{ $fs }}width:100%;margin-bottom:11px;">
                        <option value="" x-text="$store.ui.lang==='en' ? 'No specific topic' : 'Tiada topik khusus'">No specific topic</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat }}" @selected(old('topic') === $cat)>{{ $cat }}</option>
                        @endforeach
                    </select>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Urgency *' : 'Keutamaan *'">Urgency *</label>
                    <select name="urgency" required style="{{ $fs }}width:100%;margin-bottom:11px;">
                        @foreach ($urgencies as $u)
                            <option value="{{ $u }}" @selected(old('urgency', 'normal') === $u)>{{ ucfirst($u) }}</option>
                        @endforeach
                    </select>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Message *' : 'Mesej *'">Message *</label>
                    <textarea name="message" required maxlength="2000" rows="3" placeholder="Briefly, what would you like to talk about?" :placeholder="$store.ui.lang==='en' ? 'Briefly, what would you like to talk about?' : 'Secara ringkas, apa yang anda mahu bincangkan?'" style="width:100%;padding:9px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;resize:vertical;outline:none;margin-bottom:6px;font-family:inherit;">{{ old('message') }}</textarea>
                    @include('partials.hint', ['en' => 'You only need to share as much as you are comfortable with. HR will follow up privately.', 'ms' => 'Anda hanya perlu kongsi sebanyak yang anda selesa. HR akan susuli secara peribadi.'])
                    <button type="submit" class="uj-btn-primary" style="width:100%;height:40px;font-size:13px;margin-top:8px;" x-text="$store.ui.lang==='en' ? 'Send confidentially' : 'Hantar secara sulit'">Send confidentially</button>
                </form>

                @if ($myRequests->isNotEmpty())
                    <div style="border-top:1px solid var(--hairline-soft);margin-top:8px;padding-top:12px;">
                        <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;" x-text="$store.ui.lang==='en' ? 'My requests' : 'Permohonan saya'">My requests</div>
                        @foreach ($myRequests as $r)
                            <div style="padding:9px 0;border-bottom:1px solid var(--hairline-soft);">
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                                    <span style="font-size:12.5px;color:var(--ink);">@if ($r->topic){{ $r->topic }}@else<span x-text="$store.ui.lang==='en' ? 'General' : 'Umum'">General</span>@endif · <span style="color:var(--muted);">{{ ucfirst($r->urgency) }}</span></span>
                                    <span style="font-size:11.5px;font-weight:600;color:{{ $reqStatusColor[$r->status] ?? 'var(--muted)' }};">{{ ucfirst($r->status) }}</span>
                                </div>
                                <div style="font-size:12px;color:var(--muted);margin-top:2px;white-space:pre-line;">{{ $r->message }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
@endsection
