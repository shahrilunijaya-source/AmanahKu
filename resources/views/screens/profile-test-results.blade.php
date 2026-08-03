@extends('layouts.app')

@php
    use App\Support\ArchetypeCatalog;
    use App\Support\ArchetypeScorer;

    $animalEmoji = ['rabbit' => '🐇', 'tortoise' => '🐢', 'fox' => '🦊', 'sloth' => '🦥'];

    // option id → label, for turning a stored answer back into the words the person picked.
    $optionLabels = $working->concat($colour)
        ->flatMap(fn ($q) => $q->options)
        ->mapWithKeys(fn ($o) => [$o->id => $o->label_en]);

    $taken = $people->filter(fn ($p) => $p->profileTestResult?->submitted_at);
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'profile-test-results',
    'en'  => [
        'title' => 'Profile test results',
        'body'  => 'What every person answered in their Profile Test — their working-style animal, their self-assessment and their colour icebreakers. Read-only: nothing here can be edited, and it is never a pass/fail judgement of anybody. Treat the self-assessment as private HR material, not team gossip.',
        'who'   => 'HR, directors and managers (their own staff only)',
        'steps' => [
            'Each row is one person. The badge is their working-style animal.',
            'Press a row to open their full answers.',
            'Someone who has not taken the test yet shows as "Not taken".',
        ],
    ],
    'ms'  => [
        'title' => 'Keputusan ujian profil',
        'body'  => 'Apa yang dijawab oleh setiap orang dalam Ujian Profil mereka — haiwan gaya kerja, penilaian diri dan jawapan colour. Baca sahaja: tiada apa-apa di sini boleh disunting, dan ia tidak sekali-kali menjadi penilaian lulus/gagal sesiapa. Anggap penilaian diri sebagai bahan sulit HR, bukan bahan bualan pasukan.',
        'who'   => 'HR, pengarah dan pengurus (staf sendiri sahaja)',
        'steps' => [
            'Setiap baris ialah seorang. Lencana menunjukkan haiwan gaya kerja mereka.',
            'Tekan baris untuk membuka jawapan penuh mereka.',
            'Orang yang belum mengambil ujian dipaparkan sebagai "Not taken".',
        ],
    ],
])

<div class="uj-card" style="padding:14px 18px;margin-bottom:16px;display:flex;gap:22px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:12.5px;color:var(--body);">
        <strong style="color:var(--ink);">{{ $taken->count() }}</strong> of <strong style="color:var(--ink);">{{ $people->count() }}</strong>
        <span x-text="$store.ui.lang==='en' ? 'have taken the test' : 'telah mengambil ujian'">have taken the test</span>
    </span>
    @if (! $seesEveryone)
        <span style="font-size:12px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Showing the staff who report to you.' : 'Memaparkan staf yang melapor kepada anda.'">Showing the staff who report to you.</span></span>
    @endif
</div>

@forelse ($people as $person)
    @php
        $r = $person->profileTestResult;
        $done = (bool) $r?->submitted_at;
        $meta = $done && $r->animal_archetype ? ArchetypeCatalog::get($r->animal_archetype) : null;
        $totals = $r?->totals ?? [];
        $answered = array_sum($totals);
        $selfFields = [
            ['Goal', 'Matlamat', $r?->self_goal],
            ['Strengths', 'Kekuatan', $r?->self_strengths],
            ['Weaknesses', 'Kelemahan', $r?->self_weaknesses],
            ['Interests', 'Minat', $r?->self_interests],
        ];
    @endphp
    <div class="uj-card" style="padding:0;margin-bottom:10px;" x-data="{ open: false }">
        <button type="button" @click="open = ! open" style="width:100%;display:flex;gap:13px;align-items:center;padding:14px 18px;background:none;border:0;cursor:pointer;text-align:left;">
            <span style="width:36px;height:36px;border-radius:10px;background:{{ $meta['accent'] ?? 'var(--canvas)' }};border:1px solid var(--hairline);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">{{ $meta ? ($animalEmoji[$r->animal_archetype] ?? '•') : '·' }}</span>
            <span style="flex:1;min-width:0;">
                <span style="display:block;font-size:13.5px;font-weight:600;color:var(--ink);">{{ $person->name }}</span>
                <span style="display:block;font-size:12px;color:var(--muted);">{{ $person->department?->name ?? '—' }}{{ $r?->self_mbti ? ' · '.$r->self_mbti : '' }}</span>
            </span>
            @if ($done)
                <span style="font-size:12px;color:var(--body);white-space:nowrap;">{{ $meta['label'] ?? '—' }}</span>
                <span style="font-size:11px;color:var(--muted);font-family:var(--font-mono);white-space:nowrap;">{{ $r->submitted_at->format('d M Y') }}</span>
            @else
                <span style="font-size:11.5px;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:3px 10px;border-radius:9999px;white-space:nowrap;"><span x-text="$store.ui.lang==='en' ? 'Not taken' : 'Belum diambil'">Not taken</span></span>
            @endif
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;" :style="open ? 'transform:rotate(180deg);transition:.15s' : 'transition:.15s'"><path d="M6 9l6 6 6-6"/></svg>
        </button>

        @if ($done)
        <div x-show="open" x-cloak style="padding:16px 18px 20px;border-top:1px solid var(--hairline);display:flex;flex-direction:column;gap:18px;">
            {{-- Trait bars --}}
            @if ($answered)
                <div style="display:flex;flex-direction:column;gap:7px;max-width:420px;">
                    @foreach (ArchetypeScorer::ORDER as $a)
                        @php $pct = (int) round(($totals[$a] ?? 0) / $answered * 100); @endphp
                        <div>
                            <div style="display:flex;justify-content:space-between;margin-bottom:3px;"><span style="font-size:11.5px;color:var(--body);">{{ $animalEmoji[$a] }} {{ ucfirst($a) }}</span><span style="font-size:11px;color:var(--muted);font-family:var(--font-mono);">{{ $pct }}%</span></div>
                            <div class="uj-progress"><span style="width:{{ $pct }}%;background:{{ ArchetypeCatalog::get($a)['accent'] }};"></span></div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Self-assessment --}}
            <div>
                <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:8px;"><span x-text="$store.ui.lang==='en' ? 'About themselves' : 'Tentang diri mereka'">About themselves</span></div>
                <div style="display:flex;flex-direction:column;gap:9px;">
                    @foreach ($selfFields as $f)
                        <div style="display:flex;gap:12px;flex-wrap:wrap;">
                            <span style="width:96px;flex-shrink:0;font-size:12px;font-weight:600;color:var(--ink);"><span x-text="$store.ui.lang==='en' ? @js($f[0]) : @js($f[1])">{{ $f[0] }}</span></span>
                            <span style="flex:1;min-width:220px;font-size:12.5px;color:{{ filled($f[2]) ? 'var(--body)' : 'var(--muted)' }};line-height:1.55;white-space:pre-line;">{{ filled($f[2]) ? $f[2] : '—' }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Working-style picks --}}
            @if ($working->isNotEmpty())
                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:8px;"><span x-text="$store.ui.lang==='en' ? 'Working style answers' : 'Jawapan gaya kerja'">Working style answers</span></div>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        @foreach ($working as $q)
                            @php $pick = $r->working_style_answers[$q->id] ?? null; @endphp
                            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                                <span style="flex:1;min-width:220px;font-size:12.5px;color:var(--ink);">{{ $q->prompt_en }}</span>
                                <span style="flex:1;min-width:180px;font-size:12.5px;color:{{ $pick ? 'var(--body)' : 'var(--muted)' }};">{{ $optionLabels[$pick] ?? '—' }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Colour icebreakers --}}
            @if ($colour->isNotEmpty())
                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:8px;">Colour</div>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        @foreach ($colour as $q)
                            @php
                                $given = $r->colour_answers[$q->id] ?? null;
                                // Colour questions are usually free text; the ones that carry
                                // options store the option id instead.
                                $shown = $q->options->isNotEmpty() ? ($optionLabels[$given] ?? null) : $given;
                            @endphp
                            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                                <span style="flex:1;min-width:220px;font-size:12.5px;color:var(--ink);">{{ $q->prompt_en }}</span>
                                <span style="flex:1;min-width:180px;font-size:12.5px;color:{{ filled($shown) ? 'var(--body)' : 'var(--muted)' }};">{{ filled($shown) ? $shown : '—' }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
        @else
        <div x-show="open" x-cloak style="padding:16px 18px;border-top:1px solid var(--hairline);font-size:12.5px;color:var(--muted);">
            <span x-text="$store.ui.lang==='en' ? 'This person has not taken the profile test yet.' : 'Orang ini belum mengambil ujian profil.'">This person has not taken the profile test yet.</span>
        </div>
        @endif
    </div>
@empty
    <div class="uj-card" style="padding:28px;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Nobody reports to you yet.' : 'Belum ada sesiapa melapor kepada anda.'">Nobody reports to you yet.</span></div>
@endforelse
@endsection
